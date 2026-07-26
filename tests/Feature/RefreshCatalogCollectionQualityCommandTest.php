<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\CatalogCollectionQualityIssueStatus;
use App\Models\CatalogCollection;
use App\Models\CatalogCollectionCategory;
use App\Models\CatalogCollectionItem;
use App\Models\CatalogCollectionQualityIssue;
use App\Models\CatalogCollectionQualityRun;
use App\Models\CatalogTitle;
use App\Models\LicensedMedia;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class RefreshCatalogCollectionQualityCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_refresh_scores_items_and_opens_an_exact_duplicate_issue_idempotently(): void
    {
        $category = CatalogCollectionCategory::query()->create([
            'public_id' => (string) Str::uuid(),
            'slug' => 'quality-command',
            'position' => 1,
            'is_active' => true,
        ]);
        $title = CatalogTitle::factory()->create();
        $canonical = $this->collection($category, 'Подборка криминальных историй');
        $duplicate = $this->collection($category, 'Криминальные истории для просмотра');
        $canonical->forceFill(['updated_at' => now()->subDay()])->save();
        $canonicalUpdatedAt = $canonical->fresh()->updated_at;
        $this->attach($canonical, $title);
        $this->attach($duplicate, $title);

        $this->artisan('catalog-collections:quality-refresh', ['--all' => true])
            ->assertSuccessful();

        $canonical->refresh();
        $duplicate->refresh();
        $duplicateIssue = CatalogCollectionQualityIssue::query()
            ->whereBelongsTo($duplicate, 'collection')
            ->where('code', 'exact_duplicate')
            ->sole();

        self::assertSame($canonical->content_signature, $duplicate->content_signature);
        self::assertEquals($canonicalUpdatedAt, $canonical->updated_at);
        self::assertSame($canonical->content_version, $canonical->quality_content_version);
        self::assertSame($duplicate->content_version, $duplicate->quality_content_version);
        self::assertGreaterThanOrEqual(60, $canonical->quality_score);
        self::assertLessThan(60, $duplicate->quality_score);
        self::assertSame($canonical->id, $duplicateIssue->related_catalog_collection_id);
        self::assertSame(CatalogCollectionQualityIssueStatus::Open, $duplicateIssue->status);
        self::assertNotNull($canonical->items()->sole()->theme_match_percent);
        self::assertNotNull($canonical->items()->sole()->inclusion_reason_code);

        $this->artisan('catalog-collections:quality-refresh', ['--all' => true])
            ->assertSuccessful();

        self::assertSame(
            1,
            CatalogCollectionQualityIssue::query()
                ->whereBelongsTo($duplicate, 'collection')
                ->where('code', 'exact_duplicate')
                ->count(),
        );
        self::assertSame(2, CatalogCollectionQualityRun::query()->count());
    }

    public function test_dry_run_is_bounded_and_does_not_persist_scores_or_runs(): void
    {
        $category = CatalogCollectionCategory::query()->create([
            'public_id' => (string) Str::uuid(),
            'slug' => 'quality-dry-run',
            'position' => 1,
            'is_active' => true,
        ]);
        $collection = $this->collection($category, 'Dry run quality');
        $this->attach($collection, CatalogTitle::factory()->create());

        $this->artisan('catalog-collections:quality-refresh', [
            '--dry-run' => true,
            '--limit' => 1,
        ])->assertSuccessful();

        self::assertNull($collection->fresh()->quality_score);
        self::assertSame(0, CatalogCollectionQualityIssue::query()->count());
        self::assertSame(0, CatalogCollectionQualityRun::query()->count());
    }

    public function test_refresh_opens_a_fuzzy_text_issue_without_conflating_different_compositions(): void
    {
        $category = CatalogCollectionCategory::query()->create([
            'public_id' => (string) Str::uuid(),
            'slug' => 'quality-similar-text',
            'position' => 1,
            'is_active' => true,
        ]);
        $first = $this->collection(
            $category,
            'Лучшие корейские детективные сериалы с высоким рейтингом',
        );
        $second = $this->collection(
            $category,
            'Лучшие корейские сериалы-детективы с высоким рейтингом',
        );
        $this->attach($first, CatalogTitle::factory()->create());
        $this->attach($second, CatalogTitle::factory()->create());

        $this->artisan('catalog-collections:quality-refresh', ['--all' => true])
            ->assertSuccessful();

        self::assertNotSame(
            $first->fresh()->content_signature,
            $second->fresh()->content_signature,
        );
        self::assertDatabaseHas('catalog_collection_quality_issues', [
            'catalog_collection_id' => $second->id,
            'related_catalog_collection_id' => $first->id,
            'code' => 'similar_text',
            'status' => CatalogCollectionQualityIssueStatus::Open->value,
        ]);
        self::assertDatabaseMissing('catalog_collection_quality_issues', [
            'catalog_collection_id' => $second->id,
            'code' => 'exact_duplicate',
        ]);
    }

    public function test_refresh_rejects_limit_values_outside_the_bounded_range(): void
    {
        $this->artisan('catalog-collections:quality-refresh', ['--limit' => 0])
            ->assertExitCode(2);
        $this->artisan('catalog-collections:quality-refresh', ['--limit' => 501])
            ->assertExitCode(2);

        self::assertSame(0, CatalogCollectionQualityRun::query()->count());
    }

    public function test_canonical_signature_lookup_is_one_query_per_batch(): void
    {
        $category = CatalogCollectionCategory::query()->create([
            'public_id' => (string) Str::uuid(),
            'slug' => 'quality-signature-query',
            'position' => 1,
            'is_active' => true,
        ]);

        foreach (range(1, 20) as $index) {
            $collection = $this->collection($category, 'Подборка '.$index);
            $this->attach($collection, CatalogTitle::factory()->create());
        }

        $signatureQueries = [];
        DB::listen(function (QueryExecuted $query) use (&$signatureQueries): void {
            $sql = mb_strtolower($query->sql);

            if (str_contains($sql, 'content_signature')
                && str_contains($sql, 'quality_content_version')
                && str_contains($sql, ' in (')) {
                $signatureQueries[] = $query->sql;
            }
        });

        $this->artisan('catalog-collections:quality-refresh', ['--all' => true])
            ->assertSuccessful();

        self::assertCount(1, $signatureQueries);
    }

    public function test_refresh_uses_the_parent_category_rule_for_item_evidence(): void
    {
        $parent = CatalogCollectionCategory::query()
            ->where('slug', 'detective-and-crime')
            ->firstOrFail();
        $child = CatalogCollectionCategory::query()->create([
            'public_id' => (string) Str::uuid(),
            'parent_id' => $parent->id,
            'slug' => 'quality-crime-classics',
            'position' => 1,
            'is_active' => true,
        ]);
        $collection = $this->collection($child, 'Классические расследования');
        $title = CatalogTitle::factory()->create([
            'title' => 'Старая тайна',
            'description' => 'Криминальная загадка и сложное расследование.',
        ]);
        $this->attach($collection, $title);

        $this->artisan('catalog-collections:quality-refresh', ['--all' => true])
            ->assertSuccessful();

        $item = $collection->items()->sole();
        self::assertSame(80, $item->theme_match_percent);
        self::assertSame('title_theme', $item->inclusion_reason_code);
    }

    private function collection(
        CatalogCollectionCategory $category,
        string $name,
    ): CatalogCollection {
        return CatalogCollection::query()->create([
            'public_id' => (string) Str::uuid(),
            'catalog_collection_category_id' => $category->id,
            'name' => $name,
            'description' => 'Содержательное описание тематической подборки для проверки качества каталога.',
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(8)),
            'type' => 'editorial',
            'visibility' => 'private',
            'moderation_status' => 'approved',
            'content_version' => 1,
        ]);
    }

    private function attach(CatalogCollection $collection, CatalogTitle $title): void
    {
        LicensedMedia::factory()->for($title)->create([
            'status' => 'published',
            'published_at' => now(),
        ]);
        CatalogCollectionItem::query()->create([
            'catalog_collection_id' => $collection->id,
            'catalog_title_id' => $title->id,
            'position' => 1,
        ]);
    }
}
