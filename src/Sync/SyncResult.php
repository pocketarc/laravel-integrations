<?php

declare(strict_types=1);

namespace Integrations\Sync;

use Illuminate\Support\Carbon;

class SyncResult
{
    public function __construct(
        public readonly int $successCount,
        public readonly int $failureCount,
        public readonly ?Carbon $safeSyncedAt,
    ) {}

    public static function empty(): self
    {
        return new self(0, 0, null);
    }

    public function hasFailures(): bool
    {
        return $this->failureCount > 0;
    }

    public function totalCount(): int
    {
        return $this->successCount + $this->failureCount;
    }
}
