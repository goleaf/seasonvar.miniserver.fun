<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('comment_reactions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('comment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('type', 16);
            $table->timestamps();

            $table->unique(['comment_id', 'user_id'], 'comment_reactions_comment_user_unique');
            $table->index(['comment_id', 'type'], 'comment_reactions_totals_idx');
            $table->index(['user_id', 'created_at', 'id'], 'comment_reactions_user_export_idx');
        });

        Schema::create('comment_reports', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('comment_id')->constrained()->restrictOnDelete();
            $table->foreignId('reporter_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('moderator_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('category', 48);
            $table->text('details')->nullable();
            $table->string('status', 24)->default('open');
            $table->text('private_note')->nullable();
            $table->char('deduplication_key', 64)->nullable()->unique();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->index(['comment_id', 'status', 'created_at'], 'comment_reports_comment_status_idx');
            $table->index(['status', 'created_at', 'id'], 'comment_reports_moderation_queue_idx');
            $table->index(['reporter_id', 'created_at', 'id'], 'comment_reports_reporter_idx');
        });

        Schema::create('comment_restrictions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('moderator_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('revoked_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('type', 24);
            $table->string('reason_code', 48);
            $table->text('private_note')->nullable();
            $table->timestamp('starts_at');
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->index(
                ['user_id', 'revoked_at', 'starts_at', 'expires_at', 'id'],
                'comment_restrictions_active_idx',
            );
            $table->index(['type', 'revoked_at', 'created_at', 'id'], 'comment_restrictions_admin_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('comment_restrictions');
        Schema::dropIfExists('comment_reports');
        Schema::dropIfExists('comment_reactions');
    }
};
