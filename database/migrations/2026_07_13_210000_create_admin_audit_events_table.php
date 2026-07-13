<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('admin_audit_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->string('action', 64);
            $table->string('resource_type', 64);
            $table->unsignedBigInteger('resource_id');
            $table->char('before_version', 64);
            $table->char('after_version', 64);
            $table->json('changed_fields');
            $table->timestamp('occurred_at');

            $table->index(['resource_type', 'resource_id', 'occurred_at'], 'admin_audit_resource_time_idx');
            $table->index(['actor_id', 'occurred_at'], 'admin_audit_actor_time_idx');
            $table->index(['action', 'occurred_at'], 'admin_audit_action_time_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admin_audit_events');
    }
};
