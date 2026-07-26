<?php

declare(strict_types=1);

namespace Integrations\Tests\Unit\Support;

use Illuminate\Support\Facades\Schema;
use Integrations\Support\MappingCollation;
use Integrations\Tests\TestCase;

class MappingCollationTest extends TestCase
{
    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);

        $app['config']->set('integrations.mappings.collation', 'utf8mb4_general_ci');
    }

    public function test_it_drops_the_collation_on_a_driver_without_column_collations(): void
    {
        $this->assertNull(MappingCollation::forConnection());
    }

    public function test_the_migrations_run_with_a_mysql_collation_configured_on_sqlite(): void
    {
        $this->assertTrue(Schema::hasTable('integration_mappings'));
    }
}
