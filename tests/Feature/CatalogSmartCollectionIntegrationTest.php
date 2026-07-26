<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\DTOs\CatalogCollectionData;
use App\DTOs\CatalogCollectionItemCriteria;
use App\DTOs\CatalogSmartCollectionRules;
use App\Enums\CatalogCollectionMode;
use App\Enums\CatalogCollectionModerationStatus;
use App\Enums\CatalogCollectionVisibility;
use App\Enums\ReleaseCalendarFeedScope;
use App\Enums\ReleaseDatePrecision;
use App\Enums\ReleaseScheduleEntryType;
use App\Enums\ReleaseScheduleSource;
use App\Enums\ReleaseScheduleStatus;
use App\Models\CatalogCollection;
use App\Models\CatalogCollectionCategory;
use App\Models\CatalogCollectionItem;
use App\Models\CatalogTitle;
use App\Models\Genre;
use App\Models\ReleaseScheduleEntry;
use App\Models\User;
use App\Services\Collections\CatalogCollectionAccountService;
use App\Services\Collections\CatalogCollectionQuery;
use App\Services\Collections\CatalogCollectionService;
use App\Services\ReleaseCalendar\ReleaseCalendarFeedQuery;
use App\Services\ReleaseCalendar\ReleaseCalendarFeedService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class CatalogSmartCollectionIntegrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_account_export_contains_versioned_rules_without_materialized_results(): void
    {
        $owner = User::factory()->create();
        $collection = $this->collection($owner, ['genre_slug' => 'komediia']);

        $export = collect(app(CatalogCollectionAccountService::class)->export($owner))
            ->firstWhere('public_id', $collection->public_id);

        $this->assertIsArray($export);
        $this->assertSame(CatalogCollectionMode::Smart->value, $export['mode']);
        $this->assertSame(1, $export['smart_rules_version']);
        $this->assertSame('komediia', $export['smart_rules']['genre_slug']);
        $this->assertSame([], $export['items']);
        $this->assertNull($export['public_url']);
        $this->assertArrayNotHasKey('resolved_titles', $export);

        CatalogCollectionItem::query()->create([
            'catalog_collection_id' => $collection->id,
            'catalog_title_id' => CatalogTitle::factory()->create()->id,
            'added_by_id' => $owner->id,
            'position' => 1,
        ]);

        $tamperedExport = collect(app(CatalogCollectionAccountService::class)->export($owner))
            ->firstWhere('public_id', $collection->public_id);

        $this->assertSame([], $tamperedExport['items']);
    }

    public function test_collection_calendar_feed_resolves_current_smart_results(): void
    {
        CarbonImmutable::setTestNow('2026-07-26 12:00:00 UTC');
        $owner = User::factory()->create();
        $genre = Genre::query()->create(['name' => 'Комедия', 'slug' => 'komediia']);
        $matching = CatalogTitle::factory()->create(['title' => 'В умном календаре']);
        $excluded = CatalogTitle::factory()->create(['title' => 'Вне умного календаря']);
        $matching->genres()->attach($genre);
        $collection = $this->collection($owner, ['genre_slug' => 'komediia']);
        $this->entry($matching, 'smart-feed-matching');
        $this->entry($excluded, 'smart-feed-excluded');
        $feed = app(ReleaseCalendarFeedService::class)->create(
            $owner,
            ReleaseCalendarFeedScope::Collection,
            'ru',
            collection: $collection,
        );

        $titles = app(ReleaseCalendarFeedQuery::class)
            ->entries($feed->fresh(['user', 'catalogCollection']))
            ->pluck('catalogTitle.title')
            ->all();

        $this->assertSame(['В умном календаре'], $titles);
        $this->get(route('calendar.feed', ['privateToken' => $feed->token_secret]))
            ->assertOk()
            ->assertHeader('Content-Type', 'text/calendar; charset=utf-8')
            ->assertSee('В умном календаре')
            ->assertDontSee('Вне умного календаря');

        $matching->genres()->detach($genre);
        $excluded->genres()->attach($genre);

        $updatedTitles = app(ReleaseCalendarFeedQuery::class)
            ->entries($feed->fresh(['user', 'catalogCollection']))
            ->pluck('catalogTitle.title')
            ->all();

        $this->assertSame(['Вне умного календаря'], $updatedTitles);
        $this->get(route('calendar.feed', ['privateToken' => $feed->token_secret]))
            ->assertOk()
            ->assertSee('Вне умного календаря')
            ->assertDontSee('В умном календаре');
    }

    public function test_smart_collection_is_excluded_from_public_and_manual_membership_contracts_even_if_tampered(): void
    {
        $owner = User::factory()->create();
        $collection = $this->collection($owner, ['genre_slug' => 'komediia']);
        $category = CatalogCollectionCategory::query()->where('is_active', true)->firstOrFail();
        $collection->forceFill([
            'visibility' => CatalogCollectionVisibility::Public,
            'moderation_status' => CatalogCollectionModerationStatus::Approved,
            'catalog_collection_category_id' => $category->id,
            'published_at' => now(),
        ])->save();

        $this->assertFalse(CatalogCollection::query()->publiclyListed()->whereKey($collection)->exists());
        $this->assertFalse(app(CatalogCollectionQuery::class)->publicSearch('Умная')->contains('id', $collection->id));
        $this->assertFalse(app(CatalogCollectionQuery::class)
            ->manageableForTitle($owner, CatalogTitle::factory()->create()->id)
            ->contains('id', $collection->id));
        $this->getJson(route('api.v1.collections.show', ['collectionSlug' => $collection->slug]))
            ->assertNotFound();
    }

    public function test_unknown_rule_version_fails_closed_without_exposing_private_results(): void
    {
        $owner = User::factory()->create();
        $genre = Genre::query()->create(['name' => 'Комедия', 'slug' => 'komediia']);
        $title = CatalogTitle::factory()->create();
        $title->genres()->attach($genre);
        $collection = $this->collection($owner, ['genre_slug' => 'komediia']);
        $collection->forceFill(['smart_rules_version' => 999])->save();

        $items = app(CatalogCollectionQuery::class)->items(
            $collection->refresh(),
            $owner,
            new CatalogCollectionItemCriteria,
        );

        $this->assertSame(0, $items->total());
        $this->get(route('collections.show', ['collectionSlug' => $collection->slug]))
            ->assertNotFound();
    }

    private function collection(User $owner, array $rules): CatalogCollection
    {
        return app(CatalogCollectionService::class)->create(
            $owner,
            new CatalogCollectionData(
                name: 'Умная интеграционная подборка',
                description: null,
                visibility: CatalogCollectionVisibility::Private,
                mode: CatalogCollectionMode::Smart,
                smartRules: CatalogSmartCollectionRules::fromInput($rules),
            ),
        );
    }

    private function entry(CatalogTitle $title, string $key): ReleaseScheduleEntry
    {
        return ReleaseScheduleEntry::query()->create([
            'logical_key' => $key,
            'entry_type' => ReleaseScheduleEntryType::SerialPremiere,
            'status' => ReleaseScheduleStatus::Confirmed,
            'precision' => ReleaseDatePrecision::ExactDateTime,
            'source' => ReleaseScheduleSource::Official,
            'catalog_title_id' => $title->id,
            'starts_at' => CarbonImmutable::parse('2026-08-10 18:30:00 UTC'),
            'is_public' => true,
        ]);
    }
}
