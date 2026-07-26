<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('catalog_recommendation_onboarding_titles', function (Blueprint $table): void {
            $table->index(
                ['catalog_title_id', 'id'],
                'recommendation_onboarding_title_merge_lookup_idx',
            );
        });
    }

    public function down(): void
    {
        Schema::table('catalog_recommendation_onboarding_titles', function (Blueprint $table): void {
            $table->dropIndex('recommendation_onboarding_title_merge_lookup_idx');
        });
    }
};
