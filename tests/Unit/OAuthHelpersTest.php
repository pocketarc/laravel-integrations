<?php

declare(strict_types=1);

namespace Integrations\Tests\Unit;

use Integrations\IntegrationManager;
use Integrations\Models\Integration;
use Integrations\Tests\Fixtures\TestProvider;
use Integrations\Tests\TestCase;

class OAuthHelpersTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $provider = new TestProvider;
        $manager = app(IntegrationManager::class);
        $manager->register('test', TestProvider::class);
        $this->app->instance(TestProvider::class, $provider);
    }

    public function test_get_access_token(): void
    {
        $integration = Integration::create([
            'provider' => 'test',
            'name' => 'Test',
            'credentials' => [
                'access_token' => 'my-token',
                'token_expires_at' => '2099-01-01T00:00:00Z',
            ],
        ]);
        $integration->refresh();

        $this->assertSame('my-token', $integration->getAccessToken());
    }

    public function test_get_access_token_returns_null_when_missing(): void
    {
        $integration = Integration::create([
            'provider' => 'test',
            'name' => 'Test',
            'credentials' => ['client_id' => 'abc'],
        ]);
        $integration->refresh();

        $this->assertNull($integration->getAccessToken());
    }

    public function test_token_expires_soon(): void
    {
        $integration = Integration::create([
            'provider' => 'test',
            'name' => 'Test',
            'credentials' => [
                'access_token' => 'expiring',
                'token_expires_at' => now()->addSeconds(100)->toIso8601String(),
            ],
        ]);
        $integration->refresh();

        // TestProvider refreshThreshold is 300s, token expires in 100s — should be "soon"
        $this->assertTrue($integration->tokenExpiresSoon());
    }

    public function test_token_not_expiring_soon(): void
    {
        $integration = Integration::create([
            'provider' => 'test',
            'name' => 'Test',
            'credentials' => [
                'access_token' => 'fresh',
                'token_expires_at' => now()->addHour()->toIso8601String(),
            ],
        ]);
        $integration->refresh();

        $this->assertFalse($integration->tokenExpiresSoon());
    }

    public function test_refresh_token_if_needed(): void
    {
        $integration = Integration::create([
            'provider' => 'test',
            'name' => 'Test',
            'credentials' => [
                'access_token' => 'old-token',
                'refresh_token' => 'refresh-me',
                'token_expires_at' => now()->addSeconds(10)->toIso8601String(),
            ],
        ]);
        $integration->refresh();

        $integration->refreshTokenIfNeeded();

        $integration->refresh();
        $this->assertSame('refreshed-token', $integration->credentials['access_token']);
    }

    public function test_no_refresh_when_token_is_fresh(): void
    {
        $integration = Integration::create([
            'provider' => 'test',
            'name' => 'Test',
            'credentials' => [
                'access_token' => 'still-good',
                'token_expires_at' => now()->addHour()->toIso8601String(),
            ],
        ]);
        $integration->refresh();

        $integration->refreshTokenIfNeeded();

        $integration->refresh();
        $this->assertSame('still-good', $integration->credentials['access_token']);
    }
}
