<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Enums\CatalogCollectionCategorySuggestionConfidence;
use App\Models\CatalogCollection;
use App\Models\CatalogCollectionCategory;
use App\Models\CatalogCollectionCategoryTranslation;
use App\Models\CatalogCollectionItem;
use App\Models\CatalogTitle;
use App\Models\Country;
use App\Models\Genre;
use App\Models\Network;
use App\Models\Studio;
use App\Services\Collections\CatalogCollectionCategorySuggestionRules;
use App\Services\Collections\CatalogCollectionCategorySuggestionService;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Tests\TestCase;

final class CatalogCollectionCategorySuggestionServiceTest extends TestCase
{
    public function test_confidence_codes_and_thresholds_are_stable(): void
    {
        $this->assertSame('none', CatalogCollectionCategorySuggestionConfidence::None->value);
        $this->assertSame(
            CatalogCollectionCategorySuggestionConfidence::Low,
            CatalogCollectionCategorySuggestionConfidence::fromScore(60),
        );
        $this->assertSame(
            CatalogCollectionCategorySuggestionConfidence::Medium,
            CatalogCollectionCategorySuggestionConfidence::fromScore(70),
        );
        $this->assertSame(
            CatalogCollectionCategorySuggestionConfidence::High,
            CatalogCollectionCategorySuggestionConfidence::fromScore(85),
        );
    }

    public function test_rules_only_reference_supported_default_category_slugs(): void
    {
        $definitions = app(CatalogCollectionCategorySuggestionRules::class)->definitions();

        $this->assertArrayHasKey('animation-and-anime', $definitions);
        $this->assertArrayHasKey('netflix', $definitions);
        $this->assertArrayHasKey('south-korea', $definitions);
        $this->assertArrayNotHasKey('calm-evening', $definitions);
        $this->assertArrayNotHasKey('other-countries', $definitions);
    }

    public function test_exact_platform_evidence_produces_explained_high_confidence_suggestion(): void
    {
        $collection = $this->collectionEvidence(
            'Лучшие сериалы Netflix',
            'Оригинальные проекты Netflix.',
            [],
            sourceName: 'Netflix originals',
        );

        $suggestion = app(CatalogCollectionCategorySuggestionService::class)
            ->suggest($collection, $this->activeTree([
                'netflix' => 'Netflix',
            ]));

        $this->assertSame('netflix', $suggestion->categorySlug);
        $this->assertSame(
            CatalogCollectionCategorySuggestionConfidence::High,
            $suggestion->confidence,
        );
        $this->assertContains('title_exact', $suggestion->reasonCodes);
        $this->assertContains('source_name', $suggestion->reasonCodes);
    }

    public function test_thematic_title_evidence_uses_the_low_confidence_threshold(): void
    {
        $collection = $this->collectionEvidence(
            'Фантастика',
            null,
            [],
        );

        $suggestion = app(CatalogCollectionCategorySuggestionService::class)
            ->suggest($collection, $this->activeTree([
                'science-fiction-and-fantasy' => 'Фантастика и фэнтези',
            ]));

        $this->assertSame('science-fiction-and-fantasy', $suggestion->categorySlug);
        $this->assertSame(60, $suggestion->score);
        $this->assertSame(
            CatalogCollectionCategorySuggestionConfidence::Low,
            $suggestion->confidence,
        );
        $this->assertSame(['title_theme'], $suggestion->reasonCodes);
    }

    public function test_dominant_sample_metadata_produces_a_supported_suggestion(): void
    {
        $collection = $this->collectionEvidence(
            'Выбор редакции',
            null,
            array_fill(0, 8, [
                'genres' => ['anime'],
                'type' => 'anime',
            ]),
        );

        $suggestion = app(CatalogCollectionCategorySuggestionService::class)
            ->suggest($collection, $this->activeTree([
                'animation-and-anime' => 'Анимация и аниме',
            ]));

        $this->assertSame('animation-and-anime', $suggestion->categorySlug);
        $this->assertGreaterThanOrEqual(70, $suggestion->score);
        $this->assertContains('dominant_genre', $suggestion->reasonCodes);
        $this->assertContains('dominant_type', $suggestion->reasonCodes);
    }

    public function test_close_competing_candidates_return_no_suggestion(): void
    {
        $collection = $this->collectionEvidence(
            'Фантастические детективы',
            null,
            [],
        );

        $suggestion = app(CatalogCollectionCategorySuggestionService::class)
            ->suggest($collection, $this->activeTree([
                'detective-and-crime' => 'Детективы и криминал',
                'science-fiction-and-fantasy' => 'Фантастика и фэнтези',
            ]));

        $this->assertFalse($suggestion->isSuggested());
        $this->assertSame(
            CatalogCollectionCategorySuggestionConfidence::None,
            $suggestion->confidence,
        );
        $this->assertContains('conflict', $suggestion->reasonCodes);
    }

