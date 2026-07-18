<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('help_categories', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->string('code', 64)->unique();
            $table->foreignId('parent_id')->nullable()->constrained('help_categories')->restrictOnDelete();
            $table->unsignedSmallInteger('position')->default(0);
            $table->boolean('is_visible')->default(true);
            $table->unsignedInteger('content_version')->default(1);
            $table->timestamps();

            $table->index(['is_visible', 'parent_id', 'position', 'id'], 'help_categories_public_order_idx');
        });

        Schema::create('help_category_translations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('help_category_id')->constrained()->cascadeOnDelete();
            $table->string('locale', 12);
            $table->string('slug', 160);
            $table->string('title', 160);
            $table->string('description', 500);
            $table->string('seo_title', 180)->nullable();
            $table->string('seo_description', 320)->nullable();
            $table->timestamps();

            $table->unique(['help_category_id', 'locale'], 'help_category_translation_unique');
            $table->unique(['locale', 'slug'], 'help_category_locale_slug_unique');
            $table->index(['locale', 'title'], 'help_category_locale_title_idx');
        });

        Schema::create('help_category_slugs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('help_category_id')->constrained()->cascadeOnDelete();
            $table->string('locale', 12);
            $table->string('slug', 160);
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['locale', 'slug'], 'help_category_slug_history_unique');
            $table->index(['help_category_id', 'locale', 'id'], 'help_category_slug_category_idx');
        });

        Schema::create('help_articles', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->string('code', 96)->unique();
            $table->foreignId('help_category_id')->constrained()->restrictOnDelete();
            $table->foreignId('replacement_article_id')->nullable()->constrained('help_articles')->nullOnDelete();
            $table->string('type', 32);
            $table->string('audience', 24)->default('everyone');
            $table->string('status', 24)->default('draft');
            $table->string('owner_team', 32);
            $table->string('feature_code', 32)->default('general');
            $table->string('primary_escalation', 32)->default('none');
            $table->string('secondary_escalation', 32)->default('none');
            $table->string('escalation_issue_type', 64)->nullable();
            $table->string('escalation_request_type', 64)->nullable();
            $table->unsignedSmallInteger('position')->default(0);
            $table->unsignedSmallInteger('editorial_priority')->default(0);
            $table->boolean('is_featured')->default(false);
            $table->boolean('is_indexable')->default(true);
            $table->foreignId('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedInteger('content_version')->default(1);
            $table->timestamp('published_at')->nullable();
            $table->timestamp('last_reviewed_at')->nullable();
            $table->timestamp('review_due_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'audience', 'published_at', 'id'], 'help_articles_publication_idx');
            $table->index(['help_category_id', 'status', 'position', 'id'], 'help_articles_category_order_idx');
            $table->index(['feature_code', 'status', 'editorial_priority', 'id'], 'help_articles_feature_idx');
            $table->index(['is_featured', 'status', 'editorial_priority', 'id'], 'help_articles_featured_idx');
            $table->index(['owner_team', 'review_due_at', 'status', 'id'], 'help_articles_review_due_idx');
        });

        Schema::create('help_article_translations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('help_article_id')->constrained()->cascadeOnDelete();
            $table->string('locale', 12);
            $table->string('slug', 180);
            $table->string('title', 220);
            $table->string('summary', 700);
            $table->text('body_markdown');
            $table->text('search_text');
            $table->text('keywords')->nullable();
            $table->string('seo_title', 180)->nullable();
            $table->string('seo_description', 320)->nullable();
            $table->string('callout_text', 500)->nullable();
            $table->string('callout_type', 24)->nullable();
            $table->boolean('is_published')->default(false);
            $table->timestamp('published_at')->nullable();
            $table->timestamp('links_checked_at')->nullable();
            $table->string('link_status', 24)->default('unchecked');
            $table->json('link_errors')->nullable();
            $table->timestamps();

            $table->unique(['help_article_id', 'locale'], 'help_article_translation_unique');
            $table->unique(['locale', 'slug'], 'help_article_locale_slug_unique');
            $table->index(['locale', 'is_published', 'help_article_id'], 'help_article_translation_public_idx');
            $table->index(['locale', 'title'], 'help_article_translation_title_idx');
            $table->index(['link_status', 'links_checked_at', 'id'], 'help_article_translation_link_idx');
        });

        Schema::create('help_article_slugs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('help_article_id')->constrained()->cascadeOnDelete();
            $table->string('locale', 12);
            $table->string('slug', 180);
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['locale', 'slug'], 'help_article_slug_history_unique');
            $table->index(['help_article_id', 'locale', 'id'], 'help_article_slug_article_idx');
        });

        Schema::create('help_article_aliases', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('help_article_id')->constrained()->cascadeOnDelete();
            $table->string('locale', 12);
            $table->string('alias', 220);
            $table->string('normalized_alias', 220);
            $table->unsignedSmallInteger('priority')->default(0);
            $table->timestamps();

            $table->unique(['help_article_id', 'locale', 'normalized_alias'], 'help_article_alias_unique');
            $table->index(['locale', 'normalized_alias', 'priority', 'id'], 'help_article_alias_search_idx');
        });

        Schema::create('help_article_relations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('help_article_id')->constrained()->cascadeOnDelete();
            $table->foreignId('related_article_id')->constrained('help_articles')->cascadeOnDelete();
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();

            $table->unique(['help_article_id', 'related_article_id'], 'help_article_relation_unique');
            $table->index(['help_article_id', 'position', 'related_article_id'], 'help_article_relation_order_idx');
        });

        Schema::create('help_article_revisions', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('help_article_id')->constrained()->cascadeOnDelete();
            $table->foreignId('editor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('locale', 12);
            $table->unsignedInteger('revision');
            $table->string('article_status', 24);
            $table->boolean('translation_published');
            $table->string('slug', 180);
            $table->string('title', 220);
            $table->string('summary', 700);
            $table->text('body_markdown');
            $table->text('keywords')->nullable();
            $table->string('seo_title', 180)->nullable();
            $table->string('seo_description', 320)->nullable();
            $table->string('callout_text', 500)->nullable();
            $table->string('callout_type', 24)->nullable();
            $table->string('change_note', 500)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['help_article_id', 'locale', 'revision'], 'help_article_revision_unique');
            $table->index(['help_article_id', 'locale', 'created_at', 'id'], 'help_article_revision_timeline_idx');
        });

        Schema::create('help_article_feedback', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('help_article_id')->constrained()->cascadeOnDelete();
            $table->foreignId('help_article_translation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('actor_key', 64);
            $table->string('locale', 12);
            $table->string('value', 24);
            $table->string('reason', 32)->nullable();
            $table->timestamps();

            $table->unique(['help_article_translation_id', 'actor_key'], 'help_article_feedback_actor_unique');
            $table->index(['help_article_id', 'locale', 'value', 'id'], 'help_article_feedback_aggregate_idx');
            $table->index(['user_id', 'created_at', 'id'], 'help_article_feedback_user_idx');
        });

        Schema::create('help_article_reports', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('help_article_id')->constrained()->cascadeOnDelete();
            $table->foreignId('help_article_translation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('reporter_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('reviewed_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('actor_key', 64);
            $table->string('dedupe_key', 64)->unique();
            $table->string('locale', 12);
            $table->string('reason', 32);
            $table->string('details', 1000)->nullable();
            $table->string('status', 24)->default('open');
            $table->text('private_note')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at', 'id'], 'help_article_reports_queue_idx');
            $table->index(['help_article_id', 'status', 'created_at', 'id'], 'help_article_reports_article_idx');
            $table->index(['reporter_id', 'created_at', 'id'], 'help_article_reports_user_idx');
        });

        Schema::create('help_contextual_links', function (Blueprint $table): void {
            $table->id();
            $table->string('feature_code', 32);
            $table->string('context_code', 64);
            $table->foreignId('help_article_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('position')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['feature_code', 'context_code', 'help_article_id'], 'help_contextual_link_unique');
            $table->index(['feature_code', 'context_code', 'is_active', 'position'], 'help_contextual_link_lookup_idx');
        });

        if (Schema::hasTable('technical_issues') && ! Schema::hasColumn('technical_issues', 'help_article_id')) {
            Schema::table('technical_issues', function (Blueprint $table): void {
                $table->foreignId('help_article_id')
                    ->nullable()
                    ->after('translation_id')
                    ->constrained('help_articles')
                    ->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('technical_issues') && Schema::hasColumn('technical_issues', 'help_article_id')) {
            Schema::table('technical_issues', function (Blueprint $table): void {
                $table->dropConstrainedForeignId('help_article_id');
            });
        }

        Schema::dropIfExists('help_contextual_links');
        Schema::dropIfExists('help_article_reports');
        Schema::dropIfExists('help_article_feedback');
        Schema::dropIfExists('help_article_revisions');
        Schema::dropIfExists('help_article_relations');
        Schema::dropIfExists('help_article_aliases');
        Schema::dropIfExists('help_article_slugs');
        Schema::dropIfExists('help_article_translations');
        Schema::dropIfExists('help_articles');
        Schema::dropIfExists('help_category_slugs');
        Schema::dropIfExists('help_category_translations');
        Schema::dropIfExists('help_categories');
    }
};
