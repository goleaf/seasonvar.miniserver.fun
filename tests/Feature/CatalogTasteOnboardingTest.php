<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\DTOs\CatalogTasteOnboardingData;
use App\Enums\CatalogRecommendationCompletionPreference;
use App\Enums\CatalogRecommendationEpisodeLengthPreference;
use App\Enums\CatalogRecommendationPlaybackPreference;
use App\Enums\PublicationStatus;
use App\Livewire\TasteOnboardingPage;
use App\Models\CatalogTitle;
use App\Models\Country;
use App\Models\Genre;
use App\Models\User;
use App\Models\UserAccountSetting;
use App\Services\Catalog\CatalogTasteOnboardingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

final class CatalogTasteOnboardingTest extends TestCase
{
    use RefreshDatabase;

    public function test_onboarding_routes_require_an_authenticated_verified_owner(): void
    {
        $this->assertTrue(Route::has('onboarding.tastes'));
        $this->assertTrue(Route::has('localized.onboarding.tastes'));

        $this->get(route('onboarding.tastes'))
            ->assertRedirect(route('login'));

        $unverified = User::factory()->unverified()->create();

        $this->actingAs($unverified)
            ->get(route('onboarding.tastes'))
            ->assertRedirect(route('verification.notice'));

        $verified = User::factory()->create();

        $this->actingAs($verified)
            ->get(route('onboarding.tastes'))
            ->assertOk()
            ->assertSeeText('Настройте рекомендации')
            ->assertSee('<meta name="robots" content="noindex, nofollow">', false);
    }

    public function test_verified_owner_can_save_a_complete_bounded_profile_without_overwriting_other_playback_settings(): void
    {
        $user = User::factory()->create();
        UserAccountSetting::query()->create([
            'user_id' => $user->id,
            'locale' => 'ru',
            'timezone' => 'Europe/Vilnius',
            'autoplay' => true,
            'volume' => 37,
            'preferred_quality' => '1080',
            'preferred_variant' => 'voice-one',
            'subtitles_enabled' => false,
            'settings_version' => 3,
        ]);
        $titles = CatalogTitle::factory()->count(7)->create();
        $genres = collect([
            Genre::query()->create(['name' => 'Драма', 'slug' => 'onboarding-drama']),
            Genre::query()->create(['name' => 'Комедия', 'slug' => 'onboarding-comedy']),
        ]);
        $countries = collect([
            Country::query()->create(['name' => 'Япония', 'slug' => 'onboarding-japan']),
            Country::query()->create(['name' => 'Канада', 'slug' => 'onboarding-canada']),
        ]);

        $state = app(CatalogTasteOnboardingService::class)->save(
            $user,
            new CatalogTasteOnboardingData(
                likedTitleIds: $titles->take(5)->pluck('id')->all(),
                excludedTitleIds: [$titles[5]->id],
                genreIds: $genres->pluck('id')->all(),
                countryIds: $countries->pluck('id')->all(),
                locale: 'en',
                playbackPreference: CatalogRecommendationPlaybackPreference::Subtitles,
                completionPreference: CatalogRecommendationCompletionPreference::Completed,
                episodeLengthPreference: CatalogRecommendationEpisodeLengthPreference::Short,
            ),
        );

        $this->assertCount(5, $state->likedTitleIds);
        $this->assertSame([$titles[5]->id], $state->excludedTitleIds);
        $this->assertNotNull($state->completedAt);
        $this->assertDatabaseHas('catalog_recommendation_preferences', [
            'user_id' => $user->id,
            'playback_preference' => 'subtitles',
            'completion_preference' => 'completed',
            'episode_length_preference' => 'short',
        ]);
        $this->assertDatabaseCount('catalog_recommendation_onboarding_titles', 6);
        $this->assertDatabaseCount('catalog_recommendation_preferred_genres', 2);
        $this->assertDatabaseCount('catalog_recommendation_preferred_countries', 2);
        $this->assertDatabaseHas('user_account_settings', [
            'user_id' => $user->id,
            'locale' => 'en',
            'timezone' => 'Europe/Vilnius',
            'autoplay' => true,
            'volume' => 37,
            'preferred_quality' => '1080',
            'preferred_variant' => 'voice-one',
            'preferred_playback_mode' => 'original_subtitles',
            'subtitles_enabled' => true,
        ]);
    }

