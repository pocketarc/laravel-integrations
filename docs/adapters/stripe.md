# Stripe adapter

Wraps the [stripe/stripe-php](https://github.com/stripe/stripe-php) SDK. Covers payment intents, refunds, charges, customers, disputes, events, and webhook endpoints. Returns Stripe's native typed objects directly rather than wrapping them in local Data classes; see [Building adapters](/adapters/building-adapters#return-types) for when each approach fits.

Part of the [`pocketarc/laravel-integrations-adapters`](https://github.com/pocketarc/laravel-integrations-adapters) package.

## Setup

```php
// config/integrations.php
'providers' => [
    'stripe' => \Integrations\Adapters\Stripe\StripeProvider::class,
],
```

```php
$integration = Integration::create([
    'provider' => 'stripe',
    'name' => 'Stripe Live',
    'credentials' => [
        'api_key' => 'sk_live_...',
        'webhook_secret' => 'whsec_...',
        'publishable_key' => 'pk_live_...', // optional
    ],
]);
```

| Credentials        | Description                                                        |
|--------------------|--------------------------------------------------------------------|
| `api_key`          | Secret key. Must start with `sk_live_` or `sk_test_`.              |
| `webhook_secret`   | Endpoint secret. Must start with `whsec_`.                         |
| `publishable_key`  | Optional. Must start with `pk_live_` or `pk_test_` when set.       |

No metadata is required; the credential prefixes gate live vs test mode.

## Resources

```php
$client = new StripeClient($integration);
```

| Resource                        | Method                                                         | Description                                                                                              |
|---------------------------------|----------------------------------------------------------------|----------------------------------------------------------------------------------------------------------|
| `$client->paymentIntents()`     | `->create($amount, $currency, ...)`                            | Create a PaymentIntent. Auto-generates an idempotency key. Returns `\Stripe\PaymentIntent`.              |
|                                 | `->retrieve($id)`                                              | Get a PaymentIntent by id.                                                                               |
|                                 | `->update($id, ...)`                                           | Update editable fields.                                                                                  |
|                                 | `->confirm($id, $paymentMethod?)`                              | Confirm a PaymentIntent. Accepts an idempotency key.                                                     |
|                                 | `->capture($id, $amountToCapture?)`                            | Capture an authorised PaymentIntent. Accepts an idempotency key.                                         |
|                                 | `->cancel($id, $cancellationReason?)`                          | Cancel a PaymentIntent. Accepts an idempotency key.                                                      |
|                                 | `->list($customer?, $limit?)`                                  | Returns `\Stripe\Collection<\Stripe\PaymentIntent>`.                                                     |
| `$client->refunds()`            | `->create($paymentIntent?, $charge?, ...)`                     | Refund against exactly one of `paymentIntent` or `charge`. Auto-generates an idempotency key.            |
|                                 | `->retrieve($id)` / `->list($paymentIntent?, $charge?, $limit?)` | Returns `\Stripe\Refund` or `\Stripe\Collection<\Stripe\Refund>`.                                      |
| `$client->charges()`            | `->retrieve($id)`                                              | Get a Charge.                                                                                            |
|                                 | `->capture($id, $amount?, $receiptEmail?)`                     | Capture an authorised charge. Auto-generates an idempotency key.                                         |
|                                 | `->list($customer?, $paymentIntent?, $limit?)`                 | Returns `\Stripe\Collection<\Stripe\Charge>`.                                                            |
| `$client->customers()`          | `->create(...)` / `->update($id, ...)`                         | Returns `\Stripe\Customer`.                                                                              |
|                                 | `->retrieve($id)` / `->delete($id)`                            | Delete returns the Customer with `$deleted = true`.                                                      |
|                                 | `->list($email?, $limit?)`                                     | Returns `\Stripe\Collection<\Stripe\Customer>`.                                                          |
| `$client->disputes()`           | `->retrieve($id)` / `->update($id, ...)` / `->close($id)`      | Returns `\Stripe\Dispute`.                                                                               |
|                                 | `->list($charge?, $paymentIntent?, $limit?)`                   | Returns `\Stripe\Collection<\Stripe\Dispute>`.                                                           |
| `$client->events()`             | `->retrieve($id)` / `->list($types?, $limit?)`                 | Returns `\Stripe\Event` or `\Stripe\Collection<\Stripe\Event>`.                                          |
| `$client->webhookEndpoints()`   | `->create($url, $enabledEvents, ...)` / `->update($id, ...)`   | Returns `\Stripe\WebhookEndpoint`.                                                                       |
|                                 | `->retrieve($id)` / `->delete($id)` / `->list($limit?)`        | Delete returns the WebhookEndpoint with `$deleted = true`.                                               |

All methods go through `Integration::request()` internally, so every API call is logged, rate-limited, and health-tracked. The core retries GET 3 times and non-GET 1 time. Because a non-GET retry re-runs the closure, every money-moving POST accepts an optional idempotency key and auto-generates a UUID when absent, so a transient retry inside a single call collapses to one Stripe-side operation.

## Input validation

Resource methods reject empty ids, non-positive amounts and limits, and blank idempotency keys up front with `InvalidArgumentException` rather than letting them hit Stripe. Pass `null` to omit an optional parameter; pass a non-empty value to include.

## Webhooks

`StripeProvider` implements `HandlesWebhooks` with a generic dispatch model: every verified delivery fires a `StripeWebhookReceived` event carrying the integration, event type, event id, and raw payload array. Consumers listen for the event and route by type in their own code. `webhookHandlers()` returns an empty array by design, so the core falls back to `handleWebhook()` and dispatches the event for every type.

```php
Event::listen(StripeWebhookReceived::class, function (StripeWebhookReceived $event) {
    match ($event->eventType) {
        'payment_intent.succeeded' => MarkOrderPaid::dispatch($event->payload),
        'charge.dispute.created' => OpenDisputeCase::dispatch($event->payload),
        default => null,
    };
});
```

Signature verification uses `\Stripe\Webhook::constructEvent()` with the stored `webhook_secret`. Requests with missing or invalid signatures return `false` from `verifyWebhookSignature()` and are rejected by the core webhook controller.

## Health check

`healthCheck()` calls `$sdk->balance->retrieve()`. Any `\Throwable` (Stripe API error, bad credentials, network failure, or the `RuntimeException` thrown when the stored credentials aren't a `StripeCredentials` instance) returns `false`.

## Return types

Every resource method returns a Stripe SDK object (`\Stripe\PaymentIntent`, `\Stripe\Refund`, etc.) or `\Stripe\Collection<T>` for list endpoints. There are no local Data classes. The SDK already models its types with phpdoc generics, and `\Stripe\StripeObject` implements `JsonSerializable` so request logs still contain a full JSON body. Runtime narrowing of the `mixed` return from `Integration::request()` happens via a small `expectInstance()` helper on `StripeResource`.
