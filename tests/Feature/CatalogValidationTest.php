<?php

namespace Tests\Feature;

use App\Models\CatalogTitle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CatalogValidationTest extends TestCase
{
    use RefreshDatabase;

    public function test_catalog_search_form_filters_results_and_renders_normalized_query(): void
    {
        CatalogTitle::factory()->create([
            'slug' => 'znaxar',
            'title' => 'Знахарь',
            'description' => 'Медицинская драма',
        ]);
        CatalogTitle::factory()->create([
            'slug' => 'drugoi-serial',
            'title' => 'Другой сериал',
            'description' => 'Описание без точного совпадения',
        ]);

        $this
            ->get(route('titles.index', ['q' => '  Знахарь   ']))
            ->assertOk()
            ->assertSeeText('Знахарь')
            ->assertSee('value="Знахарь"', false)
            ->assertDontSeeText('Другой сериал');
    }

    public function test_catalog_search_allows_one_character_for_an_exact_title_match(): void
    {
        $title = CatalogTitle::factory()->create([
            'slug' => 'ya',
            'title' => 'Я',
        ]);

        $this
            ->get(route('titles.index', ['q' => 'я']))
            ->assertOk()
            ->assertSessionDoesntHaveErrors('q')
            ->assertSee('href="'.route('titles.show', $title).'"', false);
    }

    public function test_catalog_search_allows_eighty_cyrillic_characters(): void
    {
        $search = str_repeat('я', 80);

        $this
            ->get(route('titles.index', ['q' => $search]))
            ->assertOk()
            ->assertSessionDoesntHaveErrors('q');
    }

    public function test_catalog_search_rejects_eighty_one_characters_and_preserves_old_input(): void
    {
        $longSearch = str_repeat('я', 81);

        $this
            ->get(route('titles.index', ['q' => $longSearch]))
            ->assertOk()
            ->assertSeeText('Поисковый запрос не должен быть длиннее 80 символов.');
    }

    public function test_catalog_safely_ignores_array_search_and_unsupported_sort_direction(): void
    {
        $visible = CatalogTitle::factory()->create([
            'title' => 'Безопасный каталог',
            'slug' => 'bezopasnyi-katalog',
        ]);

        $url = route('titles.index', [
            'q' => ['bad'],
            'sort' => 'not-supported',
            'direction' => 'desc; drop table catalog_titles',
            'page' => ['999'],
        ]);

        $this->get($url)->assertRedirect(route('titles.index'));

        $this->followingRedirects()->get($url)
            ->assertOk()
            ->assertSessionDoesntHaveErrors(['q', 'sort'])
            ->assertSeeText($visible->title)
            ->assertSeeText('Недавно обновлённые');
    }

    public function test_catalog_filter_request_rejects_malformed_filter_slug(): void
    {
        $this
            ->get(route('titles.index', ['genre' => 'Bad Slug']))
            ->assertOk()
            ->assertSeeText('Поле Жанр должно быть slug: строчные латинские буквы, цифры и дефисы, до 120 символов.');
    }

    public function test_catalog_show_request_rejects_invalid_selected_episode_and_media_ids(): void
    {
        $catalogTitle = CatalogTitle::factory()->create([
            'slug' => 'validaciya-video',
            'title' => 'Валидация видео',
        ]);
        $showUrl = route('titles.show', $catalogTitle);

        $this
            ->from($showUrl)
            ->get(route('titles.show', [
                'catalogTitle' => $catalogTitle,
                'season' => ['bad'],
                'episode' => 'bad',
                'media' => 0,
            ]))
            ->assertRedirect($showUrl)
            ->assertSessionHasErrors(['season', 'episode', 'media']);
    }

    public function test_unknown_taxonomy_route_returns_not_found_instead_of_full_catalog(): void
    {
        CatalogTitle::factory()->create([
            'slug' => 'vidimyi-serial',
            'title' => 'Видимый сериал',
        ]);

        $this
            ->get(route('titles.taxonomy', ['type' => 'genre', 'taxonomy' => 'neizvestnyi-zhanr']))
            ->assertNotFound()
            ->assertDontSeeText('Видимый сериал');
    }

    public function test_catalog_rejects_more_than_twenty_values_per_filter(): void
    {
        $this
            ->get(route('titles.index', ['genre' => array_map(fn (int $index): string => 'genre-'.$index, range(1, 21))]))
            ->assertOk()
            ->assertSeeText('Выбрано слишком много значений фильтра.');
    }

    public function test_catalog_rejects_inverted_ranges(): void
    {
        $this
            ->get(route('titles.index', ['year_from' => 2024, 'year_to' => 2010]))
            ->assertOk()
            ->assertSeeText('Начало диапазона не может быть больше конца.');
    }

    public function test_catalog_rejects_the_same_included_and_excluded_value(): void
    {
        $this
            ->get(route('titles.index', [
                'country' => ['rossiya'],
                'exclude_country' => ['rossiya'],
            ]))
            ->assertOk()
            ->assertSeeText('Одно значение нельзя одновременно включить и исключить.');
    }

    public function test_catalog_ignores_missing_filter_records_without_errors(): void
    {
        $visible = CatalogTitle::factory()->create([
            'title' => 'Видимый при пустом фильтре',
            'slug' => 'vidimyi-pri-pustom-filtre',
        ]);

        $this->get(route('titles.index', [
            'actor' => ['', 'missing-actor', 'missing-actor'],
        ]))
            ->assertOk()
            ->assertSeeText($visible->title)
            ->assertDontSeeText('Проверьте параметры каталога.');
    }
}
