<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::table('catalog_titles')
            ->whereNull('publication_status')
            ->where('is_published', true)
            ->update(['publication_status' => 'published']);
        DB::table('catalog_titles')
            ->whereNull('publication_status')
            ->update(['publication_status' => 'draft']);
        DB::table('catalog_titles')
            ->whereNull('audience')
            ->update(['audience' => 'public']);

        foreach (['seasons', 'episodes'] as $table) {
            DB::table($table)->whereNull('kind')->update(['kind' => 'regular']);
            DB::table($table)->whereNull('sort_order')->update(['sort_order' => DB::raw('number')]);
            DB::table($table)->whereNull('publication_status')->update(['publication_status' => 'published']);
            DB::table($table)->whereNull('audience')->update(['audience' => 'public']);
        }

        DB::table('licensed_media')->whereNull('audience')->update(['audience' => 'public']);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Backfilled values are intentionally retained for a safe, lossless rollback.
    }
};
