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
        Schema::table('catalog_title_taxonomy', function (Blueprint $table) {
            $table->index(['taxonomy_id', 'catalog_title_id'], 'catalog_title_taxonomy_taxonomy_title_idx');
        });

        Schema::table('catalog_titles', function (Blueprint $table) {
            $table->index('indexed_at', 'catalog_titles_indexed_at_idx');
            $table->index(['year', 'indexed_at'], 'catalog_titles_year_indexed_idx');
        });

        Schema::table('source_pages', function (Blueprint $table) {
            $table->index(['parse_status', 'page_type', 'id'], 'source_pages_status_type_id_idx');
            $table->index(['page_type', 'parse_status', 'last_crawled_at', 'id'], 'source_pages_type_status_crawled_id_idx');
        });

        Schema::table('licensed_media', function (Blueprint $table) {
            $table->index(['catalog_title_id', 'status', 'published_at'], 'licensed_media_title_status_published_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('licensed_media', function (Blueprint $table) {
            $table->dropIndex('licensed_media_title_status_published_idx');
        });

        Schema::table('source_pages', function (Blueprint $table) {
            $table->dropIndex('source_pages_status_type_id_idx');
            $table->dropIndex('source_pages_type_status_crawled_id_idx');
        });

        Schema::table('catalog_titles', function (Blueprint $table) {
            $table->dropIndex('catalog_titles_indexed_at_idx');
            $table->dropIndex('catalog_titles_year_indexed_idx');
        });

        Schema::table('catalog_title_taxonomy', function (Blueprint $table) {
            $table->dropIndex('catalog_title_taxonomy_taxonomy_title_idx');
        });
    }
};
