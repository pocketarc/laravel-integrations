<?php

declare(strict_types=1);

namespace Integrations\Tests\Unit\Http;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Integrations\Events\OAuthCompleted;
use Integrations\Events\OAuthRevoked;
use Integrations\IntegrationManager;
use Integrations\Models\Integration;
use Integrations\Tests\Fixtures\TestProvider;
use Integrations\Tests\TestCase;

class OAuthControllerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $manager = app(IntegrationManager::class);
        $manager->register('test', TestProvider::class);
    }

    public function test_authorize_redirects_to_provider(): void
    {
        $integration = Integration::create(['provider' => 'test', 'name' => 'Test']);

        $response = $this->get("/integrations/{$integration->id}/oauth/authorize");

        $response->assertRedirect();
        $this->assertStringContainsString('provider.example.com/oauth/authorize', $response->headers->get('Location') ?? '');
    }

    public function test_callback_exchanges_code_and_stores_tokens(): void
    {
        Event::fake();

        $integration = Integration::create(['provider' => 'test', 'name' => 'Test']);

        $state = 'test-state-token';
        Cache::put("integrations:oauth:state:{$state}", $integration->id, 600);

        $response = $this->get("/integrations/oauth/callback?state={$state}&code=auth-code-123");

        $response->assertRedirect('/integrations');

        $integration->refresh();
        $this->assertSame('new-token', $integration->credentials['access_token']);

        Event::assertDispatched(OAuthCompleted::class);
    }

    public function test_callback_rejects_invalid_state(): void
    {
        $response = $this->get('/integrations/oauth/callback?state=bogus&code=abc');

        $response->assertStatus(400);
    }

    public function test_callback_rejects_missing_params(): void
    {
        $response = $this->get('/integrations/oauth/callback');

        $response->assertStatus(400);
    }

    public function test_revoke_clears_tokens(): void
    {
        Event::fake();

        $integration = Integration::create([
            'provider' => 'test',
            'name' => 'Test',
            'credentials' => [
                'access_token' => 'old-token',
                'refresh_token' => 'old-refresh',
                'token_expires_at' => '2099-01-01T00:00:00Z',
                'client_id' => 'my-client',
            ],
        ]);

        $response = $this->post("/integrations/{$integration->id}/oauth/revoke");

        $response->assertRedirect('/integrations');

        $integration->refresh();
        $this->assertArrayNotHasKey('access_token', $integration->credentials ?? []);
        $this->assertArrayNotHasKey('refresh_token', $integration->credentials ?? []);
        $this->assertSame('my-client', ($integration->credentials ?? [])['client_id'] ?? null);

        Event::assertDispatched(OAuthRevoked::class);
    }
}
