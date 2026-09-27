<?php

declare(strict_types=1);

namespace Integrations\Tests\Unit;

use Illuminate\Support\Facades\Cache;
use Integrations\Data\AuthenticatedUser;
use Integrations\Exceptions\UncacheableAuthenticatedUser;
use Integrations\Exceptions\UnsupportedByProvider;
use Integrations\IntegrationManager;
use Integrations\Models\Integration;
use Integrations\Support\Config;
use Integrations\Tests\Fixtures\IdentifyingProvider;
use Integrations\Tests\Fixtures\PlainProvider;
use Integrations\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;

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

    private function boundIdentifyingProvider(): IdentifyingProvider
    {
        $provider = new IdentifyingProvider;
        $this->app->instance(IdentifyingProvider::class, $provider);

        return $provider;
    }

    private function useStoreThatCannotUnserializeObjects(): void
    {
        config([
            'cache.stores.serialized' => ['driver' => 'array', 'serialize' => true],
            'cache.default' => 'serialized',
            'cache.serializable_classes' => false,
        ]);
    }

    private function cacheKey(Integration $integration): string
    {
        return Config::cachePrefix().":auth-user:v2:{$integration->id}";
    }

    private function assertIsTheFixtureUser(AuthenticatedUser $user): void
    {
        $this->assertSame('u-1', $user->id);
        $this->assertSame('octocat', $user->username);
        $this->assertSame('The Octocat', $user->name);
        $this->assertSame('octo@example.com', $user->email);
        $this->assertSame(['login' => 'octocat', 'id' => 1], $user->raw);
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

        $this->assertIsTheFixtureUser($user);
    }

    public function test_throws_when_the_provider_does_not_support_it(): void
    {
        $integration = $this->integration('plain', PlainProvider::class);

        $this->expectException(UnsupportedByProvider::class);

        $integration->authenticatedUser();
    }

    public function test_uncached_calls_hit_the_provider_every_time(): void
    {
        $provider = $this->boundIdentifyingProvider();
        $integration = $this->integration('identifying', IdentifyingProvider::class);

        $integration->authenticatedUser();
        $integration->authenticatedUser();

        $this->assertSame(2, $provider->calls);
    }

    public function test_cache_for_serves_the_identity_without_a_second_provider_call(): void
    {
        $provider = $this->boundIdentifyingProvider();
        $integration = $this->integration('identifying', IdentifyingProvider::class);

        $integration->authenticatedUser(cacheFor: now()->addHour());
        $cached = $integration->authenticatedUser(cacheFor: now()->addHour());

        $this->assertSame(1, $provider->calls);
        $this->assertIsTheFixtureUser($cached);
        $this->assertTrue(Cache::has($this->cacheKey($integration)));
    }

    public function test_cache_for_works_when_the_cache_cannot_unserialize_objects(): void
    {
        $this->useStoreThatCannotUnserializeObjects();

        $provider = $this->boundIdentifyingProvider();
        $integration = $this->integration('identifying', IdentifyingProvider::class);

        $integration->authenticatedUser(cacheFor: now()->addHour());
        $cached = $integration->authenticatedUser(cacheFor: now()->addHour());

        $this->assertSame(1, $provider->calls);
        $this->assertIsTheFixtureUser($cached);
    }

    public function test_objects_in_raw_come_back_decoded_when_the_cache_cannot_unserialize_objects(): void
    {
        $this->useStoreThatCannotUnserializeObjects();

        $provider = $this->boundIdentifyingProvider();
        $provider->raw = ['org' => (object) ['login' => 'github']];
        $integration = $this->integration('identifying', IdentifyingProvider::class);

        $integration->authenticatedUser(cacheFor: now()->addHour());
        $cached = $integration->authenticatedUser(cacheFor: now()->addHour());

        $this->assertSame(1, $provider->calls);
        $this->assertSame(['org' => ['login' => 'github']], $cached->raw);
    }

    /**
     * @return array<string, array{array<string, mixed>}>
     */
    public static function cacheableRawProvider(): array
    {
        return [
            'empty' => [[]],
            'nested list' => [['orgs' => ['github', 'laravel']]],
            'nested object' => [['plan' => ['name' => 'pro', 'seats' => 5]]],
        ];
    }

    /**
     * @param  array<string, mixed>  $raw
     */
    #[DataProvider('cacheableRawProvider')]
    public function test_raw_round_trips_through_the_cache(array $raw): void
    {
        $provider = $this->boundIdentifyingProvider();
        $provider->raw = $raw;
        $integration = $this->integration('identifying', IdentifyingProvider::class);

        $integration->authenticatedUser(cacheFor: now()->addHour());
        $cached = $integration->authenticatedUser(cacheFor: now()->addHour());

        $this->assertSame(1, $provider->calls);
        $this->assertSame($raw, $cached->raw);
    }

    public function test_a_cache_miss_returns_the_providers_own_object(): void
    {
        $provider = $this->boundIdentifyingProvider();
        $integration = $this->integration('identifying', IdentifyingProvider::class);

        $user = $integration->authenticatedUser(cacheFor: now()->addHour());

        $this->assertSame($provider->lastReturned, $user);
    }

    /**
     * @return array<string, array{mixed}>
     */
    public static function malformedEntryProvider(): array
    {
        return [
            'not JSON' => ['{'],
            'not a string' => [['id' => 'u-1', 'raw' => []]],
            'JSON that is not an object' => ['"u-1"'],
            'missing id' => ['{"username":"octocat","raw":{}}'],
            'non-string id' => ['{"id":1,"raw":{}}'],
            'non-string username' => ['{"id":"u-1","username":5,"raw":{}}'],
            'missing raw' => ['{"id":"u-1"}'],
            'raw with integer keys' => ['{"id":"u-1","raw":["octocat"]}'],
        ];
    }

    #[DataProvider('malformedEntryProvider')]
    public function test_a_malformed_cache_entry_is_refetched(mixed $entry): void
    {
        $provider = $this->boundIdentifyingProvider();
        $integration = $this->integration('identifying', IdentifyingProvider::class);

        Cache::put($this->cacheKey($integration), $entry, now()->addHour());

        $user = $integration->authenticatedUser(cacheFor: now()->addHour());
        $cached = $integration->authenticatedUser(cacheFor: now()->addHour());

        $this->assertIsTheFixtureUser($user);
        $this->assertIsTheFixtureUser($cached);
        $this->assertSame(1, $provider->calls);
    }

    /**
     * @return array<string, array{array<array-key, mixed>}>
     */
    public static function uncacheableRawProvider(): array
    {
        return [
            'integer keys' => [['octocat']],
            'mixed keys' => [['login' => 'octocat', 0 => 'extra']],
            'invalid UTF-8' => [['login' => "\xB1\x31"]],
            'INF' => [['score' => INF]],
        ];
    }

    /**
     * @param  array<array-key, mixed>  $raw
     */
    #[DataProvider('uncacheableRawProvider')]
    public function test_an_identity_whose_raw_cannot_be_cached_throws(array $raw): void
    {
        $provider = $this->boundIdentifyingProvider();
        $provider->raw = $raw;
        $integration = $this->integration('identifying', IdentifyingProvider::class);

        try {
            $integration->authenticatedUser(cacheFor: now()->addHour());
            $this->fail('Expected UncacheableAuthenticatedUser.');
        } catch (UncacheableAuthenticatedUser $e) {
            $this->assertSame($provider->lastReturned, $e->user);
        }

        $this->assertFalse(Cache::has($this->cacheKey($integration)));
        $this->assertSame($raw, $integration->authenticatedUser()->raw);
    }

    public function test_refresh_forces_a_fresh_provider_call(): void
    {
        $provider = $this->boundIdentifyingProvider();
        $integration = $this->integration('identifying', IdentifyingProvider::class);

        $integration->authenticatedUser(cacheFor: now()->addHour());
        $integration->authenticatedUser(cacheFor: now()->addHour(), refresh: true);

        $this->assertSame(2, $provider->calls);
    }

    public function test_a_failed_refresh_leaves_the_cache_empty(): void
    {
        $provider = $this->boundIdentifyingProvider();
        $integration = $this->integration('identifying', IdentifyingProvider::class);

        $integration->authenticatedUser(cacheFor: now()->addHour());

        $provider->fails = true;
        $this->assertThrows(
            fn () => $integration->authenticatedUser(cacheFor: now()->addHour(), refresh: true),
            RuntimeException::class,
        );

        $this->assertFalse(Cache::has($this->cacheKey($integration)));
    }

    public function test_forget_makes_the_next_cached_call_live(): void
    {
        $provider = $this->boundIdentifyingProvider();
        $integration = $this->integration('identifying', IdentifyingProvider::class);

        $integration->authenticatedUser(cacheFor: now()->addHour());
        $integration->forgetAuthenticatedUser();
        $integration->authenticatedUser(cacheFor: now()->addHour());

        $this->assertSame(2, $provider->calls);
    }
}
