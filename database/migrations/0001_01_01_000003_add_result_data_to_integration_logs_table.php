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
            $table->json('result_data')->nullable()->after('metadata');
        });
    }

    public function down(): void
    {
        $prefix = Config::tablePrefix();

        Schema::table("{$prefix}_logs", function (Blueprint $table): void {
            $table->dropColumn('result_data');
        });
    }
};
