<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('content_requests', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('requester_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('type', 48);
            $table->string('status', 32)->default('submitted');
            $table->string('priority', 16)->default('normal');
            $table->string('title', 240);
            $table->string('normalized_title', 240);
            $table->char('normalized_title_hash', 64);
            $table->string('original_title', 240)->nullable();
            $table->string('alternative_title', 240)->nullable();
            $table->unsignedSmallInteger('release_year')->nullable();
            $table->string('country', 100)->nullable();
            $table->string('content_locale', 16)->nullable();
            $table->string('original_language', 16)->nullable();
            $table->string('audio_language', 16)->nullable();
            $table->string('subtitle_language', 16)->nullable();
            $table->string('translation_type', 32)->nullable();
            $table->string('translation_studio', 120)->nullable();
            $table->foreignId('catalog_title_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('season_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('episode_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedSmallInteger('season_number')->nullable();
            $table->string('season_kind', 16)->nullable();
            $table->unsignedInteger('episode_number')->nullable();
            $table->date('episode_release_date')->nullable();
            $table->string('current_quality', 16)->nullable();
            $table->string('requested_quality', 16)->nullable();
            $table->string('correction_field', 48)->nullable();
            $table->text('current_value')->nullable();
            $table->text('proposed_value')->nullable();
            $table->text('explanation')->nullable();
            $table->text('different_explanation')->nullable();
            $table->char('exact_identity_hash', 64);
            $table->char('active_identity_key', 64)->nullable()->unique();
            $table->char('submission_key', 64)->unique();
            $table->boolean('probable_duplicate')->default(false);
            $table->boolean('is_public')->default(true);
            $table->string('rejection_reason', 48)->nullable();
            $table->text('public_note')->nullable();
            $table->text('private_moderator_note')->nullable();
            $table->foreignId('merged_into_id')->nullable()->constrained('content_requests')->nullOnDelete();
            $table->foreignId('completed_catalog_title_id')->nullable()->constrained('catalog_titles')->nullOnDelete();
            $table->foreignId('completed_season_id')->nullable()->constrained('seasons')->nullOnDelete();
            $table->foreignId('completed_episode_id')->nullable()->constrained('episodes')->nullOnDelete();
            $table->foreignId('completed_media_id')->nullable()->constrained('licensed_media')->nullOnDelete();
            $table->foreignId('source_page_id')->nullable()->constrained('source_pages')->nullOnDelete();
            $table->foreignId('import_run_id')->nullable()->constrained('seasonvar_import_runs')->nullOnDelete();
            $table->unsignedInteger('version')->default(1);
            $table->timestamp('partial_completed_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('withdrawn_at')->nullable();
            $table->timestamps();

            $table->index(['is_public', 'status', 'created_at', 'id'], 'content_requests_public_status_idx');
            $table->index(['type', 'status', 'updated_at', 'id'], 'content_requests_type_status_idx');
            $table->index(['requester_id', 'created_at', 'id'], 'content_requests_requester_idx');
            $table->index(['normalized_title_hash', 'release_year', 'type'], 'content_requests_title_duplicate_idx');
            $table->index(['catalog_title_id', 'type', 'status'], 'content_requests_title_target_idx');
            $table->index(['season_id', 'type', 'status'], 'content_requests_season_target_idx');
            $table->index(['episode_id', 'type', 'status'], 'content_requests_episode_target_idx');
            $table->index(['merged_into_id', 'status'], 'content_requests_merge_idx');
        });

        Schema::create('content_request_votes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('content_request_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['content_request_id', 'user_id'], 'content_request_votes_request_user_unique');
            $table->index(['user_id', 'created_at', 'id'], 'content_request_votes_user_idx');
        });

        Schema::create('content_request_followers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('content_request_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['content_request_id', 'user_id'], 'content_request_follows_request_user_unique');
            $table->index(['user_id', 'created_at', 'id'], 'content_request_follows_user_idx');
        });

        Schema::create('content_request_status_histories', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('content_request_id')->constrained()->cascadeOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('from_status', 32)->nullable();
            $table->string('to_status', 32);
            $table->text('public_reason')->nullable();
            $table->text('private_note')->nullable();
            $table->char('idempotency_key', 64)->nullable()->unique();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['content_request_id', 'created_at', 'id'], 'content_request_history_timeline_idx');
        });

        Schema::create('content_request_source_links', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('content_request_id')->constrained()->cascadeOnDelete();
            $table->foreignId('added_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('verified_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('url');
            $table->char('url_hash', 64);
            $table->string('provider', 32)->nullable();
            $table->boolean('is_public')->default(false);
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();
            $table->unique(['content_request_id', 'url_hash'], 'content_request_source_url_unique');
            $table->index(['content_request_id', 'is_public', 'id'], 'content_request_source_public_idx');
        });

        Schema::create('content_request_external_identifiers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('content_request_id')->constrained()->cascadeOnDelete();
            $table->string('provider', 32);
            $table->string('identifier', 120);
            $table->string('normalized_identifier', 120);
            $table->timestamps();
            $table->unique(
                ['content_request_id', 'provider', 'normalized_identifier'],
                'content_request_external_request_unique',
            );
            $table->index(
                ['provider', 'normalized_identifier', 'content_request_id'],
                'content_request_external_duplicate_idx',
            );
        });

        Schema::create('content_request_clarifications', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('content_request_id')->constrained()->cascadeOnDelete();
            $table->foreignId('author_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('author_role', 16);
            $table->text('body');
            $table->char('body_hash', 64);
            $table->char('submission_key', 64)->unique();
            $table->timestamps();
            $table->index(['content_request_id', 'created_at', 'id'], 'content_request_clarification_timeline_idx');
        });

        Schema::create('content_request_notification_preferences', function (Blueprint $table): void {
            $table->foreignId('user_id')->primary()->constrained()->cascadeOnDelete();
            $table->boolean('requester_updates')->default(true);
            $table->boolean('voted_updates')->default(true);
            $table->boolean('followed_updates')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('content_request_notification_preferences');
        Schema::dropIfExists('content_request_clarifications');
        Schema::dropIfExists('content_request_external_identifiers');
        Schema::dropIfExists('content_request_source_links');
        Schema::dropIfExists('content_request_status_histories');
        Schema::dropIfExists('content_request_followers');
        Schema::dropIfExists('content_request_votes');
        Schema::dropIfExists('content_requests');
    }
};
