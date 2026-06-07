<?php

declare(strict_types=1);

namespace Integrations\Reporting;

use Carbon\CarbonInterface;
use Integrations\Enums\FailureClass;
use Integrations\Models\Integration;
use Integrations\Models\IntegrationLog;
use Integrations\Support\JsonPathExtractor;

/**
 * Computes a {@see FailureSummary} for one integration over a window. The
 * aggregation shape the `integrations:health` / `integrations:stats` commands
 * render lives here, so consumers (status pages, alert routers) get the same
 * numbers without re-deriving them.
 *
 * `summary()` issues a handful of aggregate queries, so it's too heavy for the
 * request path — cache it or render it on demand rather than per request.
 */
final class FailureReporter
{
    public function __construct(
        private readonly Integration $integration,
    ) {}

    public function summary(CarbonInterface $since): FailureSummary
    {
        $totalRequests = $this->integration->requests()->since($since)->count();
        $failedRequests = $this->integration->requests()->since($since)->failed()->count();
        $distinctEndpoints = $this->integration->requests()->since($since)->distinct()->count('endpoint');

        $avgDuration = $this->integration->requests()->since($since)->successful()->avg('duration_ms');
        $avgSuccessfulDurationMs = is_numeric($avgDuration) ? (float) $avgDuration : null;

        [$lastErrorAt, $lastErrorMessage] = $this->lastError($since);

        return new FailureSummary(
            since: $since,
            totalRequests: $totalRequests,
            failedRequests: $failedRequests,
            distinctEndpoints: $distinctEndpoints,
            avgSuccessfulDurationMs: $avgSuccessfulDurationMs,
            lastErrorAt: $lastErrorAt,
            lastErrorMessage: $lastErrorMessage,
            byFailureClass: $this->failureClassBreakdown($since),
            byStatus: $this->statusBreakdown($since),
            topErrors: $this->topErrors($since),
            operations: $this->operationBreakdown($since),
        );
    }

    /**
     * The failure rate over the trailing window, for anomaly evaluation. Uses
     * the same persisted rows as summary() so the CLI, status page, and alert
     * router all see one number.
     */
    public function windowFailureRate(int $windowMinutes): FailureRateSnapshot
    {
        $since = now()->subMinutes($windowMinutes);

        $total = $this->integration->requests()->since($since)->count();
        $failed = $this->integration->requests()->since($since)->failed()->count();
        $rate = $total > 0 ? ($failed / $total) * 100 : 0.0;

        return new FailureRateSnapshot(
            rate: $rate,
            observedRequests: $total,
            dominantClass: $this->dominantFailureClass($since),
            windowMinutes: $windowMinutes,
        );
    }

    private function dominantFailureClass(CarbonInterface $since): FailureClass
    {
        $top = $this->integration->requests()
            ->since($since)
            ->failed()
            ->whereNotNull('failure_class')
            ->selectRaw('failure_class, COUNT(*) as count')
            ->groupBy('failure_class')
            ->orderByDesc('count')
            ->toBase()
            ->value('failure_class');

        return is_string($top) ? (FailureClass::tryFrom($top) ?? FailureClass::Unknown) : FailureClass::Unknown;
    }

    /**
     * @return array{0: ?CarbonInterface, 1: ?string}
     */
    private function lastError(CarbonInterface $since): array
    {
        $row = $this->integration->requests()
            ->since($since)
            ->failed()
            ->latest('id')
            ->first(['created_at', 'error']);

        if ($row === null) {
            return [null, null];
        }

        $error = $row->error;
        $message = is_array($error) && array_key_exists('message', $error) && is_string($error['message'])
            ? $error['message']
            : null;

        return [$row->created_at, $message];
    }

    /**
     * @return array<string, int>
     */
    private function failureClassBreakdown(CarbonInterface $since): array
    {
        $counts = $this->integration->requests()
            ->since($since)
            ->failed()
            ->selectRaw('failure_class, COUNT(*) as count')
            ->groupBy('failure_class')
            ->toBase()
            ->pluck('count', 'failure_class');

        $result = [
            FailureClass::Upstream->value => 0,
            FailureClass::Throttle->value => 0,
            FailureClass::Client->value => 0,
            FailureClass::Unknown->value => 0,
        ];

        foreach ($counts as $class => $count) {
            if (is_string($class) && array_key_exists($class, $result) && is_numeric($count)) {
                $result[$class] = (int) $count;
            }
        }

        return $result;
    }

