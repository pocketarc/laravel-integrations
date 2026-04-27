<?php

declare(strict_types=1);

namespace Integrations\Contracts;

/**
 * Marker interface declaring that the provider's API natively supports
 * idempotency keys (e.g. Stripe's `Idempotency-Key` header, which makes
 * the provider dedupe duplicate requests on its end).
 *
 * Adapters that implement this contract should propagate the resolved
 * `RequestContext::$idempotencyKey` to the wire inside their closure (the
 * exact mechanism is provider-specific: header, body field, or SDK option).
 *
 * Providers that don't implement this still see the key persisted on the
 * `integration_requests.idempotency_key` column for searchability and
 * future our-side dedup, but core logs a warning at runtime when a caller
 * sets a key against a non-supporting provider, since the key won't have
 * any provider-side dedup effect.
 */
interface SupportsIdempotency {}
