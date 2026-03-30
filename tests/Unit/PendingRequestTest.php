<?php

declare(strict_types=1);

namespace Integrations\Tests\Unit;

use Integrations\IntegrationManager;
use Integrations\Models\Integration;
use Integrations\PendingRequest;
use Integrations\Testing\IntegrationRequestFake;
use Integrations\Tests\Fixtures\TestProvider;
use Integrations\Tests\TestCase;

class PendingRequestTest extends TestCase
{
    private Integration $integration;

    protected function setUp(): void
    {
        parent::setUp();

        app(IntegrationManager::class)->register('test', TestProvider::class);
        $this->integration = Integration::create(['provider' => 'test', 'name' => 'Test']);
    }

    public function test_to_returns_pending_request(): void
    {
        $pending = $this->integration->to('/api/test');
        $this->assertInstanceOf(PendingRequest::class, $pending);
    }

    public function test_fluent_get_with_callback(): void
    {
        IntegrationRequestFake::activate(['/api/tickets' => ['id' => 1]]);

        $result = $this->integration->to('/api/tickets')
            ->get(fn () => ['id' => 1]);

        $this->assertSame(['id' => 1], $result);
        IntegrationRequestFake::assertRequested('/api/tickets');
    }

    public function test_fluent_post_with_callback(): void
    {
        IntegrationRequestFake::activate(['/api/tickets' => ['created' => true]]);

        $result = $this->integration->to('/api/tickets')
            ->withData(['title' => 'Test'])
            ->post(fn () => ['created' => true]);

        $this->assertSame(['created' => true], $result);
        IntegrationRequestFake::assertRequested('/api/tickets');
    }

    public function test_with_retries(): void
    {
        IntegrationRequestFake::activate(['/api/test' => 'ok']);

        $this->integration->to('/api/test')
            ->withRetries(3)
            ->get(fn () => 'ok');

        IntegrationRequestFake::assertRequested('/api/test');
    }

    public function test_with_data_as_array(): void
    {
        IntegrationRequestFake::activate(['/api/search' => []]);

        $this->integration->to('/api/search')
            ->withData(['q' => 'test'])
            ->get(fn () => []);

        IntegrationRequestFake::assertRequestedWith('/api/search', function (?string $data): bool {
            $decoded = json_decode($data ?? '', true);

            return is_array($decoded) && ($decoded['q'] ?? null) === 'test';
        });
    }

    public function test_method_chaining_returns_self(): void
    {
        $pending = $this->integration->to('/api/test');

        $this->assertSame($pending, $pending->withRetries(2));
        $this->assertSame($pending, $pending->withData('data'));
        $this->assertSame($pending, $pending->withCache(60));
    }
}
