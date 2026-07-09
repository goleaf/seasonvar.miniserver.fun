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
        $this->backfillType('genre', 'genres', 'catalog_title_genre', 'genre_id');
        $this->backfillType('country', 'countries', 'catalog_title_country', 'country_id');
        $this->backfillType('actor', 'actors', 'catalog_title_actor', 'actor_id');
        $this->backfillType('director', 'directors', 'catalog_title_director', 'director_id');
        $this->backfillType('age_rating', 'age_ratings', 'age_rating_catalog_title', 'age_rating_id');
        $this->backfillType('translation', 'translations', 'catalog_title_translation', 'translation_id');
        $this->backfillType('status', 'catalog_statuses', 'catalog_status_catalog_title', 'catalog_status_id');
        $this->backfillType('network', 'networks', 'catalog_title_network', 'network_id');
        $this->backfillType('studio', 'studios', 'catalog_title_studio', 'studio_id');
        $this->backfillType('tag', 'tags', 'catalog_title_tag', 'tag_id');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        foreach ([
            'catalog_title_genre',
            'catalog_title_country',
            'catalog_title_actor',
            'catalog_title_director',
            'age_rating_catalog_title',
            'catalog_title_translation',
            'catalog_status_catalog_title',
            'catalog_title_network',
            'catalog_title_studio',
            'catalog_title_tag',
        ] as $table) {
            DB::table($table)->delete();
        }

        foreach ([
            'genres',
            'countries',
            'actors',
            'directors',
            'age_ratings',
            'translations',
            'catalog_statuses',
            'networks',
            'studios',
            'tags',
        ] as $table) {
            DB::table($table)->delete();
        }
    }

    private function backfillType(string $type, string $targetTable, string $pivotTable, string $relatedKey): void
    {
        $now = now();
        $idByTaxonomyId = [];

        DB::table('taxonomies')
            ->where('type', $type)
            ->orderBy('id')
            ->chunk(500, function ($taxonomies) use ($targetTable, &$idByTaxonomyId, $now): void {
                foreach ($taxonomies as $taxonomy) {
                    $existingId = DB::table($targetTable)->where('slug', $taxonomy->slug)->value('id');
                    $id = $existingId ?: DB::table($targetTable)->insertGetId([
                        'name' => $taxonomy->name,
                        'slug' => $taxonomy->slug,
                        'source_url' => $taxonomy->source_url,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                    $idByTaxonomyId[(int) $taxonomy->id] = (int) $id;
                }
            });

        if ($idByTaxonomyId === []) {
            return;
        }

        DB::table('catalog_title_taxonomy')
            ->whereIn('taxonomy_id', array_keys($idByTaxonomyId))
            ->orderBy('catalog_title_id')
            ->chunk(1000, function ($rows) use ($pivotTable, $relatedKey, $idByTaxonomyId): void {
                foreach ($rows as $row) {
                    DB::table($pivotTable)->insertOrIgnore([
                        'catalog_title_id' => $row->catalog_title_id,
                        $relatedKey => $idByTaxonomyId[(int) $row->taxonomy_id],
                    ]);
                }
            });
    }
};
