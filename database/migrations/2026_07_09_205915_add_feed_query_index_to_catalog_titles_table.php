<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('catalog_titles', function (Blueprint $table): void {
            $table->index(['is_published', 'indexed_at', 'id'], 'catalog_titles_feed_query_idx');
        });
    }

    public function down(): void
    {
        Schema::table('catalog_titles', function (Blueprint $table): void {
            $table->dropIndex('catalog_titles_feed_query_idx');
        });
    }
};
