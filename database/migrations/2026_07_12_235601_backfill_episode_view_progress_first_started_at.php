<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('episode_view_progress')
            ->whereNull('first_started_at')
            ->update([
                'first_started_at' => DB::raw('COALESCE(created_at, last_watched_at)'),
            ]);
    }

    public function down(): void
    {
        // The original first-start timestamp cannot be distinguished after backfill.
    }
};
