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

## Recovering stranded webhooks

A webhook can be left with no job to process it:

- A queue worker died while processing it. The webhook stays `processing`.
- Its job was lost before a worker picked it up, for example when the queue store was flushed. The webhook stays `pending`.
- Processing failed, for example because the handler threw an exception. The webhook is `failed`.

`integrations:recover-webhooks` dispatches a new job for each webhook that has been `processing` for longer than `webhook.processing_timeout` (default 30 minutes) or `pending` for longer than `webhook.pending_timeout` (default 1 hour):

```bash
php artisan integrations:recover-webhooks
```

If the first job of a pending webhook is still queued, only one of the two jobs can claim the webhook, so the handler runs once. The command dispatches at most one extra job per webhook per `webhook.pending_timeout`, even when two runs of the command overlap.

Failed webhooks are not retried by default. A retry runs the handler again from the start, so raise `webhook.max_attempts` above 1 only if every handler is idempotent. With a higher value, the command moves a failed webhook back to `pending` and dispatches a job when both of these conditions are true:

- The webhook has at least 1 attempt and fewer than `max_attempts`. A job increments `attempts` each time it claims the webhook. A webhook with 0 attempts, such as one that failed before 6.4, is never retried.
- The backoff for the webhook's last attempt has elapsed since that attempt failed. The backoff after attempt N is entry N of `webhook.retry_backoff`. Past the end of the list, the last entry is used.

A failed webhook is retried on the first run of the command after its backoff has elapsed. Add the command to your scheduler:

```php
Schedule::command('integrations:recover-webhooks')->everyFiveMinutes();
```

## Configuration

```php
// config/integrations.php
'webhook' => [
    'prefix' => 'integrations',
    'queue' => 'default',
    'max_payload_bytes' => 1_048_576,  // 1MB
    'processing_timeout' => 1800,      // 30 minutes
    'pending_timeout' => 3600,         // 1 hour
    'max_attempts' => 1,               // no retries
    'retry_backoff' => [60, 300, 1800, 7200, 21600],
    'middleware' => [],
],
```
