<?php

namespace Tests\Feature;

use App\Enums\ContentAudience;
use App\Enums\PublicationStatus;
use App\Models\Actor;
use App\Models\CatalogTitle;
use App\Models\Country;
use App\Models\Genre;
use App\Models\LicensedMedia;
use App\Models\Translation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
}
