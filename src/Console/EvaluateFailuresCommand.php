<?php

declare(strict_types=1);

namespace Integrations\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Integrations\Events\ElevatedFailureRate;
use Integrations\Events\FailureRateRecovered;
use Integrations\Models\Integration;
use Integrations\Reporting\FailureRateSnapshot;
use Integrations\Reporting\FailureReporter;
use Integrations\Support\Config;

/**
 * Evaluates each active integration's failure rate over the configured window
 * and emits a debounced anomaly signal: one ElevatedFailureRate per incident,
 * and a FailureRateRecovered when it clears. Schedule this (e.g. every fifteen
 * minutes); the package emits the events, the consumer decides where alerts go.
 */
class EvaluateFailuresCommand extends Command
{
    protected $signature = 'integrations:evaluate-failures';

    protected $description = 'Evaluate per-integration failure rates and emit anomaly signals.';

    public function handle(): int
    {
        if (! Config::anomalyEnabled()) {
            $this->info('Failure-anomaly evaluation is disabled.');

            return self::SUCCESS;
        }

        $window = Config::anomalyWindowMinutes();
        $threshold = Config::anomalyFailureRateThreshold();
        $floor = Config::anomalyMinimumRequests();

        foreach (Integration::query()->active()->get() as $integration) {
            $snapshot = (new FailureReporter($integration))->windowFailureRate($window);

            $elevated = $snapshot->observedRequests >= $floor && $snapshot->rate >= $threshold;

            if ($elevated) {
                $this->fireIfNew($integration, $snapshot);
            } else {
                $this->clearIfRecovered($integration);
            }
        }

        return self::SUCCESS;
    }

    private function fireIfNew(Integration $integration, FailureRateSnapshot $snapshot): void
    {
        // Cache::add is atomic, so exactly one evaluator opens the incident even
        // if two run concurrently. While the rate stays elevated we refresh the
        // marker's TTL each run so it never lapses mid-incident — otherwise a
        // recovery landing after the TTL expired would go unannounced, leaving
        // the consumer's alert open forever. (Set the debounce comfortably above
        // the evaluation interval so the refresh always lands in time.)
        if (! Cache::add($this->key($integration), 1, Config::anomalyDebounceSeconds())) {
            Cache::put($this->key($integration), 1, Config::anomalyDebounceSeconds());

            return;
        }

        ElevatedFailureRate::dispatch(
            $integration,
            $snapshot->rate,
            $snapshot->windowMinutes,
            $snapshot->observedRequests,
            $snapshot->dominantClass,
        );

        $this->line("Elevated failure rate for {$integration->name}: ".round($snapshot->rate, 1).'%');
    }

    private function clearIfRecovered(Integration $integration): void
    {
        // pull() reads and clears in one step; a non-null value means we had an
        // open anomaly, so announce recovery and let the next one alert at once.
        if (Cache::pull($this->key($integration)) !== null) {
            FailureRateRecovered::dispatch($integration);
        }
    }

    private function key(Integration $integration): string
    {
        return Config::cachePrefix().':anomaly:'.$integration->id;
    }
}
