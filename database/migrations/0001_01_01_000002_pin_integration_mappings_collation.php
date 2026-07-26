<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Integrations\Support\Config;

/**
 * Applies `integrations.mappings.collation` to an existing mapping table.
 *
 * The create migration pins the collation for fresh installs, but consumers who
 * published before 6.0 already have the table, so they need this to catch up. A
 * no-op when the setting is null (the default) or on any driver without MySQL's
 * per-column collation, which means it is safe to run everywhere.
 *
 * Worth setting when your own tables use a different collation from the one
 * Laravel gave this package's: comparing `internal_id` against your primary keys
 * otherwise fails with "Illegal mix of collations", and that comparison is how
 * you find rows that lost their mapping.
 */
return new class extends Migration
{
    public function up(): void
    {
        $collation = Config::mappingCollation();

        if ($collation === null || ! $this->driverSupportsColumnCollation()) {
            return;
        }

        Schema::table(Config::tablePrefix().'_mappings', function (Blueprint $table) use ($collation): void {
            $table->string('external_id', 500)->collation($collation)->change();
            $table->string('internal_type')->collation($collation)->change();
            $table->string('internal_id')->collation($collation)->change();
        });
    }

    public function down(): void
    {
        // Deliberately not reversed: the prior collation isn't recorded
        // anywhere, so "undoing" this would mean guessing at one and could
        // leave the columns further from the connection default than they
        // started. Set the config back to null and re-create the table if you
        // genuinely need the old collation.
    }

    private function driverSupportsColumnCollation(): bool
    {
        return in_array(Schema::getConnection()->getDriverName(), ['mysql', 'mariadb'], true);
    }
};
