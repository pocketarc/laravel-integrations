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

        Schema::table("{$prefix}s", function (Blueprint $table): void {
            $table->json('sync_cursor')->nullable()->after('next_sync_at');
        });

        Schema::create("{$prefix}_webhooks", function (Blueprint $table) use ($prefix): void {
            $table->id();
            $table->foreignId('integration_id')->constrained("{$prefix}s")->cascadeOnDelete();
            $table->string('delivery_id');
            $table->string('event_type')->nullable();
            $table->text('payload');
            $table->json('headers');
            $table->string('status')->default('pending');
            $table->text('error')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->unique(['integration_id', 'delivery_id']);
            $table->index(['integration_id', 'created_at']);
        });
    }

    public function down(): void
    {
        $prefix = Config::tablePrefix();

        Schema::dropIfExists("{$prefix}_webhooks");

        Schema::table("{$prefix}s", function (Blueprint $table): void {
            $table->dropColumn('sync_cursor');
        });
    }
};
