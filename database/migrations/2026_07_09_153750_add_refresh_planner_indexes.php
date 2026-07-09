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
        Schema::table('source_pages', function (Blueprint $table): void {
            $table->index(['page_type', 'import_status', 'retry_after_at', 'last_imported_at'], 'source_pages_refresh_import_retry_idx');
            $table->index(['page_type', 'parse_status', 'retry_after_at', 'last_imported_at'], 'source_pages_refresh_parse_retry_idx');
        });

        Schema::table('seasons', function (Blueprint $table): void {
            $table->index(['source_page_id', 'catalog_title_id', 'number'], 'seasons_source_page_title_number_idx');
        });

        Schema::table('episodes', function (Blueprint $table): void {
            $table->index(['source_page_id', 'season_id', 'number'], 'episodes_source_page_season_number_idx');
        });

        Schema::table('licensed_media', function (Blueprint $table): void {
            $table->index(['catalog_title_id', 'status', 'check_status'], 'licensed_media_title_status_check_idx');
            $table->index(['season_id', 'status', 'check_status'], 'licensed_media_season_status_check_idx');
            $table->index(['episode_id', 'status', 'check_status'], 'licensed_media_episode_status_check_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('licensed_media', function (Blueprint $table): void {
            $table->dropIndex('licensed_media_episode_status_check_idx');
            $table->dropIndex('licensed_media_season_status_check_idx');
            $table->dropIndex('licensed_media_title_status_check_idx');
        });

        Schema::table('episodes', function (Blueprint $table): void {
            $table->dropIndex('episodes_source_page_season_number_idx');
        });

        Schema::table('seasons', function (Blueprint $table): void {
            $table->dropIndex('seasons_source_page_title_number_idx');
        });

        Schema::table('source_pages', function (Blueprint $table): void {
            $table->dropIndex('source_pages_refresh_import_retry_idx');
            $table->dropIndex('source_pages_refresh_parse_retry_idx');
        });
    }
};
