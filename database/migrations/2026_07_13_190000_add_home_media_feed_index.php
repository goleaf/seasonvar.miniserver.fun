<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('licensed_media', function (Blueprint $table): void {
            $table->index(['status', 'published_at', 'id'], 'licensed_media_home_feed_idx');
        });
    }

    public function down(): void
    {
        Schema::table('licensed_media', function (Blueprint $table): void {
            $table->dropIndex('licensed_media_home_feed_idx');
        });
    }
};
