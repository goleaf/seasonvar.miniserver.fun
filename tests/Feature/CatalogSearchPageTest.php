<?php

namespace Tests\Feature;

use App\Models\Actor;
use App\Models\CatalogTitle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CatalogSearchPageTest extends TestCase
{
    use RefreshDatabase;

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

    public function test_all_person_name_terms_must_match_one_title(): void
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
            ->assertSee('href="'.route('titles.show', $matchingTitle).'"', false)
            ->assertDontSee('href="'.route('titles.show', $partialTitle).'"', false);
    }

    public function test_person_search_matches_stored_yo_and_excludes_partial_names(): void
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
            ->assertSee('href="'.route('titles.show', $matchingTitle).'"', false)
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
}
