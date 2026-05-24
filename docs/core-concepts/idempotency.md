# Idempotency

Idempotency keys protect against duplicate writes. Tag a call with a key, and the package guarantees the work runs at most once for that `(integration, key)` pair: a second call with the same key throws `IdempotencyConflict` instead of running the closure again.

## What it protects against

Three failure modes the package can't fix any other way.

The first is the worker race. Two queue workers each pick up the same job a millisecond apart, both check "has this been done?", both see "no", both call the API. The customer sees two charges. With a key, only one INSERT into the unique index wins; the other throws.

The second is partial failure. The API call succeeded and the upstream is now in the new state. Then the local DB write that records the result throws because the connection blipped. The queue retries the action. Without a key, the upstream gets the work done a second time. With a key, the second attempt throws `IdempotencyConflict` carrying the prior response on `$e->priorResponse`, so the caller can finish the local write instead of re-executing the call.

The third is intra-attempt SDK retries. Some SDKs (Stripe's, for instance) retry their own HTTP calls on certain network errors. If the same logical attempt sends the request twice, the upstream charges twice. The package passes the key in `RequestContext` so adapters that implement `SupportsIdempotency` can put it on the wire as a backstop, letting the upstream dedupe across SDK-internal retries.

## Setting a key

`withIdempotencyKey()` is on the fluent builder. When you pass a key, it must be application-meaningful (the package never auto-generates one):

```php
use Integrations\Exceptions\IdempotencyConflict;

try {
    $intent = $integration->at('charges')
        ->withIdempotencyKey("charge:order-{$order->id}")
        ->post(fn () => $stripe->paymentIntents->create($params));
} catch (IdempotencyConflict $e) {
    // Already done in a previous attempt. $e->priorResponse holds the
    // response body the prior call returned; hydrate it into the DTO
    // your downstream code expects.
    $intent = $e->priorResponse !== null
        ? PaymentIntent::from($e->priorResponse)
        : throw new RuntimeException("No recoverable prior for key '{$e->key}'.", previous: $e);
}
```

The key must be stable across retries (`"charge:order-42"`, not `Str::uuid()`). A random per-call value defeats the purpose: the key is meant to identify the *operation*, not the call. If you don't have a domain-meaningful identifier for the work, omit `withIdempotencyKey()` entirely and accept that the call isn't idempotent.

Calling `withIdempotencyKey(null)` is a no-op (no key, no row, no header). Empty string throws.

## What happens when

| Situation                                           | What happens                                                              |
|-----------------------------------------------------|---------------------------------------------------------------------------|
| First call with this key                            | Row INSERTed in `integration_idempotency_keys`. Closure runs. Result returned. |
| Closure returns                                     | Row stays. Future calls with the same key throw `IdempotencyConflict`.    |
| Closure throws                                      | Original exception always rethrown. Row release is best-effort: skipped if the closure leaves an open DB transaction, or if the DELETE itself fails (the row then blocks future attempts until removed manually or by `integrations:prune`). |
| Conflict (row already exists)                       | `IdempotencyConflict` thrown with `$e->priorState`, `$e->priorRowId`, and `$e->priorResponse` populated from the prior attempt. Closure never runs. See [Recovering on conflict](#recovering-on-conflict). |
| Empty key                                           | `InvalidArgumentException` thrown.                                        |
| Key longer than 191 characters                      | `InvalidArgumentException` thrown.                                        |
| Called inside `DB::transaction()`                   | `RuntimeException` thrown immediately. See below.                         |
| Called without `withIdempotencyKey()`               | No row, no header, no guard. Today's behaviour for non-keyed calls.       |

## Don't run the keyed request inside a transaction

When the keyed request executes (at the terminal `->post()`/`->put()`/etc.), the package checks `DB::transactionLevel()` and throws `RuntimeException` if it's non-zero. The check fires at request time, not when you call the `withIdempotencyKey()` setter, since the setter only stores the key. The reason: a row INSERTed inside an outer `DB::transaction()` rolls back when the outer transaction rolls back, while the closure has already executed and shipped the upstream side effect. The next attempt sees no row and runs the closure again. At-most-once is gone.

Run keyed requests at the top of your action or job, before any `DB::transaction()`.

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

If you need to do DB work *before* the provider call (validating state, marking the order as queued), do it before the keyed request runs so a local failure prevents the row from being created at all. The row is INSERTed when the request executes (`->post()`, `->put()`, etc.), not when you chain `withIdempotencyKey()`.

## Don't swallow exceptions inside the closure

The package decides whether to keep or release the row by watching whether the closure returned or threw. If the closure catches its own exceptions and returns normally (a leftover `try { ... } catch (\Throwable) { return null; }`, typically), the package can't tell whether the work completed. The row stays, so every future call with the same key throws `IdempotencyConflict`, even when the upstream call may not have actually landed.

Don't:

```php
$integration->at('messages')
    ->withIdempotencyKey("send-receipt:{$order->id}")
    ->post(function () use ($postmark, $order) {
        try {
            return $postmark->send($order->email, $template, $data);
        } catch (\Throwable) {
            return null; // exception swallowed; delivery status unknown, row remains
        }
    });
```

Let exceptions escape. The release path inside the executor rethrows the original exception unchanged, so your caller (or Laravel's exception handler) sees exactly what the SDK threw.

## Recovering on conflict

`IdempotencyConflict` carries three properties populated automatically when the conflict fires:

- `$e->priorState` — one of `IdempotencyPriorState::NoRow`, `EmptyBody`, `Unparseable`, or `Recovered`. Tells the catch block what shape the prior attempt left things in.
- `$e->priorRowId` — the `integration_requests.id` of the prior row (null only for `NoRow`). Useful for cross-referencing logs.
- `$e->priorResponse` — the decoded JSON body when `priorState === Recovered`; null in every other state.

Dispatch on the state so each branch surfaces the right operator message:

```php
try {
    $issueData = GitHubIssueData::from(
        $integration->at("repos/{$owner}/{$repo}/issues")
            ->withData($params)
            ->withIdempotencyKey("github-issue:zendesk-{$ticket->id}")
            ->post(fn () => $github->issues()->create($owner, $repo, $params)),
    );
} catch (IdempotencyConflict $e) {
    $issueData = match ($e->priorState) {
        IdempotencyPriorState::Recovered => GitHubIssueData::from($e->priorResponse),
        IdempotencyPriorState::NoRow => throw new RuntimeException(
            "No prior integration_requests row for key '{$e->key}' (the idempotency-key row may need manual removal).",
            previous: $e,
        ),
        IdempotencyPriorState::EmptyBody => throw new RuntimeException(
            "Prior integration_requests row #{$e->priorRowId} for key '{$e->key}' has an empty response body, leaving nothing to hydrate.",
            previous: $e,
        ),
        IdempotencyPriorState::Unparseable => throw new RuntimeException(
            "Prior integration_requests row #{$e->priorRowId} for key '{$e->key}' is corrupt or schema-incompatible.",
            previous: $e,
        ),
    };
}

// Continue with the local follow-up write (the one that threw last
// time and left the key held).
$githubTicket = $integration->upsertByExternalId(...);
```

A few things worth knowing:

- The four states are distinct failure modes for an operator. `NoRow` means a key was reserved but no API request ever followed (test fixture, operator pre-seed, race where the original caller crashed). `EmptyBody` means the call landed but produced no body (a 204 reply, or a closure that returned null). `Unparseable` means the body is present but isn't a JSON object (corruption, legacy schema, unexpected closure return shape). Each warrants a different escalation; collapsing them into a single "stuck" message sends ops chasing the wrong cause.
- `priorResponse` is the raw decoded JSON, not a Data object. Hydrating into your DTO is the caller's choice; the exception stays provider-agnostic. If `from()` throws on the `Recovered` branch, that's a fifth failure mode (the persisted JSON is valid but doesn't match your current schema), so catch it and surface accordingly.
- The lookup runs on the conflict path only, not on every keyed write. The cost is one extra DB query, paid only when a conflict actually fires.
- Redaction interacts with this. If your provider implements `RedactsRequestData` and strips fields from `response_data` before persist, `priorResponse` will be the redacted version, which can break hydration or silently produce wrong-shaped DTOs downstream. Don't redact response fields you'd need to recover from; if a field is sensitive and needed for recovery, treat the keyed call as un-recoverable and re-fetch from upstream instead.
- `$integration->getIdempotencyRecovery($key)` is also available directly, returning the same `IdempotencyRecovery` shape attached to the exception. Use it to probe ahead of issuing the write, or to recover outside the catch flow. `getIdempotencyResponse($key)` is the shorthand that returns just the array (or null), for callers that don't care about the state distinction.

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

The second is cross-invocation: your queued job dies mid-charge and Horizon retries it. The retry runs `withIdempotencyKey("charge:order-42")` again, hits the existing row, and throws `IdempotencyConflict`. Your action catches it, reads `$e->priorResponse`, and finishes the local follow-up write that hadn't landed last time. See [Recovering on conflict](#recovering-on-conflict).

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
