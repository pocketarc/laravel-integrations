<?php

declare(strict_types=1);

namespace Integrations\Tests\Unit;

use Integrations\Exceptions\RetryableException;
use Integrations\IntegrationManager;
use Integrations\Models\Integration;
use Integrations\Models\IntegrationRequest;
use Integrations\RequestContext;
use Integrations\Tests\Fixtures\TestProvider;
use Integrations\Tests\TestCase;

class ProviderRequestIdTest extends TestCase
{
    private Integration $integration;

    protected function setUp(): void
    {
        parent::setUp();

        app(IntegrationManager::class)->register('test', TestProvider::class);
        $this->integration = Integration::create(['provider' => 'test', 'name' => 'Test']);
        $this->integration->refresh();
    }

    public function test_reported_provider_request_id_is_persisted(): void
    {
        $this->integration->at('/api/charge')->post(function (RequestContext $ctx): array {
            $ctx->reportResponseMetadata(providerRequestId: 'req_abc123');

            return ['ok' => true];
        });

        $row = IntegrationRequest::query()->latest()->first();
        $this->assertNotNull($row);
        $this->assertSame('req_abc123', $row->provider_request_id);
    }

    public function test_provider_request_id_column_stays_null_when_not_reported(): void
    {
        $this->integration->at('/api/charge')->post(fn (): array => ['ok' => true]);

        $row = IntegrationRequest::query()->latest()->first();
        $this->assertNotNull($row);
        $this->assertNull($row->provider_request_id);
    }

    public function test_provider_request_id_resets_between_retry_attempts(): void
    {
        $attempt = 0;

        $this->integration->at('/api/charge')
            ->withAttempts(3)
            ->post(function (RequestContext $ctx) use (&$attempt): array {
                $attempt++;

                if ($attempt < 3) {
                    // Report a request ID for the failing attempt; it should
                    // not bleed into the row persisted for the retry that
                    // ultimately succeeds.
                    $ctx->reportResponseMetadata(providerRequestId: 'req_failed_'.$attempt);
                    throw new RetryableException('boom');
                }

                $ctx->reportResponseMetadata(providerRequestId: 'req_succeeded');

                return ['ok' => true];
            });

        // The first row created during retries is the one that ultimately
        // returns; the failed attempts also persist with their own request
        // IDs. Walk the rows in creation order and assert the values track
        // the attempts.
        $rows = IntegrationRequest::query()->orderBy('id')->get();
        $this->assertCount(3, $rows);
        $this->assertSame('req_failed_1', $rows[0]->provider_request_id);
        $this->assertSame('req_failed_2', $rows[1]->provider_request_id);
        $this->assertSame('req_succeeded', $rows[2]->provider_request_id);
    }
}
