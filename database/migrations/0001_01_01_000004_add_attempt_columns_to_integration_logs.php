<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Integrations\Support\Config;

return new class extends Migration
{
    public function up(): void
    {
        $prefix = Config::tablePrefix();

        Schema::table("{$prefix}_logs", function (Blueprint $table): void {
            // Retry-attempt provenance for failures logged inside a sync item
            // run. Null for operations logged outside a sync attempt (manual
            // logOperation calls, webhook-path logging). Stored as facts, not
            // a derived "terminal" flag — terminality isn't knowable at log
            // time (retryUntil can cut retries short; a later attempt may
            // still succeed).
            $table->unsignedSmallInteger('attempt')->nullable()->after('error');
            $table->unsignedSmallInteger('max_attempts')->nullable()->after('attempt');
        });
    }

    public function down(): void
    {
        $prefix = Config::tablePrefix();

        Schema::table("{$prefix}_logs", function (Blueprint $table): void {
            $table->dropColumn(['attempt', 'max_attempts']);
        });
    }
};
