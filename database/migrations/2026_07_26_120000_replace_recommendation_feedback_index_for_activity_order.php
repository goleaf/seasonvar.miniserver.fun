<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('catalog_title_user_states', function (Blueprint $table): void {
            $table->dropIndex('catalog_user_state_recommendation_feedback_idx');
        });

        Schema::table('catalog_title_user_states', function (Blueprint $table): void {
            $table->index(
                [
                    'user_id',
                    'recommendation_feedback',
                    'recommendation_feedback_updated_at',
                    'id',
                    'catalog_title_id',
                ],
                'catalog_user_state_recommendation_feedback_idx',
            );
        });
    }

    public function down(): void
    {
        Schema::table('catalog_title_user_states', function (Blueprint $table): void {
            $table->dropIndex('catalog_user_state_recommendation_feedback_idx');
        });

        Schema::table('catalog_title_user_states', function (Blueprint $table): void {
            $table->index(
                ['user_id', 'recommendation_feedback', 'catalog_title_id'],
                'catalog_user_state_recommendation_feedback_idx',
            );
        });
    }
};
