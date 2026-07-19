<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('catalog_title_user_states', function (Blueprint $table): void {
            $table->index(
                ['catalog_title_id', 'in_watchlist', 'rating'],
                'catalog_user_state_title_summary_idx',
            );
        });
    }

    public function down(): void
    {
        Schema::table('catalog_title_user_states', function (Blueprint $table): void {
            $table->dropIndex('catalog_user_state_title_summary_idx');
        });
    }
};
