<?php

declare(strict_types=1);

namespace Integrations\Tests\Unit;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Integrations\Contracts\IntegrationProvider;
use Integrations\Contracts\SupportsIdempotency;
use Integrations\Exceptions\IdempotencyConflict;
use Integrations\Exceptions\RetryableException;
use Integrations\IntegrationManager;
use Integrations\Models\Integration;
use Integrations\Models\IntegrationIdempotencyKey;
use Integrations\Models\IntegrationRequest;
use Integrations\RequestContext;
use Integrations\Support\Config;
use Integrations\Tests\Fixtures\TestOkResponse;
use Integrations\Tests\Fixtures\TestProvider;
use Integrations\Tests\TestCase;
use InvalidArgumentException;
use RuntimeException;

class IdempotencyTest extends TestCase
{
    private Integration $integration;

    protected function setUp(): void
    {
        parent::setUp();

        app(IntegrationManager::class)->register('test', TestProvider::class);
        app(IntegrationManager::class)->register('idempotent', IdempotentTestProvider::class);
        $this->integration = Integration::create(['provider' => 'test', 'name' => 'Test']);
        $this->integration->refresh();
    }

    public function test_with_idempotency_key_passes_explicit_key_through_to_context(): void
    {
        $captured = null;

        $this->integration->at('/api/charge')
            ->withIdempotencyKey('order-42')
            ->post(function (RequestContext $ctx) use (&$captured): array {
                $captured = $ctx->idempotencyKey;

                return ['ok' => true];
            });

        $this->assertSame('order-42', $captured);
    }

    public function test_with_idempotency_key_null_is_a_no_op(): void
    {
        $captured = 'unset';

        $this->integration->at('/api/charge')
            ->withIdempotencyKey(null)
            ->post(function (RequestContext $ctx) use (&$captured): array {
                $captured = $ctx->idempotencyKey;

                return ['ok' => true];
            });

        $this->assertNull($captured);
        $this->assertSame(0, IntegrationIdempotencyKey::query()->count());
    }

    public function test_omitting_with_idempotency_key_leaves_context_key_null(): void
    {
        $captured = 'unset';

        $this->integration->at('/api/charge')->post(function (RequestContext $ctx) use (&$captured): array {
            $captured = $ctx->idempotencyKey;

            return ['ok' => true];
        });

        $this->assertNull($captured);
    }

    public function test_empty_string_idempotency_key_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('must not be empty');

