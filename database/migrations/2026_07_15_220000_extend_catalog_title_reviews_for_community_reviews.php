<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('catalog_title_reviews', function (Blueprint $table): void {
            $table->foreignId('user_id')->nullable()->after('source_page_id')->constrained()->nullOnDelete();
            $table->string('origin', 16)->default('provider')->after('user_id');
            $table->string('review_title', 160)->nullable()->after('author');
            $table->char('original_body_hash', 64)->nullable()->after('body_hash');
            $table->boolean('is_spoiler')->default(false)->after('original_body_hash');
            $table->boolean('is_verified_watch')->default(false)->after('is_spoiler');
            $table->string('status', 24)->default('published')->after('is_verified_watch');
            $table->unsignedInteger('version')->default(1)->after('status');
            $table->timestamp('edited_at')->nullable()->after('version');
            $table->string('deletion_reason', 24)->nullable()->after('edited_at');
            $table->foreignId('deleted_by_id')->nullable()->after('deletion_reason')->constrained('users')->nullOnDelete();
            $table->foreignId('moderated_by_id')->nullable()->after('deleted_by_id')->constrained('users')->nullOnDelete();
            $table->string('moderation_reason', 48)->nullable()->after('moderated_by_id');
            $table->text('moderator_note')->nullable()->after('moderation_reason');
            $table->timestamp('moderated_at')->nullable()->after('moderator_note');
            $table->char('ownership_key', 64)->nullable()->after('moderated_at');
            $table->char('submission_key', 64)->nullable()->after('ownership_key');
            $table->foreignId('merged_into_id')->nullable()->after('submission_key')->constrained('catalog_title_reviews')->nullOnDelete();
            $table->string('status_before_merge', 24)->nullable()->after('merged_into_id');
            $table->string('deletion_reason_before_merge', 24)->nullable()->after('status_before_merge');
            $table->timestamp('ownership_released_at')->nullable()->after('deletion_reason_before_merge');
            $table->softDeletes();

            $table->unique('ownership_key', 'catalog_title_reviews_ownership_unique');
            $table->unique('submission_key', 'catalog_title_reviews_submission_unique');
            $table->index(
                ['catalog_title_id', 'status', 'deleted_at', 'published_at', 'id'],
                'catalog_title_reviews_public_list_idx',
            );
            $table->index(
                ['user_id', 'status', 'deleted_at', 'created_at', 'id'],
                'catalog_title_reviews_author_activity_idx',
            );
            $table->index(
                ['status', 'deleted_at', 'created_at', 'id'],
                'catalog_title_reviews_moderation_queue_idx',
            );
            $table->index(
                ['catalog_title_id', 'is_spoiler', 'is_verified_watch', 'status'],
                'catalog_title_reviews_public_filter_idx',
            );
        });

        Schema::create('catalog_title_review_aliases', function (Blueprint $table): void {
            $table->unsignedBigInteger('legacy_review_id')->primary();
            $table->foreignId('canonical_review_id')->constrained('catalog_title_reviews')->restrictOnDelete();
            $table->foreignId('legacy_catalog_title_id')->nullable()->constrained('catalog_titles')->nullOnDelete();
            $table->string('reason', 24)->default('title_merge');
            $table->timestamps();

            $table->index(['canonical_review_id', 'legacy_review_id'], 'review_aliases_canonical_idx');
        });

        Schema::create('catalog_title_review_votes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('catalog_title_review_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('type', 16);
            $table->timestamps();

            $table->unique(
                ['catalog_title_review_id', 'user_id'],
                'review_votes_review_user_unique',
            );
            $table->index(
                ['catalog_title_review_id', 'type'],
                'review_votes_public_totals_idx',
            );
            $table->index(['user_id', 'created_at', 'id'], 'review_votes_user_export_idx');
        });

        Schema::create('catalog_title_review_reports', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('catalog_title_review_id')->constrained()->restrictOnDelete();
            $table->foreignId('reporter_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('moderator_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('category', 48);
            $table->text('details')->nullable();
            $table->string('status', 24)->default('open');
            $table->text('private_note')->nullable();
            $table->char('deduplication_key', 64)->nullable()->unique();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->index(
                ['catalog_title_review_id', 'status', 'created_at'],
                'review_reports_review_status_idx',
            );
            $table->index(['status', 'created_at', 'id'], 'review_reports_moderation_queue_idx');
            $table->index(['reporter_id', 'created_at', 'id'], 'review_reports_reporter_idx');
        });

        Schema::create('catalog_title_review_restrictions', function (Blueprint $table): void {
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
                'review_restrictions_active_idx',
            );
            $table->index(
                ['type', 'revoked_at', 'created_at', 'id'],
                'review_restrictions_admin_idx',
            );
        });

        Schema::create('catalog_title_review_notification_preferences', function (Blueprint $table): void {
            $table->foreignId('user_id')->primary()->constrained()->cascadeOnDelete();
            $table->boolean('helpful_notifications')->default(true);
            $table->boolean('moderation_notifications')->default(true);
            $table->boolean('report_notifications')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('catalog_title_review_notification_preferences');
        Schema::dropIfExists('catalog_title_review_restrictions');
        Schema::dropIfExists('catalog_title_review_reports');
        Schema::dropIfExists('catalog_title_review_votes');
        Schema::dropIfExists('catalog_title_review_aliases');

        Schema::table('catalog_title_reviews', function (Blueprint $table): void {
            $table->dropIndex('catalog_title_reviews_public_list_idx');
            $table->dropIndex('catalog_title_reviews_author_activity_idx');
            $table->dropIndex('catalog_title_reviews_moderation_queue_idx');
            $table->dropIndex('catalog_title_reviews_public_filter_idx');
            $table->dropUnique('catalog_title_reviews_ownership_unique');
            $table->dropUnique('catalog_title_reviews_submission_unique');
            $table->dropForeign(['merged_into_id']);
            $table->dropForeign(['moderated_by_id']);
            $table->dropForeign(['deleted_by_id']);
            $table->dropForeign(['user_id']);
            $table->dropColumn([
                'user_id',
                'origin',
                'review_title',
                'original_body_hash',
                'is_spoiler',
                'is_verified_watch',
                'status',
                'version',
                'edited_at',
                'deletion_reason',
                'deleted_by_id',
                'moderated_by_id',
                'moderation_reason',
                'moderator_note',
                'moderated_at',
                'ownership_key',
                'submission_key',
                'merged_into_id',
                'status_before_merge',
                'deletion_reason_before_merge',
                'ownership_released_at',
                'deleted_at',
            ]);
        });
    }
};
