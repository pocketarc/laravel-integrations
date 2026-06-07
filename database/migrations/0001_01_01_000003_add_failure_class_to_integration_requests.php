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

        Schema::table("{$prefix}_requests", function (Blueprint $table) use ($prefix): void {
            // The FailureClass this request was classified as on the failure
            // path, persisted so observability can group by it without
            // re-running FailureClassifier on read. Null on the success path.
            $table->string('failure_class')->nullable()->after('error');

            // "Failures by class for integration X over a window." Named
            // explicitly so a custom table prefix can't push it past the
            // 64-char identifier limit.
            $table->index(
                ['integration_id', 'failure_class', 'created_at'],
                "{$prefix}_requests_failure_class_idx",
            );
        });
    }

    public function down(): void
    {
        $prefix = Config::tablePrefix();

        Schema::table("{$prefix}_requests", function (Blueprint $table) use ($prefix): void {
            $table->dropIndex("{$prefix}_requests_failure_class_idx");
            $table->dropColumn('failure_class');
        });
    }
};
