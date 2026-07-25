<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\DTOs\CatalogRecommendationContext;
use App\Enums\CatalogCollectionModerationStatus;
use App\Enums\CatalogCollectionSort;
use App\Enums\CatalogCollectionType;
use App\Enums\CatalogCollectionVisibility;
use App\Enums\CatalogRecommendationType;
use App\Models\CatalogCollection;
use App\Models\CatalogCollectionItem;
use App\Models\CatalogCollectionSource;
use App\Models\CatalogTitle;
use App\Models\LicensedMedia;
use App\Services\Catalog\CatalogPublicDiscoveryQuery;
use App\Services\Collections\CatalogCollectionQuery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class CatalogEditorialDiscoveryTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function featured_and_editorial_reads_fail_closed_for_the_same_readiness_contract(): void
    {
        $readyLocal = $this->collection('ready-local', now()->subHour());
        $readySource = $this->collection('ready-source', now());
        $thin = $this->collection('thin-featured', now()->addMinute());
        $unavailable = $this->collection('unavailable-featured', now()->addMinutes(2));
        $missingSource = $this->collection('missing-source-featured', now()->addMinutes(3));

        $readyLocalTitles = $this->attachWatchableTitles($readyLocal, 12);
        $readySourceTitles = $this->attachWatchableTitles($readySource, 4);
        $this->attachWatchableTitles($thin, 11);
        $this->attachWatchableTitles($unavailable, 11);
        $this->attachUnavailableTitle($unavailable, 12);
        $this->attachWatchableTitles($missingSource, 4);
        $this->source($readySource);
        $this->source($missingSource, missing: true);

        $featured = app(CatalogCollectionQuery::class)->featured(12);

        self::assertSame(
            [$readySource->id, $readyLocal->id],
            $featured->pluck('id')->all(),
        );

        $rows = app(CatalogPublicDiscoveryQuery::class)->candidates(
            new CatalogRecommendationContext(
                type: CatalogRecommendationType::Editorial,
                user: null,
                locale: 'ru',
            ),
        );
        $candidateIds = collect($rows)->pluck('id')->all();
        $expectedIds = $readySourceTitles
            ->pluck('id')
            ->concat($readyLocalTitles->pluck('id'))
            ->all();

        self::assertSame($expectedIds, $candidateIds);
        self::assertSame($candidateIds, array_values(array_unique($candidateIds)));
    }

    private function collection(string $slug, mixed $publishedAt): CatalogCollection
    {
        $collection = CatalogCollection::query()->create([
            'public_id' => (string) Str::uuid(),
            'owner_id' => null,
            'name' => str($slug)->replace('-', ' ')->title()->toString(),
            'description' => null,
            'slug' => $slug,
            'type' => CatalogCollectionType::Editorial,
            'visibility' => CatalogCollectionVisibility::Public,
            'moderation_status' => CatalogCollectionModerationStatus::Approved,
            'sort_mode' => CatalogCollectionSort::Manual,
            'content_locale' => 'ru',
            'is_featured' => true,
            'content_version' => 1,
            'published_at' => $publishedAt,
        ]);
        $collection->forceFill(['updated_at' => $publishedAt])->saveQuietly();

        return $collection;
    }

    /** @return Collection<int, CatalogTitle> */
    private function attachWatchableTitles(CatalogCollection $collection, int $count): Collection
    {
        $titles = CatalogTitle::factory()->count($count)->create();

        $titles->each(function (CatalogTitle $title, int $index) use ($collection): void {
            LicensedMedia::factory()->for($title)->create([
                'status' => 'published',
                'published_at' => now(),
            ]);
            CatalogCollectionItem::query()->create([
                'catalog_collection_id' => $collection->id,
                'catalog_title_id' => $title->id,
                'position' => $index + 1,
            ]);
        });

        return $titles;
    }

    private function attachUnavailableTitle(CatalogCollection $collection, int $position): void
    {
        CatalogCollectionItem::query()->create([
            'catalog_collection_id' => $collection->id,
            'catalog_title_id' => CatalogTitle::factory()->create()->id,
            'position' => $position,
        ]);
    }

    private function source(CatalogCollection $collection, bool $missing = false): void
    {
        CatalogCollectionSource::query()->create([
            'provider' => 'hdrezka',
            'source_key' => 'editorial-'.$collection->id,
            'catalog_collection_id' => $collection->id,
            'source_path' => '/collections/'.$collection->slug,
            'remote_name' => $collection->name,
            'last_successful_sync_at' => now(),
            'missing_since_at' => $missing ? now() : null,
        ]);
    }
}
