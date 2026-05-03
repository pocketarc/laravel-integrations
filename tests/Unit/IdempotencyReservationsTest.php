<?php

declare(strict_types=1);

namespace Integrations\Tests\Unit;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Integrations\Exceptions\ReservationConflict;
use Integrations\IntegrationManager;
use Integrations\Models\Integration;
use Integrations\Models\IntegrationIdempotencyReservation;
use Integrations\Support\Config;
use Integrations\Tests\Fixtures\TestProvider;
use Integrations\Tests\TestCase;
use InvalidArgumentException;
use RuntimeException;

class IdempotencyReservationsTest extends TestCase
{
    private Integration $integration;

    protected function setUp(): void
    {
        parent::setUp();

        app(IntegrationManager::class)->register('test', TestProvider::class);
        $this->integration = Integration::create(['provider' => 'test', 'name' => 'Test']);
        $this->integration->refresh();
    }

    public function test_callable_runs_and_returns_its_value_and_row_is_created(): void
    {
        $result = $this->integration->withReservation('mark-duplicate:42', fn (): string => 'ran');

        $this->assertSame('ran', $result);
        $this->assertSame(1, IntegrationIdempotencyReservation::query()->count());

        $row = IntegrationIdempotencyReservation::query()->first();
        $this->assertNotNull($row);
        $this->assertSame($this->integration->id, $row->integration_id);
        $this->assertSame('mark-duplicate:42', $row->key);
    }

    public function test_second_call_with_same_key_throws_and_does_not_invoke_callable(): void
    {
        $this->integration->withReservation('mark-duplicate:42', fn (): string => 'first');

        $secondCalled = false;

        try {
            $this->integration->withReservation('mark-duplicate:42', function () use (&$secondCalled): string {
                $secondCalled = true;

                return 'second';
            });
            $this->fail('Expected ReservationConflict.');
        } catch (ReservationConflict $e) {
            $this->assertSame($this->integration->id, $e->integrationId);
            $this->assertSame('mark-duplicate:42', $e->key);
            $this->assertStringContainsString((string) $this->integration->id, $e->getMessage());
            $this->assertStringNotContainsString('mark-duplicate:42', $e->getMessage());
        }

        $this->assertFalse($secondCalled, 'Callable must not run when the key is already reserved.');
        $this->assertSame(1, IntegrationIdempotencyReservation::query()->count());
    }

    public function test_same_key_for_different_integrations_both_run(): void
    {
        $other = Integration::create(['provider' => 'test', 'name' => 'Other']);

        $a = $this->integration->withReservation('close-ticket:99', fn (): string => 'a');
        $b = $other->withReservation('close-ticket:99', fn (): string => 'b');

        $this->assertSame('a', $a);
        $this->assertSame('b', $b);
        $this->assertSame(2, IntegrationIdempotencyReservation::query()->count());
    }

    public function test_callable_throwing_releases_the_reservation(): void
    {
        try {
            $this->integration->withReservation('flaky:1', function (): void {
                throw new RuntimeException('callable blew up');
            });
            $this->fail('Expected the callable exception to propagate.');
        } catch (RuntimeException $e) {
            $this->assertSame('callable blew up', $e->getMessage());
        }

        $this->assertSame(0, IntegrationIdempotencyReservation::query()->count());

        $result = $this->integration->withReservation('flaky:1', fn (): string => 'retried');
        $this->assertSame('retried', $result);
    }

    public function test_calling_inside_a_database_transaction_throws_before_inserting(): void
    {
        DB::beginTransaction();

        try {
            $this->integration->withReservation('inside-tx:1', fn (): string => 'never');
            $this->fail('Expected RuntimeException.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('cannot run inside a database transaction', $e->getMessage());
        } finally {
            DB::rollBack();
        }

        $this->assertSame(0, IntegrationIdempotencyReservation::query()->count());
    }

    public function test_empty_key_throws_invalid_argument(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('must not be empty');

        $this->integration->withReservation('', fn (): string => 'never');
    }

    public function test_pre_existing_row_inserted_directly_still_triggers_conflict(): void
    {
        IntegrationIdempotencyReservation::query()->create([
            'integration_id' => $this->integration->id,
            'key' => 'racing:1',
        ]);

        $called = false;

        $this->expectException(ReservationConflict::class);

        try {
            $this->integration->withReservation('racing:1', function () use (&$called): string {
                $called = true;

                return 'should-not-run';
            });
        } finally {
            $this->assertFalse($called);
        }
    }

    public function test_failed_release_after_callable_throws_logs_warning_and_rethrows_original(): void
    {
        Log::spy();

        try {
            $this->integration->withReservation('release-fails:1', function (): void {
                Schema::drop(Config::tablePrefix().'_idempotency_reservations');

                throw new RuntimeException('callable blew up');
            });
            $this->fail('Expected the callable exception to propagate even when release fails.');
        } catch (RuntimeException $e) {
            $this->assertSame('callable blew up', $e->getMessage());
        }

        Log::shouldHaveReceived('warning')
            ->once()
            ->withArgs(function (string $message): bool {
                $integrationId = $this->integration->id;

                return str_contains($message, 'Reservation cleanup failed')
                    && str_contains($message, "integration {$integrationId}")
                    && ! str_contains($message, 'release-fails:1');
            });
    }

    public function test_callback_leaking_a_transaction_skips_release_and_logs_warning(): void
    {
        Log::spy();

        try {
            $this->integration->withReservation('leaked-tx:1', function (): void {
                DB::beginTransaction();

                throw new RuntimeException('callable blew up after opening a tx');
            });
            $this->fail('Expected the callable exception to propagate.');
        } catch (RuntimeException $e) {
            $this->assertSame('callable blew up after opening a tx', $e->getMessage());
        } finally {
            while (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
        }

        $this->assertSame(
            1,
            IntegrationIdempotencyReservation::query()->where('key', 'leaked-tx:1')->count(),
            'Row must remain because we cannot safely DELETE inside a leaked transaction.',
        );

        Log::shouldHaveReceived('warning')
            ->once()
            ->withArgs(function (string $message): bool {
                $integrationId = $this->integration->id;

                return str_contains($message, 'left a database transaction open')
                    && str_contains($message, "integration {$integrationId}")
                    && ! str_contains($message, 'leaked-tx:1');
            });
    }
}
