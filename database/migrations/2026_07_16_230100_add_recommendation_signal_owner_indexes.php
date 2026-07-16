<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('catalog_title_user_states', function (Blueprint $table): void {
            $table->index(
                ['user_id', 'in_watchlist', 'watchlist_updated_at', 'id', 'catalog_title_id'],
                'catalog_user_state_personal_watchlist_order_idx',
            );
            $table->index(
                ['user_id', 'watch_status_updated_at', 'id', 'watch_status', 'catalog_title_id'],
                'catalog_user_state_personal_status_order_idx',
            );
            $table->index(
                ['user_id', 'rating_updated_at', 'id', 'rating', 'catalog_title_id'],
                'catalog_user_state_personal_rating_order_idx',
            );
        });
    }

    public function down(): void
    {
        Schema::table('catalog_title_user_states', function (Blueprint $table): void {
            $table->dropIndex('catalog_user_state_personal_watchlist_order_idx');
            $table->dropIndex('catalog_user_state_personal_status_order_idx');
            $table->dropIndex('catalog_user_state_personal_rating_order_idx');
        });
    }
};
