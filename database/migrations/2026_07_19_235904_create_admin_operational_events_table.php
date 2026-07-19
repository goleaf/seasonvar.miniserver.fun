<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('admin_operational_events', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->string('action_code', 64);
            $table->string('target_code', 128);
            $table->string('status', 24);
            $table->json('result_summary');
            $table->char('idempotency_key', 64)->unique();
            $table->timestamp('occurred_at');

            $table->index(['action_code', 'occurred_at', 'id'], 'admin_operations_action_time_idx');
            $table->index(['status', 'occurred_at', 'id'], 'admin_operations_status_time_idx');
            $table->index(['actor_id', 'occurred_at', 'id'], 'admin_operations_actor_time_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admin_operational_events');
    }
};
