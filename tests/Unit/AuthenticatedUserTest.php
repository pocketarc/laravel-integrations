<?php

declare(strict_types=1);

namespace Integrations\Tests\Unit;

use Illuminate\Support\Facades\Cache;
use Integrations\Data\AuthenticatedUser;
use Integrations\Exceptions\UnsupportedByProvider;
use Integrations\IntegrationManager;
use Integrations\Models\Integration;
use Integrations\Tests\Fixtures\IdentifyingProvider;
use Integrations\Tests\Fixtures\PlainProvider;
use Integrations\Tests\TestCase;

class AuthenticatedUserTest extends TestCase
{
    protected function tearDown(): void
    {
        Cache::flush();
        parent::tearDown();
    }

    private function integration(string $provider, string $class): Integration
    {
        app(IntegrationManager::class)->register($provider, $class);
        $integration = Integration::create(['provider' => $provider, 'name' => 'Test']);

        return $integration->refresh();
    }

    public function test_supports_reflects_whether_the_provider_implements_the_contract(): void
    {
        $this->assertTrue($this->integration('identifying', IdentifyingProvider::class)->supportsAuthenticatedUser());
        $this->assertFalse($this->integration('plain', PlainProvider::class)->supportsAuthenticatedUser());
    }

    public function test_returns_the_authenticated_user_from_the_provider(): void
    {
        $integration = $this->integration('identifying', IdentifyingProvider::class);

        $user = $integration->authenticatedUser();

        $this->assertInstanceOf(AuthenticatedUser::class, $user);
        $this->assertSame('u-1', $user->id);
        $this->assertSame('octocat', $user->username);
        $this->assertSame('The Octocat', $user->name);
        $this->assertSame('octo@example.com', $user->email);
        $this->assertSame(['login' => 'octocat', 'id' => 1], $user->raw);
    }

    public function test_throws_when_the_provider_does_not_support_it(): void
    {
        $integration = $this->integration('plain', PlainProvider::class);

        $this->expectException(UnsupportedByProvider::class);

        $integration->authenticatedUser();
    }

    public function test_uncached_calls_hit_the_provider_every_time(): void
    {
        $provider = new IdentifyingProvider;
        $this->app->instance(IdentifyingProvider::class, $provider);
        $integration = $this->integration('identifying', IdentifyingProvider::class);

        $integration->authenticatedUser();
        $integration->authenticatedUser();

        $this->assertSame(2, $provider->calls);
    }

    public function test_cache_for_serves_the_identity_without_a_second_provider_call(): void
    {
        $provider = new IdentifyingProvider;
        $this->app->instance(IdentifyingProvider::class, $provider);
        $integration = $this->integration('identifying', IdentifyingProvider::class);

        $first = $integration->authenticatedUser(cacheFor: now()->addHour());
        $second = $integration->authenticatedUser(cacheFor: now()->addHour());

        $this->assertSame(1, $provider->calls);
        $this->assertEquals($first, $second);
    }

    public function test_refresh_forces_a_fresh_provider_call(): void
    {
        $provider = new IdentifyingProvider;
        $this->app->instance(IdentifyingProvider::class, $provider);
        $integration = $this->integration('identifying', IdentifyingProvider::class);

        $integration->authenticatedUser(cacheFor: now()->addHour());
        $integration->authenticatedUser(cacheFor: now()->addHour(), refresh: true);

        $this->assertSame(2, $provider->calls);
    }
}
