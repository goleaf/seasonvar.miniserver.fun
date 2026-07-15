<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('comments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('target_type', 24);
            $table->unsignedBigInteger('target_id');
            $table->foreignId('catalog_title_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('comments')->restrictOnDelete();
            $table->foreignId('reply_to_id')->nullable()->constrained('comments')->nullOnDelete();
            $table->text('body');
            $table->char('body_hash', 64);
            $table->boolean('is_spoiler')->default(false);
            $table->string('status', 24)->default('published');
            $table->unsignedInteger('version')->default(1);
            $table->timestamp('edited_at')->nullable();
            $table->string('deletion_reason', 24)->nullable();
            $table->foreignId('deleted_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('moderated_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('moderation_reason', 48)->nullable();
            $table->text('moderator_note')->nullable();
            $table->timestamp('moderated_at')->nullable();
            $table->char('submission_key', 64)->nullable()->unique();
            $table->timestamps();
            $table->softDeletes();

            $table->index(
                ['target_type', 'target_id', 'parent_id', 'status', 'created_at', 'id'],
                'comments_target_list_idx',
            );
            $table->index(
                ['parent_id', 'status', 'deleted_at', 'created_at', 'id'],
                'comments_thread_replies_idx',
            );
            $table->index(
                ['user_id', 'status', 'deleted_at', 'created_at', 'id'],
                'comments_author_activity_idx',
            );
            $table->index(
                ['user_id', 'target_type', 'target_id', 'body_hash', 'created_at'],
                'comments_duplicate_window_idx',
            );
            $table->index(['catalog_title_id', 'status', 'deleted_at'], 'comments_title_cache_idx');
            $table->index(['status', 'created_at', 'id'], 'comments_moderation_queue_idx');
            $table->index('reply_to_id', 'comments_reply_context_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('comments');
    }
};
