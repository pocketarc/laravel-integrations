<?php

declare(strict_types=1);

namespace Integrations\Tests\Unit;

use Integrations\IntegrationManager;
use Integrations\Models\Integration;
use Integrations\Models\IntegrationRequest;
use Integrations\Tests\Fixtures\TestProvider;
use Integrations\Tests\TestCase;

class IntegrationRequestFakeTest extends TestCase
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

    public function test_fake_prevents_real_calls(): void
    {
        IntegrationRequest::fake();

        $this->integration->request(
            endpoint: '/api/tickets',
            method: 'GET',
            callback: fn () => throw new \RuntimeException('Should not be called'),
        );

        $this->assertDatabaseCount('integration_requests', 0);
    }

    public function test_fake_returns_configured_response(): void
    {
        IntegrationRequest::fake([
            'customers.create' => ['id' => 'cus_123', 'email' => 'test@example.com'],
        ]);

        $result = $this->integration->request(
            endpoint: 'customers.create',
            method: 'POST',
            callback: fn () => throw new \RuntimeException('Should not be called'),
        );

        $this->assertSame(['id' => 'cus_123', 'email' => 'test@example.com'], $result);
    }

    public function test_fake_returns_null_for_unconfigured_endpoint(): void
    {
        IntegrationRequest::fake();

        $result = $this->integration->request(
            endpoint: '/api/unknown',
            method: 'GET',
            callback: fn () => throw new \RuntimeException('Should not be called'),
        );

        $this->assertNull($result);
    }

    public function test_assert_requested(): void
    {
        IntegrationRequest::fake();

        $this->integration->request(endpoint: '/api/tickets', method: 'GET');
        $this->integration->request(endpoint: '/api/tickets', method: 'GET');

        IntegrationRequest::assertRequested('/api/tickets');
        IntegrationRequest::assertRequested('/api/tickets', times: 2);
    }

    public function test_assert_not_requested(): void
    {
        IntegrationRequest::fake();

        $this->integration->request(endpoint: '/api/tickets', method: 'GET');

        IntegrationRequest::assertNotRequested('/api/users');
    }

    public function test_assert_requested_with(): void
    {
        IntegrationRequest::fake();

        $this->integration->request(
            endpoint: 'customers.create',
            method: 'POST',
            requestData: ['email' => 'test@example.com'],
        );

        IntegrationRequest::assertRequestedWith('customers.create', function (?string $data): bool {
            return $data !== null && str_contains($data, 'test@example.com');
        });
    }

    public function test_fake_with_closure_response(): void
    {
        $callCount = 0;

        IntegrationRequest::fake([
            '/api/counter' => function () use (&$callCount) {
                $callCount++;

                return ['count' => $callCount];
            },
        ]);

        $result1 = $this->integration->request(endpoint: '/api/counter', method: 'GET');
        $result2 = $this->integration->request(endpoint: '/api/counter', method: 'GET');

        $this->assertSame(['count' => 1], $result1);
        $this->assertSame(['count' => 2], $result2);
    }

    public function test_stop_faking_resumes_real_calls(): void
    {
        IntegrationRequest::fake();
        $this->integration->request(endpoint: '/api/fake', method: 'GET');
        $this->assertDatabaseCount('integration_requests', 0);

        IntegrationRequest::stopFaking();

        $this->integration->request(
            endpoint: '/api/real',
            method: 'GET',
            callback: fn () => ['real' => true],
        );

        $this->assertDatabaseCount('integration_requests', 1);
    }
}