    public function test_service_rejects_too_few_too_many_overlapping_and_unknown_titles(): void
    {
        $user = User::factory()->create();
        $titles = CatalogTitle::factory()->count(12)->create();
        $genre = Genre::query()->create(['name' => 'Триллер', 'slug' => 'onboarding-thriller']);
        $country = Country::query()->create(['name' => 'Франция', 'slug' => 'onboarding-france']);
        $service = app(CatalogTasteOnboardingService::class);

        foreach ([
            $titles->take(4)->pluck('id')->all(),
            $titles->take(11)->pluck('id')->all(),
        ] as $likedTitleIds) {
            try {
                $service->save($user, $this->data($likedTitleIds, [], $genre->id, $country->id));
                $this->fail('Неверное количество понравившихся тайтлов было принято.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('likedTitleIds', $exception->errors());
            }
        }

        try {
            $service->save(
                $user,
                $this->data(
                    $titles->take(5)->pluck('id')->all(),
                    [$titles->firstOrFail()->id],
                    $genre->id,
                    $country->id,
                ),
            );
            $this->fail('Пересекающиеся liked/excluded IDs были приняты.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('excludedTitleIds', $exception->errors());
        }

        try {
            $service->save(
                $user,
                $this->data(
                    [...$titles->take(4)->pluck('id')->all(), 9_999_999],
                    [],
                    $genre->id,
                    $country->id,
                ),
            );
            $this->fail('Неизвестный ID тайтла был принят.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('likedTitleIds', $exception->errors());
        }

        $this->assertDatabaseCount('catalog_recommendation_preferences', 0);
        $this->assertDatabaseCount('catalog_recommendation_onboarding_titles', 0);
    }

    public function test_service_rejects_duplicates_unsupported_locale_unknown_taxonomy_and_invisible_title(): void
    {
        $user = User::factory()->create();
        $titles = CatalogTitle::factory()->count(5)->create();
        $invisible = CatalogTitle::factory()->create([
            'is_published' => false,
            'publication_status' => PublicationStatus::Draft,
        ]);
        $genre = Genre::query()->create(['name' => 'Детектив', 'slug' => 'onboarding-detective']);
        $country = Country::query()->create(['name' => 'Швеция', 'slug' => 'onboarding-sweden']);
        $service = app(CatalogTasteOnboardingService::class);
        $valid = $this->data($titles->pluck('id')->all(), [], $genre->id, $country->id);
        $cases = [
            [
                new CatalogTasteOnboardingData(
                    likedTitleIds: [$titles[0]->id, $titles[0]->id, $titles[1]->id, $titles[2]->id, $titles[3]->id],
                    excludedTitleIds: [],
                    genreIds: [$genre->id],
                    countryIds: [$country->id],
                    locale: 'ru',
                    playbackPreference: CatalogRecommendationPlaybackPreference::Any,
                    completionPreference: CatalogRecommendationCompletionPreference::Any,
                    episodeLengthPreference: CatalogRecommendationEpisodeLengthPreference::Any,
                ),
                'likedTitleIds',
            ],
            [
                new CatalogTasteOnboardingData(
                    likedTitleIds: $valid->likedTitleIds,
                    excludedTitleIds: [],
                    genreIds: [$genre->id],
                    countryIds: [$country->id],
                    locale: 'de',
                    playbackPreference: CatalogRecommendationPlaybackPreference::Any,
                    completionPreference: CatalogRecommendationCompletionPreference::Any,
                    episodeLengthPreference: CatalogRecommendationEpisodeLengthPreference::Any,
                ),
                'locale',
            ],
            [
                new CatalogTasteOnboardingData(
                    likedTitleIds: $valid->likedTitleIds,
                    excludedTitleIds: [],
                    genreIds: [9_999_999],
                    countryIds: [$country->id],
                    locale: 'ru',
                    playbackPreference: CatalogRecommendationPlaybackPreference::Any,
                    completionPreference: CatalogRecommendationCompletionPreference::Any,
                    episodeLengthPreference: CatalogRecommendationEpisodeLengthPreference::Any,
                ),
                'genreIds',
            ],
            [
                new CatalogTasteOnboardingData(
                    likedTitleIds: [$invisible->id, ...$titles->take(4)->pluck('id')->all()],
                    excludedTitleIds: [],
                    genreIds: [$genre->id],
                    countryIds: [$country->id],
                    locale: 'ru',
                    playbackPreference: CatalogRecommendationPlaybackPreference::Any,
                    completionPreference: CatalogRecommendationCompletionPreference::Any,
                    episodeLengthPreference: CatalogRecommendationEpisodeLengthPreference::Any,
                ),
                'likedTitleIds',
            ],
        ];

        foreach ($cases as [$data, $field]) {
            try {
                $service->save($user, $data);
                $this->fail("Неверное поле {$field} было принято.");
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey($field, $exception->errors());
            }
        }

        $this->assertDatabaseCount('catalog_recommendation_preferences', 0);
    }

    public function test_repeated_save_replaces_owned_state_and_query_count_is_constant_at_the_title_limits(): void
    {
        $smallUser = User::factory()->create();
        $largeUser = User::factory()->create();
        $titles = CatalogTitle::factory()->count(21)->create();
        $genres = collect([
            Genre::query()->create(['name' => 'Боевик', 'slug' => 'onboarding-action']),
            Genre::query()->create(['name' => 'Мистика', 'slug' => 'onboarding-mystery']),
        ]);
        $countries = collect([
            Country::query()->create(['name' => 'Испания', 'slug' => 'onboarding-spain']),
            Country::query()->create(['name' => 'Норвегия', 'slug' => 'onboarding-norway']),
        ]);
        $service = app(CatalogTasteOnboardingService::class);
        $small = new CatalogTasteOnboardingData(
            likedTitleIds: $titles->take(5)->pluck('id')->all(),
            excludedTitleIds: [],
            genreIds: [$genres[0]->id],
            countryIds: [$countries[0]->id],
            locale: 'ru',
            playbackPreference: CatalogRecommendationPlaybackPreference::Any,
            completionPreference: CatalogRecommendationCompletionPreference::Any,
            episodeLengthPreference: CatalogRecommendationEpisodeLengthPreference::Any,
        );
        $large = new CatalogTasteOnboardingData(
            likedTitleIds: $titles->slice(5, 10)->pluck('id')->all(),
            excludedTitleIds: $titles->slice(15, 6)->pluck('id')->all(),
            genreIds: $genres->pluck('id')->all(),
            countryIds: $countries->pluck('id')->all(),
            locale: 'en',
            playbackPreference: CatalogRecommendationPlaybackPreference::Dubbed,
            completionPreference: CatalogRecommendationCompletionPreference::Ongoing,
            episodeLengthPreference: CatalogRecommendationEpisodeLengthPreference::Long,
        );

        DB::enableQueryLog();
        $service->save($smallUser, $small);
        $smallCount = count(DB::getQueryLog());
        DB::flushQueryLog();
        $service->save($largeUser, $large);
        $largeCount = count(DB::getQueryLog());
        DB::disableQueryLog();
        $service->save($smallUser, $large);

        $this->assertLessThanOrEqual($smallCount + 2, $largeCount);
        $this->assertSame(10, DB::table('catalog_recommendation_onboarding_titles')
            ->where('user_id', $smallUser->id)
            ->where('kind', 'liked')
            ->count());
        $this->assertSame(6, DB::table('catalog_recommendation_onboarding_titles')
            ->where('user_id', $smallUser->id)
            ->where('kind', 'excluded')
            ->count());
        $this->assertDatabaseMissing('catalog_recommendation_onboarding_titles', [
            'user_id' => $smallUser->id,
            'catalog_title_id' => $small->likedTitleIds[0],
        ]);
    }

    public function test_livewire_owner_can_select_titles_and_save_then_is_redirected_to_personal_discovery(): void
    {
        $user = User::factory()->create();
        $titles = CatalogTitle::factory()->count(5)->create();
        $genre = Genre::query()->create(['name' => 'Фантастика', 'slug' => 'onboarding-scifi']);
        $country = Country::query()->create(['name' => 'Южная Корея', 'slug' => 'onboarding-korea']);

        $component = Livewire::actingAs($user)->test(TasteOnboardingPage::class);

        foreach ($titles as $title) {
            $component->call('addLikedTitle', $title->id);
        }

        $component
            ->set('genreIds', [$genre->id])
            ->set('countryIds', [$country->id])
            ->set('locale', 'ru')
            ->set('playbackPreference', 'dubbed')
            ->set('completionPreference', 'ongoing')
            ->set('episodeLengthPreference', 'long')
            ->call('save')
            ->assertHasNoErrors()
            ->assertRedirect(route('discover.index', ['type' => 'personalized']));
    }

    public function test_personal_discovery_exposes_an_owner_only_edit_tastes_link(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('discover.index', ['type' => 'personalized']))
            ->assertOk()
            ->assertSeeText('Изменить вкусы')
            ->assertSee(route('onboarding.tastes'));

        auth()->logout();
        $this->get(route('discover.index', ['type' => 'personalized']))
            ->assertOk()
            ->assertDontSeeText('Изменить вкусы');
    }

    /** @param list<int> $likedTitleIds
     * @param  list<int>  $excludedTitleIds
     */
    private function data(
        array $likedTitleIds,
        array $excludedTitleIds,
        int $genreId,
        int $countryId,
    ): CatalogTasteOnboardingData {
        return new CatalogTasteOnboardingData(
            likedTitleIds: $likedTitleIds,
            excludedTitleIds: $excludedTitleIds,
            genreIds: [$genreId],
            countryIds: [$countryId],
            locale: 'ru',
            playbackPreference: CatalogRecommendationPlaybackPreference::Any,
            completionPreference: CatalogRecommendationCompletionPreference::Any,
            episodeLengthPreference: CatalogRecommendationEpisodeLengthPreference::Any,
        );
    }
}
