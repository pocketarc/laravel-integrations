<?php

declare(strict_types=1);

namespace Integrations\Testing;

use Integrations\Sync\PendingSyncItem;
use Integrations\Sync\SyncItemEvent;
use Integrations\Sync\SyncSession;
use PHPUnit\Framework\Assert;

/**
 * A `SyncSession` for adapter tests. Pass it to a provider's `sync()` /
 * `syncIncremental()` in place of the real session, then assert on what the
 * provider dispatched. No queue, no batch, no `Bus::batch` table needed.
 */
class FakeSyncSession extends SyncSession
{
    public function assertDispatchedCount(int $expected): void
    {
        Assert::assertCount(
            $expected,
            $this->pendingItems(),
            "Expected {$expected} sync item(s) to be dispatched.",
        );
    }

    public function assertNothingDispatched(): void
    {
        Assert::assertTrue($this->isEmpty(), 'Expected no sync items to be dispatched.');
    }

    /**
     * Assert at least one dispatched item is of the given event class. The
     * optional filter receives the event, its checkpoint value, and its
     * external id, and should return true for a matching item.
     *
     * @param  class-string<SyncItemEvent>  $eventClass
     * @param  (callable(SyncItemEvent, mixed, ?string): bool)|null  $filter
     */
    public function assertDispatched(string $eventClass, ?callable $filter = null): void
    {
        $matched = false;

        foreach ($this->pendingItems() as $item) {
            if (! $item->event instanceof $eventClass) {
                continue;
            }

            if ($filter === null || $filter($item->event, $item->checkpointValue, $item->externalId)) {
                $matched = true;

                break;
            }
        }

        Assert::assertTrue($matched, "Expected a [{$eventClass}] sync item to be dispatched.");
    }

    /**
     * The raw list of items the provider dispatched, in dispatch order.
     *
     * @return list<PendingSyncItem>
     */
    public function dispatched(): array
    {
        return $this->pendingItems();
    }
}
