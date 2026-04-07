# Webhooks

Providers implement the `HandlesWebhooks` interface to receive inbound webhooks with signature verification, event routing, and deduplication.

## The HandlesWebhooks interface

```php
use Integrations\Contracts\HandlesWebhooks;

interface HandlesWebhooks
{
    public function handleWebhook(Integration $integration, Request $request): mixed;
    public function verifyWebhookSignature(Integration $integration, Request $request): bool;
    public function resolveWebhookEvent(Request $request): ?string;
    public function webhookHandlers(): array;
    public function webhookDeliveryId(Request $request): ?string;
}
```

## Routes

Webhook routes are registered automatically:

| Route                                             | Name                            | Purpose                      |
|---------------------------------------------------|---------------------------------|------------------------------|
| `GET\|POST /integrations/{provider}/webhook`      | `integrations.webhook`          | Generic provider webhook     |
| `GET\|POST /integrations/{provider}/{id}/webhook` | `integrations.webhook.specific` | Integration-specific webhook |

Point your external service's webhook URL at:

```
https://yourapp.com/integrations/zendesk/webhook
https://yourapp.com/integrations/zendesk/42/webhook  # for a specific integration
```

Webhook routes have no middleware by default -- most providers can't handle CSRF or session auth.

## Webhook lifecycle

When a webhook arrives:

1. The provider is resolved from the URL
2. Signature is verified via `verifyWebhookSignature()`
3. The webhook is persisted to `integration_webhooks`
4. A `WebhookReceived` event is dispatched
5. A `ProcessWebhook` job is dispatched to the configured queue
6. The job calls your `handleWebhook()` (or routed handler)
7. The result is logged in `IntegrationLog`

## Signature verification

```php
class StripeProvider implements IntegrationProvider, HandlesWebhooks
{
    public function verifyWebhookSignature(Integration $integration, Request $request): bool
    {
        $secret = $integration->credentialsArray()['webhook_secret'];

        return hash_equals(
            hash_hmac('sha256', $request->getContent(), $secret),
            $request->header('Stripe-Signature', ''),
        );
    }
}
```

## Event type routing

Providers can declare how to extract the event type from the payload and route to specific handlers:

```php
class StripeProvider implements IntegrationProvider, HandlesWebhooks
{
    public function resolveWebhookEvent(Request $request): ?string
    {
        return $request->input('type'); // e.g. 'invoice.paid'
    }

    public function webhookHandlers(): array
    {
        return [
            'invoice.paid' => HandleInvoicePaid::class,
            'customer.created' => HandleCustomerCreated::class,
        ];
    }
}
```

## Deduplication

Providers can declare a deduplication key to prevent processing the same webhook twice:

```php
public function webhookDeliveryId(Request $request): ?string
{
    return $request->header('X-Webhook-Id');
}
```

When a duplicate is detected, the webhook is stored but not processed.

## Queue processing

All webhooks are processed asynchronously via the `ProcessWebhook` job:

```php
// config/integrations.php
'webhook' => [
    'queue' => 'webhooks',
],
```

Payloads exceeding `webhook.max_payload_bytes` (default 1MB) are rejected with a 413 response.

## Replaying webhooks

Stored webhooks can be replayed by their webhook ID:

```bash
php artisan integrations:replay-webhook {webhookId}
```

This reconstructs the request from stored data and re-dispatches it through `handleWebhook()`.

## Recovering stale webhooks

If a queue worker dies mid-processing, a webhook can get stuck in `processing` status. The recovery command finds these and re-queues them:

```bash
php artisan integrations:recover-webhooks
```

Add to your scheduler for automatic recovery:

```php
Schedule::command('integrations:recover-webhooks')->hourly();
```

A webhook is considered stale after `webhook.processing_timeout` seconds (default 1800 / 30 minutes).

## Configuration

```php
// config/integrations.php
'webhook' => [
    'prefix' => 'integrations',
    'queue' => 'default',
    'max_payload_bytes' => 1_048_576,  // 1MB
    'processing_timeout' => 1800,      // 30 minutes
    'middleware' => [],
],
```
