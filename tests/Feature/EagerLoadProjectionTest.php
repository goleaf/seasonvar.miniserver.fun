<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\CatalogCollectionModerationStatus;
use App\Enums\CatalogCollectionType;
use App\Enums\CatalogCollectionVisibility;
use App\Models\CatalogCollection;
use App\Models\CatalogTitle;
use App\Models\Tag;
use App\Models\User;
use App\Services\Collections\CatalogCollectionAccountService;
use App\Services\Tags\TagAdministrationQuery;
use App\Services\Tags\TagQuery;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class EagerLoadProjectionTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_tag_relations_use_explicit_column_projections(): void
    {
        $tag = Tag::query()->create(['name' => 'Детектив', 'slug' => 'detektiv']);
        $tag->translations()->create([
            'locale' => 'ru',
            'label' => 'Детектив',
            'description' => 'Полное описание, которое нужно странице тега.',
        ]);
        $tag->aliases()->create([
            'locale' => 'ru',
            'name' => 'Сыщик',
            'normalized_name' => 'сыщик',
            'normalized_name_hash' => hash('sha256', 'сыщик'),
        ]);
        $tag->catalogTitles()->attach(CatalogTitle::factory()->create());

        $queries = $this->captureQueries(function () use ($tag): void {
            app(TagQuery::class)->publicTags()->whereKey($tag->id)->get();
            $this->getJson(route('api.v1.tags.show', ['tagSlug' => $tag->slug]))->assertOk();
        });

        $this->assertProjectedRelation($queries, 'tag_translations', ['id', 'tag_id', 'locale', 'label'], ['created_at', 'updated_at']);
        $this->assertProjectedRelation($queries, 'tag_aliases', ['id', 'tag_id', 'locale', 'name'], ['normalized_name_hash', 'created_at', 'updated_at']);
    }

    public function test_collection_export_projects_translation_and_item_relations(): void
    {
        $user = User::factory()->create();
        $title = CatalogTitle::factory()->create();
        $collection = CatalogCollection::query()->create([
            'public_id' => (string) Str::uuid(),
            'owner_id' => $user->id,
            'name' => 'Экспортируемая подборка',
            'slug' => 'eksportiruemaia-podborka',
            'type' => CatalogCollectionType::User,
            'visibility' => CatalogCollectionVisibility::Private,
            'moderation_status' => CatalogCollectionModerationStatus::Approved,
            'content_locale' => 'ru',
        ]);
        $collection->translations()->create(['locale' => 'ru', 'name' => 'Экспортируемая подборка']);
        $collection->items()->create([
            'catalog_title_id' => $title->id,
            'added_by_id' => $user->id,
            'position' => 1,
        ]);

        $queries = $this->captureQueries(fn () => app(CatalogCollectionAccountService::class)->export($user));

        $this->assertProjectedRelation($queries, 'catalog_collection_translations', [
            'id', 'catalog_collection_id', 'locale', 'name', 'description', 'seo_title', 'seo_description',
        ], ['created_at', 'updated_at']);
        $this->assertProjectedRelation($queries, 'catalog_collection_items', [
            'id', 'catalog_collection_id', 'catalog_title_id', 'position', 'created_at',
        ], ['updated_at']);
    }

    public function test_tag_administration_projects_every_eager_loaded_relation(): void
    {
        $tag = Tag::query()->create(['name' => 'Драма', 'slug' => 'drama']);
        $tag->translations()->create(['locale' => 'ru', 'label' => 'Драма']);
        $tag->aliases()->create([
            'locale' => 'ru',
            'name' => 'Драматический',
            'normalized_name' => 'драматический',
            'normalized_name_hash' => hash('sha256', 'драматический'),
        ]);

        $queries = $this->captureQueries(fn () => app(TagAdministrationQuery::class)->tag($tag->public_id));

        foreach (['tag_translations', 'tag_aliases', 'tag_synonyms', 'tag_provider_mappings', 'tag_slugs'] as $table) {
            $this->assertRelationDoesNotSelectAllColumns($queries, $table);
        }
    }

    /** @return list<string> */
    private function captureQueries(callable $callback): array
    {
        $queries = [];
        DB::listen(function (QueryExecuted $query) use (&$queries): void {
            $queries[] = strtolower($query->sql);
        });
        $callback();

        return $queries;
    }

    /** @param list<string> $queries
     * @param  list<string>  $included
     * @param  list<string>  $excluded
     */
    private function assertProjectedRelation(array $queries, string $table, array $included, array $excluded): void
    {
        $sql = $this->relationQuery($queries, $table);

        foreach ($included as $column) {
            $this->assertStringContainsString('"'.$column.'"', $sql, $table.' must select '.$column.'.');
        }

        foreach ($excluded as $column) {
            $this->assertStringNotContainsString('"'.$column.'"', $sql, $table.' must not select '.$column.'.');
        }

        $this->assertStringNotContainsString('select *', $sql);
    }

    /** @param list<string> $queries */
    private function assertRelationDoesNotSelectAllColumns(array $queries, string $table): void
    {
        $this->assertStringNotContainsString('select *', $this->relationQuery($queries, $table));
    }

    /** @param list<string> $queries */
    private function relationQuery(array $queries, string $table): string
    {
        $sql = collect($queries)->first(fn (string $query): bool => str_contains($query, 'from "'.$table.'"'));

        $this->assertIsString($sql, 'Expected a relation query for '.$table.'.');

        return $sql;
    }
}
