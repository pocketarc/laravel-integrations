<?php

declare(strict_types=1);

namespace Integrations\Tests\Unit\Commands;

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Integrations\Models\Integration;
use Integrations\Models\IntegrationSyncItem;
use Integrations\Tests\TestCase;

class ListFailedItemsCommandTest extends TestCase
{
    private Integration $integration;

    protected function setUp(): void
    {
        parent::setUp();

        $this->integration = Integration::create(['provider' => 'test', 'name' => 'Test']);
    }

    public function test_it_lists_a_failed_item(): void
    {
        $this->failedItem('EXT-1', 'upstream exploded');

        $this->artisan('integrations:list-failed-items')
            ->assertSuccessful()
            ->expectsOutputToContain('EXT-1');

        // Separate invocation: expectsOutputToContain matches per write, and a
        // table row is one write, so two substrings from the same row compete
        // for it and only the first-declared one is ever satisfied.
        $this->artisan('integrations:list-failed-items')
            ->assertSuccessful()
            ->expectsOutputToContain('upstream exploded');
    }

    public function test_it_reports_an_empty_state(): void
    {
        $this->artisan('integrations:list-failed-items')
            ->assertSuccessful()
            ->expectsOutputToContain('No failed sync items');
    }

    public function test_it_ignores_items_that_have_not_failed(): void
    {
        $this->failedItem('EXT-DONE', null, IntegrationSyncItem::STATUS_SUCCESS);

        $this->artisan('integrations:list-failed-items')
            ->assertSuccessful()
            ->expectsOutputToContain('No failed sync items');
    }

    public function test_it_says_so_when_it_stopped_at_the_limit(): void
    {
        for ($i = 0; $i < 4; $i++) {
            $this->failedItem("EXT-{$i}", 'boom');
        }

        $this->artisan('integrations:list-failed-items', ['--limit' => '2'])
            ->assertSuccessful()
            ->expectsOutputToContain('Stopped at the --limit of 2');
    }

    public function test_it_stays_quiet_about_the_limit_when_it_showed_everything(): void
    {
        $this->failedItem('EXT-1', 'boom');

        $this->artisan('integrations:list-failed-items')
            ->assertSuccessful()
            ->doesntExpectOutputToContain('Stopped at the --limit');
    }

    public function test_it_rejects_a_non_numeric_limit(): void
    {
        $this->artisan('integrations:list-failed-items', ['--limit' => 'lots'])
            ->assertFailed()
            ->expectsOutputToContain('--limit option must be a positive integer');
    }

    public function test_it_does_not_read_the_checkpoint_payload(): void
    {
        // checkpoint_value holds a provider cursor that this table never shows.
        $this->failedItem('EXT-1', 'boom');

        $scans = [];
        DB::listen(function (QueryExecuted $query) use (&$scans): void {
            if (str_starts_with($query->sql, 'select') && str_contains($query->sql, 'integration_sync_items')) {
                $scans[] = $query->sql;
            }
        });

        $this->artisan('integrations:list-failed-items')->assertSuccessful();

        $this->assertNotEmpty($scans, 'expected the failed-item scan to run');
        $this->assertStringNotContainsString('select *', $scans[0], "scanned whole rows: {$scans[0]}");
        $this->assertStringNotContainsString('checkpoint_value', $scans[0], "pulled the cursor payload: {$scans[0]}");
    }

    private function failedItem(string $externalId, ?string $error, string $status = IntegrationSyncItem::STATUS_FAILED): IntegrationSyncItem
    {
        return IntegrationSyncItem::create([
            'integration_id' => $this->integration->id,
            'event_class' => 'Integrations\\Tests\\Fixtures\\TestSyncItemEvent',
            'external_id' => $externalId,
            'checkpoint_value' => '2026-01-01T00:00:00+00:00',
            'status' => $status,
            'error' => $error,
            'attempts' => 5,
        ]);
    }
}
