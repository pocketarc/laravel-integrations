<?php

declare(strict_types=1);

namespace Integrations;

use Closure;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Integrations\Exceptions\IdempotencyConflict;
use Integrations\Models\Integration;
use Integrations\Models\IntegrationIdempotencyKey;
use RuntimeException;
use Throwable;

/**
 * Reserve and release rows in `integration_idempotency_keys` so that a
 * single application-supplied key can drive at-most-once semantics for
 * a request. The reservation is created before the underlying call
 * fires; if the work doesn't complete, the row is released so the next
 * attempt is free to retry.
 *
 * Mirrors the shape of `RateLimiter` / `CircuitBreaker` so the
 * executor can wire it in alongside the other per-call services.
 */
final class IdempotencyKeyManager
{
    public function __construct(
        private readonly Integration $integration,
    ) {}

    /**
     * Reserve the key, run the closure, and release on failure. Lets
     * callers express the at-most-once envelope in one call instead of
     * pairing reserve/release manually.
     *
     * @template T
     *
     * @param  Closure(): T  $work
     * @return T
     */
    public function around(?string $key, Closure $work): mixed
    {
        $this->reserve($key);

        try {
            return $work();
        } catch (Throwable $e) {
            $this->release($key);

            throw $e;
        }
    }

    /**
     * Claim a `(integration_id, key)` row. Throws {@see IdempotencyConflict}
     * if a second caller is already holding the row, so the closure
     * never runs twice for the same key. No-op when no key is supplied.
     *
     * Refuses to run inside a wrapping `DB::transaction()`: an outer
     * rollback would also roll back the INSERT, defeating at-most-once.
     */
    public function reserve(?string $key): void
    {
        if ($key === null) {
            return;
        }

        if (DB::transactionLevel() > 0) {
            throw new RuntimeException(
                'withIdempotencyKey() cannot run inside a database transaction; an outer rollback would also roll back the idempotency-key row, breaking at-most-once. Call it before any DB::transaction() block.',
            );
        }

        try {
            IntegrationIdempotencyKey::query()->create([
                'integration_id' => $this->integration->id,
                'key' => $key,
            ]);
        } catch (UniqueConstraintViolationException $e) {
            throw new IdempotencyConflict($this->integration->id, $key, $e);
        }
    }

    /**
     * Best-effort delete of the idempotency-key row when the work
     * doesn't complete. If the closure left a transaction open the
     * DELETE would be rolled back along with that leaked transaction,
     * so we skip it and log loudly: the row will block future attempts
     * until manually removed.
     *
     * Log messages omit the application-supplied key to keep
     * identifiers out of shared log infrastructure. To find the stuck
     * row, query `integration_idempotency_keys` by the logged
     * integration_id ordered by `created_at` near the warning timestamp.
     */
    public function release(?string $key): void
    {
        if ($key === null) {
            return;
        }

        $level = DB::transactionLevel();

        if ($level > 0) {
            Log::warning(
                "Idempotency-key cleanup skipped for integration {$this->integration->id}: the closure left a database transaction open (level {$level}). The row will block future attempts until manually removed.",
            );

            return;
        }

        try {
            IntegrationIdempotencyKey::query()
                ->where('integration_id', $this->integration->id)
                ->where('key', $key)
                ->delete();
        } catch (Throwable $deleteError) {
            Log::warning(
                "Idempotency-key cleanup failed for integration {$this->integration->id} (".$deleteError::class.'). The row will block future attempts until manually removed.',
            );
        }
    }
}
