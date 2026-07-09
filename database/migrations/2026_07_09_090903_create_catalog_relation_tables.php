<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $this->createLookupTable('genres');
        $this->createLookupTable('countries');
        $this->createLookupTable('actors');
        $this->createLookupTable('directors');
        $this->createLookupTable('age_ratings');
        $this->createLookupTable('translations');
        $this->createLookupTable('catalog_statuses');
        $this->createLookupTable('networks');
        $this->createLookupTable('studios');
        $this->createLookupTable('tags');

        $this->createPivotTable('catalog_title_genre', 'genre_id', 'genres');
        $this->createPivotTable('catalog_title_country', 'country_id', 'countries');
        $this->createPivotTable('catalog_title_actor', 'actor_id', 'actors');
        $this->createPivotTable('catalog_title_director', 'director_id', 'directors');
        $this->createPivotTable('age_rating_catalog_title', 'age_rating_id', 'age_ratings');
        $this->createPivotTable('catalog_title_translation', 'translation_id', 'translations');
        $this->createPivotTable('catalog_status_catalog_title', 'catalog_status_id', 'catalog_statuses');
        $this->createPivotTable('catalog_title_network', 'network_id', 'networks');
        $this->createPivotTable('catalog_title_studio', 'studio_id', 'studios');
        $this->createPivotTable('catalog_title_tag', 'tag_id', 'tags');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        foreach ([
            'catalog_title_tag',
            'catalog_title_studio',
            'catalog_title_network',
            'catalog_status_catalog_title',
            'catalog_title_translation',
            'age_rating_catalog_title',
            'catalog_title_director',
            'catalog_title_actor',
            'catalog_title_country',
            'catalog_title_genre',
        ] as $table) {
            Schema::dropIfExists($table);
        }

        foreach ([
            'tags',
            'studios',
            'networks',
            'catalog_statuses',
            'translations',
            'age_ratings',
            'directors',
            'actors',
            'countries',
            'genres',
        ] as $table) {
            Schema::dropIfExists($table);
        }
    }

    private function createLookupTable(string $tableName): void
    {
        Schema::create($tableName, function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('source_url')->nullable();
            $table->timestamps();
        });
    }

    private function createPivotTable(string $tableName, string $relatedKey, string $relatedTable): void
    {
        Schema::create($tableName, function (Blueprint $table) use ($relatedKey, $relatedTable): void {
            $table->foreignId('catalog_title_id')->constrained()->cascadeOnDelete();
            $table->foreignId($relatedKey)->constrained($relatedTable)->cascadeOnDelete();
            $table->primary(['catalog_title_id', $relatedKey]);
            $table->index([$relatedKey, 'catalog_title_id']);
        });
    }
};
