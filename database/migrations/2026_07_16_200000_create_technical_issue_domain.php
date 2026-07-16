<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('technical_issues', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->string('public_number', 32)->unique();
            $table->foreignId('requester_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('assigned_to_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('support_team', 32)->default('support');
            $table->string('type', 64);
            $table->string('status', 40)->default('submitted');
            $table->string('severity', 16)->default('medium');
            $table->string('priority', 16)->default('normal');
            $table->string('target_type', 24);
            $table->string('target_label_snapshot', 300);
            $table->foreignId('catalog_title_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('season_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('episode_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('licensed_media_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('translation_id')->nullable()->constrained()->nullOnDelete();
            $table->string('feature_code', 32)->nullable();
            $table->string('route_name', 120)->nullable();
            $table->string('route_path', 240)->nullable();
            $table->string('locale', 16);
            $table->string('summary', 240)->nullable();
            $table->text('expected_behavior')->nullable();
            $table->text('actual_behavior')->nullable();
            $table->text('reproduction_steps')->nullable();
            $table->unsignedInteger('playback_position_seconds')->nullable();
            $table->string('audio_language', 16)->nullable();
            $table->string('subtitle_language', 16)->nullable();
            $table->string('quality_code', 24)->nullable();
            $table->string('public_error_code', 48)->nullable();
            $table->boolean('diagnostics_consent')->default(false);
            $table->char('exact_identity_hash', 64);
            $table->char('active_identity_key', 64)->nullable()->unique();
            $table->char('submission_key', 64)->unique();
            $table->foreignId('merged_into_id')->nullable()->constrained('technical_issues')->nullOnDelete();
            $table->string('resolution_type', 48)->nullable();
            $table->text('resolution_summary')->nullable();
            $table->string('rejection_reason', 48)->nullable();
            $table->string('rerouted_to', 32)->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->unsignedSmallInteger('reopen_count')->default(0);
            $table->timestamp('last_public_message_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamp('withdrawn_at')->nullable();
            $table->timestamps();

            $table->index(['requester_id', 'status', 'updated_at', 'id'], 'technical_issues_requester_status_idx');
            $table->index(['type', 'target_type', 'status', 'updated_at', 'id'], 'technical_issues_type_target_status_idx');
            $table->index(['catalog_title_id', 'type', 'status'], 'technical_issues_title_duplicate_idx');
            $table->index(['episode_id', 'licensed_media_id', 'type', 'status'], 'technical_issues_playback_duplicate_idx');
            $table->index(['assigned_to_id', 'status', 'priority', 'updated_at'], 'technical_issues_assignment_queue_idx');
            $table->index(['status', 'severity', 'priority', 'created_at', 'id'], 'technical_issues_support_queue_idx');
            $table->index(['route_name', 'public_error_code', 'status'], 'technical_issues_page_error_idx');
            $table->index(['public_error_code', 'status', 'id'], 'technical_issues_error_code_idx');
            $table->index('target_label_snapshot', 'technical_issues_target_label_search_idx');
            $table->index('summary', 'technical_issues_summary_search_idx');
            $table->index(['merged_into_id', 'status'], 'technical_issues_merged_idx');
        });

        Schema::create('technical_issue_diagnostics', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('technical_issue_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('authenticated_category', 16)->default('authenticated');
            $table->string('browser_family', 24)->nullable();
            $table->unsignedSmallInteger('browser_major')->nullable();
            $table->string('operating_system', 24)->nullable();
            $table->string('device_category', 16)->nullable();
            $table->unsignedSmallInteger('viewport_width')->nullable();
            $table->unsignedSmallInteger('viewport_height')->nullable();
            $table->string('timezone', 64)->nullable();
            $table->boolean('network_online')->nullable();
            $table->string('player_component', 32)->nullable();
            $table->string('source_health_code', 32)->nullable();
            $table->timestamps();
            $table->index(['browser_family', 'operating_system', 'device_category'], 'technical_issue_diagnostic_client_idx');
        });

        Schema::create('technical_issue_messages', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('technical_issue_id')->constrained()->cascadeOnDelete();
            $table->foreignId('author_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('visibility', 24);
            $table->string('kind', 24)->default('message');
            $table->text('body');
            $table->char('body_hash', 64);
            $table->char('submission_key', 64)->unique();
            $table->timestamp('redacted_at')->nullable();
            $table->timestamps();
            $table->index(['technical_issue_id', 'visibility', 'created_at', 'id'], 'technical_issue_message_timeline_idx');
        });

        Schema::create('technical_issue_attachments', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('technical_issue_id')->constrained()->cascadeOnDelete();
            $table->foreignId('technical_issue_message_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('uploader_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('disk', 32);
            $table->string('path', 500)->unique();
            $table->string('display_name', 120);
            $table->string('mime_type', 32);
            $table->string('extension', 8);
            $table->unsignedBigInteger('size_bytes');
            $table->unsignedSmallInteger('width');
            $table->unsignedSmallInteger('height');
            $table->char('content_hash', 64);
            $table->timestamps();
            $table->index(['technical_issue_id', 'created_at', 'id'], 'technical_issue_attachment_timeline_idx');
        });

        Schema::create('technical_issue_status_histories', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('technical_issue_id')->constrained()->cascadeOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('from_status', 40)->nullable();
            $table->string('to_status', 40);
            $table->string('public_reason_code', 48)->nullable();
            $table->text('public_message')->nullable();
            $table->text('private_note')->nullable();
            $table->char('idempotency_key', 64)->nullable()->unique();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['technical_issue_id', 'created_at', 'id'], 'technical_issue_status_timeline_idx');
        });

        Schema::create('technical_issue_assignments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('technical_issue_id')->constrained()->cascadeOnDelete();
            $table->foreignId('assigned_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('assignee_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('support_team', 32);
            $table->timestamp('ended_at')->nullable();
            $table->timestamps();
            $table->index(['technical_issue_id', 'ended_at', 'id'], 'technical_issue_assignment_history_idx');
            $table->index(['assignee_id', 'ended_at', 'created_at'], 'technical_issue_assignee_active_idx');
        });

        Schema::create('technical_issue_confirmations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('technical_issue_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('verification_state', 16)->nullable();
            $table->timestamps();
            $table->unique(['technical_issue_id', 'user_id'], 'technical_issue_confirmation_user_unique');
            $table->index(['user_id', 'created_at', 'id'], 'technical_issue_confirmation_user_idx');
        });

        Schema::create('technical_issue_followers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('technical_issue_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['technical_issue_id', 'user_id'], 'technical_issue_follower_user_unique');
            $table->index(['user_id', 'created_at', 'id'], 'technical_issue_follower_user_idx');
        });

        Schema::create('technical_issue_occurrences', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('technical_issue_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('browser_family', 24)->nullable();
            $table->unsignedSmallInteger('browser_major')->nullable();
            $table->string('operating_system', 24)->nullable();
            $table->string('device_category', 16)->nullable();
            $table->unsignedSmallInteger('viewport_width')->nullable();
            $table->unsignedSmallInteger('viewport_height')->nullable();
            $table->string('timezone', 64)->nullable();
            $table->boolean('network_online')->nullable();
            $table->unsignedInteger('playback_position_seconds')->nullable();
            $table->string('public_error_code', 48)->nullable();
            $table->string('source_health_code', 32)->nullable();
            $table->timestamp('occurred_at')->useCurrent();
            $table->timestamp('diagnostics_pruned_at')->nullable();
            $table->timestamps();
            $table->unique(['technical_issue_id', 'user_id'], 'technical_issue_occurrence_user_unique');
            $table->index(['technical_issue_id', 'occurred_at', 'id'], 'technical_issue_occurrence_timeline_idx');
            $table->index(['technical_issue_id', 'browser_family', 'device_category'], 'technical_issue_occurrence_client_idx');
        });

        Schema::create('technical_issue_merges', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('duplicate_issue_id')->unique()->constrained('technical_issues')->cascadeOnDelete();
            $table->foreignId('canonical_issue_id')->constrained('technical_issues')->cascadeOnDelete();
            $table->foreignId('merged_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['canonical_issue_id', 'created_at'], 'technical_issue_merge_canonical_idx');
        });

        Schema::create('technical_issue_redactions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('technical_issue_id')->constrained()->cascadeOnDelete();
            $table->foreignId('technical_issue_message_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('field', 48);
            $table->string('reason_code', 48);
            $table->char('before_hash', 64);
            $table->char('after_hash', 64);
            $table->timestamp('created_at')->useCurrent();
            $table->index(['technical_issue_id', 'created_at', 'id'], 'technical_issue_redaction_audit_idx');
        });

        Schema::create('technical_issue_source_actions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('technical_issue_id')->constrained()->cascadeOnDelete();
            $table->foreignId('licensed_media_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action', 24);
            $table->string('from_health_status', 24)->nullable();
            $table->string('to_health_status', 24)->nullable();
            $table->text('private_note')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['licensed_media_id', 'created_at', 'id'], 'technical_issue_source_action_media_idx');
        });

        Schema::create('technical_issue_notification_preferences', function (Blueprint $table): void {
            $table->foreignId('user_id')->primary()->constrained()->cascadeOnDelete();
            $table->boolean('requester_updates')->default(true);
            $table->boolean('confirmer_updates')->default(true);
            $table->boolean('follower_updates')->default(true);
            $table->boolean('support_replies')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('technical_issue_notification_preferences');
        Schema::dropIfExists('technical_issue_source_actions');
        Schema::dropIfExists('technical_issue_redactions');
        Schema::dropIfExists('technical_issue_merges');
        Schema::dropIfExists('technical_issue_occurrences');
        Schema::dropIfExists('technical_issue_followers');
        Schema::dropIfExists('technical_issue_confirmations');
        Schema::dropIfExists('technical_issue_assignments');
        Schema::dropIfExists('technical_issue_status_histories');
        Schema::dropIfExists('technical_issue_attachments');
        Schema::dropIfExists('technical_issue_messages');
        Schema::dropIfExists('technical_issue_diagnostics');
        Schema::dropIfExists('technical_issues');
    }
};
