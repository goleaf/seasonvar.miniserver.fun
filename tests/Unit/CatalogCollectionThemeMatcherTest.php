<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Enums\CatalogCollectionInclusionReason;
use App\Enums\CatalogCollectionMode;
use App\Enums\CatalogCollectionType;
use App\Models\CatalogCollection;
use App\Models\CatalogCollectionCategory;
use App\Models\CatalogCollectionSource;
use App\Models\CatalogTitle;
use App\Models\Country;
use App\Models\Genre;
use App\Models\Network;
use App\Models\Studio;
use App\Services\Collections\CatalogCollectionCategorySuggestionRules;
use App\Services\Collections\Quality\CatalogCollectionThemeMatcher;
use Illuminate\Support\Collection;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class CatalogCollectionThemeMatcherTest extends TestCase
{
    #[Test]
    public function taxonomy_match_explains_a_full_genre_match(): void
    {
        $collection = $this->collection('comedy');
        $title = new CatalogTitle(['title' => 'Смешная история', 'type' => 'serial']);
        $title->setRelation('genres', new Collection([
            new Genre(['name' => 'Комедия', 'slug' => 'comedy']),
        ]));
        $title->setRelation('countries', new Collection);
        $title->setRelation('networks', new Collection);
        $title->setRelation('studios', new Collection);

        $match = $this->matcher()->match($collection, $title);

        self::assertSame(100, $match->percent);
        self::assertSame(CatalogCollectionInclusionReason::CategoryGenre, $match->reason);
    }

    #[Test]
    public function source_and_smart_rules_are_truthful_precise_reasons(): void
    {
        $title = new CatalogTitle(['title' => 'Любой сериал', 'type' => 'serial']);
        $source = $this->collection('unknown');
        $source->setRelation('sourceRecord', new CatalogCollectionSource([
            'remote_name' => 'Источник',
        ]));
        $smart = $this->collection('unknown');
        $smart->forceFill(['mode' => CatalogCollectionMode::Smart]);

        self::assertSame(
            CatalogCollectionInclusionReason::SourceRule,
            $this->matcher()->match($source, $title)->reason,
        );
        self::assertSame(
            CatalogCollectionInclusionReason::SmartRule,
            $this->matcher()->match($smart, $title)->reason,
        );
        self::assertSame(100, $this->matcher()->match($smart, $title)->percent);
    }

    #[Test]
    public function unmatched_manual_choice_is_not_presented_as_precise_evidence(): void
    {
        $collection = $this->collection('comedy');
        $collection->forceFill(['type' => CatalogCollectionType::User]);
        $title = new CatalogTitle(['title' => 'Научная документалистика', 'type' => 'documentary']);
        $title->setRelation('genres', new Collection);
        $title->setRelation('countries', new Collection);
        $title->setRelation('networks', new Collection);
        $title->setRelation('studios', new Collection);

        $match = $this->matcher()->match($collection, $title);

        self::assertSame(0, $match->percent);
        self::assertSame(CatalogCollectionInclusionReason::ManualChoice, $match->reason);
        self::assertFalse($match->reason->isPrecise());
        self::assertFalse(CatalogCollectionInclusionReason::EditorialChoice->isPrecise());
    }

    #[Test]
    public function country_platform_type_and_text_matches_have_distinct_explanations(): void
    {
        $countryTitle = $this->title();
        $countryTitle->setRelation('countries', new Collection([
            new Country(['name' => 'Южная Корея', 'slug' => 'south-korea']),
        ]));
        $platformTitle = $this->title();
        $platformTitle->setRelation('networks', new Collection([
            new Network(['name' => 'Netflix', 'slug' => 'netflix']),
        ]));
        $typeTitle = $this->title(type: 'documentary');
        $textTitle = $this->title(
            title: 'Детектив',
            description: 'Криминальная загадка',
        );

        self::assertSame(
            CatalogCollectionInclusionReason::CategoryCountry,
            $this->matcher()->match($this->collection('south-korea'), $countryTitle)->reason,
        );
        self::assertSame(
            CatalogCollectionInclusionReason::CategoryPlatform,
            $this->matcher()->match($this->collection('netflix'), $platformTitle)->reason,
        );
        self::assertSame(
            CatalogCollectionInclusionReason::CategoryType,
            $this->matcher()->match($this->collection('documentary-stories'), $typeTitle)->reason,
        );
        self::assertSame(
            CatalogCollectionInclusionReason::TitleTheme,
            $this->matcher()->match($this->collection('detective-and-crime'), $textTitle)->reason,
        );
        self::assertSame(
            80,
            $this->matcher()->match($this->collection('detective-and-crime'), $textTitle)->percent,
        );
    }

    #[Test]
    public function subcategory_inherits_the_parent_theme_rule(): void
    {
        $collection = $this->collection('crime-classics');
        $collection->category->setRelation(
            'parent',
            new CatalogCollectionCategory([
                'slug' => 'detective-and-crime',
                'is_active' => true,
            ]),
        );

        $match = $this->matcher()->match(
            $collection,
            $this->title(description: 'Криминальная загадка и расследование'),
        );

        self::assertSame(80, $match->percent);
        self::assertSame(CatalogCollectionInclusionReason::TitleTheme, $match->reason);
    }

    private function matcher(): CatalogCollectionThemeMatcher
    {
        return new CatalogCollectionThemeMatcher(new CatalogCollectionCategorySuggestionRules);
    }

    private function collection(string $categorySlug): CatalogCollection
    {
        $collection = new CatalogCollection([
            'type' => CatalogCollectionType::Editorial,
            'mode' => CatalogCollectionMode::Manual,
        ]);
        $collection->setRelation('category', new CatalogCollectionCategory([
            'slug' => $categorySlug,
            'is_active' => true,
        ]));
        $collection->setRelation('sourceRecord', null);

        return $collection;
    }

    private function title(
        string $title = 'Нейтральный сериал',
        ?string $description = null,
        string $type = 'serial',
    ): CatalogTitle {
        $titleModel = new CatalogTitle([
            'title' => $title,
            'description' => $description,
            'type' => $type,
        ]);
        $titleModel->setRelation('genres', new Collection);
        $titleModel->setRelation('countries', new Collection);
        $titleModel->setRelation('networks', new Collection);
        $titleModel->setRelation('studios', new Collection([
            new Studio(['name' => 'Другая студия', 'slug' => 'other']),
        ]));

        return $titleModel;
    }
}
