<?php

declare(strict_types=1);

namespace Integrations\Tests\Unit;

use Illuminate\Http\Client\ConnectionException;
use Integrations\Enums\FailureClass;
use Integrations\IntegrationManager;
use Integrations\Models\Integration;
use Integrations\Models\IntegrationRequest;
use Integrations\Tests\Fixtures\TestOkResponse;
use Integrations\Tests\Fixtures\TestProvider;
use Integrations\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

class FailureClassPersistenceTest extends TestCase
{
    private Integration $integration;

    protected function setUp(): void
    {
        parent::setUp();

        app(IntegrationManager::class)->register('test', TestProvider::class);

        $this->integration = Integration::create([
            'provider' => 'test',
            'name' => 'Test',
        ]);
        $this->integration->refresh();
    }

    /**
     * @return array<string, array{Throwable, FailureClass}>
     */
    public static function failureProvider(): array
    {
        return [
            'connection error -> upstream' => [new ConnectionException('refused'), FailureClass::Upstream],
            '500 -> upstream' => [new HttpException(500, 'down'), FailureClass::Upstream],
            '429 -> throttle' => [new HttpException(429, 'slow down'), FailureClass::Throttle],
            '400 -> client' => [new HttpException(400, 'bad'), FailureClass::Client],
            'unrecognised -> unknown' => [new RuntimeException('mystery'), FailureClass::Unknown],
        ];
    }

    #[DataProvider('failureProvider')]
    public function test_persists_failure_class_on_failed_request(Throwable $exception, FailureClass $expected): void
    {
        try {
            $this->integration->request(
                endpoint: '/api/fail',
                method: 'GET',
                callback: fn () => throw $exception,
                maxAttempts: 1,
            );
            $this->fail('Expected exception was not thrown.');
        } catch (Throwable) {
            // expected
        }

        $request = IntegrationRequest::first();
        $this->assertNotNull($request);
        $this->assertFalse($request->response_success);
        $this->assertSame($expected, $request->failure_class);
    }

    public function test_failure_class_is_null_on_success(): void
    {
        $this->integration->request(
            endpoint: '/api/ok',
            method: 'GET',
            responseClass: TestOkResponse::class,
            callback: fn () => ['ok' => true],
        );

        $request = IntegrationRequest::first();
        $this->assertNotNull($request);
        $this->assertTrue($request->response_success);
        $this->assertNull($request->failure_class);
    }

    public function test_with_failure_class_scope_filters(): void
    {
        $this->integration->requests()->create([
            'endpoint' => '/a', 'method' => 'GET', 'response_success' => false,
            'failure_class' => FailureClass::Upstream->value,
        ]);
        $this->integration->requests()->create([
            'endpoint' => '/b', 'method' => 'GET', 'response_success' => false,
            'failure_class' => FailureClass::Throttle->value,
        ]);
        $this->integration->requests()->create([
            'endpoint' => '/c', 'method' => 'GET', 'response_success' => true,
        ]);

        $this->assertSame(1, IntegrationRequest::query()->withFailureClass(FailureClass::Upstream)->count());
        $this->assertSame(1, IntegrationRequest::query()->withFailureClass(FailureClass::Throttle)->count());
        $this->assertSame(0, IntegrationRequest::query()->withFailureClass(FailureClass::Client)->count());
    }
}
