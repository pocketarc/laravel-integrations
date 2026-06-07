# Circuit breaker

When an integration's upstream starts failing (the API is down, the network is partitioned, requests are timing out), the breaker stops sending requests until things look healthy again. The motivation is "don't hammer a broken dependency": queued jobs that retry every minute will pile up requests that all fail, generate noise in logs, and (depending on the upstream) potentially degrade things further.

The breaker is an *availability* detector. It cares about one question: is the dependency serving requests? A 429 (you're being throttled) or a 400 (you sent something wrong) both mean the upstream is healthy and answering. Those belong to [rate limiting](/core-concepts/rate-limiting) and your own validation, not the breaker. See [What counts as a failure](#what-counts-as-a-failure).

The breaker is on by default with conservative thresholds. You can tune, switch strategy, disable, or override it at runtime via config.

## States

| State        | Behaviour                                                                  |
|--------------|----------------------------------------------------------------------------|
| `closed`     | Normal operation. Failures are accounted by the active strategy.           |
| `open`       | Requests short-circuit with `CircuitOpenException` before reaching the upstream. |
| `half_open`  | After cooldown elapses, one request is allowed through as a probe.         |

Transitions:

- `closed` -> `open` when the active strategy trips (see [Strategies](#strategies)).
- `open` -> `half_open` when `cooldown_seconds` elapses since the breaker opened. The first request after the cooldown becomes the probe.
- `half_open` -> `closed` if the probe succeeds.
- `half_open` -> `open` if the probe fails (with an upstream fault). Cooldown clock resets.
- A successful request in `closed` state resets the breaker's accounting.

## Strategies

The breaker tracks failures in one of two ways, set via `circuit_breaker.strategy`:

**`rate` (default)**: opens when the failure *rate* over a rolling window crosses `failure_rate_threshold` percent, once at least `minimum_requests` have been seen in the window. Best for steady traffic: it tolerates the occasional blip but reacts to genuine degradation, even when successes are interleaved with failures.

**`count`**: opens after `threshold` *consecutive* upstream failures. A single success resets the counter. Volume-independent, so it trips even for low-traffic integrations.

::: warning Low-volume integrations under the rate strategy
The rate strategy can only trip once `minimum_requests` have landed in the window. An integration that makes just a handful of requests per window (an infrequent scheduled sync, or a dependency that's down and only attempted twice) will never reach the floor, so the breaker won't open. If you need volume-independent tripping, set `strategy` to `'count'`.
:::

## What counts as a failure

Every failure is run through one classifier ([`Integrations\Support\FailureClassifier`](/reference/contracts#classifiesfailures)) that sorts it into a `FailureClass`. The same verdict drives both the breaker and [health tracking](/core-concepts/health-monitoring), so the two can never disagree about what "broken" means.

| `FailureClass` | Examples | Counts toward breaker + health? |
|----------------|----------|--------------------------------|
| `Upstream`     | 5xx (except 501), connection refused, timeout | **Yes** |
| `Throttle`     | HTTP 429, provider rate-limit | No. The upstream is healthy, just pacing you |
| `Client`       | 4xx other than 429 (400, 401, 403, 404, 422…), and 501 | No. Retrying won't change the outcome |
| `Unknown`      | No HTTP status and no recognisable signal | No. Not enough evidence to penalise |

Only `Upstream` counts. This is deliberate: a 429 is the rate limiter's concern (it already honours `Retry-After`), and a malformed request from one caller shouldn't pull an integration offline for everyone sharing it. An `Unknown` failure (an SDK exception core can't read) is treated as no evidence rather than assumed-broken, so a buggy caller spraying SDK validation errors can't trip the breaker.

How the classifier reaches a verdict, first match wins:

1. A `CircuitOpenException` is always `Unknown` (the breaker never feeds itself).
2. The provider's own [`ClassifiesFailures`](#provider-classification) verdict, if it returns one.
3. An HTTP status, mapped by range. It's read from Laravel/Guzzle/Symfony exceptions, or duck-typed from common SDK accessors (`getStatusCode()`, `getHttpStatus()`, `getHttpStatusCode()`, a wrapped PSR-7 response, or `getCode()` when it's a valid status).
4. A `ConnectionException` anywhere in the chain -> `Upstream`.
5. Otherwise -> `Unknown`.

Note that retryability and breaker-failure are now separate axes. A `RetryableException` is still retried (see [Retries](/core-concepts/retries)), but it is *not* automatically an upstream fault: a 429 wrapped as retryable backs off without tripping the breaker.

### Provider classification

Core can read HTTP-shaped exceptions and duck-type the common SDK accessors, but some SDKs signal failures in ways it can't see: a throttle that arrives as a generic 403, say, or a connection error with no status. Implement [`ClassifiesFailures`](/reference/contracts#classifiesfailures) on your provider to map those precisely:

```php
use Integrations\Contracts\ClassifiesFailures;
use Integrations\Enums\FailureClass;

class AcmeProvider implements ClassifiesFailures, /* … */
{
    public function classifyFailure(\Throwable $e): ?FailureClass
    {
        if ($e instanceof AcmeRateLimitException) {
            return FailureClass::Throttle;
        }

        if ($e instanceof AcmeConnectionException) {
            return FailureClass::Upstream;
        }

        // Defer everything else to the default classifier.
        return null;
    }
}
```

Return `null` to fall back to the default logic. `FailureClass::fromStatus($code)` gives you the canonical status mapping if you've extracted a status yourself.

## Configuration

```php
// config/integrations.php
'circuit_breaker' => [
    'enabled' => true,
    'strategy' => 'rate',           // 'rate' (default) | 'count'

    // rate strategy
    'time_window' => 60,            // rolling failure window (seconds)
    'failure_rate_threshold' => 50, // percent (1-100) that opens the breaker
    'minimum_requests' => 10,       // requests in the window before it can trip

    // count strategy
    'threshold' => 5,               // consecutive upstream failures to open

    'cooldown_seconds' => 60,       // open -> half_open delay
    'overrides_enabled' => true,    // honour runtime overrides
],
```

| Key | Default | Description |
|-----|---------|-------------|
| `circuit_breaker.enabled` | `true` | Master switch. Set to `false` to disable entirely. |
| `circuit_breaker.strategy` | `'rate'` | `'rate'` or `'count'`. |
| `circuit_breaker.time_window` | `60` | Rate strategy: rolling failure window (seconds). |
| `circuit_breaker.failure_rate_threshold` | `50` | Rate strategy: failure percentage that opens the breaker. |
| `circuit_breaker.minimum_requests` | `10` | Rate strategy: requests in the window before it can trip. |
| `circuit_breaker.threshold` | `5` | Count strategy: consecutive upstream failures before opening. |
| `circuit_breaker.cooldown_seconds` | `60` | Time to stay open before allowing a half-open probe. |
| `circuit_breaker.overrides_enabled` | `true` | Honour per-integration [runtime overrides](#runtime-overrides). |

Disabling it falls back to relying solely on retries and rate limiting. Reasonable for low-volume integrations or while you're still tuning thresholds.

## Runtime overrides

Sometimes you need to act on a specific integration *now*, without a redeploy or a config change: pull a flaky vendor offline during an incident, or force a known-good one to keep serving while you investigate. Overrides live in columns on the integration row, so they survive a cache flush, and they sit *above* the state machine: a forced-open breaker is never auto-closed by a probe, and a forced-closed one never trips.

```php
$integration->forceCircuitOpen();              // block all requests
$integration->forceCircuitOpen(now()->addHour()); // …for the next hour, then auto
$integration->forceCircuitClosed();            // always allow, never trip
$integration->disableCircuit();                // bypass the breaker entirely
$integration->clearCircuitOverride();          // back to automatic
```

Each writer takes an optional `CarbonInterface $until`; once it passes, the override reverts to automatic on the next read. Precedence, highest first: `disabled` > `forced_open` > `forced_closed` > automatic.

The same controls are available from the CLI (see [`integrations:circuit`](/reference/artisan-commands#integrations-circuit)):

```bash
php artisan integrations:circuit 42 open --until="+1 hour"
php artisan integrations:circuit 42 status
php artisan integrations:circuit 42 auto
```

Rate limits can be overridden the same way, handy when a vendor temporarily lowers your quota:

```php
$integration->overrideRateLimit(RateLimit::perMinute(30), now()->addDay());
$integration->clearRateLimitOverride();
```

```bash
php artisan integrations:rate-limit 42 set --limit=30 --window=60
php artisan integrations:rate-limit 42 clear
```

A rate override takes precedence over the provider's `defaultRateLimit()`. Both override kinds can be globally switched off with `circuit_breaker.overrides_enabled` / `rate_limiting.overrides_enabled`: the columns still accept writes but are ignored.

## Events

The breaker dispatches [`CircuitOpened`](/reference/events#circuitopened) and [`CircuitClosed`](/reference/events#circuitclosed) on every state transition (automatic or forced), each carrying the integration and a `reason` (`threshold_reached`, `half_open_probe_failed`, `forced_open`; `half_open_probe_succeeded`, `forced_closed`). They fire once per transition, not per request, so they're safe to alert on.

To send notifications when a circuit trips, publish and wire the listener stub:

```bash
php artisan vendor:publish --tag=integrations-notifications
```

See [Notifications](/advanced/notifications) for wiring the published `SendCircuitNotification` listener to your notifiables.

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

State is stored in Laravel's cache (the same store used elsewhere by the package), keyed per-integration. That means breaker state is shared across queue workers: one worker tripping the breaker stops the others immediately, which is the point. (Runtime overrides live in the database, not the cache, so they outlast a `cache:clear`.)

The `open` to `half_open` transition uses an atomic probe slot (a separate cache key claimed via `Cache::add()`). When several workers see the cooldown expire at once, only one claims the slot and becomes the probe; the others throw `CircuitOpenException` until the probe outcome lands. If the probe crashes before recording success or failure, the slot expires after `cooldown_seconds * 2` and a future request can claim it.

## Tuning

If you're seeing the breaker trip too often:

- Under the rate strategy, raise `failure_rate_threshold` or `minimum_requests` so a short burst of failures doesn't cross the line. Under the count strategy, raise `threshold`.
- A flaky integration where 5xx is genuinely expected may want more retries with backoff rather than an open breaker.

If it doesn't trip when it should:

- Check the failure is being classified as `Upstream` and not `Client`/`Throttle`/`Unknown`. An upstream that signals transient errors with an odd status, or an SDK exception core can't read, may need a provider [`ClassifiesFailures`](#provider-classification) implementation.
- Under the rate strategy, confirm the integration actually makes `minimum_requests` per window. See the [low-volume warning](#strategies).

## Anomaly signal {#anomaly-signal}

The breaker protects the *upstream* — it short-circuits requests so a broken dependency isn't hammered. When to *alert a human* is separate: a failure rate over a window is the signal, and one event per incident is the cadence. Alerting per failed request just buries the operator in noise.

[`integrations:evaluate-failures`](/reference/artisan-commands#integrations-evaluate-failures) measures each active integration's failure rate over `observability.anomaly_window_minutes` from the persisted `integration_requests` rows. When the rate crosses `observability.anomaly_failure_rate_threshold` (with at least `observability.anomaly_minimum_requests` in the window), it dispatches [`ElevatedFailureRate`](/reference/events#elevatedfailurerate), then debounces: no further event for that integration until the rate recovers or `observability.anomaly_debounce_seconds` elapses. When it recovers, [`FailureRateRecovered`](/reference/events#failureraterecovered) fires and the debounce clears. Schedule it yourself:

```php
Schedule::command('integrations:evaluate-failures')->everyFifteenMinutes();
```

The package emits the events; where alerts go (Sentry, Slack) is the consumer's call, like [`CircuitOpened`](/reference/events#circuitopened) and [`SyncItemFailed`](/reference/events#syncitemfailed). The anomaly window is intentionally distinct from the breaker's `time_window`: the breaker reacts in seconds to protect the upstream, while alerting wants a longer view to avoid paging on a blip. The breaker's own live window rate is available for display via `CircuitBreaker::inspect()['failure_rate']`, which `integrations:health` surfaces.
