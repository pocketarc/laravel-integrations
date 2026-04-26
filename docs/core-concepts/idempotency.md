# Idempotency

Idempotency keys protect against duplicate writes. Two cases matter: an internal retry that hits the upstream a second time, and a user double-clicking "Pay" across two tabs so the same charge gets submitted from two separate workers.

The key is a string the upstream uses to dedupe: providers that support it (Stripe, for example) recognise the same key on a second request and return the original response instead of running the work twice. Providers that don't support it still benefit from us recording the key on the request log, so duplicates show up at query time.

## Setting a key

`withIdempotencyKey()` is on the fluent builder. Calling it without an argument auto-generates a UUID for that one call:

```php
$intent = $integration
    ->stripe()
    ->paymentIntents()
    ->create($amount, 'usd', idempotencyKey: 'order-'.$order->id);
```

The Stripe adapter exposes `idempotencyKey` as an explicit named argument on every write method, but the underlying mechanism is the same: it ends up on `PendingRequest::withIdempotencyKey()` before the call fires.

If you're using the builder directly:

```php
$integration->at('payment_intents')
    ->withIdempotencyKey('order-'.$order->id)
    ->withData($params)
    ->post(function (RequestContext $ctx) use ($params) {
        return $sdk->paymentIntents->create(
            $params,
            ['idempotency_key' => $ctx->idempotencyKey],
        );
    });
```

## Three ways to call it

| Call                                      | Result                                                                  |
|-------------------------------------------|-------------------------------------------------------------------------|
| `withIdempotencyKey('order-42')`          | Use the supplied string verbatim.                                       |
| `withIdempotencyKey()` or `(null)`        | Auto-generate a UUID at execute time. Stable across inner retries.      |
| (don't call)                              | No key. Column stays `null`. Adapter passes `null` to the upstream.     |
| `withIdempotencyKey('')`                  | Throws `InvalidArgumentException`. Empty silently disables Stripe dedup.|

The auto-generated UUID covers core's own retry attempts inside one call. For consumer-facing dedup (the double-click case), pass a deterministic key derived from your domain (`"order-{$id}"`, `"refund-{$paymentIntentId}-{$reason}"`, etc.) so two unrelated calls that should be the same call carry the same key.

## What gets persisted

Every `integration_requests` row records `idempotency_key`. That's queryable: pull all attempts that share a key to see the dedup history, or audit which keys an integration has ever issued.

```php
IntegrationRequest::where('idempotency_key', 'order-42')->get();
```

## Provider support

Not every API has native idempotency. The `SupportsIdempotency` contract on the provider tells core whether the upstream actually dedupes by key:

| Adapter   | Native dedup? | Notes                                                                 |
|-----------|---------------|-----------------------------------------------------------------------|
| Stripe    | Yes           | Stripe recognises `Idempotency-Key` and dedupes for ~24h.             |
| GitHub    | No            | Key is plumbed through and persisted, but GitHub doesn't dedupe.      |
| Postmark  | No            | Same as GitHub: decorative.                                           |
| Zendesk   | No            | Same as GitHub: decorative.                                           |

When a caller sets a key against a provider that doesn't implement `SupportsIdempotency`, core logs a warning. The key still ends up in the database for searchability and future our-side dedup, but the upstream won't deduplicate on its own.

If you build an adapter for a provider with native idempotency, mark it:

```php
class MyProvider implements IntegrationProvider, SupportsIdempotency
{
    // ...
}
```

That suppresses the warning. Adapters are responsible for getting the key onto the wire, usually through whatever option the SDK accepts (`['idempotency_key' => $ctx->idempotencyKey]` for Stripe).

## Inner retries vs cross-invocation retries

There are two threats. The first is core retrying on transient failures (5xx, connection errors). The same idempotency key is preserved across attempts inside one `Integration::request()` call, so a transient retry that re-runs the closure submits the same key and the upstream collapses both attempts into one record.

The second is cross-invocation: your queued job dies mid-charge and Horizon retries it. From the package's perspective that's a brand new call with a brand new auto-generated UUID, so there's no protection by default. Pass a deterministic key tied to the originating event (`"charge-{$orderId}-{$attemptedAt}"`) so the retry submits the same key and the provider dedupes.

The second case is also the consumer-facing one: a deterministic key derived from the order ID protects against the double-click scenario regardless of how many separate workers race for the same submission.
