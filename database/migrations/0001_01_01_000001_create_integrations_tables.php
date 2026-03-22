<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $prefix = config('integrations.table_prefix', 'integration');

        Schema::create("{$prefix}s", function (Blueprint $table): void {
            $table->id();
            $table->string('provider');
            $table->string('name');
            $table->text('credentials')->nullable();
            $table->json('metadata')->nullable();
            $table->boolean('is_active')->default(true);
            $table->string('health_status')->default('healthy');
            $table->unsignedInteger('consecutive_failures')->default(0);
            $table->timestamp('last_error_at')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->unsignedInteger('sync_interval_minutes')->nullable();
            $table->timestamp('next_sync_at')->nullable();
            $table->nullableMorphs('owner');
            $table->timestamps();

            $table->index('provider');
            $table->index(['is_active', 'next_sync_at']);
            $table->index('health_status');
        });

        Schema::create("{$prefix}_requests", function (Blueprint $table) use ($prefix): void {
            $table->id();
            $table->foreignId('integration_id')->constrained("{$prefix}s")->cascadeOnDelete();
            $table->nullableMorphs('related');
            $table->string('endpoint');
            $table->string('method', 10);
            $table->text('request_data')->nullable();
            $table->foreignId('retry_of')->nullable()->constrained("{$prefix}_requests")->nullOnDelete();
            $table->unsignedSmallInteger('response_code')->nullable();
            $table->longText('response_data')->nullable();
            $table->boolean('response_success')->default(false);
            $table->json('error')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->unsignedInteger('cache_hits')->default(0);
            $table->unsignedInteger('stale_hits')->default(0);
            $table->timestamps();

            $table->index(['integration_id', 'created_at']);
            $table->index('retry_of');
        });

        Schema::create("{$prefix}_logs", function (Blueprint $table) use ($prefix): void {
            $table->id();
            $table->foreignId('integration_id')->constrained("{$prefix}s")->cascadeOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained("{$prefix}_logs")->nullOnDelete();
            $table->string('operation');
            $table->string('direction');
            $table->string('status');
            $table->string('external_id')->nullable();
            $table->string('summary')->nullable();
            $table->json('metadata')->nullable();
            $table->text('error')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->timestamps();

            $table->index(['integration_id', 'created_at']);
            $table->index(['integration_id', 'operation']);
            $table->index(['integration_id', 'status']);
            $table->index('parent_id');
        });

        Schema::create("{$prefix}_mappings", function (Blueprint $table) use ($prefix): void {
            $table->id();
            $table->foreignId('integration_id')->constrained("{$prefix}s")->cascadeOnDelete();
            $table->string('external_id');
            $table->string('internal_type');
            $table->string('internal_id');
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['integration_id', 'external_id', 'internal_type']);
            $table->index(['internal_type', 'internal_id']);
        });
    }

    public function down(): void
    {
        $prefix = config('integrations.table_prefix', 'integration');

        Schema::dropIfExists("{$prefix}_mappings");
        Schema::dropIfExists("{$prefix}_logs");
        Schema::dropIfExists("{$prefix}_requests");
        Schema::dropIfExists("{$prefix}s");
    }
};
