<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('release_schedule_entries', function (Blueprint $table): void {
            $table->index(
                ['catalog_title_id', 'status', 'released_at', 'id'],
                'release_schedule_title_released_idx',
            );
        });
    }

    public function down(): void
    {
        Schema::table('release_schedule_entries', function (Blueprint $table): void {
            $table->dropIndex('release_schedule_title_released_idx');
        });
    }
};
