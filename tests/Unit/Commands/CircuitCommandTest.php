<?php

declare(strict_types=1);

namespace Integrations\Tests\Unit\Commands;

use Integrations\Enums\CircuitOverride;
use Integrations\IntegrationManager;
use Integrations\Models\Integration;
use Integrations\Tests\Fixtures\TestProvider;
use Integrations\Tests\TestCase;

class CircuitCommandTest extends TestCase
{
    private Integration $integration;

    protected function setUp(): void
    {
        parent::setUp();

        app(IntegrationManager::class)->register('test', TestProvider::class);
        $this->integration = Integration::create(['provider' => 'test', 'name' => 'Test']);
        $this->integration->refresh();
    }

    public function test_open_sets_forced_open(): void
    {
        $this->artisan('integrations:circuit', ['integration' => (string) $this->integration->id, 'action' => 'open'])
            ->assertSuccessful();

        $this->integration->refresh();
        $this->assertSame(CircuitOverride::ForcedOpen, $this->integration->circuit_override);
    }

    public function test_close_sets_forced_closed(): void
    {
        $this->artisan('integrations:circuit', ['integration' => (string) $this->integration->id, 'action' => 'close'])
            ->assertSuccessful();

        $this->integration->refresh();
        $this->assertSame(CircuitOverride::ForcedClosed, $this->integration->circuit_override);
    }

    public function test_disable_sets_disabled(): void
    {
        $this->artisan('integrations:circuit', ['integration' => (string) $this->integration->id, 'action' => 'disable'])
            ->assertSuccessful();

        $this->integration->refresh();
        $this->assertSame(CircuitOverride::Disabled, $this->integration->circuit_override);
    }

    public function test_auto_clears_override(): void
    {
        $this->integration->forceCircuitOpen();

        $this->artisan('integrations:circuit', ['integration' => (string) $this->integration->id, 'action' => 'auto'])
            ->assertSuccessful();

        $this->integration->refresh();
        $this->assertNull($this->integration->circuit_override);
    }

    public function test_until_is_stored(): void
    {
        $this->artisan('integrations:circuit', [
            'integration' => (string) $this->integration->id,
            'action' => 'open',
            '--until' => '+1 hour',
        ])->assertSuccessful();

        $this->integration->refresh();
        $this->assertNotNull($this->integration->circuit_override_until);
    }

    public function test_past_until_fails(): void
    {
        $this->artisan('integrations:circuit', [
            'integration' => (string) $this->integration->id,
            'action' => 'open',
            '--until' => '-1 hour',
        ])->assertFailed();
    }

    public function test_invalid_action_fails(): void
    {
        $this->artisan('integrations:circuit', ['integration' => (string) $this->integration->id, 'action' => 'bogus'])
            ->assertFailed();
    }

    public function test_missing_integration_fails(): void
    {
        $this->artisan('integrations:circuit', ['integration' => '99999', 'action' => 'status'])
            ->assertFailed();
    }

    public function test_non_numeric_id_fails(): void
    {
        $this->artisan('integrations:circuit', ['integration' => 'abc', 'action' => 'status'])
            ->assertFailed();
    }

    public function test_status_shows_override(): void
    {
        $this->integration->forceCircuitOpen();

        $this->artisan('integrations:circuit', ['integration' => (string) $this->integration->id, 'action' => 'status'])
            ->assertSuccessful()
            ->expectsOutputToContain('forced_open');
    }
}
