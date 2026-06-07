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
    }
};
