<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('account_restrictions', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('type', 32);
            $table->string('reason_code', 64);
            $table->string('public_notice_key', 128);
            $table->text('private_note')->nullable();
            $table->foreignId('applied_by_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('revoked_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('starts_at');
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->string('revocation_reason_code', 64)->nullable();
            $table->timestamps();

            $table->index(['user_id', 'revoked_at', 'starts_at', 'expires_at'], 'account_restrictions_user_active_idx');
            $table->index(['type', 'revoked_at', 'created_at', 'id'], 'account_restrictions_type_queue_idx');
            $table->index(['applied_by_id', 'created_at', 'id'], 'account_restrictions_actor_time_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('account_restrictions');
    }
};
