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
            $table->json('sync_cursor')->nullable();
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
            $table->string('idempotency_key', 191)->nullable();
            $table->string('provider_request_id', 128)->nullable();
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

            $table->string('request_data_hash', 32)->nullable()->index();
            $table->index(['integration_id', 'created_at']);
            $table->index(['endpoint', 'method', 'response_success']);
            $table->index('retry_of');
            $table->index('idempotency_key');
            $table->index('provider_request_id');
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
            $table->json('result_data')->nullable();
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

            $table->unique(
                ['integration_id', 'external_id', 'internal_type'],
                "{$prefix}_mappings_ext_int_unique",
            );
            $table->index(['internal_type', 'internal_id']);
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

        Schema::create("{$prefix}_idempotency_keys", function (Blueprint $table) use ($prefix): void {
            $table->id();
            $table->foreignId('integration_id')->constrained("{$prefix}s")->cascadeOnDelete();
            // Length must match IntegrationIdempotencyKey::MAX_KEY_LENGTH.
            $table->string('key', 191);
            $table->timestamps();

            $table->unique(['integration_id', 'key'], "{$prefix}_idempotency_keys_unique");
            $table->index(['integration_id', 'created_at']);
            $table->index(['created_at', 'id']);
        });
    }

    public function down(): void
    {
        $prefix = Config::tablePrefix();

        Schema::dropIfExists("{$prefix}_idempotency_keys");
        Schema::dropIfExists("{$prefix}_webhooks");
        Schema::dropIfExists("{$prefix}_mappings");
        Schema::dropIfExists("{$prefix}_logs");
        Schema::dropIfExists("{$prefix}_requests");
        Schema::dropIfExists("{$prefix}s");
    }
};
