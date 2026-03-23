<?php

declare(strict_types=1);

namespace Integrations\Tests\Unit\Commands;

use Illuminate\Support\Facades\DB;
use Integrations\IntegrationManager;
use Integrations\Models\Integration;
use Integrations\Models\IntegrationRequest;
use Integrations\Tests\Fixtures\TestProvider;
use Integrations\Tests\TestCase;

class ReplayWebhookCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $manager = app(IntegrationManager::class);
        $manager->register('test', TestProvider::class);
    }

    public function test_replays_webhook(): void
    {
        $integration = Integration::create(['provider' => 'test', 'name' => 'Test']);

        $request = IntegrationRequest::create([
            'integration_id' => $integration->id,
            'endpoint' => '/webhook',
            'method' => 'POST',
            'request_data' => json_encode(['event' => 'created']),
            'response_success' => true,
        ]);

        $this->artisan("integrations:replay-webhook {$request->id}")
            ->assertSuccessful()
            ->expectsOutputToContain('replayed successfully');
    }

    public function test_fails_on_missing_request(): void
    {
        $missingId = (int) DB::table('integration_requests')->max('id') + 1;

        $this->artisan("integrations:replay-webhook {$missingId}")
            ->assertFailed()
            ->expectsOutputToContain('not found');
    }
}
