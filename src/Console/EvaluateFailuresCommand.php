<?php

declare(strict_types=1);

namespace Integrations\Console;

use Illuminate\Console\Command;
use Integrations\Events\ElevatedFailureRate;
use Integrations\Events\FailureRateRecovered;
use Integrations\Models\Integration;
use Integrations\Reporting\FailureRateSnapshot;
use Integrations\Reporting\FailureReporter;
use Integrations\Support\Config;

/**
 * Evaluates each active integration's failure rate over the configured window
 * and emits the anomaly signal: one ElevatedFailureRate when the rate first
 * crosses the threshold, and a FailureRateRecovered when it drops back. Schedule
 * this (e.g. every fifteen minutes); the package emits the events, the consumer
 * decides where alerts go.
 *
 * The open/closed state lives in `integrations.anomaly_alerted_at`, so it's
 * durable (a cache flush or a skipped run can't drop a pending recovery) and the
 * edge is detected with an atomic conditional update rather than a debounce TTL.
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
                $this->openIfNew($integration, $snapshot);
            } else {
                $this->closeIfOpen($integration);
            }
        }

        return self::SUCCESS;
    }

    private function openIfNew(Integration $integration, FailureRateSnapshot $snapshot): void
    {
        // Atomic set-if-null: only the transition into the open state updates a
        // row, so exactly one ElevatedFailureRate fires per incident even if two
        // evaluators race.
        $opened = Integration::query()
            ->whereKey($integration->id)
            ->whereNull('anomaly_alerted_at')
            ->update(['anomaly_alerted_at' => now()]);

        if ($opened !== 1) {
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

    private function closeIfOpen(Integration $integration): void
    {
        // Atomic clear-if-set: the transition out of the open state updates a row
        // exactly once, so recovery announces once and the next incident re-arms.
        $closed = Integration::query()
            ->whereKey($integration->id)
            ->whereNotNull('anomaly_alerted_at')
            ->update(['anomaly_alerted_at' => null]);

        if ($closed === 1) {
            FailureRateRecovered::dispatch($integration);
        }
    }
}
