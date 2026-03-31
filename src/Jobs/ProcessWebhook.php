<?php

declare(strict_types=1);

namespace Integrations\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Http\Request;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Integrations\Contracts\HandlesWebhooks;
use Integrations\Http\WebhookController;
use Integrations\Models\IntegrationWebhook;
use Integrations\Support\IntegrationContext;

class ProcessWebhook implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public function __construct(
        public readonly int $webhookId,
    ) {}

    public function handle(): void
    {
        $webhook = IntegrationWebhook::with('integration')->find($this->webhookId);

        if ($webhook === null) {
            return;
        }

        $integration = $webhook->integration;

        if ($integration === null) {
            $webhook->markFailed('Integration not found.');

            return;
        }

        $provider = $integration->provider();

        if (! $provider instanceof HandlesWebhooks) {
            $webhook->markFailed('Provider does not support webhooks.');

            return;
        }

        if (! $webhook->markProcessing()) {
            return;
        }

        IntegrationContext::push($integration, 'webhook');

        try {
            $request = Request::create(
                uri: '/',
                method: 'POST',
                content: $webhook->payload,
            );

            foreach ($webhook->headers as $key => $value) {
                if (is_string($value)) {
                    $request->headers->set($key, $value);
                }
            }

            WebhookController::invokeHandler($integration, $provider, $request);

            $webhook->markProcessed();

            try {
                $integration->logOperation(
                    operation: 'webhook',
                    direction: 'inbound',
                    status: 'success',
                    summary: "Queued webhook from {$integration->provider} processed successfully.",
                );
            } catch (\Throwable $logException) {
                Log::warning("Failed to log successful webhook operation for '{$integration->name}': {$logException->getMessage()}");
            }
        } catch (\Throwable $e) {
            $webhook->markFailed($e->getMessage());

            try {
                $integration->logOperation(
                    operation: 'webhook',
                    direction: 'inbound',
                    status: 'failed',
                    summary: "Queued webhook from {$integration->provider} failed.",
                    error: $e->getMessage(),
                );
            } catch (\Throwable $logException) {
                Log::warning("Failed to log failed webhook operation for '{$integration->name}': {$logException->getMessage()}");
            }

            Log::error("Webhook processing failed for '{$integration->name}': {$e->getMessage()}", [
                'integration_id' => $integration->id,
                'webhook_id' => $webhook->id,
            ]);

            throw $e;
        } finally {
            IntegrationContext::clear();
        }
    }
}
