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

        Schema::create("{$prefix}_idempotency_reservations", function (Blueprint $table) use ($prefix): void {
            $table->id();
            $table->foreignId('integration_id')->constrained("{$prefix}s")->cascadeOnDelete();
            $table->string('key', 191);
            $table->timestamps();

            $table->unique(['integration_id', 'key'], "{$prefix}_idempotency_reservations_unique");
            $table->index(['integration_id', 'created_at']);
        });
    }

    public function down(): void
    {
        $prefix = Config::tablePrefix();

        Schema::dropIfExists("{$prefix}_idempotency_reservations");
    }
};
