<?php

declare(strict_types=1);

namespace Integrations\Tests\Fixtures;

use Integrations\Contracts\HasIncrementalSync;
use Integrations\Contracts\IntegrationProvider;
use Integrations\Models\Integration;
use Integrations\Sync\SyncResult;

class IncrementalProvider implements HasIncrementalSync, IntegrationProvider
{
    public static mixed $receivedCursor = null;

    public function name(): string
    {
        return 'Incremental';
    }

    public function credentialRules(): array
    {
        return [];
    }

    public function metadataRules(): array
    {
        return [];
    }

    public function credentialDataClass(): ?string
    {
        return null;
    }

    public function metadataDataClass(): ?string
    {
        return null;
    }

    public function sync(Integration $integration): SyncResult
    {
        return SyncResult::empty();
    }

    public function syncIncremental(Integration $integration, mixed $cursor): SyncResult
    {
        self::$receivedCursor = $cursor;

        return new SyncResult(1, 0, now(), cursor: ['page' => 2]);
    }

    public function defaultSyncInterval(): int
    {
        return 15;
    }

    public function defaultRateLimit(): ?int
    {
        return null;
    }
}
