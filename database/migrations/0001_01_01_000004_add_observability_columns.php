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

        Schema::table("{$prefix}_requests", function (Blueprint $table): void {
            $table->string('idempotency_key', 64)->nullable()->after('request_data');
            $table->string('provider_request_id', 128)->nullable()->after('idempotency_key');
            $table->index('idempotency_key');
            $table->index('provider_request_id');
        });
    }

    public function down(): void
    {
        $prefix = Config::tablePrefix();

        Schema::table("{$prefix}_requests", function (Blueprint $table) use ($prefix): void {
            $table->dropIndex("{$prefix}_requests_idempotency_key_index");
            $table->dropIndex("{$prefix}_requests_provider_request_id_index");
            $table->dropColumn(['idempotency_key', 'provider_request_id']);
        });
    }
};
