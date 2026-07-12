<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $this->ensureBackfillIsComplete();
        $this->ensureReleaseKeysAreUnique();

        Schema::table('catalog_titles', function (Blueprint $table): void {
            $table->string('publication_status', 20)->default('published')->nullable(false)->change();
            $table->string('audience', 20)->default('public')->nullable(false)->change();
            $table->index(['publication_status', 'audience', 'deleted_at', 'available_from'], 'catalog_titles_publication_lookup_idx');
            $table->index('available_until', 'catalog_titles_available_until_idx');
        });

        Schema::table('seasons', function (Blueprint $table): void {
            $table->dropUnique('seasons_catalog_title_id_number_unique');
            $table->string('kind', 20)->default('regular')->nullable(false)->change();
            $table->unsignedInteger('sort_order')->default(0)->nullable(false)->change();
            $table->string('publication_status', 20)->default('published')->nullable(false)->change();
            $table->string('audience', 20)->default('public')->nullable(false)->change();
            $table->unique(['catalog_title_id', 'kind', 'number'], 'seasons_title_kind_number_unique');
            $table->index(['catalog_title_id', 'kind', 'sort_order', 'number'], 'seasons_title_display_order_idx');
            $table->index(['catalog_title_id', 'publication_status', 'audience', 'deleted_at'], 'seasons_publication_lookup_idx');
            $table->index('available_until', 'seasons_available_until_idx');
        });

        Schema::table('episodes', function (Blueprint $table): void {
            $table->dropUnique('episodes_season_id_number_unique');
            $table->string('kind', 20)->default('regular')->nullable(false)->change();
            $table->unsignedInteger('sort_order')->default(0)->nullable(false)->change();
            $table->string('publication_status', 20)->default('published')->nullable(false)->change();
            $table->string('audience', 20)->default('public')->nullable(false)->change();
            $table->unique(['season_id', 'kind', 'number'], 'episodes_season_kind_number_unique');
            $table->index(['season_id', 'kind', 'sort_order', 'number'], 'episodes_season_display_order_idx');
            $table->index(['season_id', 'publication_status', 'audience', 'deleted_at'], 'episodes_publication_lookup_idx');
            $table->index('available_until', 'episodes_available_until_idx');
        });

        Schema::table('licensed_media', function (Blueprint $table): void {
            $table->string('audience', 20)->default('public')->nullable(false)->change();
            $table->index(['catalog_title_id', 'status', 'audience', 'deleted_at', 'available_from'], 'licensed_media_publication_lookup_idx');
            $table->index('available_until', 'licensed_media_available_until_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $this->ensureLegacyReleaseKeysAreUnique();

        Schema::table('licensed_media', function (Blueprint $table): void {
            $table->dropIndex('licensed_media_publication_lookup_idx');
            $table->dropIndex('licensed_media_available_until_idx');
            $table->string('audience', 20)->nullable()->default(null)->change();
        });

        Schema::table('episodes', function (Blueprint $table): void {
            $table->dropUnique('episodes_season_kind_number_unique');
            $table->dropIndex('episodes_season_display_order_idx');
            $table->dropIndex('episodes_publication_lookup_idx');
            $table->dropIndex('episodes_available_until_idx');
            $table->unique(['season_id', 'number']);
            $table->string('kind', 20)->nullable()->default(null)->change();
            $table->unsignedInteger('sort_order')->nullable()->default(null)->change();
            $table->string('publication_status', 20)->nullable()->default(null)->change();
            $table->string('audience', 20)->nullable()->default(null)->change();
        });

        Schema::table('seasons', function (Blueprint $table): void {
            $table->dropUnique('seasons_title_kind_number_unique');
            $table->dropIndex('seasons_title_display_order_idx');
            $table->dropIndex('seasons_publication_lookup_idx');
            $table->dropIndex('seasons_available_until_idx');
            $table->unique(['catalog_title_id', 'number']);
            $table->string('kind', 20)->nullable()->default(null)->change();
            $table->unsignedInteger('sort_order')->nullable()->default(null)->change();
            $table->string('publication_status', 20)->nullable()->default(null)->change();
            $table->string('audience', 20)->nullable()->default(null)->change();
        });

        Schema::table('catalog_titles', function (Blueprint $table): void {
            $table->dropIndex('catalog_titles_publication_lookup_idx');
            $table->dropIndex('catalog_titles_available_until_idx');
            $table->string('publication_status', 20)->nullable()->default(null)->change();
            $table->string('audience', 20)->nullable()->default(null)->change();
        });
    }

    private function ensureBackfillIsComplete(): void
    {
        $required = [
            'catalog_titles' => ['publication_status', 'audience'],
            'seasons' => ['kind', 'sort_order', 'publication_status', 'audience'],
            'episodes' => ['kind', 'sort_order', 'publication_status', 'audience'],
            'licensed_media' => ['audience'],
        ];

        foreach ($required as $table => $columns) {
            foreach ($columns as $column) {
                if (DB::table($table)->whereNull($column)->exists()) {
                    throw new RuntimeException("Cannot enforce catalog integrity: {$table}.{$column} still contains NULL values.");
                }
            }
        }
    }

    private function ensureReleaseKeysAreUnique(): void
    {
        $this->ensureNoDuplicateKey('seasons', ['catalog_title_id', 'kind', 'number']);
        $this->ensureNoDuplicateKey('episodes', ['season_id', 'kind', 'number']);
    }

    private function ensureLegacyReleaseKeysAreUnique(): void
    {
        $this->ensureNoDuplicateKey('seasons', ['catalog_title_id', 'number']);
        $this->ensureNoDuplicateKey('episodes', ['season_id', 'number']);
    }

    /**
     * @param  list<string>  $columns
     */
    private function ensureNoDuplicateKey(string $table, array $columns): void
    {
        $duplicates = DB::table($table)
            ->select($columns)
            ->selectRaw('COUNT(*) AS duplicate_count')
            ->groupBy($columns)
            ->havingRaw('COUNT(*) > 1')
            ->limit(1)
            ->exists();

        if ($duplicates) {
            throw new RuntimeException(sprintf(
                'Cannot change unique key on %s: duplicate values exist for (%s).',
                $table,
                implode(', ', $columns),
            ));
        }
    }
};
