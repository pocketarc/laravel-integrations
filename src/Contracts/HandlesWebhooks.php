<?php

declare(strict_types=1);

namespace Integrations\Contracts;

use Illuminate\Http\Request;
use Integrations\Models\Integration;

interface HandlesWebhooks
{
    /**
     * Handle an incoming webhook for the given integration.
     */
    public function handleWebhook(Integration $integration, Request $request): mixed;

    /**
     * Verify the webhook signature is valid.
     */
    public function verifyWebhookSignature(Integration $integration, Request $request): bool;

    /**
     * Extract the event type from the incoming webhook request.
     * Return null if the provider does not distinguish event types.
     */
    public function resolveWebhookEvent(Request $request): ?string;

    /**
     * Map webhook event types to handler callables.
     * Return an empty array to use handleWebhook() for all events.
     *
     * @return array<string, class-string|callable(Integration, Request): mixed|array{class-string, string}>
     */
    public function webhookHandlers(): array;

    /**
     * Extract a unique delivery ID from the webhook request for deduplication.
     * Return null to fall back to payload hash.
     */
    public function webhookDeliveryId(Request $request): ?string;
}