    public function test_the_same_platform_signal_is_not_counted_twice_across_networks_and_studios(): void
    {
        $collection = $this->collectionEvidence(
            'Выбор редакции',
            null,
            array_fill(0, 8, [
                'networks' => ['netflix'],
                'studios' => ['netflix'],
            ]),
        );

        $suggestion = app(CatalogCollectionCategorySuggestionService::class)
            ->suggest($collection, $this->activeTree([
                'netflix' => 'Netflix',
            ]));

        $this->assertFalse($suggestion->isSuggested());
        $this->assertSame(55, $suggestion->score);
        $this->assertSame(['insufficient_evidence'], $suggestion->reasonCodes);
    }

    /**
     * @param  list<array{
     *     genres?: list<string>,
     *     countries?: list<string>,
     *     networks?: list<string>,
     *     studios?: list<string>,
     *     type?: string
     * }>  $sample
     */
    private function collectionEvidence(
        string $name,
        ?string $description,
        array $sample,
        ?string $sourceName = null,
    ): CatalogCollection {
        $collection = new CatalogCollection([
            'public_id' => (string) Str::uuid(),
            'name' => $name,
            'description' => $description,
            'slug' => Str::slug($name),
            'content_version' => 3,
        ]);
        $collection->setAttribute('total_items_count', count($sample));
        $collection->setRelation('translations', new EloquentCollection);
        $collection->setRelation('sourceRecord', $sourceName !== null
            ? (object) ['remote_name' => $sourceName]
            : null);
        $collection->setRelation('items', new EloquentCollection(
            collect($sample)
                ->map(function (array $evidence, int $index): CatalogCollectionItem {
                    $title = new CatalogTitle([
                        'slug' => 'sample-'.$index,
                        'title' => 'Sample '.$index,
                        'type' => $evidence['type'] ?? 'serial',
                    ]);
                    $title->setRelation(
                        'genres',
                        $this->lookupModels(Genre::class, $evidence['genres'] ?? []),
                    );
                    $title->setRelation(
                        'countries',
                        $this->lookupModels(Country::class, $evidence['countries'] ?? []),
                    );
                    $title->setRelation(
                        'networks',
                        $this->lookupModels(Network::class, $evidence['networks'] ?? []),
                    );
                    $title->setRelation(
                        'studios',
                        $this->lookupModels(Studio::class, $evidence['studios'] ?? []),
                    );

                    $item = new CatalogCollectionItem([
                        'catalog_title_id' => $index + 1,
                        'position' => $index,
                    ]);
                    $item->setRelation('catalogTitle', $title);

                    return $item;
                })
                ->all(),
        ));

        return $collection;
    }

    /**
     * @param  array<string, string>  $children
     * @return Collection<int, CatalogCollectionCategory>
     */
    private function activeTree(array $children): Collection
    {
        $root = new CatalogCollectionCategory([
            'public_id' => (string) Str::uuid(),
            'slug' => 'test-root',
            'position' => 0,
            'is_active' => true,
        ]);
        $root->setRelation('translations', new EloquentCollection([
            new CatalogCollectionCategoryTranslation([
                'locale' => 'ru',
                'name' => 'Тестовая категория',
            ]),
        ]));
        $root->setRelation('children', new EloquentCollection(
            collect($children)
                ->map(function (string $name, string $slug) use ($root): CatalogCollectionCategory {
                    $child = new CatalogCollectionCategory([
                        'public_id' => (string) Str::uuid(),
                        'parent_id' => 1,
                        'slug' => $slug,
                        'position' => 0,
                        'is_active' => true,
                    ]);
                    $child->setRelation('parent', $root);
                    $child->setRelation('translations', new EloquentCollection([
                        new CatalogCollectionCategoryTranslation([
                            'locale' => 'ru',
                            'name' => $name,
                        ]),
                    ]));

                    return $child;
                })
                ->values()
                ->all(),
        ));

        return collect([$root]);
    }

    /**
     * @param  class-string<Genre|Country|Network|Studio>  $modelClass
     * @param  list<string>  $slugs
     * @return EloquentCollection<int, Genre|Country|Network|Studio>
     */
    private function lookupModels(string $modelClass, array $slugs): EloquentCollection
    {
        return new EloquentCollection(
            collect($slugs)
                ->map(fn (string $slug) => new $modelClass([
                    'name' => $slug,
                    'slug' => $slug,
                ]))
                ->all(),
        );
    }
}
