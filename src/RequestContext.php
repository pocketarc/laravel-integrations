<?php

declare(strict_types=1);

namespace Integrations;

use Carbon\CarbonInterface;
use InvalidArgumentException;

/**
 * Per-request mutable context shared between core's RequestExecutor and the
 * adapter closure. Adapters read the resolved idempotency key (if any) and
 * report response metadata back via reportResponseMetadata(). Core uses
 * what the closure reports to feed adaptive rate limiting and to persist the
 * provider's request ID alongside the IntegrationRequest row.
 *
 * One context is created per Integration::request() call. The idempotency
 * key is preserved across inner retry attempts (that's the whole point);
 * the response metadata is reset between retries so a previous attempt's
 * metadata doesn't leak into the persistence of the current one.
 *
 * Closures opt in by typing the first parameter as RequestContext:
 *
 *   ->post(function (RequestContext $ctx) use ($params): Charge {
 *       $charge = $sdk->charges->create($params, ['idempotency_key' => $ctx->idempotencyKey]);
 *       $ctx->reportResponseMetadata(providerRequestId: $sdk->getLastResponse()?->headers['Request-Id'] ?? null);
 *       return $charge;
 *   });
 *
 * Zero-arg closures (`fn () => ...`) continue to work; the context is just
 * not passed in. Closures that can't accept an arg can still reach for
 * `Integration::currentContext()` as an escape hatch.
 */
final class RequestContext
{
    /**
     * Match the column widths on `integration_requests`. The idempotency
     * key is caller-controlled, so we throw when it's too long; the
     * provider request ID comes from upstream headers and gets truncated
     * silently rather than failing a request just because the upstream
     * shipped a longer-than-expected value.
     */
    public const MAX_IDEMPOTENCY_KEY_LENGTH = 64;

    public const MAX_PROVIDER_REQUEST_ID_LENGTH = 128;

    private ?string $providerRequestId = null;

    private ?int $rateLimitRemaining = null;

    private ?CarbonInterface $rateLimitResetAt = null;

    private ?int $retryAfterSeconds = null;

    public function __construct(
        public readonly ?string $idempotencyKey = null,
    ) {
        if ($idempotencyKey !== null && mb_strlen($idempotencyKey) > self::MAX_IDEMPOTENCY_KEY_LENGTH) {
            throw new InvalidArgumentException(sprintf(
                'Idempotency key must be at most %d characters; got %d.',
                self::MAX_IDEMPOTENCY_KEY_LENGTH,
                mb_strlen($idempotencyKey),
            ));
        }
    }

    /**
     * Report response metadata extracted from the provider's response.
     * Each parameter is independent: a null arg leaves the existing value
     * alone, so adapters can call this multiple times to fill different
     * fields without clobbering earlier ones.
     */
    public function reportResponseMetadata(
        ?string $providerRequestId = null,
        ?int $rateLimitRemaining = null,
        ?CarbonInterface $rateLimitResetAt = null,
        ?int $retryAfterSeconds = null,
    ): void {
        if ($providerRequestId !== null) {
            $this->providerRequestId = mb_strcut($providerRequestId, 0, self::MAX_PROVIDER_REQUEST_ID_LENGTH);
        }
        if ($rateLimitRemaining !== null) {
            $this->rateLimitRemaining = $rateLimitRemaining;
        }
        if ($rateLimitResetAt !== null) {
            $this->rateLimitResetAt = $rateLimitResetAt;
        }
        if ($retryAfterSeconds !== null) {
            $this->retryAfterSeconds = $retryAfterSeconds;
        }
    }

    public function providerRequestId(): ?string
    {
        return $this->providerRequestId;
    }

    public function rateLimitRemaining(): ?int
    {
        return $this->rateLimitRemaining;
    }

    public function rateLimitResetAt(): ?CarbonInterface
    {
        return $this->rateLimitResetAt;
    }

    public function retryAfterSeconds(): ?int
    {
        return $this->retryAfterSeconds;
    }

    /**
     * Reset response metadata between retry attempts within a single
     * Integration::request() call. The idempotency key is intentionally not
     * reset; it stays stable so the provider can dedupe the retry.
     *
     * @internal Called by RequestExecutor between attempts.
     */
    public function resetResponseMetadata(): void
    {
        $this->providerRequestId = null;
        $this->rateLimitRemaining = null;
        $this->rateLimitResetAt = null;
        $this->retryAfterSeconds = null;
    }
}