    /**
     * Failed requests bucketed by HTTP status family. Keyed by '5xx' | '4xx' |
     * '429' | 'other' (PHP coerces the numeric '429' key to int).
     *
     * @return array<int|string, int>
     */
    private function statusBreakdown(CarbonInterface $since): array
    {
        $counts = $this->integration->requests()
            ->since($since)
            ->failed()
            ->selectRaw('response_code, COUNT(*) as count')
            ->groupBy('response_code')
            ->toBase()
            ->pluck('count', 'response_code');

        $result = ['5xx' => 0, '4xx' => 0, '429' => 0, 'other' => 0];

        foreach ($counts as $code => $count) {
            if (! is_numeric($count)) {
                continue;
            }

            $status = is_numeric($code) ? (int) $code : 0;
            $bucket = match (true) {
                $status === 429 => '429',
                $status >= 500 => '5xx',
                $status >= 400 => '4xx',
                default => 'other',
            };

            $result[$bucket] += (int) $count;
        }

        return $result;
    }

    /**
     * @return list<TopError>
     */
    private function topErrors(CarbonInterface $since, int $limit = 3): array
    {
        $expr = JsonPathExtractor::stringPath('error', 'message');

        $pairs = $this->integration->requests()
            ->since($since)
            ->failed()
            ->selectRaw("{$expr} as error_message, COUNT(*) as count")
            ->groupByRaw($expr)
            ->orderByDesc('count')
            ->limit($limit)
            ->toBase()
            ->pluck('count', 'error_message');

        $result = [];

        foreach ($pairs as $message => $count) {
            // A null JSON extraction (no message field) lands as an empty-string
            // key; skip it rather than reporting a blank error.
            if ($message === '' || ! is_numeric($count)) {
                continue;
            }

            $result[] = new TopError((string) $message, (int) $count);
        }

        return $result;
    }

    /**
     * @return array<string, OperationFailureBreakdown>
     */
    private function operationBreakdown(CarbonInterface $since): array
    {
        $rows = $this->integration->logs()
            ->topLevel()
            ->since($since)
            ->selectRaw('operation, status, COUNT(*) as count')
            ->groupBy('operation', 'status')
            ->toBase()
            ->get();

        $distinctItems = $this->integration->logs()
            ->topLevel()
            ->since($since)
            ->whereNotNull('external_id')
            ->selectRaw('operation, COUNT(DISTINCT external_id) as items')
            ->groupBy('operation')
            ->toBase()
            ->pluck('items', 'operation');

        /** @var array<string, array{total: int, successful: int, partial: int, failed: int}> $acc */
        $acc = [];

        foreach ($rows as $row) {
            $operation = $row->operation;
            $status = $row->status;
            $count = $row->count;

            if (! is_string($operation) || ! is_string($status) || ! is_numeric($count)) {
                continue;
            }

            $acc[$operation] ??= ['total' => 0, 'successful' => 0, 'partial' => 0, 'failed' => 0];

            $n = (int) $count;
            $acc[$operation]['total'] += $n;

            if ($status === IntegrationLog::STATUS_SUCCESS) {
                $acc[$operation]['successful'] += $n;
            } elseif ($status === IntegrationLog::STATUS_PARTIAL) {
                $acc[$operation]['partial'] += $n;
            } elseif ($status === IntegrationLog::STATUS_FAILED) {
                $acc[$operation]['failed'] += $n;
            }
        }

        $result = [];

        foreach ($acc as $operation => $counts) {
            $items = $distinctItems[$operation] ?? 0;

            $result[$operation] = new OperationFailureBreakdown(
                operation: $operation,
                total: $counts['total'],
                successful: $counts['successful'],
                partial: $counts['partial'],
                failed: $counts['failed'],
                distinctItems: is_numeric($items) ? (int) $items : 0,
            );
        }

        return $result;
    }
}
