<?php

declare(strict_types=1);

namespace Integrations\Tests\Unit;

use Illuminate\Support\Facades\Event;
use Integrations\Events\RequestCompleted;
use Integrations\Events\RequestFailed;
use Integrations\IntegrationManager;
use Integrations\Models\Integration;
use Integrations\Models\IntegrationRequest;
use Integrations\Tests\Fixtures\TestOkResponse;
use Integrations\Tests\Fixtures\TestProvider;
use Integrations\Tests\TestCase;
use RuntimeException;

class RequestWrapperTest extends TestCase
{
    private Integration $integration;

    protected function setUp(): void
    {
        parent::setUp();

        $manager = app(IntegrationManager::class);
        $manager->register('test', TestProvider::class);

        $this->integration = Integration::create([
            'provider' => 'test',
            'name' => 'Test',
        ]);
        $this->integration->refresh();
    }

    public function test_successful_request_logs_and_fires_event(): void
    {
        Event::fake();

        $result = $this->integration->request(
            endpoint: '/api/tickets',
            method: 'GET',
            responseClass: TestOkResponse::class,
            callback: fn () => ['ok' => true],
        );

        $this->assertInstanceOf(TestOkResponse::class, $result);
        $this->assertTrue($result->ok);

        $this->assertDatabaseCount('integration_requests', 1);

        $request = IntegrationRequest::first();
        $this->assertNotNull($request);
        $this->assertSame('/api/tickets', $request->endpoint);
        $this->assertSame('GET', $request->method);
        $this->assertTrue($request->response_success);
        $this->assertNotNull($request->duration_ms);

        Event::assertDispatched(RequestCompleted::class);
    }

    public function test_failed_request_logs_error_and_fires_event(): void
    {
        Event::fake();

        try {
            $this->integration->request(
                endpoint: '/api/fail',
                method: 'POST',
                responseClass: TestOkResponse::class,
                callback: fn () => throw new RuntimeException('Something broke'),
            );
            $this->fail('Expected exception was not thrown.');
        } catch (RuntimeException $e) {
            $this->assertSame('Something broke', $e->getMessage());
        }

        $request = IntegrationRequest::first();
        $this->assertNotNull($request);
        $this->assertFalse($request->response_success);
        $this->assertNotNull($request->error);
        $this->assertSame('RuntimeException', $request->error['class']);

        Event::assertDispatched(RequestFailed::class);
    }

    public function test_null_returning_callback_returns_null(): void
    {
        $result = $this->integration->request(
            endpoint: '/api/nothing',
            method: 'GET',
            callback: fn () => null,
        );

        $this->assertNull($result);
        $this->assertDatabaseCount('integration_requests', 1);
    }

    public function test_request_with_explicit_request_data(): void
    {
        $this->integration->request(
            endpoint: 'customers.create',
            method: 'POST',
            responseClass: TestOkResponse::class,
            callback: fn () => ['ok' => true],
            requestData: ['email' => 'test@example.com'],
        );

        $request = IntegrationRequest::first();
        $this->assertNotNull($request);
        $decoded = json_decode($request->request_data ?? '', true);
        $this->assertSame('test@example.com', $decoded['email'] ?? null);
    }

    public function test_retry_of_links_to_original(): void
    {
        $this->integration->request(
            endpoint: '/api/first',
            method: 'GET',
            responseClass: TestOkResponse::class,
            callback: fn () => ['ok' => true],
        );

        $originalRequest = IntegrationRequest::first();
        $this->assertNotNull($originalRequest);

        $this->integration->request(
            endpoint: '/api/first',
            method: 'GET',
            responseClass: TestOkResponse::class,
            callback: fn () => ['ok' => true],
            retryOfId: $originalRequest->id,
        );

        $retry = IntegrationRequest::latest('id')->first();
        $this->assertNotNull($retry);
        $this->assertSame($originalRequest->id, $retry->retry_of);
        $this->assertSame($originalRequest->id, $retry->originalRequest?->id);
    }

    public function test_health_updated_on_failure(): void
    {
        try {
            $this->integration->request(
                endpoint: '/api/fail',
                method: 'GET',
                responseClass: TestOkResponse::class,
                callback: fn () => throw new RuntimeException('fail'),
            );
        } catch (RuntimeException) {
            // expected
        }

        $this->integration->refresh();
        $this->assertSame(1, $this->integration->consecutive_failures);
    }

    public function test_health_resets_on_success(): void
    {
        $this->integration->update(['consecutive_failures' => 3]);

        $this->integration->request(
            endpoint: '/api/ok',
            method: 'GET',
            responseClass: TestOkResponse::class,
            callback: fn () => ['ok' => true],
        );

        $this->integration->refresh();
        $this->assertSame(0, $this->integration->consecutive_failures);
    }
}