        $this->integration->at('/api/charge')->withIdempotencyKey('');
    }

    public function test_idempotency_key_longer_than_max_length_throws(): void
    {
        $tooLong = str_repeat('a', IntegrationIdempotencyKey::MAX_KEY_LENGTH + 1);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('at most '.IntegrationIdempotencyKey::MAX_KEY_LENGTH);

        $this->integration->at('/api/charge')
            ->withIdempotencyKey($tooLong)
            ->post(fn (): array => ['ok' => true]);
    }

    public function test_idempotency_key_at_exactly_max_length_works(): void
    {
        $atLimit = str_repeat('a', IntegrationIdempotencyKey::MAX_KEY_LENGTH);

        $this->integration->at('/api/charge')
            ->withIdempotencyKey($atLimit)
            ->post(fn (): array => ['ok' => true]);

        $row = IntegrationRequest::query()->latest()->first();
        $this->assertNotNull($row);
        $this->assertSame($atLimit, $row->idempotency_key);
    }

    public function test_idempotency_key_is_persisted_to_integration_requests(): void
    {
        $this->integration->at('/api/charge')
            ->withIdempotencyKey('order-99')
            ->post(fn (): array => ['ok' => true]);

        $row = IntegrationRequest::query()->latest()->first();
        $this->assertNotNull($row);
        $this->assertSame('order-99', $row->idempotency_key);
    }

    public function test_idempotency_key_is_persisted_to_idempotency_keys_table(): void
    {
        $this->integration->at('/api/charge')
            ->withIdempotencyKey('order-99')
            ->post(fn (): array => ['ok' => true]);

        $this->assertSame(1, IntegrationIdempotencyKey::query()->count());

        $row = IntegrationIdempotencyKey::query()->first();
        $this->assertNotNull($row);
        $this->assertSame($this->integration->id, $row->integration_id);
        $this->assertSame('order-99', $row->key);
    }

    public function test_idempotency_key_column_stays_null_when_not_set(): void
    {
        $this->integration->at('/api/charge')->post(fn (): array => ['ok' => true]);

        $row = IntegrationRequest::query()->latest()->first();
        $this->assertNotNull($row);
        $this->assertNull($row->idempotency_key);
        $this->assertSame(0, IntegrationIdempotencyKey::query()->count());
    }

    public function test_second_call_with_same_key_throws_conflict_and_does_not_invoke_callback(): void
    {
        $this->integration->at('/api/charge')
            ->withIdempotencyKey('order-42')
            ->post(fn (): array => ['ok' => true, 'attempt' => 1]);

        $secondCalled = false;

        try {
            $this->integration->at('/api/charge')
                ->withIdempotencyKey('order-42')
                ->post(function () use (&$secondCalled): array {
                    $secondCalled = true;

                    return ['ok' => true, 'attempt' => 2];
                });
            $this->fail('Expected IdempotencyConflict.');
        } catch (IdempotencyConflict $e) {
            $this->assertSame($this->integration->id, $e->integrationId);
            $this->assertSame('order-42', $e->key);
        }

        $this->assertFalse($secondCalled, 'Callback must not run when the key is already reserved.');
        $this->assertSame(1, IntegrationIdempotencyKey::query()->count());
    }

    public function test_same_key_for_different_integrations_both_run(): void
    {
        $other = Integration::create(['provider' => 'test', 'name' => 'Other']);
        $other->refresh();

        $this->integration->at('/api/charge')
            ->withIdempotencyKey('close-ticket:99')
            ->post(fn (): array => ['ok' => true, 'side' => 'a']);

        $other->at('/api/charge')
            ->withIdempotencyKey('close-ticket:99')
            ->post(fn (): array => ['ok' => true, 'side' => 'b']);

        $this->assertSame(2, IntegrationIdempotencyKey::query()->count());
    }

    public function test_callback_throwing_releases_the_key_for_retry(): void
    {
        try {
            $this->integration->at('/api/charge')
                ->withIdempotencyKey('flaky:1')
                ->post(function (): array {
                    throw new RuntimeException('callback blew up');
                });
            $this->fail('Expected the callback exception to propagate.');
        } catch (RuntimeException $e) {
            $this->assertSame('callback blew up', $e->getMessage());
        }

        $this->assertSame(0, IntegrationIdempotencyKey::query()->count());

        $this->integration->at('/api/charge')
            ->withIdempotencyKey('flaky:1')
            ->post(fn (): array => ['ok' => true, 'attempt' => 'retried']);

        $this->assertSame(1, IntegrationIdempotencyKey::query()->count());
    }

    public function test_calling_inside_a_database_transaction_throws_before_inserting(): void
    {
        DB::beginTransaction();

        try {
            $this->integration->at('/api/charge')
                ->withIdempotencyKey('inside-tx:1')
                ->post(fn (): array => ['ok' => true]);
            $this->fail('Expected RuntimeException.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('cannot run inside a database transaction', $e->getMessage());
        } finally {
            DB::rollBack();
        }

        $this->assertSame(0, IntegrationIdempotencyKey::query()->count());
    }

    public function test_pre_existing_row_inserted_directly_still_triggers_conflict(): void
    {
        IntegrationIdempotencyKey::query()->create([
            'integration_id' => $this->integration->id,
            'key' => 'racing:1',
        ]);

        $called = false;

        $this->expectException(IdempotencyConflict::class);

        try {
            $this->integration->at('/api/charge')
                ->withIdempotencyKey('racing:1')
                ->post(function () use (&$called): array {
                    $called = true;

                    return ['ok' => true];
                });
        } finally {
            $this->assertFalse($called);
        }
    }

    public function test_failed_release_after_callback_throws_logs_warning_and_rethrows_original(): void
    {
        Log::spy();

        try {
            $this->integration->at('/api/charge')
                ->withIdempotencyKey('release-fails:1')
                ->post(function (): void {
                    Schema::drop(Config::tablePrefix().'_idempotency_keys');

                    throw new RuntimeException('callback blew up');
                });
            $this->fail('Expected the callback exception to propagate even when release fails.');
        } catch (RuntimeException $e) {
            $this->assertSame('callback blew up', $e->getMessage());
        }

        Log::shouldHaveReceived('warning')
            ->atLeast()
            ->once()
            ->withArgs(function (string $message): bool {
                $integrationId = $this->integration->id;

                return str_contains($message, 'Idempotency-key cleanup failed')
                    && str_contains($message, "integration {$integrationId}")
                    && ! str_contains($message, 'release-fails:1');
            });
    }

    public function test_callback_leaking_a_transaction_skips_release_and_logs_warning(): void
    {
        Log::spy();

        try {
            $this->integration->at('/api/charge')
                ->withIdempotencyKey('leaked-tx:1')
                ->post(function (): void {
                    DB::beginTransaction();

                    throw new RuntimeException('callback blew up after opening a tx');
                });
            $this->fail('Expected the callback exception to propagate.');
        } catch (RuntimeException $e) {
            $this->assertSame('callback blew up after opening a tx', $e->getMessage());
        } finally {
            while (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
        }

        $this->assertSame(
            1,
            IntegrationIdempotencyKey::query()->where('key', 'leaked-tx:1')->count(),
            'Row must remain because we cannot safely DELETE inside a leaked transaction.',
        );

        Log::shouldHaveReceived('warning')
            ->atLeast()
            ->once()
            ->withArgs(function (string $message): bool {
                $integrationId = $this->integration->id;

                return str_contains($message, 'left a database transaction open')
                    && str_contains($message, "integration {$integrationId}")
                    && ! str_contains($message, 'leaked-tx:1');
            });
    }

    public function test_same_key_used_across_inner_retry_attempts(): void
    {
        /** @var list<?string> $observed */
        $observed = [];
        $attempt = 0;

        $this->integration->at('/api/charge')
            ->withAttempts(3)
            ->withIdempotencyKey('charge-stable:1')
            ->post(function (RequestContext $ctx) use (&$observed, &$attempt): array {
                $observed[] = $ctx->idempotencyKey;
                $attempt++;

                if ($attempt < 3) {
                    throw new RetryableException('boom');
                }

                return ['ok' => true];
            });

        $this->assertCount(3, $observed);
        $this->assertSame('charge-stable:1', $observed[0]);
        $this->assertSame($observed[0], $observed[1]);
        $this->assertSame($observed[1], $observed[2]);
    }

    public function test_warns_when_provider_does_not_support_idempotency(): void
    {
        Log::spy();

        $this->integration->at('/api/charge')
            ->withIdempotencyKey('order-1')
            ->post(fn (): array => ['ok' => true]);

        Log::shouldHaveReceived('warning')
            ->once()
            ->withArgs(fn (string $message): bool => str_contains($message, 'SupportsIdempotency'));
    }

    public function test_does_not_warn_when_provider_supports_idempotency(): void
    {
        $integration = Integration::create(['provider' => 'idempotent', 'name' => 'Idem']);
        $integration->refresh();

        Log::spy();

        $integration->at('/api/charge')
            ->withIdempotencyKey('order-1')
            ->post(fn (): array => ['ok' => true]);

        Log::shouldNotHaveReceived('warning');
    }

    public function test_does_not_warn_when_no_key_set(): void
    {
        Log::spy();

        $this->integration->at('/api/charge')->post(fn (): array => ['ok' => true]);

        Log::shouldNotHaveReceived('warning');
    }

    public function test_typed_builder_inherits_with_idempotency_key(): void
    {
        $captured = null;

        $this->integration->at('/api/charge')
            ->withIdempotencyKey('order-7')
            ->as(TestOkResponse::class)
            ->post(function (RequestContext $ctx) use (&$captured): array {
                $captured = $ctx->idempotencyKey;

                return ['ok' => true];
            });

        $this->assertSame('order-7', $captured);
    }
}

/**
 * @internal Test fixture: provider that declares native provider-side
 * idempotency support so the warning path is suppressed.
 */
class IdempotentTestProvider implements IntegrationProvider, SupportsIdempotency
{
    public function name(): string
    {
        return 'Idempotent Test Provider';
    }

    public function credentialRules(): array
    {
        return [];
    }

    public function metadataRules(): array
    {
        return [];
    }

    public function credentialDataClass(): ?string
    {
        return null;
    }

    public function metadataDataClass(): ?string
    {
        return null;
    }
}
