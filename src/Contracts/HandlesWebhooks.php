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
}
