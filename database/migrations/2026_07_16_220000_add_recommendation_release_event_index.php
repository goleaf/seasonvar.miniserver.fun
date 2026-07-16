<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('episodes', function (Blueprint $table): void {
            $table->index(
                ['publication_status', 'deleted_at', 'released_at', 'id', 'season_id'],
                'episodes_recommendation_release_events_idx',
            );
        });
    }

    public function down(): void
    {
        Schema::table('episodes', function (Blueprint $table): void {
            $table->dropIndex('episodes_recommendation_release_events_idx');
        });
    }
};
