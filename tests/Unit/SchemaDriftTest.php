<?php

declare(strict_types=1);

namespace Integrations\Tests\Unit;

use Integrations\Exceptions\SchemaDriftException;
use Integrations\IntegrationManager;
use Integrations\Models\Integration;
use Integrations\Tests\Fixtures\TestDataResponse;
use Integrations\Tests\Fixtures\TestProvider;
use Integrations\Tests\TestCase;

class SchemaDriftTest extends TestCase
{
    private Integration $integration;

    protected function setUp(): void
    {
        parent::setUp();

        app(IntegrationManager::class)->register('test', TestProvider::class);
        $this->integration = Integration::create(['provider' => 'test', 'name' => 'Test']);
        $this->integration->refresh();
    }

    public function test_throws_schema_drift_on_live_response_when_hydration_fails(): void
    {
        try {
            $this->integration->at('/api/data')
                ->as(TestDataResponse::class)
                ->get(fn (): array => ['unexpected' => 'shape']);

            $this->fail('Expected SchemaDriftException to be thrown.');
        } catch (SchemaDriftException $e) {
            $this->assertSame('live', $e->source);
            $this->assertSame(TestDataResponse::class, $e->responseClass);
            $this->assertSame(['unexpected' => 'shape'], $e->parsedData);
            $this->assertNotNull($e->getPrevious());
            $this->assertSame($this->integration->id, $e->integration->id);
        }
    }

    public function test_throws_schema_drift_on_cache_when_stored_payload_no_longer_matches(): void
    {
        // Seed a cache row whose response_data won't fit TestDataResponse.
        $this->integration->requests()->create([
            'endpoint' => '/api/data',
            'method' => 'GET',
            'request_data' => null,
            'response_code' => 200,
            'response_data' => '{"unexpected":"shape"}',
            'response_success' => true,
            'duration_ms' => 0,
            'expires_at' => now()->addHour(),
        ]);

        try {
            $this->integration->at('/api/data')
                ->withCache(now()->addHour())
                ->as(TestDataResponse::class)
                ->get(fn (): array => ['data' => 'fresh']);

            $this->fail('Expected SchemaDriftException to be thrown.');
        } catch (SchemaDriftException $e) {
            $this->assertSame('cache', $e->source);
            $this->assertSame(TestDataResponse::class, $e->responseClass);
            $this->assertSame(['unexpected' => 'shape'], $e->parsedData);
        }
    }

    public function test_well_formed_responses_hydrate_normally(): void
    {
        $result = $this->integration->at('/api/data')
            ->as(TestDataResponse::class)
            ->get(fn (): array => ['data' => 'ok']);

        $this->assertInstanceOf(TestDataResponse::class, $result);
        $this->assertSame('ok', $result->data);
    }

    public function test_schema_drift_message_includes_class_and_source(): void
    {
        try {
            $this->integration->at('/api/data')
                ->as(TestDataResponse::class)
                ->get(fn (): array => ['unexpected' => 'shape']);
        } catch (SchemaDriftException $e) {
            $this->assertStringContainsString(TestDataResponse::class, $e->getMessage());
            $this->assertStringContainsString('live', $e->getMessage());
        }
    }
}
