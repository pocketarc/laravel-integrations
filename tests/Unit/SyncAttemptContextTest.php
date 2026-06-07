<?php

declare(strict_types=1);

namespace Integrations\Tests\Unit;

use Integrations\Sync\SyncAttemptContext;
use Integrations\Tests\TestCase;

class SyncAttemptContextTest extends TestCase
{
    public function test_is_likely_final_attempt_below_the_ceiling(): void
    {
        $context = new SyncAttemptContext(
            attempt: 3,
            maxAttempts: 5,
            syncItemId: 1,
            integrationId: 1,
            syncLogId: 1,
            externalId: 'EXT-1',
        );

        $this->assertFalse($context->isLikelyFinalAttempt());
    }

    public function test_is_likely_final_attempt_at_and_above_the_ceiling(): void
    {
        $atCeiling = new SyncAttemptContext(5, 5, 1, 1, null, null);
        $aboveCeiling = new SyncAttemptContext(6, 5, 1, 1, null, null);

        $this->assertTrue($atCeiling->isLikelyFinalAttempt());
        $this->assertTrue($aboveCeiling->isLikelyFinalAttempt());
    }

    public function test_exposes_its_fields(): void
    {
        $context = new SyncAttemptContext(2, 5, 42, 7, 99, 'EXT-2');

        $this->assertSame(2, $context->attempt);
        $this->assertSame(5, $context->maxAttempts);
        $this->assertSame(42, $context->syncItemId);
        $this->assertSame(7, $context->integrationId);
        $this->assertSame(99, $context->syncLogId);
        $this->assertSame('EXT-2', $context->externalId);
    }
}
