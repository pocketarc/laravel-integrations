<?php

declare(strict_types=1);

namespace Integrations\Support;

final class Config
{
    public static function tablePrefix(): string
    {
        $value = config('integrations.table_prefix', 'integration');

        return is_string($value) && $value !== '' ? $value : 'integration';
    }

    public static function cachePrefix(): string
    {
        $value = config('integrations.cache_prefix', 'integrations');

        return is_string($value) && $value !== '' ? $value : 'integrations';
    }

    public static function webhookPrefix(): string
    {
        $value = config('integrations.webhook.prefix', 'integrations');

        return is_string($value) && $value !== '' ? $value : 'integrations';
    }

    /**
     * @return list<string>
     */
    public static function webhookMiddleware(): array
    {
        $value = config('integrations.webhook.middleware', []);

        return is_array($value) ? array_values(array_filter($value, 'is_string')) : [];
    }

    public static function webhookQueue(): string
    {
        $value = config('integrations.webhook.queue', 'default');

        return is_string($value) && $value !== '' ? $value : 'default';
    }

    public static function oauthRoutePrefix(): string
    {
        $value = config('integrations.oauth.route_prefix', 'integrations');

        return is_string($value) && $value !== '' ? $value : 'integrations';
    }

    /**
     * @return list<string>
     */
    public static function oauthMiddleware(): array
    {
        $value = config('integrations.oauth.middleware', ['web']);

        return is_array($value) ? array_values(array_filter($value, 'is_string')) : ['web'];
    }

    /**
     * @return list<string>
     */
    public static function oauthCallbackMiddleware(): array
    {
        $value = config('integrations.oauth.callback_middleware', ['web']);

        return is_array($value) ? array_values(array_filter($value, 'is_string')) : ['web'];
    }

    public static function oauthStateTtl(): int
    {
        return self::boundedInt(config('integrations.oauth.state_ttl', 600), 600, 1);
    }

    public static function oauthSuccessRedirect(): string
    {
        $value = config('integrations.oauth.success_redirect', '/integrations');

        return is_string($value) ? $value : '/integrations';
    }

    public static function oauthRefreshLockTtl(): int
    {
        return self::boundedInt(config('integrations.oauth.refresh_lock_ttl', 30), 30, 1);
    }

    public static function oauthRefreshLockWait(): int
    {
        return self::boundedInt(config('integrations.oauth.refresh_lock_wait', 15), 15, 1);
    }

    public static function syncQueue(?string $provider = null): string
    {
        if ($provider !== null) {
            $queues = config('integrations.sync.queues', []);
            if (is_array($queues) && array_key_exists($provider, $queues) && is_string($queues[$provider]) && $queues[$provider] !== '') {
                return $queues[$provider];
            }
        }

        $value = config('integrations.sync.queue', 'default');

        return is_string($value) && $value !== '' ? $value : 'default';
    }

    public static function syncLockTtl(): int
    {
        return self::boundedInt(config('integrations.sync.lock_ttl', 1800), 1800, 1);
    }

    public static function syncJobTimeout(): int
    {
        return self::boundedInt(config('integrations.sync.job_timeout', 1800), 1800, 1);
    }

    /**
     * Queue for the per-item ProcessSyncItem jobs. Defaults to the same
     * queue as the parent sync job for the given provider.
     */
    public static function syncItemQueue(?string $provider = null): string
    {
        $value = config('integrations.sync.item_queue');

        if (is_string($value) && $value !== '') {
            return $value;
        }

        return self::syncQueue($provider);
    }

    public static function syncItemTries(): int
    {
        return self::boundedInt(config('integrations.sync.item_tries', 5), 5, 1);
    }

    /**
     * Backoff schedule (seconds between retries) for ProcessSyncItem jobs.
     *
     * @return list<int>
     */
    public static function syncItemBackoff(): array
    {
        $default = [10, 30, 120, 300, 900];
        $value = config('integrations.sync.item_backoff', $default);

        if (! is_array($value)) {
            return $default;
        }

        $backoff = array_values(array_filter(
            $value,
            static fn (mixed $seconds): bool => is_int($seconds) && $seconds >= 0,
        ));

        return $backoff === [] ? $default : $backoff;
    }

    /**
     * Absolute wall-clock window (seconds) a ProcessSyncItem may keep
     * retrying, including transient rate-limit deferrals, before the queue
     * gives up on it.
     */
    public static function syncItemRetryWindow(): int
    {
        return self::boundedInt(config('integrations.sync.item_retry_window', 21_600), 21_600, 1);
    }

    /**
     * Soft cap on the number of items in one sync run. A run with more items
     * is still processed as a single batch, but SyncIntegration logs a warning.
     */
    public static function syncMaxItemsPerBatch(): int
    {
        return self::boundedInt(config('integrations.sync.max_items_per_batch', 10_000), 10_000, 1);
    }

