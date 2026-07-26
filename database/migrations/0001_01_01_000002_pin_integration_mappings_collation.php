<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Integrations\Support\Config;
use Integrations\Support\MappingCollation;

/** Applies `integrations.mappings.collation` to an already-created mapping table. */
return new class extends Migration
{
    public function up(): void
    {
        $collation = MappingCollation::forConnection();

        if ($collation === null) {
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
        // Not reversible: the prior collation isn't recorded anywhere, so
        // undoing this would mean guessing at one.
    }
};
