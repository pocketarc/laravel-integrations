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
            $table->index(['batch_id', 'status']);
        });
    }

    public function down(): void
    {
        $prefix = Config::tablePrefix();

        Schema::dropIfExists("{$prefix}_sync_items");
    }
};
