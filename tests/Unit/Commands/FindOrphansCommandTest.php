<?php

declare(strict_types=1);

namespace Integrations\Tests\Unit\Commands;

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Integrations\Models\Integration;
use Integrations\Tests\Fixtures\AbstractTestModel;
use Integrations\Tests\TestCase;

class FindOrphansCommandTest extends TestCase
{
    private Integration $integration;

    protected function setUp(): void
    {
        parent::setUp();

        $this->integration = Integration::create(['provider' => 'test', 'name' => 'Test']);
    }

    public function test_it_reports_a_row_with_no_mapping(): void
    {
        $mapped = Integration::create(['provider' => 'a', 'name' => 'Mapped']);
        $this->integration->mapExternalId('EXT-1', $mapped);

        $orphan = Integration::create(['provider' => 'b', 'name' => 'Orphan']);

        $this->artisan('integrations:find-orphans', ['model' => Integration::class])
            ->assertSuccessful()
            ->expectsOutputToContain((string) $orphan->id)
            ->expectsOutputToContain('row(s) with no external ID');
    }

    public function test_it_reports_nothing_when_every_row_is_mapped(): void
    {
        // The integration doing the mapping needs one too, or it counts itself.
        $this->integration->mapExternalId('EXT-SELF', $this->integration);

        $mapped = Integration::create(['provider' => 'a', 'name' => 'Mapped']);
        $this->integration->mapExternalId('EXT-1', $mapped);

        $this->artisan('integrations:find-orphans', ['model' => Integration::class])
            ->assertSuccessful()
            ->expectsOutputToContain('every one has a mapping');
    }

    public function test_it_scopes_to_one_integration(): void
    {
        $other = Integration::create(['provider' => 'other', 'name' => 'Other']);

        $mapped = Integration::create(['provider' => 'a', 'name' => 'Mapped elsewhere']);
        $other->mapExternalId('EXT-1', $mapped);

        $this->artisan('integrations:find-orphans', [
            'model' => Integration::class,
            '--integration' => (string) $this->integration->id,
        ])
            ->assertSuccessful()
            ->expectsOutputToContain((string) $mapped->id);
    }

    public function test_it_caps_output_at_the_limit(): void
    {
        for ($i = 0; $i < 5; $i++) {
            Integration::create(['provider' => 'orphan', 'name' => "Orphan {$i}"]);
        }

        $this->artisan('integrations:find-orphans', [
            'model' => Integration::class,
            '--limit' => '2',
        ])
            ->assertSuccessful()
            ->expectsOutputToContain('2 row(s) with no external ID');
    }

    public function test_it_reads_only_the_columns_it_prints(): void
    {
        // A mapped model can store payloads inline — file contents, raw API
        // responses. Selecting `*` loads every byte of every chunk to print an
        // id and a date, which exhausts memory on exactly the model most likely
        // to need this command. Integration stands in: its credentials and
        // metadata columns must not be in the scan.
        Integration::create(['provider' => 'orphan', 'name' => 'Orphan']);

        $scans = [];
        DB::listen(function (QueryExecuted $query) use (&$scans): void {
            if (str_contains($query->sql, 'from "integrations"')) {
                $scans[] = $query->sql;
            }
        });

        $this->artisan('integrations:find-orphans', ['model' => Integration::class])->assertSuccessful();

        $this->assertNotEmpty($scans, 'expected the model scan to run');

        foreach ($scans as $sql) {
            $this->assertStringNotContainsString('select *', $sql, "scanned whole rows: {$sql}");
            $this->assertStringNotContainsString('credentials', $sql, "pulled a payload column: {$sql}");
        }
    }

    public function test_it_rejects_a_class_that_is_not_a_model(): void
    {
        $this->artisan('integrations:find-orphans', ['model' => 'App\\Nope'])
            ->assertFailed()
            ->expectsOutputToContain('fully-qualified Eloquent model class');
    }

    public function test_it_rejects_an_abstract_model_class(): void
    {
        $this->artisan('integrations:find-orphans', ['model' => AbstractTestModel::class])
            ->assertFailed()
            ->expectsOutputToContain('is abstract');
    }

    public function test_it_rejects_an_unknown_integration_id(): void
    {
        $this->artisan('integrations:find-orphans', [
            'model' => Integration::class,
            '--integration' => '999999',
        ])
            ->assertFailed()
            ->expectsOutputToContain('No integration with id 999999');
    }

    public function test_it_rejects_a_non_numeric_limit(): void
    {
        $this->artisan('integrations:find-orphans', [
            'model' => Integration::class,
            '--limit' => 'lots',
        ])
            ->assertFailed()
            ->expectsOutputToContain('--limit option must be a positive integer');
    }
}
