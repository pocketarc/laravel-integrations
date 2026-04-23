# Stripe adapter

Wraps the [stripe/stripe-php](https://github.com/stripe/stripe-php) SDK. Covers payment intents, refunds, charges, customers, disputes, events, and webhook endpoints. Methods return Stripe's native typed objects (`\Stripe\Refund`, `\Stripe\PaymentIntent`, etc.), or `\Stripe\Collection<T>` for list endpoints.

Part of the [`pocketarc/laravel-integrations-adapters`](https://github.com/pocketarc/laravel-integrations-adapters) package.

## Installation

```bash
composer require pocketarc/laravel-integrations-adapters
```

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
| `$client->paymentIntents()`     | `->create($amount, $currency, ..., $idempotencyKey?)`          | Create a PaymentIntent. Auto-generates an idempotency key. Returns `\Stripe\PaymentIntent`.              |
|                                 | `->retrieve($id)`                                              | Get a PaymentIntent by id.                                                                               |
|                                 | `->update($id, ...)`                                           | Update editable fields.                                                                                  |
|                                 | `->confirm($id, $paymentMethod?, $idempotencyKey?)`            | Confirm a PaymentIntent.                                                                                 |
|                                 | `->capture($id, $amountToCapture?, $idempotencyKey?)`          | Capture an authorised PaymentIntent.                                                                     |
|                                 | `->cancel($id, $cancellationReason?, $idempotencyKey?)`        | Cancel a PaymentIntent.                                                                                  |
|                                 | `->list($customer?, $limit?)`                                  | Returns `\Stripe\Collection<\Stripe\PaymentIntent>`.                                                     |
| `$client->refunds()`            | `->create($paymentIntent?, $charge?, ..., $idempotencyKey?)`   | Refund against exactly one of `paymentIntent` or `charge`. Auto-generates an idempotency key.            |
|                                 | `->retrieve($id)` / `->list($paymentIntent?, $charge?, $limit?)` | Returns `\Stripe\Refund` or `\Stripe\Collection<\Stripe\Refund>`.                                      |
| `$client->charges()`            | `->retrieve($id)`                                              | Get a Charge.                                                                                            |
|                                 | `->capture($id, $amount?, $receiptEmail?, $idempotencyKey?)`   | Capture an authorised charge. Auto-generates an idempotency key.                                         |
|                                 | `->list($customer?, $paymentIntent?, $limit?)`                 | Returns `\Stripe\Collection<\Stripe\Charge>`.                                                            |
| `$client->customers()`          | `->create(...)` / `->update($id, ...)`                         | Returns `\Stripe\Customer`.                                                                              |
|                                 | `->retrieve($id)` / `->delete($id)`                            | Delete returns the Customer with `$deleted = true`.                                                      |
|                                 | `->list($email?, $limit?)`                                     | Returns `\Stripe\Collection<\Stripe\Customer>`.                                                          |
| `$client->disputes()`           | `->retrieve($id)` / `->update($id, ...)` / `->close($id)`      | Returns `\Stripe\Dispute`.                                                                               |
|                                 | `->list($charge?, $paymentIntent?, $limit?)`                   | Returns `\Stripe\Collection<\Stripe\Dispute>`.                                                           |
| `$client->events()`             | `->retrieve($id)` / `->list($types?, $limit?)`                 | Returns `\Stripe\Event` or `\Stripe\Collection<\Stripe\Event>`.                                          |
| `$client->webhookEndpoints()`   | `->create($url, $enabledEvents, ...)` / `->update($id, ...)`   | Returns `\Stripe\WebhookEndpoint`.                                                                       |
|                                 | `->retrieve($id)` / `->delete($id)` / `->list($limit?)`        | Delete returns the WebhookEndpoint with `$deleted = true`.                                               |

All methods go through `Integration::request()` internally, so every API call is logged, rate-limited, and health-tracked. Every money-moving POST accepts an optional `$idempotencyKey` and auto-generates a UUID when absent, so a transient retry inside one call collapses to a single Stripe-side operation. Pass your own stable key when you need idempotency across separate calls (e.g. re-issuing from a queued job retry).

## Input validation

Resource methods reject empty ids, non-positive amounts and limits, and blank idempotency keys up front with `InvalidArgumentException` rather than letting them hit Stripe. Pass `null` to omit an optional parameter; pass a non-empty value to include.

## Webhooks

Every verified delivery fires a `StripeWebhookReceived` event carrying the integration, event type, event id, and raw payload array. Listen for the event and route by type:

```php
Event::listen(StripeWebhookReceived::class, function (StripeWebhookReceived $event) {
    match ($event->eventType) {
        'payment_intent.succeeded' => MarkOrderPaid::dispatch($event->payload),
        'charge.dispute.created' => OpenDisputeCase::dispatch($event->payload),
        default => null,
    };
});
```

Signature verification uses `\Stripe\Webhook::constructEvent()` with the stored `webhook_secret`. Requests with missing or invalid signatures are rejected before the event fires.

## Health check

`healthCheck()` calls `$sdk->balance->retrieve()` and returns `false` on any error (API error, bad credentials, network failure).
