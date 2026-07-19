<?php

declare(strict_types=1);

namespace Integrations\Tests\Unit;

use Integrations\IntegrationManager;
use Integrations\Models\Integration;
use Integrations\Models\IntegrationRequest;
use Integrations\Tests\Fixtures\QuietLoggingTestProvider;
use Integrations\Tests\Fixtures\TestDataResponse;
use Integrations\Tests\Fixtures\TestProvider;
use Integrations\Tests\TestCase;

class ResponseBodyLoggingTest extends TestCase
{
    private Integration $integration;

    protected function setUp(): void
    {
        parent::setUp();

        $manager = app(IntegrationManager::class);
        $manager->register('test', TestProvider::class);
        $manager->register('quiet', QuietLoggingTestProvider::class);

        $this->integration = Integration::create(['provider' => 'test', 'name' => 'Test']);
        $this->integration->refresh();
    }

    public function test_a_body_over_the_cap_is_truncated_and_notes_its_original_size(): void
    {
        config(['integrations.logging.max_response_bytes' => 1024]);

        $this->integration->at('/api/data')->get(fn (): array => ['blob' => str_repeat('x', 5000)]);

        $stored = $this->latestRequest()->response_data;

        $this->assertNotNull($stored);
        $this->assertLessThan(5000, mb_strlen($stored, '8bit'));
        $this->assertStringContainsString('truncated from', $stored);
    }

    public function test_a_body_under_the_cap_is_stored_whole(): void
    {
        config(['integrations.logging.max_response_bytes' => 1024]);

        $this->integration->at('/api/data')->get(fn (): array => ['ok' => true]);

        $this->assertSame('{"ok":true}', $this->latestRequest()->response_data);
    }

    public function test_a_body_is_stored_whole_when_no_cap_is_set(): void
    {
        $this->integration->at('/api/data')->get(fn (): array => ['blob' => str_repeat('x', 5000)]);

        $stored = $this->latestRequest()->response_data;

        $this->assertNotNull($stored);
        $this->assertGreaterThan(5000, mb_strlen($stored, '8bit'));
    }

    public function test_a_cached_response_keeps_its_body_despite_the_cap(): void
    {
        // The cache decodes this column back into a response, so truncating
        // here would break the next cache hit.
        config(['integrations.logging.max_response_bytes' => 1024]);

        $this->integration->request(
            endpoint: '/api/data',
            method: 'GET',
            responseClass: TestDataResponse::class,
            callback: fn (): array => ['data' => str_repeat('x', 5000)],
            cacheFor: now()->addHour(),
        );

        $stored = $this->latestRequest()->response_data;

        $this->assertNotNull($stored);
        $this->assertStringNotContainsString('truncated from', $stored);
        $this->assertGreaterThan(5000, mb_strlen($stored, '8bit'));
    }

    public function test_an_idempotent_write_keeps_its_body_despite_the_cap(): void
    {
        // IdempotencyConflict recovery decodes this column to replay the prior
        // response, so truncating here would lose it.
        config(['integrations.logging.max_response_bytes' => 1024]);

        $this->integration->at('/api/charge')
            ->withIdempotencyKey('order-42')
            ->post(fn (): array => ['receipt' => str_repeat('x', 5000)]);

        $stored = $this->latestRequest()->response_data;

        $this->assertNotNull($stored);
        $this->assertStringNotContainsString('truncated from', $stored);
        $this->assertGreaterThan(5000, mb_strlen($stored, '8bit'));
    }

    public function test_a_provider_can_opt_an_endpoint_out_of_body_storage(): void
    {
        $integration = Integration::create(['provider' => 'quiet', 'name' => 'Quiet']);
        $integration->refresh();

        $integration->at('chat/completions')->post(fn (): array => ['answer' => str_repeat('x', 5000)]);

        $stored = $this->latestRequest()->response_data;

        $this->assertNotNull($stored);
        $this->assertStringContainsString('not stored', $stored);

        // The row is still written, so health and request counts are unaffected.
        $this->assertSame('chat/completions', $this->latestRequest()->endpoint);
        $this->assertTrue($this->latestRequest()->response_success);
    }

    public function test_an_endpoint_the_provider_did_not_name_is_unaffected(): void
    {
        $integration = Integration::create(['provider' => 'quiet', 'name' => 'Quiet']);
        $integration->refresh();

        $integration->at('models')->get(fn (): array => ['models' => ['a', 'b']]);

        $this->assertSame('{"models":["a","b"]}', $this->latestRequest()->response_data);
    }

    public function test_the_opt_out_honours_a_method_prefix(): void
    {
        $integration = Integration::create(['provider' => 'quiet', 'name' => 'Quiet']);
        $integration->refresh();

        // The provider's pattern is POST:embeddings, so a GET is unaffected.
        $integration->at('embeddings')->get(fn (): array => ['vector' => [1, 2, 3]]);
        $this->assertSame('{"vector":[1,2,3]}', $this->latestRequest()->response_data);

        $integration->at('embeddings')->post(fn (): array => ['vector' => [1, 2, 3]]);
        $this->assertStringContainsString('not stored', (string) $this->latestRequest()->response_data);
    }

    private function latestRequest(): IntegrationRequest
    {
        $request = IntegrationRequest::query()->latest('id')->first();

        $this->assertNotNull($request);

        return $request;
    }
}
