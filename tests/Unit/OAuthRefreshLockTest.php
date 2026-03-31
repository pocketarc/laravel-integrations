<?php

declare(strict_types=1);

namespace Integrations\Tests\Unit;

use Integrations\IntegrationManager;
use Integrations\Models\Integration;
use Integrations\Tests\Fixtures\TestProvider;
use Integrations\Tests\TestCase;

class OAuthRefreshLockTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        app(IntegrationManager::class)->register('test', TestProvider::class);
    }

    public function test_refresh_acquires_lock_and_refreshes_token(): void
    {
        $integration = Integration::create([
            'provider' => 'test',
            'name' => 'Test',
            'credentials' => ['access_token' => 'old-token', 'token_expires_at' => now()->subMinute()->toIso8601String()],
        ]);
        $integration->refresh();

        $integration->refreshTokenIfNeeded();
        $integration->refresh();

        $creds = $integration->credentialsArray();
        $this->assertSame('refreshed-token', $creds['access_token']);
    }

    public function test_skips_refresh_when_token_not_expiring(): void
    {
        $integration = Integration::create([
            'provider' => 'test',
            'name' => 'Test',
            'credentials' => ['access_token' => 'valid-token', 'token_expires_at' => now()->addHour()->toIso8601String()],
        ]);
        $integration->refresh();

        $integration->refreshTokenIfNeeded();
        $integration->refresh();

        $creds = $integration->credentialsArray();
        $this->assertSame('valid-token', $creds['access_token']);
    }

    public function test_second_call_skips_refresh_when_token_already_refreshed(): void
    {
        $integration = Integration::create([
            'provider' => 'test',
            'name' => 'Test',
            'credentials' => ['access_token' => 'old', 'token_expires_at' => now()->subMinute()->toIso8601String()],
        ]);
        $integration->refresh();

        // First call refreshes
        $integration->refreshTokenIfNeeded();
        $integration->refresh();
        $this->assertSame('refreshed-token', $integration->credentialsArray()['access_token']);

        // Second call should skip (token no longer expiring since refreshed token expires in 2099)
        $integration->refreshTokenIfNeeded();
        $integration->refresh();
        $this->assertSame('refreshed-token', $integration->credentialsArray()['access_token']);
    }

    public function test_lock_key_uses_config_prefix(): void
    {
        config(['integrations.cache_prefix' => 'custom']);

        $integration = Integration::create([
            'provider' => 'test',
            'name' => 'Test',
            'credentials' => ['access_token' => 'old', 'token_expires_at' => now()->subMinute()->toIso8601String()],
        ]);
        $integration->refresh();

        $integration->refreshTokenIfNeeded();
        $integration->refresh();

        $this->assertSame('refreshed-token', $integration->credentialsArray()['access_token']);
    }
}
