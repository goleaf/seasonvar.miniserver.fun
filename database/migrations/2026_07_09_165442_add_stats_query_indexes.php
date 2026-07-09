<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('catalog_titles', function (Blueprint $table): void {
            $table->index(['is_published', 'id'], 'catalog_titles_published_id_idx');
            $table->index(['is_published', 'year'], 'catalog_titles_published_year_idx');
            $table->index(['is_published', 'slug'], 'catalog_titles_published_slug_idx');
            $table->index('created_at', 'catalog_titles_created_at_idx');
        });

        Schema::table('episodes', function (Blueprint $table): void {
            $table->index('created_at', 'episodes_created_at_idx');
        });

        Schema::table('licensed_media', function (Blueprint $table): void {
            $table->index(['status', 'catalog_title_id', 'episode_id'], 'licensed_media_status_title_episode_idx');
            $table->index('created_at', 'licensed_media_created_at_idx');
        });

        Schema::table('source_pages', function (Blueprint $table): void {
            $table->index('last_crawled_at', 'source_pages_last_crawled_at_idx');
        });

        Schema::table('seasonvar_import_events', function (Blueprint $table): void {
            $table->index(['level', 'created_at'], 'seasonvar_import_events_level_created_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('seasonvar_import_events', function (Blueprint $table): void {
            $table->dropIndex('seasonvar_import_events_level_created_idx');
        });

        Schema::table('source_pages', function (Blueprint $table): void {
            $table->dropIndex('source_pages_last_crawled_at_idx');
        });

        Schema::table('licensed_media', function (Blueprint $table): void {
            $table->dropIndex('licensed_media_created_at_idx');
            $table->dropIndex('licensed_media_status_title_episode_idx');
        });

        Schema::table('episodes', function (Blueprint $table): void {
            $table->dropIndex('episodes_created_at_idx');
        });

        Schema::table('catalog_titles', function (Blueprint $table): void {
            $table->dropIndex('catalog_titles_created_at_idx');
            $table->dropIndex('catalog_titles_published_slug_idx');
            $table->dropIndex('catalog_titles_published_year_idx');
            $table->dropIndex('catalog_titles_published_id_idx');
        });
    }
};
