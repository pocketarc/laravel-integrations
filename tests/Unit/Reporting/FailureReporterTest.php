<?php

declare(strict_types=1);

namespace Integrations\Tests\Unit\Reporting;

use Integrations\Enums\FailureClass;
use Integrations\Models\Integration;
use Integrations\Reporting\FailureReporter;
use Integrations\Reporting\TopError;
use Integrations\Tests\TestCase;

class FailureReporterTest extends TestCase
{
    private Integration $integration;

    protected function setUp(): void
    {
        parent::setUp();

        $this->integration = Integration::create(['provider' => 'test', 'name' => 'Test']);
        $this->integration->refresh();

        $this->seedRequests();
        $this->seedLogs();
    }

    public function test_summarises_request_level_failures(): void
    {
        $summary = $this->integration->failureSummary(now()->subDay());

        $this->assertSame(6, $summary->totalRequests);
        $this->assertSame(4, $summary->failedRequests);
        $this->assertEqualsWithDelta(66.67, $summary->failureRate(), 0.01);
        $this->assertSame(4, $summary->distinctEndpoints);
        $this->assertEqualsWithDelta(150.0, $summary->avgSuccessfulDurationMs, 0.01);
    }

    public function test_breaks_down_by_failure_class(): void
    {
        $summary = $this->integration->failureSummary(now()->subDay());

        $this->assertSame(
            [
                FailureClass::Upstream->value => 2,
                FailureClass::Throttle->value => 1,
                FailureClass::Client->value => 1,
                FailureClass::Unknown->value => 0,
            ],
            $summary->byFailureClass,
        );
    }

    public function test_unclassified_failures_count_as_unknown(): void
    {
        $legacy = Integration::create(['provider' => 'test', 'name' => 'Legacy']);

        // Failed requests with no persisted failure_class (e.g. rows from before
        // the column existed) must fold into "unknown", not vanish.
        $legacy->requests()->create(['endpoint' => '/a', 'method' => 'GET', 'response_success' => false, 'response_code' => 500]);
        $legacy->requests()->create(['endpoint' => '/b', 'method' => 'GET', 'response_success' => false, 'response_code' => 500]);

        $summary = $legacy->failureSummary(now()->subDay());

        $this->assertSame(2, $summary->byFailureClass['unknown']);
        $this->assertSame(2, array_sum($summary->byFailureClass));
        $this->assertSame($summary->failedRequests, array_sum($summary->byFailureClass));

        $snapshot = (new FailureReporter($legacy))->windowFailureRate(60);
        $this->assertSame(FailureClass::Unknown, $snapshot->dominantClass);
    }

    public function test_breaks_down_by_status_bucket(): void
    {
        $summary = $this->integration->failureSummary(now()->subDay());

        $this->assertSame(['5xx' => 2, '4xx' => 1, '429' => 1, 'other' => 0], $summary->byStatus);
    }

    public function test_reports_top_errors_highest_first(): void
    {
        $summary = $this->integration->failureSummary(now()->subDay());

        $this->assertCount(3, $summary->topErrors);
        $this->assertSame('boom', $summary->topErrors[0]->message);
        $this->assertSame(2, $summary->topErrors[0]->count);
    }

    public function test_top_errors_excludes_blank_messages_before_limiting(): void
    {
        // Five failures with no message would otherwise be the highest-count
        // group and consume a top-3 slot, dropping a real message. The SQL-level
        // filter must keep all three real messages (boom/slow/bad from setUp).
        for ($i = 0; $i < 5; $i++) {
            $this->integration->requests()->create([
                'endpoint' => '/blank',
                'method' => 'GET',
                'response_success' => false,
                'response_code' => 500,
                'error' => null,
            ]);
        }

        // An explicit empty-string message must be excluded too (the `<> ''`
        // half of the filter), not just a missing one (the `IS NOT NULL` half).
        $this->integration->requests()->create([
            'endpoint' => '/blank-string',
            'method' => 'GET',
            'response_success' => false,
            'response_code' => 500,
            'error' => ['message' => ''],
        ]);

        $summary = $this->integration->failureSummary(now()->subDay());

        $this->assertCount(3, $summary->topErrors);

        $messages = array_map(static fn (TopError $e): string => $e->message, $summary->topErrors);
        sort($messages);
        $this->assertSame(['bad', 'boom', 'slow'], $messages);
    }

