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
                ['user_id', 'in_watchlist', 'updated_at', 'id'],
                'catalog_user_state_watchlist_order_idx',
            );
            $table->index(
                ['user_id', 'updated_at', 'id'],
                'catalog_user_state_updated_order_idx',
            );
            $table->index(
                ['user_id', 'rating', 'updated_at', 'id'],
                'catalog_user_state_rating_order_idx',
            );
        });
    }

    public function down(): void
    {
        Schema::table('catalog_title_user_states', function (Blueprint $table): void {
            $table->dropIndex('catalog_user_state_watchlist_order_idx');
            $table->dropIndex('catalog_user_state_updated_order_idx');
            $table->dropIndex('catalog_user_state_rating_order_idx');
        });
    }
};
