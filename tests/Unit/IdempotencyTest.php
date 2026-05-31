<?php

declare(strict_types=1);

namespace Integrations\Tests\Unit;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Integrations\Contracts\IntegrationProvider;
use Integrations\Contracts\SupportsIdempotency;
use Integrations\Enums\IdempotencyPriorState;
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

    public function test_get_idempotency_response_returns_null_when_no_row_exists_for_key(): void
    {
        $this->assertNull($this->integration->getIdempotencyResponse('never-used'));
    }

    public function test_get_idempotency_response_returns_decoded_array_for_a_completed_keyed_call(): void
    {
        $this->integration->at('/api/charge')
            ->withIdempotencyKey('recover-me:1')
            ->post(fn (): array => ['ok' => true, 'id' => 42]);

        $prior = $this->integration->getIdempotencyResponse('recover-me:1');

        $this->assertSame(['ok' => true, 'id' => 42], $prior);
    }

    public function test_get_idempotency_response_ignores_failed_requests(): void
    {
        // A failed POST writes an integration_requests row but with
        // response_success=false. The helper should skip these and behave
        // as if no recoverable prior exists.
        IntegrationRequest::query()->create([
            'integration_id' => $this->integration->id,
            'endpoint' => '/api/charge',
            'method' => 'POST',
            'idempotency_key' => 'half-baked:1',
            'response_code' => 500,
            'response_data' => '{"error":"upstream blew up"}',
            'response_success' => false,
            'duration_ms' => 12,
        ]);

        $this->assertNull($this->integration->getIdempotencyResponse('half-baked:1'));
    }

    public function test_get_idempotency_response_returns_null_when_response_data_is_null(): void
    {
        IntegrationRequest::query()->create([
            'integration_id' => $this->integration->id,
            'endpoint' => '/api/charge',
            'method' => 'POST',
            'idempotency_key' => 'no-body:1',
            'response_code' => 204,
            'response_data' => null,
            'response_success' => true,
            'duration_ms' => 12,
        ]);

        $this->assertNull($this->integration->getIdempotencyResponse('no-body:1'));
    }

    public function test_get_idempotency_response_returns_null_for_unparseable_json(): void
    {
        // A row with garbage in response_data (legacy schema, truncated
        // write, manual edit, whatever). The helper should not throw —
        // just hand back null so the caller can surface a corrupt-payload
        // failure on its own terms.
        IntegrationRequest::query()->create([
            'integration_id' => $this->integration->id,
            'endpoint' => '/api/charge',
            'method' => 'POST',
            'idempotency_key' => 'corrupt:1',
            'response_code' => 200,
            'response_data' => '{not valid json',
            'response_success' => true,
            'duration_ms' => 12,
        ]);

        $this->assertNull($this->integration->getIdempotencyResponse('corrupt:1'));
    }

    public function test_get_idempotency_response_returns_null_when_response_decodes_to_a_scalar(): void
    {
        // Edge case: a closure returned a primitive (e.g. a string) which
        // normalises into response_data as a JSON string literal. Not an
        // array, so not recoverable as a structured response.
        IntegrationRequest::query()->create([
            'integration_id' => $this->integration->id,
            'endpoint' => '/api/charge',
            'method' => 'POST',
            'idempotency_key' => 'scalar:1',
            'response_code' => 200,
            'response_data' => '"just a string"',
            'response_success' => true,
            'duration_ms' => 12,
        ]);

        $this->assertNull($this->integration->getIdempotencyResponse('scalar:1'));
    }

    public function test_get_idempotency_response_returns_the_latest_when_multiple_rows_exist(): void
    {
        // Defensive: there should normally only be one successful row per
        // key, but if (e.g.) operator intervention leaves earlier rows
        // around, recovery should use the most recent.
        IntegrationRequest::query()->create([
            'integration_id' => $this->integration->id,
            'endpoint' => '/api/charge',
            'method' => 'POST',
            'idempotency_key' => 'dupes:1',
            'response_code' => 200,
            'response_data' => '{"version":"older"}',
            'response_success' => true,
            'duration_ms' => 12,
        ]);
        IntegrationRequest::query()->create([
            'integration_id' => $this->integration->id,
            'endpoint' => '/api/charge',
            'method' => 'POST',
            'idempotency_key' => 'dupes:1',
            'response_code' => 200,
            'response_data' => '{"version":"newer"}',
            'response_success' => true,
            'duration_ms' => 12,
        ]);

        $this->assertSame(['version' => 'newer'], $this->integration->getIdempotencyResponse('dupes:1'));
    }

    public function test_get_idempotency_response_is_scoped_to_the_integration(): void
    {
        $other = Integration::create(['provider' => 'test', 'name' => 'Other']);
        $other->refresh();

        $other->at('/api/charge')
            ->withIdempotencyKey('shared:1')
            ->post(fn (): array => ['ok' => true, 'side' => 'other']);

        // The other integration's row must not leak through this integration's helper.
        $this->assertNull($this->integration->getIdempotencyResponse('shared:1'));
        $this->assertSame(['ok' => true, 'side' => 'other'], $other->getIdempotencyResponse('shared:1'));
    }

    public function test_conflict_carries_prior_response_when_a_successful_keyed_call_already_exists(): void
    {
        // First, complete a keyed call. The closure's return becomes the
        // response_data the helper will replay on the conflict path.
        $this->integration->at('/api/charge')
            ->withIdempotencyKey('replayable:1')
            ->post(fn (): array => ['ok' => true, 'order_id' => 4242]);

        try {
            $this->integration->at('/api/charge')
                ->withIdempotencyKey('replayable:1')
                ->post(fn (): array => ['ok' => true, 'attempt' => 2]);
            $this->fail('Expected IdempotencyConflict.');
        } catch (IdempotencyConflict $e) {
            $this->assertSame(['ok' => true, 'order_id' => 4242], $e->priorResponse);
            $this->assertSame(IdempotencyPriorState::Recovered, $e->priorState);
            $this->assertNotNull($e->priorRowId, 'priorRowId should point at the persisted integration_requests row.');
        }
    }

    public function test_conflict_prior_response_is_null_when_only_the_idempotency_row_exists(): void
    {
        // Edge case: someone (a test, an operator, a race) wrote a row
        // directly into integration_idempotency_keys without ever
        // completing a request. There's nothing to recover from, so
        // priorResponse should be null and the caller knows to surface
        // a "stuck key" failure instead of replaying a phantom response.
        IntegrationIdempotencyKey::query()->create([
            'integration_id' => $this->integration->id,
            'key' => 'stuck:1',
        ]);

        try {
            $this->integration->at('/api/charge')
                ->withIdempotencyKey('stuck:1')
                ->post(fn (): array => ['ok' => true]);
            $this->fail('Expected IdempotencyConflict.');
        } catch (IdempotencyConflict $e) {
            $this->assertNull($e->priorResponse);
            $this->assertSame(IdempotencyPriorState::NoRow, $e->priorState);
            $this->assertNull($e->priorRowId);
        }
    }

    public function test_conflict_carries_empty_body_state_when_prior_row_has_null_response_data(): void
    {
        // Distinct branch from NoRow: the keyed call landed and a row was
        // persisted, but the response_data is null (e.g. a 204 reply, or
        // an explicit null return). An operator triaging this should see
        // "empty body" not "no row" so they investigate the right thing.
        IntegrationIdempotencyKey::query()->create([
            'integration_id' => $this->integration->id,
            'key' => 'empty:1',
        ]);
        $row = IntegrationRequest::query()->create([
            'integration_id' => $this->integration->id,
            'endpoint' => '/api/charge',
            'method' => 'POST',
            'idempotency_key' => 'empty:1',
            'response_code' => 204,
            'response_data' => null,
            'response_success' => true,
            'duration_ms' => 10,
        ]);

        try {
            $this->integration->at('/api/charge')
                ->withIdempotencyKey('empty:1')
                ->post(fn (): array => ['ok' => true]);
            $this->fail('Expected IdempotencyConflict.');
        } catch (IdempotencyConflict $e) {
            $this->assertNull($e->priorResponse);
            $this->assertSame(IdempotencyPriorState::EmptyBody, $e->priorState);
            $this->assertSame($row->id, $e->priorRowId);
        }
    }

    public function test_conflict_carries_empty_body_state_when_prior_row_has_empty_string_response_data(): void
    {
        // Sibling case to the null branch above: response_data is the
        // empty string rather than NULL. Same recovery shape, same state.
        IntegrationIdempotencyKey::query()->create([
            'integration_id' => $this->integration->id,
            'key' => 'empty-str:1',
        ]);
        $row = IntegrationRequest::query()->create([
            'integration_id' => $this->integration->id,
            'endpoint' => '/api/charge',
            'method' => 'POST',
            'idempotency_key' => 'empty-str:1',
            'response_code' => 200,
            'response_data' => '',
            'response_success' => true,
            'duration_ms' => 10,
        ]);

        try {
            $this->integration->at('/api/charge')
                ->withIdempotencyKey('empty-str:1')
                ->post(fn (): array => ['ok' => true]);
            $this->fail('Expected IdempotencyConflict.');
        } catch (IdempotencyConflict $e) {
            $this->assertNull($e->priorResponse);
            $this->assertSame(IdempotencyPriorState::EmptyBody, $e->priorState);
            $this->assertSame($row->id, $e->priorRowId);
        }
    }

    public function test_conflict_carries_unparseable_state_when_prior_row_response_data_is_invalid_json(): void
    {
        IntegrationIdempotencyKey::query()->create([
            'integration_id' => $this->integration->id,
            'key' => 'corrupt:1',
        ]);
        $row = IntegrationRequest::query()->create([
            'integration_id' => $this->integration->id,
            'endpoint' => '/api/charge',
            'method' => 'POST',
            'idempotency_key' => 'corrupt:1',
            'response_code' => 200,
            'response_data' => '{not valid json',
            'response_success' => true,
            'duration_ms' => 10,
        ]);

        try {
            $this->integration->at('/api/charge')
                ->withIdempotencyKey('corrupt:1')
                ->post(fn (): array => ['ok' => true]);
            $this->fail('Expected IdempotencyConflict.');
        } catch (IdempotencyConflict $e) {
            $this->assertNull($e->priorResponse);
            $this->assertSame(IdempotencyPriorState::Unparseable, $e->priorState);
            $this->assertSame($row->id, $e->priorRowId);
        }
    }

    public function test_conflict_carries_unparseable_state_when_prior_row_response_data_decodes_to_scalar(): void
    {
        // Valid JSON but decodes to a string rather than an array — same
        // Unparseable branch from the recovery standpoint, since the
        // caller can't hydrate it as a response object.
        IntegrationIdempotencyKey::query()->create([
            'integration_id' => $this->integration->id,
            'key' => 'scalar:1',
        ]);
        $row = IntegrationRequest::query()->create([
            'integration_id' => $this->integration->id,
            'endpoint' => '/api/charge',
            'method' => 'POST',
            'idempotency_key' => 'scalar:1',
            'response_code' => 200,
            'response_data' => '"just a string"',
            'response_success' => true,
            'duration_ms' => 10,
        ]);

        try {
            $this->integration->at('/api/charge')
                ->withIdempotencyKey('scalar:1')
                ->post(fn (): array => ['ok' => true]);
            $this->fail('Expected IdempotencyConflict.');
        } catch (IdempotencyConflict $e) {
            $this->assertNull($e->priorResponse);
            $this->assertSame(IdempotencyPriorState::Unparseable, $e->priorState);
            $this->assertSame($row->id, $e->priorRowId);
        }
    }

    public function test_get_idempotency_recovery_returns_no_row_state_when_nothing_on_file(): void
    {
        $recovery = $this->integration->getIdempotencyRecovery('never-used');

        $this->assertSame(IdempotencyPriorState::NoRow, $recovery->priorState);
        $this->assertNull($recovery->priorRowId);
        $this->assertNull($recovery->priorResponse);
    }

    public function test_get_idempotency_recovery_returns_recovered_state_with_row_id_and_response(): void
    {
        $this->integration->at('/api/charge')
            ->withIdempotencyKey('happy:1')
            ->post(fn (): array => ['ok' => true, 'id' => 99]);

        $recovery = $this->integration->getIdempotencyRecovery('happy:1');

        $this->assertSame(IdempotencyPriorState::Recovered, $recovery->priorState);
        $this->assertNotNull($recovery->priorRowId);
        $this->assertSame(['ok' => true, 'id' => 99], $recovery->priorResponse);
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
