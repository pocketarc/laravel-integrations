<?php

declare(strict_types=1);

namespace Integrations\Console;

use Illuminate\Console\Command;
use Illuminate\Http\Request;
use Integrations\Contracts\HandlesWebhooks;
use Integrations\Models\IntegrationRequest;

class ReplayWebhookCommand extends Command
{
    protected $signature = 'integrations:replay-webhook {requestId : The ID of the IntegrationRequest to replay}';

    protected $description = 'Re-dispatch a stored webhook payload through its handler.';

    public function handle(): int
    {
        /** @var string $requestId */
        $requestId = $this->argument('requestId');
        $originalRequest = IntegrationRequest::find($requestId);

        if ($originalRequest === null) {
            $this->error("IntegrationRequest #{$requestId} not found.");

            return self::FAILURE;
        }

        $integration = $originalRequest->integration;

        if ($integration === null) {
            $this->error('Integration not found for this request.');

            return self::FAILURE;
        }

        $provider = $integration->provider();

        if (! $provider instanceof HandlesWebhooks) {
            $this->error("Provider '{$integration->provider}' does not handle webhooks.");

            return self::FAILURE;
        }

        $fakeRequest = Request::create(
            uri: $originalRequest->endpoint,
            method: $originalRequest->method,
            content: $originalRequest->request_data ?? '',
        );

        if ($originalRequest->request_data !== null) {
            $decoded = json_decode($originalRequest->request_data, true);
            if (is_array($decoded)) {
                $fakeRequest->merge($decoded);
            }
        }

        $this->info("Replaying webhook for '{$integration->name}'...");

        try {
            $provider->handleWebhook($integration, $fakeRequest);

            $integration->logOperation(
                operation: 'webhook_replay',
                direction: 'inbound',
                status: 'success',
                summary: "Replayed IntegrationRequest #{$requestId}",
            );

            $this->info('Webhook replayed successfully.');

            return self::SUCCESS;
        } catch (\Throwable $e) {
            $integration->logOperation(
                operation: 'webhook_replay',
                direction: 'inbound',
                status: 'failed',
                error: $e->getMessage(),
                summary: "Failed to replay IntegrationRequest #{$requestId}",
            );

            $this->error("Replay failed: {$e->getMessage()}");

            return self::FAILURE;
        }
    }
}
