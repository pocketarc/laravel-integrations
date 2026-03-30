<?php

declare(strict_types=1);

namespace Integrations\Sync;

use Illuminate\Support\Carbon;
use InvalidArgumentException;

class SyncResult
{
    public function __construct(
        public readonly int $successCount,
        public readonly int $failureCount,
        public readonly ?Carbon $safeSyncedAt,
        public readonly mixed $cursor = null,
    ) {
        if ($successCount < 0) {
            throw new InvalidArgumentException('Success count must not be negative.');
        }

        if ($failureCount < 0) {
            throw new InvalidArgumentException('Failure count must not be negative.');
        }
    }

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
