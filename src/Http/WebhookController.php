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
use Integrations\Support\Config;
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

        $content = $request->getContent();

        if (strlen($content) > Config::webhookMaxPayloadBytes()) {
            return new JsonResponse(['error' => 'Payload too large.'], 413);
        }

        IntegrationContext::push($integration, 'webhook');

        try {
            $deliveryId = $provider->webhookDeliveryId($request) ?? hash('xxh128', $content);
            $eventType = $provider->resolveWebhookEvent($request);

            try {
                $webhook = IntegrationWebhook::create([
                    'integration_id' => $integration->id,
                    'delivery_id' => $deliveryId,
                    'event_type' => $eventType,
                    'payload' => $content,
                    'headers' => $this->allHeaders($request),
                    'status' => 'pending',
                ]);
            } catch (UniqueConstraintViolationException) {
                return new JsonResponse(['status' => 'duplicate']);
            }

            WebhookReceived::dispatch($integration, $providerKey);

            ProcessWebhook::dispatch($webhook->id)->onQueue(Config::webhookQueue());

            return new JsonResponse(['status' => 'queued']);
        } finally {
            IntegrationContext::clear();
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
    private function allHeaders(Request $request): array
    {
        $headers = [];

        foreach ($request->headers->all() as $key => $values) {
            $headers[$key] = $values[0] ?? '';
        }

        return $headers;
    }
}
