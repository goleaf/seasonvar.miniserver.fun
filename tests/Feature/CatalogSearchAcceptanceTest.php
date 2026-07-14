<?php

namespace Tests\Feature;

use App\Enums\CatalogSearchIndexStatus;
use App\Models\Actor;
use App\Models\CatalogSearchIndexState;
use App\Models\CatalogTitle;
use App\Models\CatalogTitleAlias;
use App\Models\Country;
use App\Models\Director;
use App\Models\Genre;
use App\Models\Tag;
use App\Models\Translation;
use App\Services\Catalog\Search\CatalogSearchIndexer;
use App\Services\Catalog\Search\CatalogSearchQueryParser;
use App\Services\Catalog\Search\CatalogTitleSearch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CatalogSearchAcceptanceTest extends TestCase
{
    use RefreshDatabase;

    public function test_title_corpus_keeps_exact_original_alias_and_year_results_at_the_top(): void
    {
        $breakingBad = CatalogTitle::factory()->create(['title' => 'Во все тяжкие', 'original_title' => 'Breaking Bad', 'year' => 2008]);
        $friends = CatalogTitle::factory()->create(['title' => 'Друзья', 'original_title' => 'Friends', 'year' => 1994]);
        $deathNote = CatalogTitle::factory()->create(['title' => 'Тетрадь смерти', 'original_title' => 'Death Note', 'year' => 2006]);
        $znakhar2019 = CatalogTitle::factory()->create(['title' => 'Знахарь', 'year' => 2019]);
        $znakhar2008 = CatalogTitle::factory()->create(['title' => 'Знахарь', 'year' => 2008]);
        $chernobyl2022 = CatalogTitle::factory()->create(['title' => 'Чернобыль', 'year' => 2022]);
        $chernobyl1986 = CatalogTitle::factory()->create(['title' => 'Чернобыль', 'year' => 1986]);
        $monteCristo = CatalogTitle::factory()->create([
            'title' => 'Граф Монте-Кристо',
            'original_title' => 'Le Comte de Monte Cristo',
            'year' => 1961,
        ]);
        $aliasTitle = CatalogTitle::factory()->create(['title' => 'Приключения Шерлока']);
        CatalogTitleAlias::query()->create([
            'catalog_title_id' => $aliasTitle->id,
            'name' => 'Sherlock Holmes',
            'name_hash' => hash('sha256', 'sherlock holmes'),
            'type' => 'alternative',
            'source' => 'seasonvar',
        ]);
        CatalogTitle::factory()->create([
            'title' => 'Постороннее совпадение',
            'description' => 'Breaking Bad, Friends, Death Note, Знахарь, Чернобыль и Sherlock Holmes.',
            'year' => 2022,
        ]);
        $this->indexAllTitlesAndMarkReady();

        foreach ([
            'Во все тяжкие' => $breakingBad->id,
            'Breaking Bad' => $breakingBad->id,
            'Friends' => $friends->id,
            'Death Note' => $deathNote->id,
            'Знахарь 2019' => $znakhar2019->id,
            'Чернобыль 2022' => $chernobyl2022->id,
            'Le Comte de Monte Cristo 1961' => $monteCristo->id,
            'Sherlock Holmes' => $aliasTitle->id,
        ] as $query => $expectedId) {
            $this->assertSame($expectedId, $this->rankedIds($query, 1)[0] ?? null, $query);
        }

        $this->assertNotContains($znakhar2008->id, $this->rankedIds('Знахарь 2019', 3));
        $this->assertNotContains($chernobyl1986->id, $this->rankedIds('Чернобыль 2022', 3));
    }

    public function test_people_names_do_not_enter_title_search(): void
    {
        $people = [
            ['query' => 'Милли Бобби Браун', 'stored' => 'Милли Бобби Браун', 'model' => Actor::class, 'relation' => 'actors'],
            ['query' => 'Брайан Крэнстон', 'stored' => 'Брайан Крэнстон', 'model' => Director::class, 'relation' => 'directors'],
            ['query' => 'Федор Лавров', 'stored' => 'Фёдор Лавров', 'model' => Actor::class, 'relation' => 'actors'],
        ];

        foreach ($people as $personIndex => $person) {
            $model = $person['model']::query()->create([
                'name' => $person['stored'],
                'slug' => 'acceptance-person-'.$personIndex,
            ]);

            foreach (range(1, 3) as $titleIndex) {
                $title = CatalogTitle::factory()->create(['title' => sprintf('Работа актёра %d-%02d', $personIndex, $titleIndex)]);
                $title->{$person['relation']}()->attach($model);
            }

            CatalogTitle::factory()->create([
                'title' => 'Посторонняя работа '.$personIndex,
                'description' => 'В справочном описании упоминается '.$person['stored'].'.',
            ]);
        }
        $this->indexAllTitlesAndMarkReady();

        foreach ($people as $person) {
            $this->assertSame([], $this->rankedIds($person['query'], 10), $person['query']);
        }
    }

    public function test_short_punctuation_transliteration_zero_and_stopword_corpus(): void
    {
        $expected = [
            'OA' => CatalogTitle::factory()->create(['title' => 'OA'])->id,
            'FM' => CatalogTitle::factory()->create(['title' => 'FM'])->id,
            '11.22.63' => CatalogTitle::factory()->create(['title' => '11/22/63'])->id,
            'znakhar' => CatalogTitle::factory()->create(['title' => 'Знахарь'])->id,
        ];
        CatalogTitle::factory()->create(['title' => 'Посторонний сериал']);
        $this->indexAllTitlesAndMarkReady();

        foreach ($expected as $query => $expectedId) {
            $this->assertContains($expectedId, $this->rankedIds($query, 3), $query);
        }

        $this->assertSame([], $this->rankedIds('несуществующее совпадение', 3));

        $insufficient = app(CatalogSearchQueryParser::class)->parse('смотреть онлайн');
        $this->assertNull(app(CatalogTitleSearch::class)->candidateQuery($insufficient));
    }

    public function test_taxonomy_names_do_not_enter_title_search(): void
    {
        $categories = [
            ['query' => 'аниме', 'model' => Genre::class, 'relation' => 'genres'],
            ['query' => 'Япония', 'model' => Country::class, 'relation' => 'countries'],
            ['query' => 'дорама', 'model' => Genre::class, 'relation' => 'genres'],
            ['query' => 'LostFilm', 'model' => Translation::class, 'relation' => 'translations'],
            ['query' => 'медицина', 'model' => Tag::class, 'relation' => 'tags'],
        ];

        foreach ($categories as $categoryIndex => $category) {
            $taxonomy = $category['model']::query()->create([
                'name' => $category['query'],
                'slug' => 'acceptance-category-'.$categoryIndex,
            ]);

            foreach (range(1, 3) as $titleIndex) {
                $title = CatalogTitle::factory()->create(['title' => sprintf('Категорийная работа %d-%02d', $categoryIndex, $titleIndex)]);
                $title->{$category['relation']}()->attach($taxonomy);
            }

            CatalogTitle::factory()->create([
                'title' => 'Посторонняя категория '.$categoryIndex,
                'description' => 'В описании упоминается '.$category['query'].'.',
            ]);
        }
        $this->indexAllTitlesAndMarkReady();

        foreach ($categories as $category) {
            $this->assertSame([], $this->rankedIds($category['query'], 24), $category['query']);
        }
    }

    /** @return list<int> */
    private function rankedIds(string $value, int $limit): array
    {
        $query = app(CatalogSearchQueryParser::class)->parse($value);
        $candidates = app(CatalogTitleSearch::class)->candidateQuery($query);

        $this->assertNotNull($candidates);

        if ($query->year !== null) {
            $candidates
                ->join('catalog_titles', 'catalog_titles.id', '=', 'catalog_title_search_documents.catalog_title_id')
                ->where('catalog_titles.year', $query->year);
        }

        return $candidates
            ->limit($limit)
            ->pluck('catalog_title_search_documents.catalog_title_id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();
    }

    private function indexAllTitlesAndMarkReady(): void
    {
        $ids = CatalogTitle::query()->orderBy('id')->pluck('id');

        app(CatalogSearchIndexer::class)->indexTitleIds($ids);
        CatalogSearchIndexState::query()->findOrFail(CatalogSearchIndexState::SINGLETON_ID)->update([
            'version' => CatalogSearchIndexer::INDEX_VERSION,
            'status' => CatalogSearchIndexStatus::Ready,
            'source_count' => $ids->count(),
            'document_count' => $ids->count(),
            'completed_at' => now(),
        ]);
    }
}
