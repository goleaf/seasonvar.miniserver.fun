<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('catalog_titles', function (Blueprint $table): void {
            $table->index(['source_page_id', 'deleted_at'], 'catalog_titles_source_page_lookup_idx');
        });

        Schema::table('seasons', function (Blueprint $table): void {
            $table->index(['source_url_hash', 'deleted_at', 'catalog_title_id'], 'seasons_source_url_hash_lookup_idx');
        });
    }

    public function down(): void
    {
        Schema::table('seasons', function (Blueprint $table): void {
            $table->dropIndex('seasons_source_url_hash_lookup_idx');
        });

        Schema::table('catalog_titles', function (Blueprint $table): void {
            $table->dropIndex('catalog_titles_source_page_lookup_idx');
        });
    }
};
