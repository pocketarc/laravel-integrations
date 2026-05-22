<?php

declare(strict_types=1);

namespace Integrations\Tests\Fixtures;

use Integrations\Concerns\ReducesCheckpointsByMax;
use Integrations\Contracts\HasIncrementalSync;
use Integrations\Contracts\IntegrationProvider;
use Integrations\Models\Integration;
use Integrations\RateLimit;
use Integrations\Sync\SyncSession;
use LogicException;

class IncrementalProvider implements HasIncrementalSync, IntegrationProvider
{
    use ReducesCheckpointsByMax;

    /** @var list<array{id: string, checkpoint: mixed}> */
    private const DEFAULT_ITEMS = [
        ['id' => 'item-1', 'checkpoint' => ['page' => 2]],
    ];

    public static mixed $receivedCursor = null;

    /**
     * Items the next syncIncremental() call dispatches.
     *
     * @var list<array{id: string, checkpoint: mixed}>
     */
    public static array $items = self::DEFAULT_ITEMS;

    public static function resetState(): void
    {
        self::$receivedCursor = null;
        self::$items = self::DEFAULT_ITEMS;
    }

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

    public function sync(Integration $integration, SyncSession $session): void
    {
        throw new LogicException('IncrementalProvider::sync() is not expected to run; tests should exercise syncIncremental().');
    }

    public function syncIncremental(Integration $integration, SyncSession $session): void
    {
        self::$receivedCursor = $session->cursor();

        foreach (self::$items as $item) {
            $session->dispatch(
                new TestSyncItemEvent($integration, $item['id']),
                checkpointValue: $item['checkpoint'],
            );
        }
    }

    public function defaultSyncInterval(): int
    {
        return 15;
    }

    public function defaultRateLimit(): ?RateLimit
    {
        return null;
    }
}
