<?php

namespace Tests\Feature;

use App\Enums\CatalogSearchIndexStatus;
use App\Models\Actor;
use App\Models\CatalogSearchIndexState;
use App\Models\CatalogTitle;
use App\Models\CatalogTitleAlias;
use App\Models\Genre;
use App\Services\Catalog\Search\CatalogSearchIndexer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CatalogSearchPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_repeated_catalog_queries_are_available_to_crawlers_without_a_request_budget(): void
    {
        $crawler = $this
            ->withServerVariables(['REMOTE_ADDR' => '203.0.113.21'])
            ->withHeader('User-Agent', 'ClaudeBot/1.0');

        $crawler->get(route('titles.index', ['q' => 'первый запрос']))->assertOk();
        $crawler->get(route('titles.index', ['q' => 'второй запрос']))->assertOk();
        $crawler->get(route('titles.index'))->assertOk();
    }

    public function test_site_search_suggests_a_matching_local_directory_without_remote_requests(): void
    {
        $this->get(route('titles.index', ['q' => 'Жанры сериалов']))
            ->assertOk()
            ->assertSeeText('Открыть справочник')
            ->assertSee('href="'.route('genres.index').'"', false)
            ->assertSeeText('Жанры сериалов');
    }

    public function test_year_inside_query_is_a_hard_constraint(): void
    {
        CatalogTitle::factory()->create(['title' => 'Знахарь', 'slug' => 'znaxar-2008', 'year' => 2008]);
        CatalogTitle::factory()->create(['title' => 'Знахарь', 'slug' => 'znaxar-2019', 'year' => 2019]);

        $this->get(route('titles.index', ['q' => 'Знахарь 2019']))
            ->assertOk()
            ->assertSee('znaxar-2019', false)
            ->assertDontSee('znaxar-2008', false);
    }

    public function test_query_year_conflicting_with_explicit_year_returns_no_titles(): void
    {
        CatalogTitle::factory()->create(['title' => 'Знахарь', 'slug' => 'znaxar-2008', 'year' => 2008]);
        CatalogTitle::factory()->create(['title' => 'Знахарь', 'slug' => 'znaxar-2019', 'year' => 2019]);

        $this->get(route('titles.index', [
            'q' => 'Знахарь 2019',
            'year' => 2008,
        ]))
            ->assertOk()
            ->assertDontSee('znaxar-2008', false)
            ->assertDontSee('znaxar-2019', false);
    }

    public function test_short_and_punctuation_titles_remain_searchable(): void
    {
        CatalogTitle::factory()->create(['title' => 'OA', 'slug' => 'oa']);
        CatalogTitle::factory()->create(['title' => '11/22/63', 'slug' => '11-22-63']);
        CatalogTitle::factory()->create(['title' => 'Посторонний сериал', 'slug' => 'postoronnii-serial']);

        $this->get(route('titles.index', ['q' => 'OA']))
            ->assertSee('href="'.route('titles.show', 'oa').'"', false)
            ->assertDontSee('href="'.route('titles.show', 'postoronnii-serial').'"', false);
        $this->get(route('titles.index', ['q' => '11.22.63']))
            ->assertSee('href="'.route('titles.show', '11-22-63').'"', false)
            ->assertDontSee('href="'.route('titles.show', 'postoronnii-serial').'"', false);
    }

    public function test_legacy_search_matches_only_title_original_and_alias_names(): void
    {
        $titleMatch = CatalogTitle::factory()->create([
            'title' => 'Художник 2: Возвращение',
            'slug' => 'xudoznik-2-vozvrashhenie',
        ]);
        $originalMatch = CatalogTitle::factory()->create([
            'title' => 'Иностранное название',
            'original_title' => 'Художник 2: Оригинал',
            'slug' => 'inostrannoe-nazvanie',
        ]);
        $aliasMatch = CatalogTitle::factory()->create([
            'title' => 'Название по алиасу',
            'slug' => 'nazvanie-po-aliasu',
        ]);
        CatalogTitleAlias::query()->create([
            'catalog_title_id' => $aliasMatch->id,
            'name' => 'Художник 2: Альтернативный',
            'name_hash' => hash('sha256', 'художник 2 альтернативный'),
            'type' => 'alternative',
            'source' => 'test',
        ]);
        $descriptionNoise = CatalogTitle::factory()->create([
            'title' => 'Шум из описания',
            'slug' => 'sum-iz-opisaniia',
            'description' => 'Художник вернулся во втором сезоне 2.',
        ]);
        $actorNoise = CatalogTitle::factory()->create([
            'title' => 'Шум из актёра',
            'slug' => 'sum-iz-aktera',
        ]);
        $actor = Actor::query()->create(['name' => 'Художник 2', 'slug' => 'xudoznik-2-actor']);
        $actorNoise->actors()->attach($actor);
        $taxonomyNoise = CatalogTitle::factory()->create([
            'title' => 'Шум из жанра',
            'slug' => 'sum-iz-zanra',
        ]);
        $genre = Genre::query()->create(['name' => 'Художник 2', 'slug' => 'xudoznik-2-genre']);
        $taxonomyNoise->genres()->attach($genre);
        $slugNoise = CatalogTitle::factory()->create([
            'title' => 'Шум из адреса',
            'slug' => 'xudoznik-2',
        ]);
        $externalIdNoise = CatalogTitle::factory()->create([
            'title' => 'Шум из внешнего ID',
            'slug' => 'sum-iz-vnesnego-id',
            'external_id' => 'Художник 2',
        ]);

        $response = $this->get(route('titles.index', ['q' => 'Художник 2']));

        $response
            ->assertOk()
            ->assertSee('placeholder="Название сериала"', false)
            ->assertSee('href="'.route('titles.show', $titleMatch).'"', false)
            ->assertSee('href="'.route('titles.show', $originalMatch).'"', false)
            ->assertSee('href="'.route('titles.show', $aliasMatch).'"', false)
            ->assertDontSee('href="'.route('titles.show', $descriptionNoise).'"', false)
            ->assertDontSee('href="'.route('titles.show', $actorNoise).'"', false)
            ->assertDontSee('href="'.route('titles.show', $taxonomyNoise).'"', false)
            ->assertDontSee('href="'.route('titles.show', $slugNoise).'"', false)
            ->assertDontSee('href="'.route('titles.show', $externalIdNoise).'"', false);
    }

    public function test_person_names_do_not_match_titles_through_actor_relations(): void
    {
        $matchingTitle = CatalogTitle::factory()->create([
            'title' => 'Точный актерский результат',
            'slug' => 'tocnyi-akterskii-rezultat',
        ]);
        $matchingActor = Actor::query()->create([
            'name' => 'Милли Бобби Браун',
            'slug' => 'milli-bobbi-braun',
        ]);
        $matchingTitle->actors()->attach($matchingActor->id);

        $partialTitle = CatalogTitle::factory()->create([
            'title' => 'Частичный актерский результат',
            'slug' => 'casticnyi-akterskii-rezultat',
        ]);
        $partialActor = Actor::query()->create([
            'name' => 'Милли Джонсон',
            'slug' => 'milli-dzonson',
        ]);
        $partialTitle->actors()->attach($partialActor->id);

        $this->get(route('titles.index', ['q' => 'Милли Бобби Браун']))
            ->assertOk()
            ->assertDontSee('href="'.route('titles.show', $matchingTitle).'"', false)
            ->assertDontSee('href="'.route('titles.show', $partialTitle).'"', false);
    }

    public function test_person_names_with_yo_do_not_match_titles_through_actor_relations(): void
    {
        $matchingTitle = CatalogTitle::factory()->create([
            'title' => 'Совпадение полного имени',
            'slug' => 'sovpadenie-polnogo-imeni',
        ]);
        $matchingActor = Actor::query()->create([
            'name' => 'Фёдор Лавров',
            'slug' => 'fedor-lavrov',
        ]);
        $matchingTitle->actors()->attach($matchingActor->id);

        $firstNameOnlyTitle = CatalogTitle::factory()->create([
            'title' => 'Совпадение только имени',
            'slug' => 'sovpadenie-tolko-imeni',
        ]);
        $firstNameOnlyActor = Actor::query()->create([
            'name' => 'Федор Соколов',
            'slug' => 'fedor-sokolov',
        ]);
        $firstNameOnlyTitle->actors()->attach($firstNameOnlyActor->id);

        $lastNameOnlyTitle = CatalogTitle::factory()->create([
            'title' => 'Совпадение только фамилии',
            'slug' => 'sovpadenie-tolko-familii',
        ]);
        $lastNameOnlyActor = Actor::query()->create([
            'name' => 'Иван Лавров',
            'slug' => 'ivan-lavrov',
        ]);
        $lastNameOnlyTitle->actors()->attach($lastNameOnlyActor->id);

        $this->get(route('titles.index', ['q' => 'Федор Лавров']))
            ->assertOk()
            ->assertDontSee('href="'.route('titles.show', $matchingTitle).'"', false)
            ->assertDontSee('href="'.route('titles.show', $firstNameOnlyTitle).'"', false)
            ->assertDontSee('href="'.route('titles.show', $lastNameOnlyTitle).'"', false);
    }

    public function test_complete_meaningful_phrase_prefers_exact_title(): void
    {
        $exactTitle = CatalogTitle::factory()->create([
            'title' => 'Очень странные дела',
            'slug' => 'ocen-strannye-dela',
        ]);
        $broaderTitle = CatalogTitle::factory()->create([
            'title' => 'Очень странные важные дела',
            'slug' => 'ocen-strannye-vaznye-dela',
        ]);

        $this->get(route('titles.index', ['q' => 'Очень странные дела']))
            ->assertOk()
            ->assertSee('href="'.route('titles.show', $exactTitle).'"', false)
            ->assertDontSee('href="'.route('titles.show', $broaderTitle).'"', false);
    }

    public function test_external_provider_ids_and_descriptions_do_not_enter_title_search(): void
    {
        $exact = CatalogTitle::factory()->create([
            'title' => 'Сериал по внешнему ID',
            'slug' => 'serial-po-vneshnemu-id',
            'external_id' => '47915',
        ]);
        $broad = CatalogTitle::factory()->create([
            'title' => 'Постороннее описание',
            'slug' => 'postoronnee-opisanie',
            'external_id' => '99999',
            'description' => 'Служебная заметка 47915',
        ]);

        $this->get(route('titles.index', ['q' => '47915']))
            ->assertOk()
            ->assertDontSee('href="'.route('titles.show', $exact).'"', false)
            ->assertDontSee('href="'.route('titles.show', $broad).'"', false);
    }

    public function test_multiple_matching_people_do_not_enter_title_search(): void
    {
        $title = CatalogTitle::factory()->create([
            'title' => 'Один сериал с двумя совпадениями',
            'slug' => 'odin-serial-s-dvumia-sovpadeniiami',
        ]);
        foreach (['Иван Петров', 'Иван Сидоров'] as $index => $name) {
            $actor = Actor::query()->create([
                'name' => $name,
                'slug' => 'ivan-'.($index + 1),
            ]);
            $title->actors()->attach($actor);
        }

        $this->get(route('titles.index', ['q' => 'Иван']))
            ->assertOk()
            ->assertDontSee('href="'.route('titles.show', $title).'"', false);
    }

    public function test_unpublished_exact_title_is_absent(): void
    {
        $unpublishedTitle = CatalogTitle::factory()->create([
            'title' => 'Скрытый точный сериал',
            'slug' => 'skrytyi-tocnyi-serial',
            'is_published' => false,
        ]);

        $this->get(route('titles.index', ['q' => 'Скрытый точный сериал']))
            ->assertOk()
            ->assertDontSee('href="'.route('titles.show', $unpublishedTitle).'"', false);
    }

    public function test_unknown_query_keeps_a_true_zero_result(): void
    {
        CatalogTitle::factory()->create([
            'title' => 'Посторонний сериал',
            'slug' => 'postoronnii-serial',
        ]);

        $this->get(route('titles.index', ['q' => 'шерлокк']))
            ->assertOk()
            ->assertSee('<meta name="robots" content="noindex,nofollow', false)
            ->assertSeeText('По запросу «шерлокк» ничего не найдено.')
            ->assertDontSeeText('Посторонний сериал')
            ->assertDontSeeText('ближайшие результаты');
    }

    public function test_stopword_only_query_has_an_insufficient_state(): void
    {
        CatalogTitle::factory()->create([
            'title' => 'Посторонний сериал',
            'slug' => 'postoronnii-serial',
        ]);

        $this->get(route('titles.index', ['q' => 'смотреть онлайн']))
            ->assertOk()
            ->assertSeeText('Запрос «смотреть онлайн» слишком общий.')
            ->assertDontSeeText('Посторонний сериал');
    }

    public function test_ready_fts_matches_only_title_original_and_alias_names(): void
    {
        $exactTitle = CatalogTitle::factory()->create(['title' => 'Ветер', 'description' => null]);
        $originalTitle = CatalogTitle::factory()->create([
            'title' => 'Оригинальное совпадение',
            'original_title' => 'Ветер',
            'description' => null,
        ]);
        $aliasTitle = CatalogTitle::factory()->create(['title' => 'Совпадение алиаса', 'description' => null]);
        CatalogTitleAlias::query()->create([
            'catalog_title_id' => $aliasTitle->id,
            'name' => 'Ветер',
            'name_hash' => hash('sha256', 'ветер'),
            'type' => 'alternative',
            'source' => 'seasonvar',
        ]);
        $personTitle = CatalogTitle::factory()->create(['title' => 'Совпадение актёра', 'description' => null]);
        $actor = Actor::query()->create(['name' => 'Ветер', 'slug' => 'veter-actor']);
        $personTitle->actors()->attach($actor);
        $taxonomyTitle = CatalogTitle::factory()->create(['title' => 'Совпадение жанра', 'description' => null]);
        $genre = Genre::query()->create(['name' => 'Ветер', 'slug' => 'veter-genre']);
        $taxonomyTitle->genres()->attach($genre);
        $descriptionTitle = CatalogTitle::factory()->create([
            'title' => 'Совпадение описания',
            'description' => 'Ветер упомянут только в описании.',
        ]);
        $titles = collect([$exactTitle, $originalTitle, $aliasTitle, $personTitle, $taxonomyTitle, $descriptionTitle]);
        app(CatalogSearchIndexer::class)->indexTitleIds($titles->pluck('id'));
        CatalogSearchIndexState::query()->findOrFail(CatalogSearchIndexState::SINGLETON_ID)->update([
            'version' => CatalogSearchIndexer::INDEX_VERSION,
            'status' => CatalogSearchIndexStatus::Ready,
            'source_count' => $titles->count(),
            'document_count' => $titles->count(),
            'completed_at' => now(),
        ]);

        $this->get(route('titles.index', ['q' => 'Ветер']))
            ->assertOk()
            ->assertSeeInOrder([$exactTitle->title, $originalTitle->title, $aliasTitle->title])
            ->assertDontSeeText($personTitle->title)
            ->assertDontSeeText($taxonomyTitle->title)
            ->assertDontSeeText($descriptionTitle->title);
    }

    public function test_stale_index_uses_legacy_search_and_never_hides_new_title_text(): void
    {
        $title = CatalogTitle::factory()->create(['title' => 'Старое имя']);
        app(CatalogSearchIndexer::class)->indexTitleIds([$title->id]);
        $title->update(['title' => 'Совершенно новое имя']);
        CatalogSearchIndexState::query()->findOrFail(CatalogSearchIndexState::SINGLETON_ID)->update([
            'version' => CatalogSearchIndexer::INDEX_VERSION,
            'status' => CatalogSearchIndexStatus::Stale,
            'source_count' => 1,
            'document_count' => 1,
            'completed_at' => now(),
        ]);

        $this->get(route('titles.index', ['q' => 'Совершенно новое имя']))
            ->assertOk()
            ->assertSee('href="'.route('titles.show', $title).'"', false);
    }

    public function test_true_zero_renders_typo_suggestions_without_changing_the_result_count(): void
    {
        $title = CatalogTitle::factory()->create(['title' => 'Шерлок']);
        app(CatalogSearchIndexer::class)->indexTitleIds([$title->id]);
        CatalogSearchIndexState::query()->findOrFail(CatalogSearchIndexState::SINGLETON_ID)->update([
            'version' => CatalogSearchIndexer::INDEX_VERSION,
            'status' => CatalogSearchIndexStatus::Ready,
            'source_count' => 1,
            'document_count' => 1,
            'completed_at' => now(),
        ]);

        $this->get(route('titles.index', ['q' => 'шерлокк']))
            ->assertOk()
            ->assertSeeText('Найдено сейчас: 0')
            ->assertSeeText('Возможно, подойдет')
            ->assertSee(route('titles.index', ['sort' => 'relevance', 'q' => 'Шерлок']))
            ->assertDontSee('href="'.route('titles.show', $title).'"', false);
    }

    public function test_filter_only_zero_does_not_render_a_typo_suggestion(): void
    {
        $title = CatalogTitle::factory()->create(['title' => 'Шерлок', 'year' => 2019]);
        app(CatalogSearchIndexer::class)->indexTitleIds([$title->id]);
        CatalogSearchIndexState::query()->findOrFail(CatalogSearchIndexState::SINGLETON_ID)->update([
            'version' => CatalogSearchIndexer::INDEX_VERSION,
            'status' => CatalogSearchIndexStatus::Ready,
            'source_count' => 1,
            'document_count' => 1,
            'completed_at' => now(),
        ]);

        $this->get(route('titles.index', ['q' => 'Шерлок', 'year' => 2020]))
            ->assertOk()
            ->assertSeeText('Найдено сейчас: 0')
            ->assertDontSeeText('Возможно, подойдет');
    }
}
