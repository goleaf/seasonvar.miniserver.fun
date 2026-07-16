<?php

declare(strict_types=1);

namespace Tests\Feature\DemoData;

use App\DTOs\DemoData\DemoDataOptions;
use App\Enums\CatalogCollectionSort;
use App\Enums\CatalogCollectionVisibility;
use App\Models\CatalogCollection;
use App\Models\CatalogTitle;
use App\Models\Tag;
use App\Models\User;
use App\Models\UserTag;
use App\Services\DemoData\DemoTitleSelector;
use App\Services\DemoData\Stages\DemoAccountStage;
use App\Services\DemoData\Stages\DemoOrganizationStage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class DemoCatalogCorpusStageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('uploads');
        config([
            'demo-data.user_count' => 4,
            'demo-data.coverage_numerator' => 1,
            'demo-data.coverage_denominator' => 2,
            'demo-data.chunk_size' => 100,
            'demo-data.asset_disk' => 'uploads',
            'demo-data.asset_prefix' => 'demo-tests',
            'demo-data.personal_tags.minimum' => 12,
            'demo-data.personal_tags.maximum' => 12,
            'demo-data.personal_tags.per_title_minimum' => 2,
            'demo-data.personal_tags.per_title_maximum' => 7,
            'demo-data.collections.minimum' => 8,
            'demo-data.collections.maximum' => 8,
            'demo-data.collections.per_title_minimum' => 1,
            'demo-data.collections.per_title_maximum' => 3,
            'demo-data.public_tag_target' => 12,
            'session.driver' => 'array',
        ]);
    }

    public function test_organization_stage_fills_tags_collections_and_exact_half_public_assignments_idempotently(): void
    {
        CatalogTitle::factory()->count(8)->create();
        $options = DemoDataOptions::fromConfig();
        app(DemoAccountStage::class)->run($options);
        $stage = app(DemoOrganizationStage::class);
        $firstReport = $stage->run($options);
        $firstCounts = $this->organizationCounts();
        $secondReport = $stage->run($options);

        $this->assertSame('organization', $stage->key());
        $this->assertSame($firstCounts, $this->organizationCounts());
        $this->assertSame(48, $firstReport->counters['personal_tags']);
        $this->assertSame(48, $secondReport->counters['personal_tags']);
        $this->assertGreaterThanOrEqual(12, Tag::query()->publiclyEligible()->count());
        $this->assertSame(4, DB::table('catalog_title_tag')->distinct()->count('catalog_title_id'));

        $users = User::query()->whereIn('email', [
            'user1@example.com', 'user2@example.com', 'user3@example.com', 'user4@example.com',
        ])->orderBy('email')->get();
        $selector = new DemoTitleSelector($options);

        foreach ($users->values() as $offset => $user) {
            $selectedIds = $selector->selectedIds($offset + 1)->all();
            $this->assertSame(12, UserTag::query()->where('user_id', $user->id)->count());
            $this->assertSame(12, UserTag::query()
                ->where('user_id', $user->id)
                ->distinct()
                ->count('normalized_name_hash'));
            $this->assertSame(8, CatalogCollection::query()->where('owner_id', $user->id)->count());

            foreach ($selectedIds as $titleId) {
                $personalAssignments = DB::table('catalog_title_user_tag')
                    ->join('user_tags', 'user_tags.id', '=', 'catalog_title_user_tag.user_tag_id')
                    ->where('user_tags.user_id', $user->id)
                    ->where('catalog_title_user_tag.catalog_title_id', $titleId)
                    ->count();
                $collectionAssignments = DB::table('catalog_collection_items')
                    ->join('catalog_collections', 'catalog_collections.id', '=', 'catalog_collection_items.catalog_collection_id')
                    ->where('catalog_collections.owner_id', $user->id)
                    ->where('catalog_collection_items.catalog_title_id', $titleId)
                    ->count();

                $this->assertGreaterThanOrEqual(2, $personalAssignments);
                $this->assertLessThanOrEqual(7, $personalAssignments);
                $this->assertGreaterThanOrEqual(1, $collectionAssignments);
                $this->assertLessThanOrEqual(3, $collectionAssignments);
            }
        }

        $this->assertEqualsCanonicalizing(
            array_column(CatalogCollectionVisibility::cases(), 'value'),
            CatalogCollection::query()->get(['visibility'])->pluck('visibility')
                ->map(static fn (CatalogCollectionVisibility $visibility): string => $visibility->value)
                ->unique()
                ->values()
                ->all(),
        );
        $this->assertEqualsCanonicalizing(
            array_column(CatalogCollectionSort::cases(), 'value'),
            CatalogCollection::query()->get(['sort_mode'])->pluck('sort_mode')
                ->map(static fn (CatalogCollectionSort $sort): string => $sort->value)
                ->unique()
                ->values()
                ->all(),
        );

        CatalogCollection::query()->each(function (CatalogCollection $collection): void {
            $this->assertNotNull($collection->cover_path);
            Storage::disk('uploads')->assertExists($collection->cover_path);
        });

        $duplicatePersonalPivot = DB::table('catalog_title_user_tag')
            ->select(['user_tag_id', 'catalog_title_id'])
            ->groupBy(['user_tag_id', 'catalog_title_id'])
            ->havingRaw('count(*) > 1')
            ->exists();
        $duplicateCollectionPivot = DB::table('catalog_collection_items')
            ->select(['catalog_collection_id', 'catalog_title_id'])
            ->groupBy(['catalog_collection_id', 'catalog_title_id'])
            ->havingRaw('count(*) > 1')
            ->exists();

        $this->assertFalse($duplicatePersonalPivot);
        $this->assertFalse($duplicateCollectionPivot);
    }

    /** @return array<string, int> */
    private function organizationCounts(): array
    {
        return [
            'personal_tags' => DB::table('user_tags')->count(),
            'personal_assignments' => DB::table('catalog_title_user_tag')->count(),
            'collections' => DB::table('catalog_collections')->count(),
            'collection_items' => DB::table('catalog_collection_items')->count(),
            'public_tags' => DB::table('tags')->count(),
            'tag_translations' => DB::table('tag_translations')->count(),
            'public_assignments' => DB::table('catalog_title_tag')->count(),
        ];
    }
}
