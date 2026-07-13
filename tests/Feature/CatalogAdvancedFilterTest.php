<?php

namespace Tests\Feature;

use App\Enums\CatalogSearchIndexStatus;
use App\Enums\ContentAudience;
use App\Enums\PublicationStatus;
use App\Http\Requests\CatalogTitlesRequest;
use App\Models\Actor;
use App\Models\CatalogSearchIndexState;
use App\Models\CatalogTitle;
use App\Models\Country;
use App\Models\Episode;
use App\Models\Genre;
use App\Models\LicensedMedia;
use App\Models\Season;
use App\Models\Translation;
use App\Models\User;
use App\Services\Catalog\CatalogTitlesPageBuilder;
use App\Services\Catalog\Search\CatalogSearchIndexer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CatalogAdvancedFilterTest extends TestCase
{
    use RefreshDatabase;

    public function test_values_are_or_inside_each_group_and_groups_are_combined_with_and(): void
    {
        $actorA = Actor::query()->create(['name' => 'Актер А', 'slug' => 'akter-a']);
        $actorB = Actor::query()->create(['name' => 'Актер Б', 'slug' => 'akter-b']);
        $actorC = Actor::query()->create(['name' => 'Актер В', 'slug' => 'akter-v']);
        $drama = Genre::query()->create(['name' => 'Драма', 'slug' => 'drama']);
        $thriller = Genre::query()->create(['name' => 'Триллер', 'slug' => 'thriller']);
        $comedy = Genre::query()->create(['name' => 'Комедия', 'slug' => 'comedy']);

        $actorADrama = CatalogTitle::factory()->create(['title' => 'А и драма 2024', 'slug' => 'a-drama-2024', 'year' => 2024]);
        $actorADrama->actors()->attach($actorA);
        $actorADrama->genres()->attach($drama);

        $actorBThriller = CatalogTitle::factory()->create(['title' => 'Б и триллер 2025', 'slug' => 'b-thriller-2025', 'year' => 2025]);
        $actorBThriller->actors()->attach($actorB);
        $actorBThriller->genres()->attach($thriller);

        $allSelectedValues = CatalogTitle::factory()->create(['title' => 'Все выбранные значения', 'slug' => 'vse-vybrannye-znacheniia', 'year' => 2024]);
        $allSelectedValues->actors()->attach([$actorA->id, $actorB->id]);
        $allSelectedValues->genres()->attach([$drama->id, $thriller->id]);

        $wrongActor = CatalogTitle::factory()->create(['title' => 'Неподходящий актер', 'slug' => 'wrong-actor', 'year' => 2024]);
        $wrongActor->actors()->attach($actorC);
        $wrongActor->genres()->attach($drama);

        $wrongGenre = CatalogTitle::factory()->create(['title' => 'Неподходящий жанр', 'slug' => 'wrong-genre', 'year' => 2024]);
        $wrongGenre->actors()->attach($actorA);
        $wrongGenre->genres()->attach($comedy);

        $wrongYear = CatalogTitle::factory()->create(['title' => 'Неподходящий год', 'slug' => 'wrong-year', 'year' => 2023]);
        $wrongYear->actors()->attach($actorA);
        $wrongYear->genres()->attach($drama);

        $this->get(route('titles.index', [
            'year' => [2024, 2025],
            'actor' => ['akter-a', 'akter-b'],
            'genre' => ['drama', 'thriller'],
        ]))
            ->assertOk()
            ->assertSeeText('Найдено сейчас: 3')
            ->assertSeeText($actorADrama->title)
            ->assertSeeText($actorBThriller->title)
            ->assertSeeText($allSelectedValues->title)
            ->assertDontSeeText($wrongActor->title)
            ->assertDontSeeText($wrongGenre->title)
            ->assertDontSeeText($wrongYear->title);
    }

    public function test_country_exclusion_removes_matching_titles(): void
    {
        $russia = Country::query()->create(['name' => 'Россия', 'slug' => 'rossiya']);
        $usa = Country::query()->create(['name' => 'США', 'slug' => 'ssha']);
        $russianTitle = CatalogTitle::factory()->create(['title' => 'Российский сериал', 'slug' => 'rossiiskii-serial']);
        $americanTitle = CatalogTitle::factory()->create(['title' => 'Американский сериал', 'slug' => 'amerikanskii-serial']);
        $russianTitle->countries()->attach($russia);
        $americanTitle->countries()->attach($usa);

        $this->get(route('titles.index', ['exclude_country' => ['ssha']]))
            ->assertOk()
            ->assertSeeText('Российский сериал')
            ->assertDontSeeText('Американский сериал');
    }

    public function test_catalog_visibility_and_facets_are_resolved_for_the_current_user(): void
    {
        $memberGenre = Genre::query()->create(['name' => 'Для участников', 'slug' => 'dlia-uchastnikov']);
        $publicTitle = CatalogTitle::factory()->create([
            'title' => 'Публичный сериал',
            'slug' => 'publichnyi-serial',
        ]);
        $memberTitle = CatalogTitle::factory()->create([
            'title' => 'Сериал для участника',
            'slug' => 'serial-dlia-uchastnika',
            'audience' => ContentAudience::Authenticated,
        ]);
        $memberTitle->genres()->attach($memberGenre);
        CatalogTitle::factory()->create([
            'title' => 'Будущий сериал',
            'slug' => 'budushchii-serial',
            'available_from' => now()->addDay(),
        ]);
        CatalogTitle::factory()->create([
            'title' => 'Скрытый сериал',
            'slug' => 'skrytyi-serial',
            'publication_status' => PublicationStatus::Hidden,
        ]);

        $this->get(route('titles.index'))
            ->assertOk()
            ->assertSeeText($publicTitle->title)
            ->assertDontSeeText($memberTitle->title)
            ->assertDontSeeText($memberGenre->name)
            ->assertDontSeeText('Будущий сериал')
            ->assertDontSeeText('Скрытый сериал');

        $this->actingAs(User::factory()->create())
            ->get(route('titles.index'))
            ->assertOk()
            ->assertSeeText($publicTitle->title)
            ->assertSeeText($memberTitle->title)
            ->assertSeeText($memberGenre->name)
            ->assertDontSeeText('Будущий сериал')
            ->assertDontSeeText('Скрытый сериал');
    }

    public function test_multi_value_pivot_filters_keep_one_row_and_exact_paginator_total(): void
    {
        $drama = Genre::query()->create(['name' => 'Драма', 'slug' => 'drama']);
        $detective = Genre::query()->create(['name' => 'Детектив', 'slug' => 'detective']);
        $matching = CatalogTitle::factory()->create([
            'title' => 'Один подходящий сериал',
            'slug' => 'odin-podkhodiashchii-serial',
        ]);
        $matching->genres()->attach([$drama->id, $detective->id]);
        $partial = CatalogTitle::factory()->create([
            'title' => 'Только драма',
            'slug' => 'tolko-drama',
        ]);
        $partial->genres()->attach($drama);

        $this->get(route('titles.index', ['genre' => ['drama', 'detective']]))
            ->assertOk()
            ->assertSeeText('Найдено сейчас: 2')
            ->assertSeeText($matching->title)
            ->assertSeeText($partial->title);
    }

    public function test_contextual_relation_facets_exclude_their_own_group_and_match_exact_result_ids(): void
    {
        $actorA = Actor::query()->create(['name' => 'Актер А', 'slug' => 'akter-a']);
        $actorB = Actor::query()->create(['name' => 'Актер Б', 'slug' => 'akter-b']);
        $russia = Country::query()->create(['name' => 'Россия', 'slug' => 'rossiya']);
        $usa = Country::query()->create(['name' => 'США', 'slug' => 'ssha']);
        $canada = Country::query()->create(['name' => 'Канада', 'slug' => 'kanada']);
        $drama = Genre::query()->create(['name' => 'Драма', 'slug' => 'drama']);
        $thriller = Genre::query()->create(['name' => 'Триллер', 'slug' => 'thriller']);

        $matching = CatalogTitle::factory()->create(['title' => 'Россия А драма', 'year' => 2024]);
        $matching->actors()->attach($actorA);
        $matching->countries()->attach($russia);
        $matching->genres()->attach($drama);

        $otherCountry = CatalogTitle::factory()->create(['title' => 'США А триллер', 'year' => 2024]);
        $otherCountry->actors()->attach($actorA);
        $otherCountry->countries()->attach($usa);
        $otherCountry->genres()->attach($thriller);

        $otherActor = CatalogTitle::factory()->create(['title' => 'Россия Б триллер', 'year' => 2024]);
        $otherActor->actors()->attach($actorB);
        $otherActor->countries()->attach($russia);
        $otherActor->genres()->attach($thriller);

        $wrongYear = CatalogTitle::factory()->create(['title' => 'Россия А старый', 'year' => 2023]);
        $wrongYear->actors()->attach($actorA);
        $wrongYear->countries()->attach($russia);
        $wrongYear->genres()->attach($drama);

        $unpublished = CatalogTitle::factory()->create([
            'title' => 'Скрытый Канада А',
            'year' => 2024,
            'publication_status' => PublicationStatus::Hidden,
        ]);
        $unpublished->actors()->attach($actorA);
        $unpublished->countries()->attach($canada);

        $data = $this->catalogData([
            'year' => [2024],
            'actor' => [$actorA->slug],
            'country' => [$russia->slug],
        ]);

        $this->assertSame(1, $data['titles']->total());
        $this->assertSame([$matching->id], $data['titles']->getCollection()->pluck('id')->all());

        $countries = $data['filterTaxonomies']->get('country')->keyBy('slug');
        $this->assertSame(1, $countries->get('rossiya')->context_titles_count);
        $this->assertSame(1, $countries->get('ssha')->context_titles_count);
        $this->assertFalse($countries->has('kanada'));
        $this->assertArrayNotHasKey('catalog_titles_count', $countries->get('rossiya')->getAttributes());

        $actors = $data['filterTaxonomies']->get('actor')->keyBy('slug');
        $this->assertSame(1, $actors->get('akter-a')->context_titles_count);
        $this->assertSame(1, $actors->get('akter-b')->context_titles_count);

        $genres = $data['filterTaxonomies']->get('genre')->keyBy('slug');
        $this->assertSame(1, $genres->get('drama')->context_titles_count);
        $this->assertFalse($genres->has('thriller'));
    }

    public function test_contextual_relation_limits_are_applied_after_other_filters(): void
    {
        $russia = Country::query()->create(['name' => 'Россия', 'slug' => 'rossiya']);

        foreach (range(1, 24) as $number) {
            $actor = Actor::query()->create([
                'name' => sprintf('Актер %02d', $number),
                'slug' => 'akter-'.$number,
            ]);
            $title = CatalogTitle::factory()->create(['title' => 'Посторонний '.$number]);
            $title->actors()->attach($actor);
        }

        $contextActor = Actor::query()->create(['name' => 'Я Контекст', 'slug' => 'ia-kontekst']);
        $contextTitle = CatalogTitle::factory()->create(['title' => 'Контекстный сериал']);
        $contextTitle->actors()->attach($contextActor);
        $contextTitle->countries()->attach($russia);

        $actors = $this->catalogData(['country' => [$russia->slug]])['filterTaxonomies']
            ->get('actor')
            ->keyBy('slug');

        $this->assertCount(1, $actors);
        $this->assertSame(1, $actors->get('ia-kontekst')->context_titles_count);
    }

    public function test_fixed_facets_use_contextual_own_group_excluded_counts(): void
    {
        $actorA = Actor::query()->create(['name' => 'Актер А', 'slug' => 'akter-a']);
        $actorB = Actor::query()->create(['name' => 'Актер Б', 'slug' => 'akter-b']);

        $serial = CatalogTitle::factory()->create(['title' => 'Сериал с субтитрами', 'type' => 'serial']);
        $serial->actors()->attach($actorA);
        LicensedMedia::factory()->create([
            'catalog_title_id' => $serial->id,
            'status' => 'published',
            'published_at' => now(),
            'has_subtitles' => true,
        ]);

        $anime = CatalogTitle::factory()->create(['title' => 'Аниме без субтитров', 'type' => 'anime']);
        $anime->actors()->attach($actorA);
        LicensedMedia::factory()->create([
            'catalog_title_id' => $anime->id,
            'status' => 'published',
            'published_at' => now(),
            'has_subtitles' => false,
        ]);

        $documentary = CatalogTitle::factory()->create(['title' => 'Чужой документальный', 'type' => 'documentary']);
        $documentary->actors()->attach($actorB);
        LicensedMedia::factory()->create([
            'catalog_title_id' => $documentary->id,
            'status' => 'published',
            'published_at' => now(),
            'has_subtitles' => true,
        ]);

        $publicationContext = $this->catalogData([
            'actor' => [$actorA->slug],
            'publication_type' => ['serial'],
        ]);
        $publicationTypes = $publicationContext['publicationTypeOptions']->keyBy('value');

        $this->assertSame(1, $publicationContext['titles']->total());
        $this->assertSame(1, $publicationTypes->get('serial')->context_titles_count);
        $this->assertSame(1, $publicationTypes->get('anime')->context_titles_count);
        $this->assertSame(0, $publicationTypes->get('documentary')->context_titles_count);

        $subtitleContext = $this->catalogData([
            'actor' => [$actorA->slug],
            'subtitles' => ['available'],
        ]);
        $subtitles = $subtitleContext['subtitleOptions']->keyBy('value');

        $this->assertSame(1, $subtitleContext['titles']->total());
        $this->assertSame(1, $subtitles->get('available')->context_titles_count);
        $this->assertSame(1, $subtitles->get('missing')->context_titles_count);
    }

    public function test_facet_query_count_does_not_grow_with_option_count(): void
    {
        $firstActor = Actor::query()->create([
            'name' => 'Счетчик 1',
            'slug' => 'schetchik-1',
        ]);
        $firstTitle = CatalogTitle::factory()->create(['title' => 'Сериал счетчика 1']);
        $firstTitle->actors()->attach($firstActor);

        $queriesWithoutOptions = $this->catalogQueryCount();

        $this->assertSame(11, $queriesWithoutOptions);

        foreach (range(2, 20) as $number) {
            $actor = Actor::query()->create([
                'name' => 'Счетчик '.$number,
                'slug' => 'schetchik-'.$number,
            ]);
            $title = CatalogTitle::factory()->create(['title' => 'Сериал счетчика '.$number]);
            $title->actors()->attach($actor);
        }

        $this->assertSame($queriesWithoutOptions, $this->catalogQueryCount());
    }

    public function test_contextual_counts_are_fresh_after_catalog_lifecycle_changes(): void
    {
        $actor = Actor::query()->create(['name' => 'Жизненный цикл', 'slug' => 'zhiznennyi-tsikl']);
        $title = CatalogTitle::factory()->create(['title' => 'Изменяемый сериал']);
        $title->actors()->attach($actor);
        $media = LicensedMedia::factory()->create([
            'catalog_title_id' => $title->id,
            'status' => 'published',
            'published_at' => now(),
            'has_subtitles' => true,
        ]);

        $this->assertSame(1, $this->catalogData()['filterTaxonomies']->get('actor')->firstWhere('id', $actor->id)->context_titles_count);

        $title->update(['publication_status' => PublicationStatus::Hidden]);
        $this->assertNull($this->catalogData()['filterTaxonomies']->get('actor')->firstWhere('id', $actor->id));

        $title->update(['publication_status' => PublicationStatus::Published]);
        $title->delete();
        $this->assertNull($this->catalogData()['filterTaxonomies']->get('actor')->firstWhere('id', $actor->id));

        $title->restore();
        $title->actors()->detach($actor);
        $this->assertNull($this->catalogData()['filterTaxonomies']->get('actor')->firstWhere('id', $actor->id));

        $title->actors()->attach($actor);
        $subtitles = $this->catalogData()['subtitleOptions']->keyBy('value');
        $this->assertSame(1, $subtitles->get('available')->context_titles_count);

        $media->update(['status' => 'unavailable']);
        $subtitles = $this->catalogData()['subtitleOptions']->keyBy('value');
        $this->assertSame(0, $subtitles->get('available')->context_titles_count);
        $this->assertSame(1, $subtitles->get('missing')->context_titles_count);

        $selectedActor = $this->catalogData(['actor' => [$actor->slug], 'episodes_min' => 1])['filterTaxonomies']
            ->get('actor')
            ->firstWhere('id', $actor->id);
        $this->assertSame(0, $selectedActor->context_titles_count);

        $season = Season::factory()->create(['catalog_title_id' => $title->id]);
        Episode::factory()->create(['season_id' => $season->id]);

        $actorOption = $this->catalogData(['actor' => [$actor->slug], 'episodes_min' => 1])['filterTaxonomies']
            ->get('actor')
            ->firstWhere('id', $actor->id);
        $this->assertSame(1, $actorOption->context_titles_count);
    }

    public function test_fixed_and_relation_groups_use_or_inside_group_and_and_between_groups(): void
    {
        $dubbed = Translation::query()->create(['name' => 'Дубляж', 'slug' => 'dubliazh']);
        $original = Translation::query()->create(['name' => 'Оригинал', 'slug' => 'original']);

        $serial = CatalogTitle::factory()->create(['title' => 'Сериал 1080p', 'slug' => 'serial-1080p', 'type' => 'serial']);
        $serial->translations()->attach($dubbed);
        LicensedMedia::factory()->create([
            'catalog_title_id' => $serial->id,
            'status' => 'published',
            'published_at' => now(),
            'quality' => '1080p',
            'has_subtitles' => true,
        ]);

        $anime = CatalogTitle::factory()->create(['title' => 'Аниме 720p', 'slug' => 'anime-720p', 'type' => 'anime']);
        $anime->translations()->attach($original);
        LicensedMedia::factory()->create([
            'catalog_title_id' => $anime->id,
            'status' => 'published',
            'published_at' => now(),
            'quality' => '720p',
            'has_subtitles' => false,
        ]);

        $documentary = CatalogTitle::factory()->create(['title' => 'Документальный 1080p', 'slug' => 'documentary-1080p', 'type' => 'documentary']);
        $documentary->translations()->attach($dubbed);
        LicensedMedia::factory()->create([
            'catalog_title_id' => $documentary->id,
            'status' => 'published',
            'published_at' => now(),
            'quality' => '1080p',
            'has_subtitles' => true,
        ]);

        $this->get(route('titles.index', [
            'publication_type' => ['serial', 'anime'],
            'translation' => ['dubliazh', 'original'],
            'quality' => ['1080p', '720p'],
            'subtitles' => ['available', 'missing'],
        ]))
            ->assertOk()
            ->assertSeeText('Найдено сейчас: 2')
            ->assertSeeText($serial->title)
            ->assertSeeText($anime->title)
            ->assertDontSeeText($documentary->title);

        $this->get(route('titles.index', [
            'publication_type' => ['serial', 'anime'],
            'subtitles' => ['available'],
        ]))
            ->assertOk()
            ->assertSeeText($serial->title)
            ->assertDontSeeText($anime->title)
            ->assertDontSeeText($documentary->title);
    }

    public function test_unknown_title_context_never_falls_back_to_the_full_catalog(): void
    {
        CatalogTitle::factory()->create([
            'title' => 'Не должен появиться',
            'slug' => 'ne-dolzhen-poiavitsia',
        ]);

        $this->get(route('titles.index', ['title' => 'neizvestnyi-serial']))
            ->assertOk()
            ->assertSeeText('Ничего не найдено.')
            ->assertDontSeeText('Не должен появиться');
    }

    public function test_ready_fts_candidates_are_shared_by_results_and_contextual_facets(): void
    {
        $actor = Actor::query()->create(['name' => 'Поисковый Актер', 'slug' => 'poiskovyi-akter']);
        $drama = Genre::query()->create(['name' => 'Поисковая драма', 'slug' => 'poiskovaia-drama']);
        $thriller = Genre::query()->create(['name' => 'Поисковый триллер', 'slug' => 'poiskovyi-triller']);
        $outside = Genre::query()->create(['name' => 'Вне поиска', 'slug' => 'vne-poiska']);
        $first = CatalogTitle::factory()->create(['title' => 'Первый результат']);
        $first->actors()->attach($actor);
        $first->genres()->attach($drama);
        $second = CatalogTitle::factory()->create(['title' => 'Второй результат']);
        $second->actors()->attach($actor);
        $second->genres()->attach($thriller);
        $unmatched = CatalogTitle::factory()->create(['title' => 'Посторонний результат']);
        $unmatched->genres()->attach($outside);
        app(CatalogSearchIndexer::class)->indexTitleIds([$first->id, $second->id, $unmatched->id]);
        CatalogSearchIndexState::query()->findOrFail(CatalogSearchIndexState::SINGLETON_ID)->update([
            'version' => CatalogSearchIndexer::INDEX_VERSION,
            'status' => CatalogSearchIndexStatus::Ready,
            'source_count' => 3,
            'document_count' => 3,
            'completed_at' => now(),
        ]);

        $data = $this->catalogData(['q' => 'Поисковый Актер']);
        $genres = $data['filterTaxonomies']->get('genre')->keyBy('slug');

        $this->assertSame(2, $data['titles']->total());
        $this->assertSame(1, $genres->get('poiskovaia-drama')->context_titles_count);
        $this->assertSame(1, $genres->get('poiskovyi-triller')->context_titles_count);
        $this->assertFalse($genres->has('vne-poiska'));
    }

    /** @return array<string, mixed> */
    private function catalogData(array $query = []): array
    {
        $request = CatalogTitlesRequest::create(route('titles.index'), 'GET', $query);
        $request->setContainer(app())->setRedirector(app('redirect'));
        $request->setUserResolver(fn (): null => null);
        $request->validateResolved();

        return app(CatalogTitlesPageBuilder::class)->data($request);
    }

    private function catalogQueryCount(): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->catalogData();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    }
}
