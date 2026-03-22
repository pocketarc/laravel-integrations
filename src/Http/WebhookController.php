<?php

declare(strict_types=1);

namespace Integrations\Http;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Integrations\Contracts\HandlesWebhooks;
use Integrations\Events\WebhookReceived;
use Integrations\IntegrationManager;
use Integrations\Models\Integration;

class WebhookController extends Controller
{
    public function handle(Request $request, string $provider): JsonResponse
    {
        $manager = app(IntegrationManager::class);

        if (! $manager->has($provider)) {
            return new JsonResponse(['error' => 'Unknown provider.'], 404);
        }

        $providerInstance = $manager->provider($provider);

        if (! $providerInstance instanceof HandlesWebhooks) {
            return new JsonResponse(['error' => 'Provider does not handle webhooks.'], 400);
        }

        $integration = Integration::query()
            ->forProvider($provider)
            ->active()
            ->first();

        if ($integration === null) {
            return new JsonResponse(['error' => 'No active integration found.'], 404);
        }

        return $this->processWebhook($integration, $providerInstance, $request, $provider);
    }

    public function handleForIntegration(Request $request, string $provider, int $id): JsonResponse
    {
        $manager = app(IntegrationManager::class);

        if (! $manager->has($provider)) {
            return new JsonResponse(['error' => 'Unknown provider.'], 404);
        }

        $providerInstance = $manager->provider($provider);

        if (! $providerInstance instanceof HandlesWebhooks) {
            return new JsonResponse(['error' => 'Provider does not handle webhooks.'], 400);
        }

        $integration = Integration::find($id);

        if ($integration === null || $integration->provider !== $provider || ! $integration->is_active) {
            return new JsonResponse(['error' => 'Integration not found.'], 404);
        }

        return $this->processWebhook($integration, $providerInstance, $request, $provider);
    }

    private function processWebhook(
        Integration $integration,
        HandlesWebhooks $provider,
        Request $request,
        string $providerKey,
    ): JsonResponse {
        if (! $provider->verifyWebhookSignature($integration, $request)) {
            return new JsonResponse(['error' => 'Invalid signature.'], 403);
        }

        WebhookReceived::dispatch($integration, $providerKey);

        try {
            $result = $provider->handleWebhook($integration, $request);

            $integration->logOperation(
                operation: 'webhook',
                direction: 'inbound',
                status: 'success',
                summary: "Webhook from {$providerKey} handled successfully.",
            );

            return new JsonResponse($result ?? ['status' => 'ok']);
        } catch (\Throwable $e) {
            $integration->logOperation(
                operation: 'webhook',
                direction: 'inbound',
                status: 'failed',
                summary: "Webhook from {$providerKey} failed.",
                error: $e->getMessage(),
            );

            throw $e;
        }
    }
}
