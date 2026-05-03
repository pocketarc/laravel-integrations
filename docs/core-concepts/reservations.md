# Reservations

`withReservation()` reserves a `(integration, key)` row in our database before running a callback, and refuses to run the callback again for the same key. It's the application-level twin of [transport-level idempotency keys](/core-concepts/idempotency): use it when the upstream provider doesn't dedupe by header (Zendesk, Postmark, most non-payments APIs) and you still need at-most-once semantics for a customer-visible side effect.

## What it protects against

Two failure modes the package can't fix any other way.

The first is the worker race. Worker A reads "no comment yet, post one"; worker B reads the same thing a millisecond later; both call the API; the customer sees two duplicate comments. The unique index on `(integration_id, key)` lets exactly one of them win.

The second is partial failure. The API call succeeded and added a comment to the remote ticket. Then `Comment::create(...)` threw locally because the connection blipped. The queue retries the action. Without a reservation, the upstream gets a second comment.

`integration_requests.idempotency_key` is append-only audit and only exists *after* the call, so it can't dedupe before the fact.

## Setting one up

```php
use Integrations\Exceptions\ReservationConflict;

try {
    $comment = $integration->withReservation("mark-duplicate:{$ticket->id}:zendesk", function () use ($client, $ticket, $body) {
        return $client->comments()->add($ticket->id, $body);
    });
} catch (ReservationConflict) {
    // another worker (or an earlier attempt of this job) already posted the comment
}
```

The key is whatever uniquely identifies the work. Scope it to whatever makes the work unique end-to-end: `"close-ticket:{$ticket->id}"`, `"refund:{$paymentIntentId}:{$reason}"`, `"send-confirmation:{$orderId}"`. Two integrations using the same key are independent: `close-ticket:42` against Zendesk and the same string against GitHub each get their own row.

## Outcomes

| Situation                                           | What happens                                                              |
|-----------------------------------------------------|---------------------------------------------------------------------------|
| First call with this key                            | Row INSERTed. Callback runs. Result returned.                             |
| Callback returns                                    | Row stays. Future calls with the same key throw `ReservationConflict`.    |
| Callback throws                                     | Row deleted. Original exception rethrown. Next attempt is free to retry.  |
| Conflict (row already exists)                       | `ReservationConflict` thrown. Callback never runs.                        |
| Empty key                                           | `InvalidArgumentException` thrown.                                        |
| Called inside `DB::transaction()`                   | `RuntimeException` thrown immediately. See below.                         |

## When to use this vs `withIdempotencyKey()`

| Need                                                              | Use                              |
|-------------------------------------------------------------------|----------------------------------|
| Provider supports `Idempotency-Key` and you want them to dedupe   | `->withIdempotencyKey($key)`     |
| Provider doesn't dedupe and you need at-most-once on your side    | `withReservation($key, $fn)`     |
| Both                                                              | Use both. Same key works fine.   |

`withIdempotencyKey()` lives on the [fluent request builder](/core-concepts/idempotency) and dedupes upstream. `withReservation()` lives on the integration model and dedupes locally before the call goes out.

## Don't call it inside a transaction

`withReservation()` checks `DB::transactionLevel()` and throws if it's not zero. If the INSERT happens inside an outer `DB::transaction()` and the outer transaction later rolls back (for an unrelated reason), the reservation row rolls back with it. The callback already ran, the side effect already shipped, and the next attempt sees no row and runs the callback again. At-most-once is gone.

Call `withReservation()` at the top of your action or job, before any `DB::transaction()`. If you need to do DB work tied to the reservation's success, do it inside the callback:

```php
$integration->withReservation("send-receipt:{$order->id}", function () use ($order) {
    DB::transaction(function () use ($order) {
        $messageId = $postmark->send($order->email, $template, $data);
        $order->receipts()->create(['message_id' => $messageId]);
    });
});
```

## Testing

`RefreshDatabase` wraps every test in a transaction, so it'll trip the transaction guard. Two options.

Use `DatabaseMigrations` instead: it re-runs migrations per test, which is slower, but each test starts at `transactionLevel() === 0`.

Or stub the surrounding action. Move the `withReservation()` call into the action under test and unit-test the action by calling it; integration-test the side effect separately.

This package's own test suite uses `defineDatabaseMigrations()` from Testbench, which doesn't wrap in a transaction.

## Pruning

`integrations:prune` sweeps `integration_idempotency_reservations` rows older than `pruning.reservations_days` (default 90, matching `requests_days`). Once a row is pruned, the same key can run again, so set this comfortably longer than your longest queue retry window or a delayed retry can slip through after the row is gone:

```php
// config/integrations.php
'pruning' => [
    'requests_days' => 90,
    'logs_days' => 365,
    'reservations_days' => 90,
    'chunk_size' => 1000,
],
```

If you'd rather treat reservations as a permanent ledger, leave `reservations_days` set to a multi-year value; the table is small (one row per reservation, no payload) and grows slowly compared to `integration_requests`.

## Inspecting reservations

The model is exposed for ad-hoc queries:

```php
use Integrations\Models\IntegrationIdempotencyReservation;

IntegrationIdempotencyReservation::query()
    ->where('integration_id', $integration->id)
    ->where('key', 'like', 'mark-duplicate:%')
    ->orderByDesc('created_at')
    ->get();
```

To force a key to be available again (e.g. an operator override after a manual replay), delete the row directly:

```php
IntegrationIdempotencyReservation::query()
    ->where('integration_id', $integration->id)
    ->where('key', 'send-receipt:42')
    ->delete();
```