    public static function rateLimitMaxWaitSeconds(): int
    {
        return self::boundedInt(config('integrations.rate_limiting.max_wait_seconds', 10), 10, 0);
    }

    /**
     * Master switch for per-integration rate-limit overrides. When false the
     * override columns still accept writes but are not consulted.
     */
    public static function rateLimitOverridesEnabled(): bool
    {
        $value = config('integrations.rate_limiting.overrides_enabled', true);

        return is_bool($value) ? $value : true;
    }

    public static function retryAfterMaxMs(): int
    {
        return self::boundedInt(config('integrations.retry.retry_after_max_seconds', 600), 600, 1) * 1000;
    }

    public static function circuitBreakerEnabled(): bool
    {
        $value = config('integrations.circuit_breaker.enabled', true);

        return is_bool($value) ? $value : true;
    }

    public static function circuitBreakerThreshold(): int
    {
        return self::boundedInt(config('integrations.circuit_breaker.threshold', 5), 5, 1);
    }

    public static function circuitBreakerCooldownSeconds(): int
    {
        return self::boundedInt(config('integrations.circuit_breaker.cooldown_seconds', 60), 60, 1);
    }

    /**
     * Which tripping strategy the breaker uses: 'rate' (failure percentage
     * over a rolling window, the default) or 'count' (consecutive upstream
     * failures). Anything unrecognised falls back to 'rate'.
     */
    public static function circuitBreakerStrategy(): string
    {
        $value = config('integrations.circuit_breaker.strategy', 'rate');

        return $value === 'count' ? 'count' : 'rate';
    }

    public static function circuitBreakerTimeWindow(): int
    {
        return self::boundedInt(config('integrations.circuit_breaker.time_window', 60), 60, 1);
    }

    public static function circuitBreakerFailureRateThreshold(): int
    {
        return min(100, self::boundedInt(config('integrations.circuit_breaker.failure_rate_threshold', 50), 50, 1));
    }

    public static function circuitBreakerMinimumRequests(): int
    {
        return self::boundedInt(config('integrations.circuit_breaker.minimum_requests', 10), 10, 1);
    }

    /**
     * Master switch for per-integration circuit overrides. When false the
     * override columns still accept writes but are not consulted.
     */
    public static function circuitOverridesEnabled(): bool
    {
        $value = config('integrations.circuit_breaker.overrides_enabled', true);

        return is_bool($value) ? $value : true;
    }

    public static function degradedAfter(): int
    {
        return self::boundedInt(config('integrations.health.degraded_after', 5), 5, 1);
    }

    public static function failingAfter(): int
    {
        return self::boundedInt(config('integrations.health.failing_after', 20), 20, 1);
    }

    public static function degradedBackoff(): int
    {
        return self::boundedInt(config('integrations.health.degraded_backoff', 2), 2, 1);
    }

    public static function failingBackoff(): int
    {
        return self::boundedInt(config('integrations.health.failing_backoff', 10), 10, 1);
    }

    public static function disabledAfter(): ?int
    {
        $value = config('integrations.health.disabled_after', 50);

        if ($value === null) {
            return null;
        }

        return is_int($value) && $value >= 1 ? $value : 50;
    }

    public static function webhookMaxPayloadBytes(): int
    {
        return self::boundedInt(config('integrations.webhook.max_payload_bytes', 1_048_576), 1_048_576, 1);
    }

    public static function webhookProcessingTimeout(): int
    {
        return self::boundedInt(config('integrations.webhook.processing_timeout', 1800), 1800, 60);
    }

    public static function pruningRequestsDays(): int
    {
        return self::boundedInt(config('integrations.pruning.requests_days', 90), 90, 1);
    }

    public static function pruningLogsDays(): int
    {
        return self::boundedInt(config('integrations.pruning.logs_days', 365), 365, 1);
    }

    public static function pruningIdempotencyKeysDays(): int
    {
        return self::boundedInt(config('integrations.pruning.idempotency_keys_days', 90), 90, 1);
    }

    public static function pruningSyncItemsDays(): int
    {
        return self::boundedInt(config('integrations.pruning.sync_items_days', 30), 30, 1);
    }

    public static function pruningChunkSize(): int
    {
        return self::boundedInt(config('integrations.pruning.chunk_size', 1000), 1000, 1);
    }

    /**
     * @return array<string, class-string>
     */
    public static function providers(): array
    {
        $value = config('integrations.providers', []);

        if (! is_array($value)) {
            return [];
        }

        /** @var array<non-empty-string, class-string> */
        return array_filter($value, static function (mixed $class, mixed $key): bool {
            return is_string($key) && $key !== '' && is_string($class) && $class !== '';
        }, ARRAY_FILTER_USE_BOTH);
    }

    private static function boundedInt(mixed $value, int $default, int $min): int
    {
        return is_int($value) && $value >= $min ? $value : $default;
    }
}
