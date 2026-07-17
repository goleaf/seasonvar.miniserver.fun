<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('catalog_recommendation_build_rows', function (Blueprint $table): void {
            $table->index(
                ['build_id', 'score', 'id'],
                'catalog_recommendation_build_rows_build_score_idx',
            );
        });
    }

    public function down(): void
    {
        Schema::table('catalog_recommendation_build_rows', function (Blueprint $table): void {
            $table->dropIndex('catalog_recommendation_build_rows_build_score_idx');
        });
    }
};
