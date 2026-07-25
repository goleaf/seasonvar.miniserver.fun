<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\CatalogCollectionModerationStatus;
use App\Enums\CatalogCollectionVisibility;
use App\Models\CatalogCollection;
use App\Models\CatalogCollectionCategory;
use App\Models\CatalogCollectionItem;
use App\Models\CatalogTitle;
use App\Models\User;
use App\Services\Collections\CatalogCollectionCategoryQuery;
use App\Services\Collections\CatalogCollectionClassificationQuery;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class CatalogCollectionClassificationQueryTest extends TestCase
{
    use RefreshDatabase;

    public function test_summary_and_queue_use_authoritative_uncategorized_state(): void
    {
        $this->collection('Классифицированная', [
            'catalog_collection_category_id' => CatalogCollectionCategory::query()
                ->where('slug', 'detective-and-crime')
                ->valueOrFail('id'),
        ]);
        $public = $this->collection('Публичная', [
            'visibility' => CatalogCollectionVisibility::Public,
        ]);
        $this->collection('Личная', [
            'visibility' => CatalogCollectionVisibility::Private,
        ]);

        $query = app(CatalogCollectionClassificationQuery::class);
        $summary = $query->summary();
        $page = $query->paginateUncategorized(visibility: 'public');

        $this->assertSame(3, $summary->total);
        $this->assertSame(1, $summary->categorized);
        $this->assertSame(2, $summary->uncategorized);
        $this->assertSame(1, $summary->publicUncategorized);
        $this->assertSame(33.3, $summary->completionPercentage);
        $this->assertSame([$public->public_id], $page->pluck('public_id')->all());
    }

    public function test_evidence_is_limited_to_the_current_page_and_query_budget_is_bounded(): void
    {
        $owner = User::factory()->create();
        $first = $this->collection('Первая Netflix', ['owner_id' => $owner->id]);
        $second = $this->collection('Вторая Netflix');
        $titles = CatalogTitle::factory()->count(80)->create();
        $now = now();

        foreach ([$first, $second] as $collection) {
            CatalogCollectionItem::query()->insert(
                $titles
                    ->values()
                    ->map(fn (CatalogTitle $title, int $position): array => [
                        'catalog_collection_id' => $collection->id,
                        'catalog_title_id' => $title->id,
                        'position' => $position,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ])
                    ->all(),
            );
        }

        $tree = app(CatalogCollectionCategoryQuery::class)->activeTree();
        DB::flushQueryLog();
        DB::enableQueryLog();

        $query = app(CatalogCollectionClassificationQuery::class);
        $page = $query->paginateUncategorized(perPage: 20);
        $suggestions = $query->suggestionsFor($page, $tree);
        $executed = DB::getQueryLog();
        DB::disableQueryLog();

        $this->assertCount(2, $page);
        $this->assertCount(50, $page->first()->items);
        $this->assertSame(80, $page->first()->total_items_count);
        $this->assertCount(2, $suggestions);
        $this->assertLessThanOrEqual(
            14,
            count($executed),
            implode("\n", array_column($executed, 'query')),
        );
    }

    public function test_empty_page_does_not_load_collection_items(): void
    {
        $queries = [];
        DB::listen(static function (QueryExecuted $query) use (&$queries): void {
            $queries[] = strtolower($query->sql);
        });

        $page = app(CatalogCollectionClassificationQuery::class)
            ->paginateUncategorized(search: 'точно отсутствующая подборка');

        $this->assertSame(0, $page->total());
        $this->assertFalse(collect($queries)->contains(
            fn (string $sql): bool => str_contains($sql, 'catalog_collection_items'),
        ));
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function collection(string $name, array $attributes = []): CatalogCollection
    {
        return CatalogCollection::query()->create([
            'public_id' => (string) Str::uuid(),
            'name' => $name,
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(6)),
            'visibility' => CatalogCollectionVisibility::Public,
            'moderation_status' => CatalogCollectionModerationStatus::Approved,
            'published_at' => now(),
            ...$attributes,
        ]);
    }
}
