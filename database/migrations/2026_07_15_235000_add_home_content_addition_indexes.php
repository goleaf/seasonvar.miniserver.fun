<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('episodes', function (Blueprint $table): void {
            $table->index(['season_id', 'created_at', 'id'], 'episodes_home_additions_idx');
        });

        Schema::table('licensed_media', function (Blueprint $table): void {
            $table->index(['catalog_title_id', 'created_at', 'id'], 'licensed_media_home_additions_idx');
        });
    }

    public function down(): void
    {
        Schema::table('licensed_media', function (Blueprint $table): void {
            $table->dropIndex('licensed_media_home_additions_idx');
        });

        Schema::table('episodes', function (Blueprint $table): void {
            $table->dropIndex('episodes_home_additions_idx');
        });
    }
};
