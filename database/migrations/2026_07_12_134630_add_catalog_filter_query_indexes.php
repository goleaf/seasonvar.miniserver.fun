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
            $table->index(
                ['is_published', 'year', 'indexed_at', 'id'],
                'catalog_titles_public_year_updated_idx',
            );
        });

        Schema::table('licensed_media', function (Blueprint $table): void {
            $table->index(
                ['catalog_title_id', 'status', 'quality', 'has_subtitles'],
                'licensed_media_title_status_quality_subtitles_idx',
            );
        });

        Schema::table('catalog_title_ratings', function (Blueprint $table): void {
            $table->index(
                ['provider', 'rating', 'votes', 'catalog_title_id'],
                'catalog_ratings_provider_score_votes_title_idx',
            );
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('catalog_title_ratings', function (Blueprint $table): void {
            $table->dropIndex('catalog_ratings_provider_score_votes_title_idx');
        });

        Schema::table('licensed_media', function (Blueprint $table): void {
            $table->dropIndex('licensed_media_title_status_quality_subtitles_idx');
        });

        Schema::table('catalog_titles', function (Blueprint $table): void {
            $table->dropIndex('catalog_titles_public_year_updated_idx');
        });
    }
};
