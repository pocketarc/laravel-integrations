<?php

declare(strict_types=1);

namespace Integrations\Tests\Unit\Commands;

use Carbon\CarbonInterface;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Integrations\IntegrationManager;
use Integrations\Jobs\ProcessWebhook;
use Integrations\Models\Integration;
use Integrations\Models\IntegrationWebhook;
use Integrations\Tests\Fixtures\TestProvider;
use Integrations\Tests\TestCase;

class RecoverWebhooksCommandTest extends TestCase
{
    private Integration $integration;

    protected function setUp(): void
    {
        parent::setUp();

        app(IntegrationManager::class)->register('test', TestProvider::class);
        $this->integration = Integration::create(['provider' => 'test', 'name' => 'Test']);

        Queue::fake();
    }

    public function test_recovers_stale_processing_webhooks(): void
    {
        $webhook = $this->webhook('stale-1', 'processing', updatedAt: now()->subHours(2));

        $this->artisan('integrations:recover-webhooks')
            ->assertSuccessful()
            ->expectsOutputToContain("Re-dispatched 1 webhook(s) stuck in 'processing'");

        $this->assertSame('pending', $webhook->refresh()->status);
        $this->assertPushedFor($webhook);
    }

    public function test_it_does_not_read_webhook_payloads_to_recover_them(): void
    {
        config(['integrations.webhook.max_attempts' => 2]);
        $payload = str_repeat('x', 2048);
        $this->webhook('stale-payload', 'processing', updatedAt: now()->subHours(2), payload: $payload);
        $this->webhook('lost-payload', 'pending', updatedAt: now()->subHours(2), payload: $payload);
        $this->webhook('failed-payload', 'failed', attempts: 1, processedAt: now()->subHours(2), payload: $payload);

        $scans = [];
        DB::listen(function (QueryExecuted $query) use (&$scans): void {
            if (str_starts_with($query->sql, 'select') && str_contains($query->sql, 'integration_webhooks')) {
                $scans[] = $query->sql;
            }
        });

        $this->artisan('integrations:recover-webhooks')->assertSuccessful();

        $this->assertCount(3, $scans, 'expected one scan per kind of stranded webhook');

        foreach ($scans as $scan) {
            $this->assertStringNotContainsString('select *', $scan, "scanned whole rows: {$scan}");
        }
    }

    public function test_does_not_recover_recent_processing_webhooks(): void
    {
        $this->webhook('fresh-1', 'processing');

        $this->artisan('integrations:recover-webhooks')
            ->assertSuccessful()
            ->expectsOutputToContain('No webhooks to recover');

        Queue::assertNothingPushed();
    }

    public function test_leaves_processed_and_failed_webhooks_alone_by_default(): void
    {
        $this->webhook('old-processed', 'processed', attempts: 1, updatedAt: now()->subHours(2), processedAt: now()->subHours(2));
        $this->webhook('old-failed', 'failed', attempts: 1, updatedAt: now()->subHours(2), processedAt: now()->subHours(2));

        $this->artisan('integrations:recover-webhooks')
            ->assertSuccessful()
            ->expectsOutputToContain('No webhooks to recover');

        Queue::assertNothingPushed();
    }

    public function test_redispatches_a_pending_webhook_whose_job_was_lost(): void
    {
        $webhook = $this->webhook('lost-1', 'pending', updatedAt: now()->subHours(2));

        $this->artisan('integrations:recover-webhooks')
            ->assertSuccessful()
            ->expectsOutputToContain("Re-dispatched 1 webhook(s) stuck in 'pending'");

        $this->assertSame('pending', $webhook->refresh()->status);
        $this->assertPushedFor($webhook);
    }

    public function test_redispatches_a_lost_pending_webhook_once_per_timeout(): void
    {
        $this->webhook('lost-2', 'pending', updatedAt: now()->subHours(2));

        $this->artisan('integrations:recover-webhooks')->assertSuccessful();
        $this->artisan('integrations:recover-webhooks')->assertSuccessful();

        Queue::assertPushed(ProcessWebhook::class, 1);
    }

    public function test_leaves_a_recent_pending_webhook_alone(): void
    {
        $this->webhook('queued-1', 'pending');

        $this->artisan('integrations:recover-webhooks')
            ->assertSuccessful()
            ->expectsOutputToContain('No webhooks to recover');

        Queue::assertNothingPushed();
    }

    public function test_retries_a_failed_webhook_once_its_backoff_has_passed(): void
    {
        config(['integrations.webhook.max_attempts' => 3, 'integrations.webhook.retry_backoff' => [60, 600]]);
        $webhook = $this->webhook('retry-1', 'failed', attempts: 1, processedAt: now()->subMinutes(2));

        $this->artisan('integrations:recover-webhooks')
            ->assertSuccessful()
            ->expectsOutputToContain('Re-dispatched 1 failed webhook(s)');

        $this->assertSame('pending', $webhook->refresh()->status);
        $this->assertPushedFor($webhook);
    }

    public function test_waits_out_the_backoff_for_the_attempt_that_failed(): void
    {
        config(['integrations.webhook.max_attempts' => 3, 'integrations.webhook.retry_backoff' => [60, 600]]);
        $this->webhook('retry-2', 'failed', attempts: 2, processedAt: now()->subMinutes(2));

        $this->artisan('integrations:recover-webhooks')
            ->assertSuccessful()
            ->expectsOutputToContain('No webhooks to recover');

        Queue::assertNothingPushed();
    }

    public function test_reuses_the_last_backoff_past_the_end_of_the_list(): void
    {
        config(['integrations.webhook.max_attempts' => 5, 'integrations.webhook.retry_backoff' => [60]]);
        $webhook = $this->webhook('retry-3', 'failed', attempts: 3, processedAt: now()->subMinutes(2));

        $this->artisan('integrations:recover-webhooks')->assertSuccessful();

        $this->assertPushedFor($webhook);
    }

    public function test_stops_retrying_after_the_last_attempt(): void
    {
        config(['integrations.webhook.max_attempts' => 3, 'integrations.webhook.retry_backoff' => [60]]);
        $this->webhook('retry-4', 'failed', attempts: 3, processedAt: now()->subHours(2));

        $this->artisan('integrations:recover-webhooks')->assertSuccessful();

        Queue::assertNothingPushed();
    }

    public function test_does_not_retry_a_webhook_that_failed_before_it_was_claimed(): void
    {
        config(['integrations.webhook.max_attempts' => 3, 'integrations.webhook.retry_backoff' => [60]]);
        $this->webhook('retry-5', 'failed', attempts: 0, processedAt: now()->subHours(2));

        $this->artisan('integrations:recover-webhooks')->assertSuccessful();

        Queue::assertNothingPushed();
    }

    private function webhook(
        string $deliveryId,
        string $status,
        int $attempts = 0,
        ?CarbonInterface $updatedAt = null,
        ?CarbonInterface $processedAt = null,
        string $payload = '{}',
    ): IntegrationWebhook {
        $webhook = IntegrationWebhook::create([
            'integration_id' => $this->integration->id,
            'delivery_id' => $deliveryId,
            'payload' => $payload,
            'headers' => [],
            'status' => $status,
            'attempts' => $attempts,
            'processed_at' => $processedAt,
        ]);

        if ($updatedAt !== null) {
            $webhook->newQuery()->where('id', $webhook->id)->update(['updated_at' => $updatedAt]);
        }

        return $webhook;
    }

    private function assertPushedFor(IntegrationWebhook $webhook): void
    {
        Queue::assertPushed(ProcessWebhook::class, static fn (ProcessWebhook $job): bool => $job->webhookId === $webhook->id);
    }
}
