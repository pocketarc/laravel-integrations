<?php

declare(strict_types=1);

namespace Integrations\Tests\Unit;

use Illuminate\Queue\Middleware\WithoutOverlapping;
use Integrations\Jobs\SyncIntegration;
use Integrations\Tests\TestCase;

class SyncIntegrationMiddlewareTest extends TestCase
{
    public function test_middleware_drops_duplicates_silently(): void
    {
        $job = new SyncIntegration(integrationId: 42);
        $middleware = $job->middleware();

        $this->assertCount(1, $middleware);
        $this->assertInstanceOf(WithoutOverlapping::class, $middleware[0]);

        // dontRelease() sets releaseAfter to null. Without it, the default 0
        // makes the middleware release-and-re-pop duplicates instantly,
        // burning through tries=3 in milliseconds.
        $this->assertNull($middleware[0]->releaseAfter);
        $this->assertSame('integration-sync-42', $middleware[0]->key);
    }

    public function test_middleware_uses_configured_lock_ttl(): void
    {
        config()->set('integrations.sync.lock_ttl', 1234);

        $middleware = (new SyncIntegration(integrationId: 1))->middleware()[0];

        $this->assertInstanceOf(WithoutOverlapping::class, $middleware);
        $this->assertSame(1234, $middleware->expiresAfter);
    }
}
