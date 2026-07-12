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

    public function test_catalog_search_rejects_one_character_with_russian_validation_message(): void
    {
        $this
            ->get(route('titles.index', ['q' => 'я']))
            ->assertOk()
            ->assertSeeText('Введите не менее 2 символов для поиска.');
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
            ->assertSeeText('Поисковый запрос слишком длинный.');
    }

    public function test_catalog_filter_request_rejects_malformed_filter_slug(): void
    {
        $this
            ->get(route('titles.index', ['genre' => 'Bad Slug']))
            ->assertOk()
            ->assertSeeText('Поле жанр должно быть slug: строчные латинские буквы, цифры и дефисы, до 120 символов.');
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
                'episode' => 'bad',
                'media' => 0,
            ]))
            ->assertRedirect($showUrl)
            ->assertSessionHasErrors(['episode', 'media']);
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
}
