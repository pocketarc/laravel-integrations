<?php

declare(strict_types=1);

namespace Integrations\Tests\Fixtures;

use Illuminate\Http\Request;
use Integrations\Contracts\HandlesWebhooks;
use Integrations\Contracts\HasHealthCheck;
use Integrations\Contracts\HasOAuth2;
use Integrations\Contracts\HasScheduledSync;
use Integrations\Contracts\IntegrationProvider;
use Integrations\Models\Integration;
use Integrations\Sync\SyncResult;

class TestProvider implements HandlesWebhooks, HasHealthCheck, HasOAuth2, HasScheduledSync, IntegrationProvider
{
    public bool $syncCalled = false;

    public bool $healthCheckResult = true;

    public bool $webhookVerified = true;

    /** @var array<string, mixed> */
    public array $exchangeCodeResult = ['access_token' => 'new-token', 'token_expires_at' => '2099-01-01T00:00:00Z'];

    /** @var array<string, mixed> */
    public array $refreshTokenResult = ['access_token' => 'refreshed-token', 'token_expires_at' => '2099-01-01T00:00:00Z'];

    public function name(): string
    {
        return 'Test Provider';
    }

    public function credentialRules(): array
    {
        return ['api_key' => 'required|string'];
    }

    public function metadataRules(): array
    {
        return [];
    }

    public function sync(Integration $integration): SyncResult
    {
        $this->syncCalled = true;

        return new SyncResult(1, 0, now());
    }

    public function defaultSyncInterval(): int
    {
        return 15;
    }

    public function defaultRateLimit(): ?int
    {
        return 100;
    }

    public function healthCheck(Integration $integration): bool
    {
        return $this->healthCheckResult;
    }

    public function handleWebhook(Integration $integration, Request $request): mixed
    {
        return ['handled' => true];
    }

    public function verifyWebhookSignature(Integration $integration, Request $request): bool
    {
        return $this->webhookVerified;
    }

    public function resolveWebhookEvent(Request $request): ?string
    {
        return null;
    }

    public function webhookHandlers(): array
    {
        return [];
    }

    public function webhookDeliveryId(Request $request): ?string
    {
        return null;
    }

    public function webhookQueue(): ?string
    {
        return null;
    }

    public function authorizationUrl(Integration $integration, string $redirectUri, string $state): string
    {
        $query = http_build_query(['redirect_uri' => $redirectUri, 'state' => $state]);

        return "https://provider.example.com/oauth/authorize?{$query}";
    }

    public function exchangeCode(Integration $integration, string $code, string $redirectUri): array
    {
        return $this->exchangeCodeResult;
    }

    public function refreshToken(Integration $integration): array
    {
        return $this->refreshTokenResult;
    }

    public function revokeToken(Integration $integration): void {}

    public function refreshThreshold(): int
    {
        return 300;
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
