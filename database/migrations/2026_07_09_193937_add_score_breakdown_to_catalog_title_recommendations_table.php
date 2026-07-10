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
        Schema::table('catalog_title_recommendations', function (Blueprint $table) {
            $table->string('algorithm_version', 32)->default('v1')->after('rank')->index();
            $table->unsignedInteger('matched_features_count')->default(0)->after('algorithm_version');
            $table->unsignedInteger('metadata_score')->default(0)->after('matched_features_count');
            $table->unsignedInteger('source_score')->default(0)->after('metadata_score');
            $table->unsignedInteger('quality_score')->default(0)->after('source_score');

            $table->index(['catalog_title_id', 'algorithm_version', 'rank'], 'catalog_title_recommendations_algorithm_rank_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('catalog_title_recommendations', function (Blueprint $table) {
            $table->dropIndex('catalog_title_recommendations_algorithm_rank_idx');
            $table->dropColumn([
                'algorithm_version',
                'matched_features_count',
                'metadata_score',
                'source_score',
                'quality_score',
            ]);
        });
    }
};
