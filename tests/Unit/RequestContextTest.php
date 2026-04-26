<?php

declare(strict_types=1);

namespace Integrations\Tests\Unit;

use Integrations\Exceptions\RetryableException;
use Integrations\IntegrationManager;
use Integrations\Models\Integration;
use Integrations\RequestContext;
use Integrations\Tests\Fixtures\TestProvider;
use Integrations\Tests\TestCase;

class RequestContextTest extends TestCase
{
    private Integration $integration;

    protected function setUp(): void
    {
        parent::setUp();

        app(IntegrationManager::class)->register('test', TestProvider::class);
        $this->integration = Integration::create(['provider' => 'test', 'name' => 'Test']);
        $this->integration->refresh();
    }

    public function test_zero_arg_closure_still_works(): void
    {
        $result = $this->integration->at('/api/test')->get(fn (): array => ['ok' => true]);

        $this->assertSame(['ok' => true], $result);
    }

    public function test_closure_receives_context_when_typed_first_param_is_request_context(): void
    {
        $captured = null;

        $this->integration->at('/api/test')->get(function (RequestContext $ctx) use (&$captured): array {
            $captured = $ctx;

            return ['ok' => true];
        });

        $this->assertInstanceOf(RequestContext::class, $captured);
        $this->assertNull($captured->idempotencyKey);
    }

    public function test_invokable_with_arity_zero_does_not_throw(): void
    {
        $invokable = new class
        {
            /** @return array<string, bool> */
            public function __invoke(): array
            {
                return ['ok' => true];
            }
        };

        // \Closure::fromCallable on an invokable yields a strict-arity closure;
        // passing extra args would throw ArgumentCountError without the
        // reflection gate.
        $callback = \Closure::fromCallable($invokable);

        $result = $this->integration->at('/api/test')->get($callback);

        $this->assertSame(['ok' => true], $result);
    }

    public function test_typed_first_param_other_than_request_context_does_not_get_injected(): void
    {
        // A typed first parameter that isn't RequestContext (here, a string
        // with a default) must not receive a RequestContext: that would
        // TypeError. Without the type guard the closure is invoked with
        // the context as its first arg and this test fails.
        $injectedAnything = false;

        $this->integration->at('/api/test')->get(function (string $marker = 'default') use (&$injectedAnything): array {
            $injectedAnything = $marker !== 'default';

            return ['ok' => true];
        });

        $this->assertFalse($injectedAnything);
    }

    public function test_nested_integration_call_restores_outer_context(): void
    {
        $outerKey = null;
        $outerKeyAfterInner = null;

        $this->integration->at('/api/outer')
            ->withIdempotencyKey('outer-key')
            ->post(function (RequestContext $ctx) use (&$outerKey, &$outerKeyAfterInner): array {
                $outerKey = Integration::currentContext()?->idempotencyKey;

                // Nested call with its own idempotency key. After it
                // unwinds, the outer context should be restored.
                $this->integration->at('/api/inner')
                    ->withIdempotencyKey('inner-key')
                    ->post(fn (): array => ['ok' => true]);

                $outerKeyAfterInner = Integration::currentContext()?->idempotencyKey;

                return ['ok' => true];
            });

        $this->assertSame('outer-key', $outerKey);
        $this->assertSame('outer-key', $outerKeyAfterInner);
    }

    public function test_current_context_is_available_during_closure_call(): void
    {
        $captured = null;

        $this->integration->at('/api/test')->get(function () use (&$captured): array {
            $captured = Integration::currentContext();

            return ['ok' => true];
        });

        $this->assertInstanceOf(RequestContext::class, $captured);
    }

    public function test_current_context_is_null_outside_a_request(): void
    {
        $this->assertNull(Integration::currentContext());

        $this->integration->at('/api/test')->get(fn (): array => ['ok' => true]);

        $this->assertNull(Integration::currentContext());
    }

    public function test_same_context_instance_is_reused_across_retries(): void
    {
        /** @var list<RequestContext> $captured */
        $captured = [];
        $attempt = 0;

        $this->integration->at('/api/test')
            ->withAttempts(3)
            ->get(function (RequestContext $ctx) use (&$captured, &$attempt): array {
                $captured[] = $ctx;
                $attempt++;

                if ($attempt < 3) {
                    throw new RetryableException('boom');
                }

                return ['ok' => true];
            });

        $this->assertCount(3, $captured);
        $this->assertSame($captured[0], $captured[1]);
        $this->assertSame($captured[1], $captured[2]);
    }

    public function test_response_metadata_resets_between_retries(): void
    {
        /** @var list<?string> $observedRequestIds */
        $observedRequestIds = [];
        $attempt = 0;

        $this->integration->at('/api/test')
            ->withAttempts(3)
            ->get(function (RequestContext $ctx) use (&$observedRequestIds, &$attempt): array {
                // Record what the context looked like at the start of this attempt.
                $observedRequestIds[] = $ctx->providerRequestId();

                $ctx->reportResponseMetadata(providerRequestId: 'req_attempt_'.($attempt + 1));
                $attempt++;

                if ($attempt < 3) {
                    throw new RetryableException('boom');
                }

                return ['ok' => true];
            });

        // First attempt sees null (fresh), and subsequent attempts ALSO see
        // null because resetResponseMetadata() runs between retries.
        $this->assertSame([null, null, null], $observedRequestIds);
    }

    public function test_idempotency_key_remains_stable_across_retries(): void
    {
        // Exercise the lower-level Integration::request() entry point
        // directly. The fluent builder is covered separately in
        // IdempotencyTest; here we verify the executor preserves the
        // key across inner retries no matter how the call enters core.
        /** @var list<?string> $observed */
        $observed = [];
        $attempt = 0;

        $this->integration->request(
            endpoint: '/api/test',
            method: 'POST',
            callback: function (RequestContext $ctx) use (&$observed, &$attempt): array {
                $observed[] = $ctx->idempotencyKey;
                $attempt++;

                if ($attempt < 3) {
                    throw new RetryableException('boom');
                }

                return ['ok' => true];
            },
            maxAttempts: 3,
            idempotencyKey: 'order-42',
        );

        $this->assertSame(['order-42', 'order-42', 'order-42'], $observed);
    }
}
