<?php

declare(strict_types=1);

namespace App\Livewire;

use App\DTOs\CatalogTasteOnboardingData;
use App\Enums\CatalogRecommendationCompletionPreference;
use App\Enums\CatalogRecommendationEpisodeLengthPreference;
use App\Enums\CatalogRecommendationPlaybackPreference;
use App\Models\User;
use App\Services\Auth\AccountSettingsService;
use App\Services\Auth\AuthenticationRedirectService;
use App\Services\Catalog\CatalogTasteOnboardingQuery;
use App\Services\Catalog\CatalogTasteOnboardingSchema;
use App\Services\Catalog\CatalogTasteOnboardingService;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Throwable;

final class TasteOnboardingPage extends Component
{
    #[Locked]
    public array $likedTitleIds = [];

    #[Locked]
    public array $excludedTitleIds = [];

    public array $genreIds = [];

    public array $countryIds = [];

    public string $locale = 'ru';

    public string $playbackPreference = 'any';

    public string $completionPreference = 'any';

    public string $episodeLengthPreference = 'any';

    public string $likedSearch = '';

    public string $excludedSearch = '';

    public function mount(
        CatalogTasteOnboardingSchema $schema,
        CatalogTasteOnboardingQuery $onboarding,
        AccountSettingsService $accountSettings,
    ): void {
        $user = $this->user();
        abort_unless($schema->ready(), 503, __('onboarding.errors.unavailable'));
        $state = $onboarding->state($user);
        $settings = $accountSettings->resolve($user);

        $this->likedTitleIds = $state->likedTitleIds;
        $this->excludedTitleIds = $state->excludedTitleIds;
        $this->genreIds = $state->genreIds;
        $this->countryIds = $state->countryIds;
        $this->locale = $settings->locale;
        $this->playbackPreference = $state->playbackPreference->value;
        $this->completionPreference = $state->completionPreference->value;
        $this->episodeLengthPreference = $state->episodeLengthPreference->value;
    }

    public function addLikedTitle(int $titleId, CatalogTasteOnboardingQuery $onboarding): void
    {
        $this->resetErrorBag('likedTitleIds');

        if (in_array($titleId, $this->likedTitleIds, true)) {
            return;
        }

        if (count($this->likedTitleIds) >= 10) {
            $this->addError('likedTitleIds', __('onboarding.validation.likedTitleIds'));

            return;
        }

        if (in_array($titleId, $this->excludedTitleIds, true)
            || $onboarding->resolveVisibleTitle($this->user(), $titleId) === null) {
            $this->addError('likedTitleIds', __('onboarding.validation.titles_unavailable'));

            return;
        }

        $this->likedTitleIds[] = $titleId;
        $this->likedSearch = '';
    }

    public function updatedLikedSearch(): void
    {
        $this->likedSearch = Str::limit(Str::squish($this->likedSearch), 120, '');
    }

    public function updatedExcludedSearch(): void
    {
        $this->excludedSearch = Str::limit(Str::squish($this->excludedSearch), 120, '');
    }

    public function removeLikedTitle(int $titleId): void
    {
        $this->likedTitleIds = array_values(array_diff($this->likedTitleIds, [$titleId]));
    }

    public function addExcludedTitle(int $titleId, CatalogTasteOnboardingQuery $onboarding): void
    {
        $this->resetErrorBag('excludedTitleIds');

        if (in_array($titleId, $this->excludedTitleIds, true)) {
            return;
        }

        if (count($this->excludedTitleIds) >= 10) {
            $this->addError('excludedTitleIds', __('onboarding.validation.excludedTitleIds'));

            return;
        }

        if (in_array($titleId, $this->likedTitleIds, true)
            || $onboarding->resolveVisibleTitle($this->user(), $titleId) === null) {
            $this->addError('excludedTitleIds', __('onboarding.validation.title_overlap'));

            return;
        }

        $this->excludedTitleIds[] = $titleId;
        $this->excludedSearch = '';
    }

    public function removeExcludedTitle(int $titleId): void
    {
        $this->excludedTitleIds = array_values(array_diff($this->excludedTitleIds, [$titleId]));
    }

