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

    public static function oauthStateTtl(): int
    {
        $value = config('integrations.oauth.state_ttl', 600);

        return is_int($value) ? $value : 600;
    }

    public static function oauthSuccessRedirect(): string
    {
        $value = config('integrations.oauth.success_redirect', '/integrations');

        return is_string($value) ? $value : '/integrations';
    }

    public static function syncQueue(): string
    {
        $value = config('integrations.sync.queue', 'default');

        return is_string($value) && $value !== '' ? $value : 'default';
    }

    public static function syncLockTtl(): int
    {
        $value = config('integrations.sync.lock_ttl', 600);

        return is_int($value) ? $value : 600;
    }

    public static function rateLimitingEnabled(): bool
    {
        $value = config('integrations.rate_limiting.enabled', true);

        return is_bool($value) ? $value : true;
    }

    public static function cacheEnabled(): bool
    {
        $value = config('integrations.request_logging.cache_enabled', true);

        return is_bool($value) ? $value : true;
    }

    public static function degradedAfter(): int
    {
        $value = config('integrations.health.degraded_after', 5);

        return is_int($value) ? $value : 5;
    }

    public static function failingAfter(): int
    {
        $value = config('integrations.health.failing_after', 20);

        return is_int($value) ? $value : 20;
    }

    public static function degradedBackoff(): int
    {
        $value = config('integrations.health.degraded_backoff', 2);

        return is_int($value) ? $value : 2;
    }

    public static function failingBackoff(): int
    {
        $value = config('integrations.health.failing_backoff', 10);

        return is_int($value) ? $value : 10;
    }

    public static function pruningRequestsDays(): int
    {
        $value = config('integrations.pruning.requests_days', 90);

        return is_int($value) ? $value : 90;
    }

    public static function pruningLogsDays(): int
    {
        $value = config('integrations.pruning.logs_days', 365);

        return is_int($value) ? $value : 365;
    }

    public static function pruningChunkSize(): int
    {
        $value = config('integrations.pruning.chunk_size', 1000);

        return is_int($value) ? $value : 1000;
    }

    /**
     * @return array<string, class-string>
     */
    public static function providers(): array
    {
        $value = config('integrations.providers', []);

        /** @var array<string, class-string> */
        return is_array($value) ? $value : [];
    }
}
