<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_profiles', function (Blueprint $table): void {
            $table->foreignId('user_id')->primary()->constrained()->cascadeOnDelete();
            $table->string('username', 32);
            $table->string('normalized_username', 32)->unique();
            $table->text('biography')->nullable();
            $table->string('profile_visibility', 24)->default('private');
            $table->string('biography_visibility', 24)->default('private');
            $table->string('member_since_visibility', 24)->default('private');
            $table->string('collections_visibility', 24)->default('private');
            $table->string('reviews_visibility', 24)->default('private');
            $table->string('comments_visibility', 24)->default('private');
            $table->string('watching_visibility', 24)->default('private');
            $table->string('completed_visibility', 24)->default('private');
            $table->string('activity_visibility', 24)->default('private');
            $table->string('moderation_status', 24)->default('active');
            $table->string('avatar_disk', 64)->nullable();
            $table->string('avatar_path', 512)->nullable();
            $table->string('avatar_mime_type', 96)->nullable();
            $table->unsignedBigInteger('avatar_size')->nullable();
            $table->unsignedBigInteger('avatar_version')->default(0);
            $table->string('cover_disk', 64)->nullable();
            $table->string('cover_path', 512)->nullable();
            $table->string('cover_mime_type', 96)->nullable();
            $table->unsignedBigInteger('cover_size')->nullable();
            $table->unsignedBigInteger('cover_version')->default(0);
            $table->unsignedBigInteger('content_version')->default(1);
            $table->timestamps();

            $table->index(
                ['profile_visibility', 'moderation_status', 'updated_at', 'user_id'],
                'user_profiles_public_listing_idx',
            );
        });

        Schema::create('user_profile_username_histories', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('username', 32);
            $table->string('normalized_username', 32)->unique();
            $table->timestamps();

            $table->index(['user_id', 'created_at'], 'user_profile_username_history_user_idx');
        });

        Schema::create('user_profile_reports', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('target_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->uuid('target_public_id');
            $table->foreignId('reporter_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('moderator_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('category', 32);
            $table->text('details')->nullable();
            $table->string('status', 24)->default('open');
            $table->text('private_note')->nullable();
            $table->char('deduplication_key', 64)->unique();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->index(['target_user_id', 'status', 'created_at'], 'user_profile_reports_target_status_idx');
            $table->index(['status', 'created_at', 'id'], 'user_profile_reports_moderation_queue_idx');
            $table->index(['reporter_id', 'created_at'], 'user_profile_reports_reporter_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_profile_reports');
        Schema::dropIfExists('user_profile_username_histories');
        Schema::dropIfExists('user_profiles');
    }
};
