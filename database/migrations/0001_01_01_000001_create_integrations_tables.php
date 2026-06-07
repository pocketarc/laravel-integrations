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
            // Runtime circuit-breaker override (operator control, no redeploy).
            // Null = auto. Backs Integrations\Enums\CircuitOverride.
            $table->string('circuit_override')->nullable();
            $table->timestamp('circuit_override_until')->nullable();
            // Runtime rate-limit override; takes precedence over the provider's
            // defaultRateLimit(). JSON: {limit, windowSeconds, window}.
            $table->json('rate_limit_override')->nullable();
            $table->timestamp('rate_limit_override_until')->nullable();
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
            // The FailureClass this request was classified as on the failure
            // path, so observability can group by it without re-classifying.
            // Null on the success path.
            $table->string('failure_class')->nullable();
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
            // "Failures by class for integration X over a window." Named so a
            // custom prefix can't push it past the 64-char identifier limit.
            $table->index(['integration_id', 'failure_class', 'created_at'], "{$prefix}_requests_failure_class_idx");
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
            // Retry-attempt provenance for failures logged inside a sync item
            // run; null otherwise. Facts, not a derived "terminal" flag —
            // terminality isn't knowable at log time.
            $table->unsignedSmallInteger('attempt')->nullable();
            $table->unsignedSmallInteger('max_attempts')->nullable();
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
            $table->string('external_id', 500);
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

        Schema::create("{$prefix}_sync_items", function (Blueprint $table) use ($prefix): void {
            $table->id();
            $table->foreignId('integration_id')->constrained("{$prefix}s")->cascadeOnDelete();
            // Laravel Bus batch UUID. Set once the batch is dispatched; null
            // only in the brief window between row insert and batch dispatch.
            $table->string('batch_id', 36)->nullable();
            // Parent integration_logs row for the sync run this item belongs to.
            $table->foreignId('sync_log_id')->nullable()->constrained("{$prefix}_logs")->nullOnDelete();
            // The per-item event class, for ops/debugging.
            $table->string('event_class');
            // Adapter-provided external identifier, for ops/debugging.
            $table->string('external_id', 500)->nullable();
            // The cursor token this item represents. Reduced into a new
            // sync_cursor by the provider once the batch completes. Nullable:
            // full (non-incremental) syncs may have no meaningful checkpoint.
            $table->json('checkpoint_value')->nullable();
            // pending | processing | success | failed | skipped
            $table->string('status', 16)->default('pending');
            $table->text('error')->nullable();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['integration_id', 'status']);
            $table->index(['integration_id', 'created_at']);
            $table->index(['sync_log_id', 'status']);
            $table->index(['batch_id', 'status']);
        });

        Schema::create("{$prefix}_incidents", function (Blueprint $table) use ($prefix): void {
            $table->id();
            $table->foreignId('integration_id')->constrained("{$prefix}s")->cascadeOnDelete();
            // open | closed
            $table->string('status', 16)->default('open');
            // What opened the incident: health | circuit.
            $table->string('source', 16);
            // The opening reason (e.g. health_degraded, threshold_reached).
            $table->string('reason');
            // Worst HealthStatus reached over the incident's life.
            $table->string('peak_severity');
            $table->timestamp('opened_at');
            // Snapshot of the integration's last_error_at, refreshed on escalate.
            $table->timestamp('last_error_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();

            // "the open incident for integration X" — also the lockForUpdate target.
            $table->index(['integration_id', 'status']);
            // "incidents for integration X since T".
            $table->index(['integration_id', 'opened_at']);
        });
    }

    public function down(): void
    {
        $prefix = Config::tablePrefix();

        Schema::dropIfExists("{$prefix}_incidents");
        Schema::dropIfExists("{$prefix}_sync_items");
        Schema::dropIfExists("{$prefix}_idempotency_keys");
        Schema::dropIfExists("{$prefix}_webhooks");
        Schema::dropIfExists("{$prefix}_mappings");
        Schema::dropIfExists("{$prefix}_logs");
        Schema::dropIfExists("{$prefix}_requests");
        Schema::dropIfExists("{$prefix}s");
    }
};
