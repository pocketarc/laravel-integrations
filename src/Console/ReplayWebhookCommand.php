<?php

declare(strict_types=1);

namespace Integrations\Console;

use Illuminate\Console\Command;
use Illuminate\Http\Request;
use Integrations\Contracts\HandlesWebhooks;
use Integrations\Http\WebhookController;
use Integrations\Models\IntegrationWebhook;

class ReplayWebhookCommand extends Command
{
    protected $signature = 'integrations:replay-webhook {webhookId : The ID of the IntegrationWebhook to replay}';

    protected $description = 'Re-dispatch a stored webhook payload through its handler.';

    public function handle(): int
    {
        $webhookId = $this->argument('webhookId');

        if (! is_string($webhookId) || $webhookId === '') {
            $this->error('A valid webhook ID is required.');

            return self::FAILURE;
        }

        $webhook = IntegrationWebhook::find($webhookId);

        if ($webhook === null) {
            $this->error("IntegrationWebhook #{$webhookId} not found.");

            return self::FAILURE;
        }

        $integration = $webhook->integration;

        if ($integration === null) {
            $this->error('Integration not found for this webhook.');

            return self::FAILURE;
        }

        $provider = $integration->provider();

        if (! $provider instanceof HandlesWebhooks) {
            $this->error("Provider '{$integration->provider}' does not handle webhooks.");

            return self::FAILURE;
        }

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

        $this->info("Replaying webhook for '{$integration->name}'...");

        try {
            WebhookController::invokeHandler($integration, $provider, $request);

            $integration->logOperation(
                operation: 'webhook_replay',
                direction: 'inbound',
                status: 'success',
                summary: "Replayed IntegrationWebhook #{$webhookId}",
            );

            $this->info('Webhook replayed successfully.');

            return self::SUCCESS;
        } catch (\Throwable $e) {
            $integration->logOperation(
                operation: 'webhook_replay',
                direction: 'inbound',
                status: 'failed',
                summary: "Failed to replay IntegrationWebhook #{$webhookId}",
                error: $e->getMessage(),
            );

            $this->error("Replay failed: {$e->getMessage()}");

            return self::FAILURE;
        }
    }
}
