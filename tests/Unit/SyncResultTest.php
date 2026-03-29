<?php

declare(strict_types=1);

namespace Integrations\Tests\Unit;

use Illuminate\Support\Carbon;
use Integrations\Sync\SyncResult;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class SyncResultTest extends TestCase
{
    public function test_negative_success_count_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Success count must not be negative.');

        new SyncResult(-1, 0, null);
    }

    public function test_negative_failure_count_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Failure count must not be negative.');

        new SyncResult(0, -1, null);
    }

    public function test_zero_counts_are_valid(): void
    {
        $result = new SyncResult(0, 0, null);

        $this->assertSame(0, $result->successCount);
        $this->assertSame(0, $result->failureCount);
        $this->assertNull($result->safeSyncedAt);
    }

    public function test_positive_counts_are_valid(): void
    {
        $now = Carbon::now();
        $result = new SyncResult(5, 3, $now);

        $this->assertSame(5, $result->successCount);
        $this->assertSame(3, $result->failureCount);
        $this->assertSame($now, $result->safeSyncedAt);
    }

    public function test_empty_factory(): void
    {
        $result = SyncResult::empty();

        $this->assertSame(0, $result->successCount);
        $this->assertSame(0, $result->failureCount);
        $this->assertNull($result->safeSyncedAt);
    }

    public function test_has_failures(): void
    {
        $this->assertFalse((new SyncResult(5, 0, null))->hasFailures());
        $this->assertTrue((new SyncResult(5, 1, null))->hasFailures());
    }

    public function test_total_count(): void
    {
        $this->assertSame(8, (new SyncResult(5, 3, null))->totalCount());
        $this->assertSame(0, SyncResult::empty()->totalCount());
    }
}
