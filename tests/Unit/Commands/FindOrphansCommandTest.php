<?php

declare(strict_types=1);

namespace Integrations\Tests\Unit\Commands;

use Integrations\Models\Integration;
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

    public function test_it_rejects_a_class_that_is_not_a_model(): void
    {
        $this->artisan('integrations:find-orphans', ['model' => 'App\\Nope'])
            ->assertFailed()
            ->expectsOutputToContain('fully-qualified Eloquent model class');
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
