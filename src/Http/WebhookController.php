<?php

declare(strict_types=1);

namespace Integrations\Http;

use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Integrations\Contracts\HandlesWebhooks;
use Integrations\Events\WebhookReceived;
use Integrations\IntegrationManager;
use Integrations\Jobs\ProcessWebhook;
use Integrations\Models\Integration;
use Integrations\Models\IntegrationWebhook;
use Integrations\Support\IntegrationContext;

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

        IntegrationContext::push($integration, 'webhook');

        $content = $request->getContent();
        $deliveryId = $provider->webhookDeliveryId($request) ?? hash('xxh128', $content);
        $eventType = $provider->resolveWebhookEvent($request);

        try {
            $webhook = IntegrationWebhook::create([
                'integration_id' => $integration->id,
                'delivery_id' => $deliveryId,
                'event_type' => $eventType,
                'payload' => $content,
                'headers' => $this->relevantHeaders($request),
                'status' => 'pending',
            ]);
        } catch (UniqueConstraintViolationException) {
            return new JsonResponse(['status' => 'duplicate']);
        }

        WebhookReceived::dispatch($integration, $providerKey);

        $queue = $provider->webhookQueue();
        if ($queue !== null) {
            ProcessWebhook::dispatch($webhook->id)->onQueue($queue);

            return new JsonResponse(['status' => 'queued']);
        }

        return $this->processSynchronously($integration, $provider, $request, $providerKey, $webhook);
    }

    private function processSynchronously(
        Integration $integration,
        HandlesWebhooks $provider,
        Request $request,
        string $providerKey,
        IntegrationWebhook $webhook,
    ): JsonResponse {
        $webhook->markProcessing();

        try {
            $result = $this->invokeHandler($integration, $provider, $request);

            $webhook->markProcessed();

            $integration->logOperation(
                operation: 'webhook',
                direction: 'inbound',
                status: 'success',
                summary: "Webhook from {$providerKey} handled successfully.",
            );

            return new JsonResponse($result ?? ['status' => 'ok']);
        } catch (\Throwable $e) {
            $webhook->markFailed($e->getMessage());

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

    /**
     * Resolve and invoke the appropriate webhook handler.
     */
    public static function invokeHandler(
        Integration $integration,
        HandlesWebhooks $provider,
        Request $request,
    ): mixed {
        $eventType = $provider->resolveWebhookEvent($request);
        $handlers = $provider->webhookHandlers();

        if ($eventType !== null && isset($handlers[$eventType])) {
            $handler = $handlers[$eventType];

            if (is_string($handler) && class_exists($handler)) {
                $handler = app($handler);
            }

            if (is_callable($handler)) {
                return $handler($integration, $request);
            }
        }

        if ($eventType !== null && $handlers !== [] && ! isset($handlers[$eventType])) {
            return ['status' => 'ignored'];
        }

        return $provider->handleWebhook($integration, $request);
    }

    /**
     * @return array<string, string>
     */
    private function relevantHeaders(Request $request): array
    {
        $headers = [];
        $relevant = ['content-type', 'user-agent', 'x-signature', 'x-hub-signature', 'x-hub-signature-256'];

        foreach ($request->headers->all() as $key => $values) {
            $lower = strtolower($key);
            if (in_array($lower, $relevant, true) || str_starts_with($lower, 'x-')) {
                $headers[$key] = $values[0] ?? '';
            }
        }

        return $headers;
    }
}
