<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('catalog_title_user_states', function (Blueprint $table): void {
            $table->string('recommendation_feedback', 32)->nullable();
            $table->unsignedBigInteger('recommendation_feedback_version')->default(0);
            $table->timestamp('recommendation_feedback_updated_at')->nullable();
            $table->string('watch_status', 24)->nullable();
            $table->unsignedBigInteger('watch_status_version')->default(0);

            $table->index(
                ['user_id', 'recommendation_feedback', 'catalog_title_id'],
                'catalog_user_state_recommendation_feedback_idx',
            );
            $table->index(
                ['user_id', 'watch_status', 'updated_at', 'catalog_title_id'],
                'catalog_user_state_watch_status_idx',
            );
            $table->index(
                ['in_watchlist', 'updated_at', 'catalog_title_id', 'user_id'],
                'catalog_user_state_recent_watchlist_idx',
            );
        });

        Schema::table('episode_view_progress', function (Blueprint $table): void {
            $table->index(
                ['last_watched_at', 'catalog_title_id', 'user_id'],
                'episode_progress_recent_title_viewers_idx',
            );
        });

        Schema::create('catalog_title_relations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('source_title_id')->constrained('catalog_titles')->cascadeOnDelete();
            $table->foreignId('target_title_id')->constrained('catalog_titles')->cascadeOnDelete();
            $table->string('relation_type', 32);
            $table->string('source', 32);
            $table->string('provider_key', 64)->nullable();
            $table->unsignedSmallInteger('priority')->default(100);
            $table->boolean('is_locked')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(
                ['source_title_id', 'target_title_id', 'relation_type', 'source'],
                'catalog_title_relations_identity_unique',
            );
            $table->index(
                ['source_title_id', 'is_active', 'priority', 'id'],
                'catalog_title_relations_source_display_idx',
            );
            $table->index(
                ['target_title_id', 'relation_type', 'is_active'],
                'catalog_title_relations_target_lookup_idx',
            );
            $table->index(
                ['source', 'provider_key'],
                'catalog_title_relations_provider_idx',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('catalog_title_relations');

        Schema::table('episode_view_progress', function (Blueprint $table): void {
            $table->dropIndex('episode_progress_recent_title_viewers_idx');
        });

        Schema::table('catalog_title_user_states', function (Blueprint $table): void {
            $table->dropIndex('catalog_user_state_recommendation_feedback_idx');
            $table->dropIndex('catalog_user_state_watch_status_idx');
            $table->dropIndex('catalog_user_state_recent_watchlist_idx');
            $table->dropColumn([
                'recommendation_feedback',
                'recommendation_feedback_version',
                'recommendation_feedback_updated_at',
                'watch_status',
                'watch_status_version',
            ]);
        });
    }
};
