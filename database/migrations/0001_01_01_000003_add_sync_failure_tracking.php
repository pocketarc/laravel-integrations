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
        Schema::table(Config::tablePrefix().'s', function (Blueprint $table): void {
            $table->unsignedInteger('consecutive_sync_failures')->default(0)->after('consecutive_failures');
            $table->timestamp('sync_stale_alerted_at')->nullable()->after('next_sync_at');
        });
    }

    public function down(): void
    {
        Schema::table(Config::tablePrefix().'s', function (Blueprint $table): void {
            $table->dropColumn(['consecutive_sync_failures', 'sync_stale_alerted_at']);
        });
    }
};
