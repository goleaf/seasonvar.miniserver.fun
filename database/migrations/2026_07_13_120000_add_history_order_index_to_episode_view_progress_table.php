<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('episode_view_progress', function (Blueprint $table): void {
            $table->index(
                ['user_id', 'last_watched_at', 'id'],
                'episode_progress_user_history_idx',
            );
        });
    }

    public function down(): void
    {
        Schema::table('episode_view_progress', function (Blueprint $table): void {
            $table->dropIndex('episode_progress_user_history_idx');
        });
    }
};
