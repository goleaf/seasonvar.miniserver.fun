<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('catalog_title_user_states', function (Blueprint $table): void {
            $table->timestamp('watchlist_updated_at')->nullable();
            $table->timestamp('rating_updated_at')->nullable();
            $table->timestamp('watch_status_updated_at')->nullable();
        });

        Schema::table('catalog_title_user_states', function (Blueprint $table): void {
            $table->dropIndex('catalog_user_state_recent_watchlist_idx');
            $table->dropIndex('catalog_user_state_watch_status_idx');
            $table->index(
                ['in_watchlist', 'watchlist_updated_at', 'catalog_title_id', 'user_id'],
                'catalog_user_state_recent_watchlist_activity_idx',
            );
            $table->index(
                ['user_id', 'watch_status', 'watch_status_updated_at', 'catalog_title_id'],
                'catalog_user_state_watch_status_activity_idx',
            );
        });
    }

    public function down(): void
    {
        Schema::table('catalog_title_user_states', function (Blueprint $table): void {
            $table->dropIndex('catalog_user_state_recent_watchlist_activity_idx');
            $table->dropIndex('catalog_user_state_watch_status_activity_idx');
            $table->index(
                ['in_watchlist', 'updated_at', 'catalog_title_id', 'user_id'],
                'catalog_user_state_recent_watchlist_idx',
            );
            $table->index(
                ['user_id', 'watch_status', 'updated_at', 'catalog_title_id'],
                'catalog_user_state_watch_status_idx',
            );
        });

        Schema::table('catalog_title_user_states', function (Blueprint $table): void {
            $table->dropColumn([
                'watchlist_updated_at',
                'rating_updated_at',
                'watch_status_updated_at',
            ]);
        });
    }
};
