<?php

declare(strict_types=1);

namespace Integrations\Tests\Unit\Http;

use Integrations\IntegrationManager;
use Integrations\Models\Integration;
use Integrations\Tests\Fixtures\TestProvider;
use Integrations\Tests\TestCase;

class WebhookControllerTest extends TestCase
{
    private TestProvider $provider;

    protected function setUp(): void
    {
        parent::setUp();

        $this->provider = new TestProvider;
        $manager = app(IntegrationManager::class);
        $manager->register('test', TestProvider::class);
        $this->app->instance(TestProvider::class, $this->provider);
    }

    public function test_successful_webhook(): void
    {
        Integration::create(['provider' => 'test', 'name' => 'Test']);

        $response = $this->postJson('/integrations/test/webhook', ['event' => 'created']);

        $response->assertOk();
        $response->assertJson(['handled' => true]);
    }

    public function test_404_on_unknown_provider(): void
    {
        $response = $this->postJson('/integrations/unknown/webhook');

        $response->assertNotFound();
    }

    public function test_403_on_invalid_signature(): void
    {
        Integration::create(['provider' => 'test', 'name' => 'Test']);
        $this->provider->webhookVerified = false;

        $response = $this->postJson('/integrations/test/webhook');

        $response->assertForbidden();
    }

    public function test_404_when_no_active_integration(): void
    {
        Integration::create(['provider' => 'test', 'name' => 'Inactive', 'is_active' => false]);

        $response = $this->postJson('/integrations/test/webhook');

        $response->assertNotFound();
    }

    public function test_webhook_with_specific_integration_id(): void
    {
        $integration = Integration::create(['provider' => 'test', 'name' => 'Specific']);

        $response = $this->postJson("/integrations/test/{$integration->id}/webhook");

        $response->assertOk();
        $response->assertJson(['handled' => true]);
    }

    public function test_webhook_with_wrong_provider_for_integration(): void
    {
        $integration = Integration::create(['provider' => 'other', 'name' => 'Wrong']);

        $manager = app(IntegrationManager::class);
        $manager->register('other', TestProvider::class);

        $response = $this->postJson("/integrations/test/{$integration->id}/webhook");

        $response->assertNotFound();
    }
}
