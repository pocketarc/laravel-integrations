# Circuit breaker

When an integration starts consistently failing (upstream is down, credentials are revoked, network is partitioned), the breaker stops sending requests until things look healthy again. The motivation is "don't hammer a broken dependency": queued jobs that retry every minute will pile up requests that all fail, generate noise in logs, and (depending on the upstream) potentially degrade things further.

The breaker is on by default with conservative thresholds. You can tune or disable it via config.

## States

| State        | Behaviour                                                                  |
|--------------|----------------------------------------------------------------------------|
| `closed`     | Normal operation. Failures increment a counter.                            |
| `open`       | Requests short-circuit with `CircuitOpenException` before reaching the upstream. |
| `half_open`  | After cooldown elapses, one request is allowed through as a probe.         |

Transitions:

- `closed` -> `open` when `consecutive_failures >= threshold`.
- `open` -> `half_open` when `cooldown_seconds` elapses since the breaker opened. The first request after the cooldown becomes the probe.
- `half_open` -> `closed` if the probe succeeds.
- `half_open` -> `open` if the probe fails. Cooldown clock resets.
- A successful request in `closed` state resets the failure counter to zero.

## What counts as a failure

The breaker is selective so a malformed request from one caller doesn't take an integration offline for everyone:

| Outcome                           | Counted? |
|-----------------------------------|----------|
| 5xx response                      | Yes      |
| 429 (rate limited)                | Yes      |
| Connection error / timeout        | Yes      |
| `RetryableException`              | Yes      |
| 4xx (other than 429)              | No       |
| `CircuitOpenException` itself     | No       |
| `RateLimitExceededException`      | No (it doesn't reach the closure) |

Client errors (400, 401, 403, 404, etc.) are caller bugs: retrying won't change the outcome, and one bad caller shouldn't open the breaker for everyone using the same integration.

## Configuration

```php
// config/integrations.php
'circuit_breaker' => [
    'enabled' => true,
    'threshold' => 5,        // consecutive failures to open
    'cooldown_seconds' => 60, // open -> half_open delay
],
```

| Key | Default | Description |
|-----|---------|-------------|
| `circuit_breaker.enabled` | `true` | Master switch. Set to `false` to disable entirely. |
| `circuit_breaker.threshold` | `5` | Consecutive failures before opening. |
| `circuit_breaker.cooldown_seconds` | `60` | Time to stay open before allowing a half-open probe. |

Disabling it falls back to relying solely on retries and rate limiting. Reasonable for low-volume integrations or while you're still tuning thresholds.

## CircuitOpenException

`Integrations\Exceptions\CircuitOpenException` carries the integration, the open-at timestamp, and the cooldown so callers can decide whether to surface a friendly error or just log:

```php
try {
    $integration->stripe()->refunds()->create(/* ... */);
} catch (CircuitOpenException $e) {
    // Don't bother retrying inside this request; the breaker just told us
    // the integration is down. Stash the work for later instead.
    DeferRefund::dispatch($refundRequest)->delay(now()->addMinutes(2));
}
```

It's intentionally not retryable: the retry handler returns `false` for it, so a `withAttempts(3)` chain immediately throws to the caller without burning attempts on a known-failed integration.

## Composition with other resilience features

Order of checks before a request fires:

1. Cache (response cache hit short-circuits everything).
2. Circuit breaker.
3. Rate limiter (including the [adaptive suppression](/core-concepts/rate-limiting#adaptive-rate-limits) window).
4. The user closure.

The breaker check happens before the rate limiter so an open breaker doesn't waste rate-limit budget on requests that would never reach the upstream anyway.

State is stored in Laravel's cache (the same store used elsewhere by the package), keyed per-integration. That means breaker state is shared across queue workers: one worker tripping the breaker stops the others immediately, which is the point.

The `open` to `half_open` transition uses an atomic probe slot (a separate cache key claimed via `Cache::add()`). When several workers see the cooldown expire at once, only one claims the slot and becomes the probe; the others throw `CircuitOpenException` until the probe outcome lands. If the probe crashes before recording success or failure, the slot expires after `cooldown_seconds * 2` and a future request can claim it.

## Tuning

If you're seeing the breaker trip too often, the usual culprits are:

- Threshold too low for your traffic pattern. A bursty workload that legitimately fails once or twice in a row still shouldn't open the breaker.
- A flaky integration where 5xx is actually expected and the right move is more retries with backoff, not opening the breaker. Bump `threshold` up.

If it doesn't trip when it should:

- Check that failures are being categorised as failures (5xx / connection errors / `RetryableException`) and not 4xx that the upstream is using to signal transient errors.
- For SDK-specific exceptions, implement [`CustomizesRetry`](/advanced/custom-retry) so core knows to treat them as retryable; that also makes them count toward the breaker.