    public function save(
        CatalogTasteOnboardingService $onboarding,
        AuthenticationRedirectService $redirects,
    ): void {
        $validated = $this->validate($this->rules(), [], $this->attributes());
        $user = $this->user();
        try {
            $onboarding->save($user, new CatalogTasteOnboardingData(
                likedTitleIds: $this->integerIds($validated['likedTitleIds']),
                excludedTitleIds: $this->integerIds($validated['excludedTitleIds']),
                genreIds: $this->integerIds($validated['genreIds']),
                countryIds: $this->integerIds($validated['countryIds']),
                locale: $validated['locale'],
                playbackPreference: CatalogRecommendationPlaybackPreference::from($validated['playbackPreference']),
                completionPreference: CatalogRecommendationCompletionPreference::from($validated['completionPreference']),
                episodeLengthPreference: CatalogRecommendationEpisodeLengthPreference::from($validated['episodeLengthPreference']),
            ));
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            report($exception);
            $this->addError('onboarding', __('onboarding.errors.save_failed'));

            return;
        }

        $this->redirect(
            $redirects->guestUrl('discover.index', ['type' => 'personalized'], $this->locale),
            navigate: true,
        );
    }

    public function skip(AuthenticationRedirectService $redirects): void
    {
        $this->redirect(
            $redirects->guestUrl('library.index', locale: $this->locale),
            navigate: true,
        );
    }

    public function render(CatalogTasteOnboardingQuery $onboarding): View
    {
        $user = $this->user();

        return view('livewire.taste-onboarding-page', [
            'genres' => $onboarding->genres(),
            'countries' => $onboarding->countries(),
            'likedTitles' => $onboarding->selectedTitles($user, $this->likedTitleIds),
            'excludedTitles' => $onboarding->selectedTitles($user, $this->excludedTitleIds),
            'likedSuggestions' => $onboarding->searchTitles($user, $this->likedSearch),
            'excludedSuggestions' => $onboarding->searchTitles($user, $this->excludedSearch),
            'likedSearchReady' => mb_strlen($this->likedSearch) >= 2,
            'excludedSearchReady' => mb_strlen($this->excludedSearch) >= 2,
        ])
            ->extends('layouts.app', [
                'title' => __('onboarding.title'),
                'seo' => [
                    'title' => __('onboarding.title'),
                    'description' => __('onboarding.intro'),
                    'robots' => 'noindex, nofollow',
                    'canonical' => route('onboarding.tastes'),
                    'social' => false,
                    'alternates' => [],
                    'jsonLd' => [],
                ],
            ])
            ->section('content');
    }

    /** @return array<string, list<string>> */
    private function rules(): array
    {
        return [
            'likedTitleIds' => ['required', 'array', 'min:5', 'max:10'],
            'likedTitleIds.*' => ['required', 'integer', 'distinct', 'min:1'],
            'excludedTitleIds' => ['array', 'max:10'],
            'excludedTitleIds.*' => ['required', 'integer', 'distinct', 'min:1'],
            'genreIds' => ['required', 'array', 'min:1', 'max:8'],
            'genreIds.*' => ['required', 'integer', 'distinct', 'min:1'],
            'countryIds' => ['required', 'array', 'min:1', 'max:8'],
            'countryIds.*' => ['required', 'integer', 'distinct', 'min:1'],
            'locale' => ['required', 'string', 'in:'.implode(',', config('catalog-collections.supported_locales', ['ru']))],
            'playbackPreference' => ['required', 'string', 'in:any,dubbed,subtitles'],
            'completionPreference' => ['required', 'string', 'in:any,completed,ongoing'],
            'episodeLengthPreference' => ['required', 'string', 'in:any,short,long'],
        ];
    }

    /** @return array<string, string> */
    private function attributes(): array
    {
        return [
            'likedTitleIds' => __('onboarding.fields.liked_titles'),
            'excludedTitleIds' => __('onboarding.fields.excluded_titles'),
            'genreIds' => __('onboarding.fields.genres'),
            'countryIds' => __('onboarding.fields.countries'),
            'locale' => __('onboarding.fields.locale'),
            'playbackPreference' => __('onboarding.fields.playback'),
            'completionPreference' => __('onboarding.fields.completion'),
            'episodeLengthPreference' => __('onboarding.fields.episode_length'),
        ];
    }

    /** @param array<int, mixed> $ids
     * @return list<int>
     */
    private function integerIds(array $ids): array
    {
        return collect($ids)->map(static fn (mixed $id): int => (int) $id)->values()->all();
    }

    private function user(): User
    {
        $user = auth()->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
