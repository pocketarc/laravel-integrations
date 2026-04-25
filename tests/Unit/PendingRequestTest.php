<?php

declare(strict_types=1);

namespace Integrations\Tests\Unit;

use Integrations\IntegrationManager;
use Integrations\Models\Integration;
use Integrations\PendingRequest;
use Integrations\Testing\IntegrationRequestFake;
use Integrations\Tests\Fixtures\TestOkResponse;
use Integrations\Tests\Fixtures\TestProvider;
use Integrations\Tests\TestCase;
use Integrations\TypedPendingRequest;
use InvalidArgumentException;
use Spatie\LaravelData\Data;
use stdClass;

class PendingRequestTest extends TestCase
{
    private Integration $integration;

    protected function setUp(): void
    {
        parent::setUp();

        app(IntegrationManager::class)->register('test', TestProvider::class);
        $this->integration = Integration::create(['provider' => 'test', 'name' => 'Test']);
    }

    public function test_at_returns_untyped_pending_request(): void
    {
        $pending = $this->integration->at('/api/test');
        $this->assertInstanceOf(PendingRequest::class, $pending);
    }

    public function test_as_returns_typed_pending_request(): void
    {
        $typed = $this->integration->at('/api/test')->as(TestOkResponse::class);
        $this->assertInstanceOf(TypedPendingRequest::class, $typed);
    }

    public function test_as_rejects_classes_that_do_not_extend_data(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(Data::class);

        $this->integration->at('/api/test')->as(stdClass::class); // @phpstan-ignore-line argument.type
    }

    public function test_fluent_get_with_callback(): void
    {
        IntegrationRequestFake::activate(['/api/tickets' => ['ok' => true]]);

        $result = $this->integration->at('/api/tickets')->as(TestOkResponse::class)
            ->get(fn () => ['ok' => true]);

        $this->assertInstanceOf(TestOkResponse::class, $result);
        $this->assertTrue($result->ok);
        IntegrationRequestFake::assertRequested('/api/tickets');
    }

    public function test_fluent_post_with_callback(): void
    {
        IntegrationRequestFake::activate(['/api/tickets' => ['ok' => true]]);

        $result = $this->integration->at('/api/tickets')->as(TestOkResponse::class)
            ->withData(['title' => 'Test'])
            ->post(fn () => ['ok' => true]);

        $this->assertInstanceOf(TestOkResponse::class, $result);
        $this->assertTrue($result->ok);
        IntegrationRequestFake::assertRequested('/api/tickets');
    }

    public function test_with_retries(): void
    {
        IntegrationRequestFake::activate(['/api/test' => ['ok' => true]]);

        $this->integration->at('/api/test')->as(TestOkResponse::class)
            ->withAttempts(3)
            ->get(fn () => ['ok' => true]);

        IntegrationRequestFake::assertRequested('/api/test');
    }

    public function test_with_data_as_array(): void
    {
        IntegrationRequestFake::activate(['/api/search' => ['ok' => true]]);

        $this->integration->at('/api/search')->as(TestOkResponse::class)
            ->withData(['q' => 'test'])
            ->get(fn () => ['ok' => true]);

        IntegrationRequestFake::assertRequestedWith('/api/search', function (?string $data): bool {
            $decoded = json_decode($data ?? '', true);

            return is_array($decoded) && ($decoded['q'] ?? null) === 'test';
        });
    }

    public function test_method_chaining_returns_self(): void
    {
        $pending = $this->integration->at('/api/test')->as(TestOkResponse::class);

        $this->assertSame($pending, $pending->withAttempts(2));
        $this->assertSame($pending, $pending->withData('data'));
        $this->assertSame($pending, $pending->withCache(60));
    }
}
