<?php

declare(strict_types=1);

namespace Integrations\Tests\Unit;

use Illuminate\Support\Facades\Log;
use Integrations\Contracts\IntegrationProvider;
use Integrations\Contracts\SupportsIdempotency;
use Integrations\Exceptions\RetryableException;
use Integrations\IntegrationManager;
use Integrations\Models\Integration;
use Integrations\Models\IntegrationRequest;
use Integrations\RequestContext;
use Integrations\Tests\Fixtures\TestOkResponse;
use Integrations\Tests\Fixtures\TestProvider;
use Integrations\Tests\TestCase;
use InvalidArgumentException;

class IdempotencyTest extends TestCase
{
    private Integration $integration;

    protected function setUp(): void
    {
        parent::setUp();

        app(IntegrationManager::class)->register('test', TestProvider::class);
        app(IntegrationManager::class)->register('idempotent', IdempotentTestProvider::class);
        $this->integration = Integration::create(['provider' => 'test', 'name' => 'Test']);
        $this->integration->refresh();
    }

    public function test_with_idempotency_key_passes_explicit_key_through(): void
    {
        $captured = null;

        $this->integration->at('/api/charge')
            ->withIdempotencyKey('order-42')
            ->post(function (RequestContext $ctx) use (&$captured): array {
                $captured = $ctx->idempotencyKey;

                return ['ok' => true];
            });

        $this->assertSame('order-42', $captured);
    }

    public function test_with_idempotency_key_null_auto_generates_a_uuid(): void
    {
        $captured = null;

        $this->integration->at('/api/charge')
            ->withIdempotencyKey()
            ->post(function (RequestContext $ctx) use (&$captured): array {
                $captured = $ctx->idempotencyKey;

                return ['ok' => true];
            });

        $this->assertNotNull($captured);
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/',
            $captured,
        );
    }

    public function test_omitting_with_idempotency_key_leaves_context_key_null(): void
    {
        $captured = 'unset';

        $this->integration->at('/api/charge')->post(function (RequestContext $ctx) use (&$captured): array {
            $captured = $ctx->idempotencyKey;

            return ['ok' => true];
        });

        $this->assertNull($captured);
    }

    public function test_empty_string_idempotency_key_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('must not be empty');

        $this->integration->at('/api/charge')->withIdempotencyKey('');
    }

    public function test_idempotency_key_longer_than_64_chars_throws(): void
    {
        $tooLong = str_repeat('a', 65);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('at most 64');

        $this->integration->at('/api/charge')
            ->withIdempotencyKey($tooLong)
            ->post(fn (): array => ['ok' => true]);
    }

    public function test_idempotency_key_at_exactly_64_chars_works(): void
    {
        $atLimit = str_repeat('a', 64);

        $this->integration->at('/api/charge')
            ->withIdempotencyKey($atLimit)
            ->post(fn (): array => ['ok' => true]);

        $row = IntegrationRequest::query()->latest()->first();
        $this->assertNotNull($row);
        $this->assertSame($atLimit, $row->idempotency_key);
    }

    public function test_idempotency_key_is_persisted_to_integration_requests(): void
    {
        $this->integration->at('/api/charge')
            ->withIdempotencyKey('order-99')
            ->post(fn (): array => ['ok' => true]);

        $row = IntegrationRequest::query()->latest()->first();
        $this->assertNotNull($row);
        $this->assertSame('order-99', $row->idempotency_key);
    }

    public function test_idempotency_key_column_stays_null_when_not_set(): void
    {
        $this->integration->at('/api/charge')->post(fn (): array => ['ok' => true]);

        $row = IntegrationRequest::query()->latest()->first();
        $this->assertNotNull($row);
        $this->assertNull($row->idempotency_key);
    }

    public function test_same_key_used_across_retry_attempts(): void
    {
        /** @var list<?string> $observed */
        $observed = [];
        $attempt = 0;

        $this->integration->at('/api/charge')
            ->withAttempts(3)
            ->withIdempotencyKey()
            ->post(function (RequestContext $ctx) use (&$observed, &$attempt): array {
                $observed[] = $ctx->idempotencyKey;
                $attempt++;

                if ($attempt < 3) {
                    throw new RetryableException('boom');
                }

                return ['ok' => true];
            });

        $this->assertCount(3, $observed);
        $this->assertNotNull($observed[0]);
        $this->assertSame($observed[0], $observed[1]);
        $this->assertSame($observed[1], $observed[2]);
    }

    public function test_warns_when_provider_does_not_support_idempotency(): void
    {
        Log::spy();

        $this->integration->at('/api/charge')
            ->withIdempotencyKey('order-1')
            ->post(fn (): array => ['ok' => true]);

        Log::shouldHaveReceived('warning')
            ->once()
            ->withArgs(fn (string $message): bool => str_contains($message, 'SupportsIdempotency'));
    }

    public function test_does_not_warn_when_provider_supports_idempotency(): void
    {
        $integration = Integration::create(['provider' => 'idempotent', 'name' => 'Idem']);
        $integration->refresh();

        Log::spy();

        $integration->at('/api/charge')
            ->withIdempotencyKey('order-1')
            ->post(fn (): array => ['ok' => true]);

        Log::shouldNotHaveReceived('warning');
    }

    public function test_does_not_warn_when_no_key_set(): void
    {
        Log::spy();

        $this->integration->at('/api/charge')->post(fn (): array => ['ok' => true]);

        Log::shouldNotHaveReceived('warning');
    }

    public function test_typed_builder_inherits_with_idempotency_key(): void
    {
        $captured = null;

        $this->integration->at('/api/charge')
            ->withIdempotencyKey('order-7')
            ->as(TestOkResponse::class)
            ->post(function (RequestContext $ctx) use (&$captured): array {
                $captured = $ctx->idempotencyKey;

                return ['ok' => true];
            });

        $this->assertSame('order-7', $captured);
    }
}

/**
 * @internal Test fixture: provider that declares native provider-side
 * idempotency support so the warning path is suppressed.
 */
class IdempotentTestProvider implements IntegrationProvider, SupportsIdempotency
{
    public function name(): string
    {
        return 'Idempotent Test Provider';
    }

    public function credentialRules(): array
    {
        return [];
    }

    public function metadataRules(): array
    {
        return [];
    }

    public function credentialDataClass(): ?string
    {
        return null;
    }

    public function metadataDataClass(): ?string
    {
        return null;
    }
}