    public function test_reports_the_last_error(): void
    {
        $summary = $this->integration->failureSummary(now()->subDay());

        $this->assertNotNull($summary->lastErrorAt);
        $this->assertSame('bad', $summary->lastErrorMessage);
    }

    public function test_breaks_down_operations_from_logs(): void
    {
        $summary = $this->integration->failureSummary(now()->subDay());

        $sync = $summary->operations['sync'];
        $this->assertSame(4, $sync->total);
        $this->assertSame(2, $sync->successful);
        $this->assertSame(1, $sync->partial);
        $this->assertSame(1, $sync->failed);
        $this->assertSame(0, $sync->distinctItems);
        $this->assertEqualsWithDelta(25.0, $sync->failureRate(), 0.01);

        $push = $summary->operations['push'];
        $this->assertSame(3, $push->total);
        $this->assertSame(3, $push->successful);
        $this->assertSame(2, $push->distinctItems);
    }

    public function test_window_failure_rate(): void
    {
        $snapshot = (new FailureReporter($this->integration))->windowFailureRate(60);

        $this->assertEqualsWithDelta(66.67, $snapshot->rate, 0.01);
        $this->assertSame(6, $snapshot->observedRequests);
        $this->assertSame(FailureClass::Upstream, $snapshot->dominantClass);
        $this->assertSame(60, $snapshot->windowMinutes);
    }

    public function test_window_failure_rate_defaults_dominant_class_when_no_failures(): void
    {
        $other = Integration::create(['provider' => 'test', 'name' => 'Quiet']);
        $other->requests()->create(['endpoint' => '/a', 'method' => 'GET', 'response_success' => true]);

        $snapshot = (new FailureReporter($other))->windowFailureRate(60);

        $this->assertSame(0.0, $snapshot->rate);
        $this->assertSame(1, $snapshot->observedRequests);
        $this->assertSame(FailureClass::Unknown, $snapshot->dominantClass);
    }

    public function test_empty_window_yields_zeroes(): void
    {
        $other = Integration::create(['provider' => 'test', 'name' => 'Empty']);

        $summary = $other->failureSummary(now()->subDay());

        $this->assertSame(0, $summary->totalRequests);
        $this->assertSame(0, $summary->failedRequests);
        $this->assertSame(0.0, $summary->failureRate());
        $this->assertNull($summary->avgSuccessfulDurationMs);
        $this->assertNull($summary->lastErrorAt);
        $this->assertSame([], $summary->topErrors);
        $this->assertSame([], $summary->operations);
    }

    private function seedRequests(): void
    {
        $this->request(true, '/a', null, 200, duration: 100);
        $this->request(true, '/a', null, 200, duration: 200);
        $this->request(false, '/b', FailureClass::Upstream, 500, 'boom');
        $this->request(false, '/b', FailureClass::Upstream, 503, 'boom');
        $this->request(false, '/c', FailureClass::Throttle, 429, 'slow');
        $this->request(false, '/d', FailureClass::Client, 400, 'bad');
    }

    private function request(
        bool $success,
        string $endpoint,
        ?FailureClass $class,
        int $code,
        ?string $message = null,
        ?int $duration = null,
    ): void {
        $this->integration->requests()->create([
            'endpoint' => $endpoint,
            'method' => 'GET',
            'response_success' => $success,
            'response_code' => $code,
            'failure_class' => $class,
            'error' => $message !== null ? ['message' => $message] : null,
            'duration_ms' => $duration,
        ]);
    }

    private function seedLogs(): void
    {
        $this->integration->logOperation('sync', 'inbound', 'success');
        $this->integration->logOperation('sync', 'inbound', 'success');
        $this->integration->logOperation('sync', 'inbound', 'partial');
        $this->integration->logOperation('sync', 'inbound', 'failed');
        $this->integration->logOperation('push', 'outbound', 'success', externalId: 'X1');
        $this->integration->logOperation('push', 'outbound', 'success', externalId: 'X1');
        $this->integration->logOperation('push', 'outbound', 'success', externalId: 'X2');
    }
}
