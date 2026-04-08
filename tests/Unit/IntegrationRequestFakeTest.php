<?php

declare(strict_types=1);

namespace Integrations\Tests\Unit;

use Integrations\IntegrationManager;
use Integrations\Models\Integration;
use Integrations\Models\IntegrationRequest;
use Integrations\Testing\IntegrationRequestFake;
use Integrations\Testing\ResponseSequence;
use Integrations\Tests\Fixtures\TestDataResponse;
use Integrations\Tests\Fixtures\TestOkResponse;
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

        $this->integration->requestAs(
            endpoint: '/api/tickets',
            method: 'GET',
            responseClass: TestOkResponse::class,
            callback: fn () => throw new \RuntimeException('Should not be called'),
        );

        $this->assertDatabaseCount('integration_requests', 0);
    }

    public function test_fake_returns_configured_response(): void
    {
        IntegrationRequest::fake([
            'customers.create' => ['ok' => true],
        ]);

        $result = $this->integration->requestAs(
            endpoint: 'customers.create',
            method: 'POST',
            responseClass: TestOkResponse::class,
            callback: fn () => throw new \RuntimeException('Should not be called'),
        );

        $this->assertInstanceOf(TestOkResponse::class, $result);
        $this->assertTrue($result->ok);
    }

    public function test_fake_returns_null_for_unconfigured_endpoint(): void
    {
        IntegrationRequest::fake();

        $result = $this->integration->requestAs(
            endpoint: '/api/unknown',
            method: 'GET',
            responseClass: TestOkResponse::class,
            callback: fn () => throw new \RuntimeException('Should not be called'),
        );

        $this->assertNull($result);
    }

    public function test_assert_requested(): void
    {
        IntegrationRequest::fake();

        $this->integration->requestAs(endpoint: '/api/tickets', method: 'GET', responseClass: TestOkResponse::class, callback: fn () => null);
        $this->integration->requestAs(endpoint: '/api/tickets', method: 'GET', responseClass: TestOkResponse::class, callback: fn () => null);

        IntegrationRequest::assertRequested('/api/tickets');
        IntegrationRequest::assertRequested('/api/tickets', times: 2);
    }

    public function test_assert_not_requested(): void
    {
        IntegrationRequest::fake();

        $this->integration->requestAs(endpoint: '/api/tickets', method: 'GET', responseClass: TestOkResponse::class, callback: fn () => null);

        IntegrationRequest::assertNotRequested('/api/users');
    }

    public function test_assert_requested_with(): void
    {
        IntegrationRequest::fake();

        $this->integration->requestAs(
            endpoint: 'customers.create',
            method: 'POST',
            responseClass: TestOkResponse::class,
            callback: fn () => null,
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

                return ['data' => (string) $callCount];
            },
        ]);

        $result1 = $this->integration->requestAs(endpoint: '/api/counter', method: 'GET', responseClass: TestDataResponse::class, callback: fn () => null);
        $result2 = $this->integration->requestAs(endpoint: '/api/counter', method: 'GET', responseClass: TestDataResponse::class, callback: fn () => null);

        $this->assertSame('1', $result1->data);
        $this->assertSame('2', $result2->data);
    }

    public function test_sequence_returns_different_responses(): void
    {
        IntegrationRequest::fake([
            '/api/items' => new ResponseSequence(['data' => 'first'], ['data' => 'second'], ['data' => 'third']),
        ]);

        $r1 = $this->integration->requestAs(endpoint: '/api/items', method: 'GET', responseClass: TestDataResponse::class, callback: fn () => null);
        $r2 = $this->integration->requestAs(endpoint: '/api/items', method: 'GET', responseClass: TestDataResponse::class, callback: fn () => null);
        $r3 = $this->integration->requestAs(endpoint: '/api/items', method: 'GET', responseClass: TestDataResponse::class, callback: fn () => null);

        $this->assertSame('first', $r1->data);
        $this->assertSame('second', $r2->data);
        $this->assertSame('third', $r3->data);
    }

    public function test_sequence_returns_null_when_exhausted(): void
    {
        IntegrationRequest::fake([
            '/api/items' => new ResponseSequence(['data' => 'only']),
        ]);

        $r1 = $this->integration->requestAs(endpoint: '/api/items', method: 'GET', responseClass: TestDataResponse::class, callback: fn () => null);
        $r2 = $this->integration->requestAs(endpoint: '/api/items', method: 'GET', responseClass: TestDataResponse::class, callback: fn () => null);

        $this->assertSame('only', $r1->data);
        $this->assertNull($r2);
    }

    public function test_exception_faking_throws(): void
    {
        IntegrationRequest::fake([
            '/api/fail' => new \RuntimeException('API is down'),
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('API is down');

        $this->integration->requestAs(endpoint: '/api/fail', method: 'GET', responseClass: TestOkResponse::class, callback: fn () => null);
    }

    public function test_assert_request_count(): void
    {
        IntegrationRequest::fake();

        $this->integration->requestAs(endpoint: '/api/a', method: 'GET', responseClass: TestOkResponse::class, callback: fn () => null);
        $this->integration->requestAs(endpoint: '/api/b', method: 'GET', responseClass: TestOkResponse::class, callback: fn () => null);

        IntegrationRequest::assertRequestCount(2);
    }

    public function test_assert_nothing_requested(): void
    {
        IntegrationRequest::fake();

        IntegrationRequest::assertNothingRequested();
    }

    public function test_stop_faking_resumes_real_calls(): void
    {
        IntegrationRequest::fake();
        $this->integration->requestAs(endpoint: '/api/fake', method: 'GET', responseClass: TestOkResponse::class, callback: fn () => null);
        $this->assertDatabaseCount('integration_requests', 0);

        IntegrationRequest::stopFaking();

        $this->integration->requestAs(
            endpoint: '/api/real',
            method: 'GET',
            responseClass: TestOkResponse::class,
            callback: fn () => ['ok' => true],
        );

        $this->assertDatabaseCount('integration_requests', 1);
    }

    public function test_fake_matches_wildcard_endpoint(): void
    {
        IntegrationRequest::fake([
            'tickets/*.json' => ['ok' => true],
        ]);

        $result = $this->integration->requestAs(
            endpoint: 'tickets/123.json',
            method: 'GET',
            responseClass: TestOkResponse::class,
            callback: fn () => throw new \RuntimeException('Should not be called'),
        );

        $this->assertInstanceOf(TestOkResponse::class, $result);
        $this->assertTrue($result->ok);
    }

    public function test_fake_exact_match_takes_precedence_over_wildcard(): void
    {
        IntegrationRequest::fake([
            'tickets/*.json' => ['data' => 'wildcard'],
            'tickets/123.json' => ['data' => 'exact'],
        ]);

        $result = $this->integration->requestAs(
            endpoint: 'tickets/123.json',
            method: 'GET',
            responseClass: TestDataResponse::class,
            callback: fn () => null,
        );

        $this->assertInstanceOf(TestDataResponse::class, $result);
        $this->assertSame('exact', $result->data);
    }

    public function test_fake_specific_wildcard_beats_general_wildcard(): void
    {
        IntegrationRequest::fake([
            'tickets/*.json' => ['data' => 'general'],
            'tickets/*/comments.json' => ['data' => 'specific'],
        ]);

        $result = $this->integration->requestAs(
            endpoint: 'tickets/42/comments.json',
            method: 'GET',
            responseClass: TestDataResponse::class,
            callback: fn () => null,
        );

        $this->assertInstanceOf(TestDataResponse::class, $result);
        $this->assertSame('specific', $result->data);
    }

    public function test_fake_wildcard_does_not_cross_path_segments(): void
    {
        IntegrationRequest::fake([
            'tickets/*.json' => ['data' => 'single-segment'],
        ]);

        $match = $this->integration->requestAs(endpoint: 'tickets/123.json', method: 'GET', responseClass: TestDataResponse::class, callback: fn () => null);
        $miss = $this->integration->requestAs(endpoint: 'tickets/42/comments.json', method: 'GET', responseClass: TestDataResponse::class, callback: fn () => null);

        $this->assertInstanceOf(TestDataResponse::class, $match);
        $this->assertNull($miss);
    }

    public function test_assert_requested_with_wildcard_pattern(): void
    {
        IntegrationRequest::fake();

        $this->integration->requestAs(endpoint: 'tickets/123.json', method: 'GET', responseClass: TestOkResponse::class, callback: fn () => null);

        IntegrationRequest::assertRequested('tickets/*.json');
    }

    public function test_fake_method_aware_responses(): void
    {
        IntegrationRequest::fake([
            'GET:tickets/123.json' => ['data' => 'read'],
            'PUT:tickets/123.json' => ['data' => 'updated'],
        ]);

        $getResult = $this->integration->requestAs(
            endpoint: 'tickets/123.json',
            method: 'GET',
            responseClass: TestDataResponse::class,
            callback: fn () => null,
        );

        $putResult = $this->integration->requestAs(
            endpoint: 'tickets/123.json',
            method: 'PUT',
            responseClass: TestDataResponse::class,
            callback: fn () => null,
        );

        $this->assertSame('read', $getResult->data);
        $this->assertSame('updated', $putResult->data);
    }

    public function test_fake_unprefixed_matches_any_method(): void
    {
        IntegrationRequest::fake([
            'tickets/*.json' => ['ok' => true],
        ]);

        $getResult = $this->integration->requestAs(endpoint: 'tickets/1.json', method: 'GET', responseClass: TestOkResponse::class, callback: fn () => null);
        $postResult = $this->integration->requestAs(endpoint: 'tickets/2.json', method: 'POST', responseClass: TestOkResponse::class, callback: fn () => null);

        $this->assertInstanceOf(TestOkResponse::class, $getResult);
        $this->assertInstanceOf(TestOkResponse::class, $postResult);
    }

    public function test_fake_method_prefix_takes_precedence_over_unprefixed(): void
    {
        IntegrationRequest::fake([
            'tickets/*.json' => ['data' => 'any'],
            'GET:tickets/*.json' => ['data' => 'get-specific'],
        ]);

        $getResult = $this->integration->requestAs(
            endpoint: 'tickets/1.json',
            method: 'GET',
            responseClass: TestDataResponse::class,
            callback: fn () => null,
        );

        $postResult = $this->integration->requestAs(
            endpoint: 'tickets/2.json',
            method: 'POST',
            responseClass: TestDataResponse::class,
            callback: fn () => null,
        );

        $this->assertSame('get-specific', $getResult->data);
        $this->assertSame('any', $postResult->data);
    }

    public function test_fake_method_with_wildcard_combined(): void
    {
        IntegrationRequest::fake([
            'GET:tickets/*/comments.json' => ['data' => 'comments'],
        ]);

        $result = $this->integration->requestAs(
            endpoint: 'tickets/42/comments.json',
            method: 'GET',
            responseClass: TestDataResponse::class,
            callback: fn () => null,
        );

        $this->assertInstanceOf(TestDataResponse::class, $result);
        $this->assertSame('comments', $result->data);

        // Different method should not match
        $miss = $this->integration->requestAs(
            endpoint: 'tickets/42/comments.json',
            method: 'POST',
            responseClass: TestDataResponse::class,
            callback: fn () => null,
        );

        $this->assertNull($miss);
    }

    public function test_scoped_fake_returns_integration_specific_response(): void
    {
        $other = Integration::create(['provider' => 'test', 'name' => 'Other']);
        $other->refresh();

        IntegrationRequest::fake()
            ->forIntegration($this->integration, ['tickets/*.json' => ['data' => 'integration-a']])
            ->forIntegration($other, ['tickets/*.json' => ['data' => 'integration-b']]);

        $resultA = $this->integration->requestAs(endpoint: 'tickets/1.json', method: 'GET', responseClass: TestDataResponse::class, callback: fn () => null);
        $resultB = $other->requestAs(endpoint: 'tickets/1.json', method: 'GET', responseClass: TestDataResponse::class, callback: fn () => null);

        $this->assertSame('integration-a', $resultA->data);
        $this->assertSame('integration-b', $resultB->data);
    }

    public function test_scoped_fake_falls_back_to_global(): void
    {
        IntegrationRequest::fake(['fallback/endpoint' => ['data' => 'global']])
            ->forIntegration($this->integration, ['scoped/endpoint' => ['data' => 'scoped']]);

        $scoped = $this->integration->requestAs(endpoint: 'scoped/endpoint', method: 'GET', responseClass: TestDataResponse::class, callback: fn () => null);
        $global = $this->integration->requestAs(endpoint: 'fallback/endpoint', method: 'GET', responseClass: TestDataResponse::class, callback: fn () => null);

        $this->assertSame('scoped', $scoped->data);
        $this->assertSame('global', $global->data);
    }

    public function test_scoped_fake_with_integer_id(): void
    {
        IntegrationRequestFake::activate()
            ->forIntegration($this->integration->id, ['tickets/*.json' => ['ok' => true]]);

        $result = $this->integration->requestAs(endpoint: 'tickets/1.json', method: 'GET', responseClass: TestOkResponse::class, callback: fn () => null);

        $this->assertInstanceOf(TestOkResponse::class, $result);
        $this->assertTrue($result->ok);
    }

    public function test_assert_requested_with_method_filter(): void
    {
        IntegrationRequest::fake();

        $this->integration->requestAs(endpoint: 'tickets/1.json', method: 'GET', responseClass: TestOkResponse::class, callback: fn () => null);
        $this->integration->requestAs(endpoint: 'tickets/1.json', method: 'PUT', responseClass: TestOkResponse::class, callback: fn () => null);

        IntegrationRequest::assertRequested('tickets/1.json', times: 1, method: 'GET');
        IntegrationRequest::assertRequested('tickets/1.json', times: 1, method: 'PUT');
        IntegrationRequest::assertRequested('tickets/1.json', times: 2);
    }

    public function test_assert_requested_with_integration_id_filter(): void
    {
        $other = Integration::create(['provider' => 'test', 'name' => 'Other']);
        $other->refresh();

        IntegrationRequest::fake();

        $this->integration->requestAs(endpoint: 'tickets/1.json', method: 'GET', responseClass: TestOkResponse::class, callback: fn () => null);
        $other->requestAs(endpoint: 'tickets/1.json', method: 'GET', responseClass: TestOkResponse::class, callback: fn () => null);

        IntegrationRequest::assertRequested('tickets/1.json', times: 1, integrationId: $this->integration->id);
        IntegrationRequest::assertRequested('tickets/1.json', times: 1, integrationId: $other->id);
        IntegrationRequest::assertRequested('tickets/1.json', times: 2);
    }
}
