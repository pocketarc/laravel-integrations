# Idempotency

Idempotency keys protect against duplicate writes. Tag a call with a key, and the package guarantees the work runs at most once for that `(integration, key)` pair: a second call with the same key throws `IdempotencyConflict` instead of running the closure again.

## What it protects against

Three failure modes the package can't fix any other way.

The first is the worker race. Two queue workers each pick up the same job a millisecond apart, both check "has this been done?", both see "no", both call the API. The customer sees two charges. With a key, only one INSERT into the unique index wins; the other throws.

The second is partial failure. The API call succeeded and the upstream is now in the new state. Then the local DB write that records the result throws because the connection blipped. The queue retries the action. Without a key, the upstream gets the work done a second time. With a key, the second attempt throws `IdempotencyConflict` and the caller knows to recover the original result rather than re-executing.

The third is intra-attempt SDK retries. Some SDKs (Stripe's, for instance) retry their own HTTP calls on certain network errors. If the same logical attempt sends the request twice, the upstream charges twice. The package passes the key in `RequestContext` so adapters that implement `SupportsIdempotency` can put it on the wire as a backstop, letting the upstream dedupe across SDK-internal retries.

## Setting a key

`withIdempotencyKey()` is on the fluent builder. The key is mandatory and application-meaningful:

```php
use Integrations\Exceptions\IdempotencyConflict;

try {
    $intent = $integration->at('charges')
        ->withIdempotencyKey("charge:order-{$order->id}")
        ->post(fn () => $stripe->paymentIntents->create($params));
} catch (IdempotencyConflict) {
    // already done in a previous attempt; recover the original result via local state or by re-fetching from the upstream
}
```

The key must be stable across retries (`"charge:order-42"`, not `Str::uuid()`). A random per-call value defeats the purpose: the key is meant to identify the *operation*, not the call. If you don't have a domain-meaningful identifier for the work, omit `withIdempotencyKey()` entirely and accept that the call isn't idempotent.

Calling `withIdempotencyKey(null)` is a no-op (no key, no row, no header). Empty string throws.

## What happens when

| Situation                                           | What happens                                                              |
|-----------------------------------------------------|---------------------------------------------------------------------------|
| First call with this key                            | Row INSERTed in `integration_idempotency_keys`. Closure runs. Result returned. |
| Closure returns                                     | Row stays. Future calls with the same key throw `IdempotencyConflict`.    |
| Closure throws                                      | Row deleted. Original exception rethrown. Next attempt is free to retry.  |
| Conflict (row already exists)                       | `IdempotencyConflict` thrown. Closure never runs.                         |
| Empty key                                           | `InvalidArgumentException` thrown.                                        |
| Key longer than 191 characters                      | `InvalidArgumentException` thrown.                                        |
| Called inside `DB::transaction()`                   | `RuntimeException` thrown immediately. See below.                         |
| Called without `withIdempotencyKey()`               | No row, no header, no guard. Today's behaviour for non-keyed calls.       |

## Don't call it inside a transaction

If `withIdempotencyKey()` is called inside a wrapping `DB::transaction()`, the package throws `RuntimeException` before any work runs. Reason: the row INSERT would happen inside that outer transaction, so an outer rollback (for any unrelated reason) would also roll back the row, while the closure has already executed and shipped the upstream side effect. At-most-once is gone.

Call `withIdempotencyKey()` at the top of your action or job, before any `DB::transaction()`.

## Keep non-idempotent provider calls last in the closure

A successful closure keeps the row; a failed closure releases it. So if the closure runs a non-idempotent provider call and then any DB work that can throw, a failure on that DB write releases the row while the upstream side effect already shipped. The next retry duplicates the send.

Keep the provider call as the last thing in the closure, and move follow-up DB writes after it returns:

```php
try {
    $messageId = $integration->at('messages')
        ->withIdempotencyKey("send-receipt:{$order->id}")
        ->post(fn () => $postmark->send($order->email, $template, $data));

    $order->receipts()->create(['message_id' => $messageId]);
} catch (IdempotencyConflict) {
    // already done in a prior attempt
}
```

If you need to do DB work *before* the provider call (validating state, marking the order as queued), do it before `withIdempotencyKey()` so a local failure prevents the row from being created at all.

## Don't swallow exceptions inside the closure

The package decides whether to keep or release the row by watching whether the closure returned or threw. If the closure catches its own exceptions and returns normally (a leftover `try { ... } catch (\Throwable) { return null; }`, typically), the package can't tell the work didn't complete. The row stays, and every future call with the same key throws `IdempotencyConflict` even though nothing was reserved.

Don't:

```php
$integration->at('messages')
    ->withIdempotencyKey("send-receipt:{$order->id}")
    ->post(function () use ($postmark, $order) {
        try {
            return $postmark->send($order->email, $template, $data);
        } catch (\Throwable) {
            return null; // swallowed; row stays even though nothing got sent
        }
    });
```

Let exceptions escape. The release path inside the executor rethrows the original exception unchanged, so your caller (or Laravel's exception handler) sees exactly what the SDK threw.

## Provider support: header-on-the-wire backstop

Not every API has native idempotency. The `SupportsIdempotency` marker contract on the provider tells the package whether the upstream actually dedupes by header, so it knows whether to plumb the key through to the wire as well as into the local row:

| Adapter   | Native dedup? | What the key does end-to-end                                                |
|-----------|---------------|-----------------------------------------------------------------------------|
| Stripe    | Yes           | Local row + `Idempotency-Key` header. Stripe dedupes for ~24h.              |
| GitHub    | No            | Local row only. No header, no upstream-side dedup.                          |
| Postmark  | No            | Local row only. No header. The local row is the only protection here.      |
| Zendesk   | No            | Local row only. Same as Postmark.                                           |

When a caller sets a key against a provider that doesn't implement `SupportsIdempotency`, the package logs a warning. The local row still gives at-most-once, but the upstream won't dedupe on its own (e.g. across multiple SDK-internal retries within one attempt, which is rare but possible).

If you build an adapter for a provider with native idempotency, mark it:

```php
class MyProvider implements IntegrationProvider, SupportsIdempotency
{
    // ...
}
```

That suppresses the warning. Adapters are responsible for getting the key onto the wire, usually through whatever option the SDK accepts (`['idempotency_key' => $ctx->idempotencyKey]` for Stripe).

## Inner retries vs cross-invocation retries

There are two threats and a key handles both.

The first is the package retrying on transient failures (5xx, connection errors). The same key is preserved across attempts inside one `Integration::request()` call, so a transient retry that re-runs the closure submits the same key. The local row stays in place across retries (only released if the whole call fails terminally), and the upstream's dedup collapses both attempts into one if the provider supports it.

The second is cross-invocation: your queued job dies mid-charge and Horizon retries it. The retry runs `withIdempotencyKey("charge:order-42")` again, hits the existing row, and throws `IdempotencyConflict`. Your action catches it and recovers the original result via local state or by re-fetching from the upstream.

## Pruning

`integrations:prune` sweeps `integration_idempotency_keys` rows older than `pruning.idempotency_keys_days` (default 90, matching `requests_days`). Once a row is pruned, the same key can run again, so set this comfortably longer than your longest queue retry window or a delayed retry can slip through after the row is gone:

```php
// config/integrations.php
'pruning' => [
    'requests_days' => 90,
    'logs_days' => 365,
    'idempotency_keys_days' => 90,
    'chunk_size' => 1000,
],
```

If you'd rather treat keys as a permanent ledger, leave `idempotency_keys_days` set to a multi-year value; the table is small (one row per key, no payload) and grows slowly compared to `integration_requests`.

## Inspecting keys

The model is exposed for ad-hoc queries:

```php
use Integrations\Models\IntegrationIdempotencyKey;

IntegrationIdempotencyKey::query()
    ->where('integration_id', $integration->id)
    ->where('key', 'like', 'send-receipt:%')
    ->orderByDesc('created_at')
    ->get();
```

To force a key to be available again (e.g. an operator override after a manual replay), delete the row directly:

```php
IntegrationIdempotencyKey::query()
    ->where('integration_id', $integration->id)
    ->where('key', 'send-receipt:42')
    ->delete();
```

Every `integration_requests` row also records `idempotency_key` for audit. Same value as the row in the keys table for any given keyed call:

```php
IntegrationRequest::where('idempotency_key', 'send-receipt:42')->get();
```

The `integration_requests.idempotency_key` column is append-only audit; the `integration_idempotency_keys.key` column is the unique-constraint enforcer.

## Testing your code

`RefreshDatabase` wraps every test in a transaction, so it'll trip the transaction guard. Use `DatabaseMigrations` instead: it re-runs migrations per test, which is slower, but each test starts at `transactionLevel() === 0`.

Or stub the surrounding action. Move the keyed call into the action under test and unit-test the action by calling it; integration-test the side effect separately.

This package's own test suite uses `defineDatabaseMigrations()` from Testbench, which doesn't wrap in a transaction.
