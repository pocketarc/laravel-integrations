<?php

declare(strict_types=1);

namespace Integrations\Support;

use Illuminate\Support\Facades\Schema;

/** Resolves the collation the mapping table's string columns should carry. */
class MappingCollation
{
    /**
     * The configured collation, or null when this connection's driver has no
     * per-column collations to apply it to. Laravel's Postgres and SQLite
     * grammars both emit the name into the DDL anyway, and both engines then
     * error on a collation they don't know, so an unguarded MySQL value fails
     * the migration outright.
     */
    public static function forConnection(): ?string
    {
        $collation = Config::mappingCollation();

        if ($collation === null || ! in_array(Schema::getConnection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            return null;
        }

        return $collation;
    }
}
