<?php

declare(strict_types=1);

namespace Integrations;

use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Cache;
use Integrations\Contracts\HasScheduledSync;
use Integrations\Exceptions\RateLimitExceededException;
use Integrations\Models\Integration;
use Integrations\Support\Config;

final class RateLimiter
{
    public function __construct(
        private readonly Integration $integration,
    ) {}

    public function enforce(): void
    {
        $limit = $this->resolveLimit();
        if ($limit === null) {
            return;
        }

        $maxWait = Config::rateLimitMaxWaitSeconds();
        $waited = 0;

        while (true) {
            $estimate = $this->estimateCurrentRate();

            if ($estimate < $limit) {
                Cache::increment($this->key(now()));

                return;
            }

            if ($waited >= $maxWait) {
                throw new RateLimitExceededException($this->integration, $estimate, $limit);
            }

            sleep(1);
            $waited++;
        }
    }

    private function resolveLimit(): ?int
    {
        $provider = $this->integration->provider();

        return $provider instanceof HasScheduledSync ? $provider->defaultRateLimit() : null;
    }

    private function estimateCurrentRate(): int
    {
        $now = now();
        $currentKey = $this->key($now);
        $previousKey = $this->key($now->copy()->subMinute());

        $elapsedFraction = ((int) $now->format('s') + (int) $now->format('u') / 1_000_000) / 60.0;

        Cache::add($currentKey, 0, 120);
        Cache::add($previousKey, 0, 120);

        $rawCurrent = Cache::get($currentKey, 0);
        $rawPrevious = Cache::get($previousKey, 0);
        $currentCount = is_numeric($rawCurrent) ? (int) $rawCurrent : 0;
        $previousCount = is_numeric($rawPrevious) ? (int) $rawPrevious : 0;

        return (int) ceil($currentCount + $previousCount * (1.0 - $elapsedFraction));
    }

    private function key(CarbonInterface $time): string
    {
        return Config::cachePrefix().':rate:'.$this->integration->id.':'.$time->format('Y-m-d-H-i');
    }
}
