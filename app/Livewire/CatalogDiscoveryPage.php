<?php

declare(strict_types=1);

namespace App\Livewire;

use App\DTOs\CatalogRecommendationContext;
use App\DTOs\CatalogRecommendationItem;
use App\DTOs\CatalogRecommendationListItem;
use App\DTOs\CatalogRecommendationPreferenceData;
use App\DTOs\CatalogRecommendationResult;
use App\Enums\CatalogPopularityPeriod;
use App\Enums\CatalogRecommendationDiversityPreference;
use App\Enums\CatalogRecommendationFeedback;
use App\Enums\CatalogRecommendationFeedbackReason;
use App\Enums\CatalogRecommendationFreshnessPreference;
use App\Enums\CatalogRecommendationType;
use App\Models\Genre;
use App\Models\User;
use App\Services\Auth\AuthenticationRedirectService;
use App\Services\Catalog\CatalogFacetQuery;
use App\Services\Catalog\CatalogRecommendationFeedbackOptionQuery;
use App\Services\Catalog\CatalogRecommendationFeedbackService;
use App\Services\Catalog\CatalogRecommendationPreferenceQuery;
use App\Services\Catalog\CatalogRecommendationPreferenceService;
use App\Services\Catalog\CatalogRecommendationPresenter;
use App\Services\Catalog\CatalogRecommendationService;
use App\Services\Catalog\CatalogSeoBuilder;
use App\Services\Catalog\CatalogTitleQuery;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;
use Throwable;

final class CatalogDiscoveryPage extends Component
{
    #[Locked]
    public string $type = 'popular';

    #[Locked]
    public string $seed = '';

    #[Url(history: true, except: 'week')]
    public mixed $period = 'week';

    #[Url(as: 'rating_source', history: true, except: 'kinopoisk')]
    public mixed $ratingSource = 'kinopoisk';

    #[Url(history: true, except: '')]
    public mixed $genre = '';

    #[Url(history: true, except: '')]
    public mixed $country = '';

    #[Url(history: true, except: '')]
    public mixed $tag = '';

    #[Url(history: true, except: '')]
    public mixed $actor = '';

    #[Url(history: true, except: '')]
    public mixed $director = '';

    #[Url(history: true, except: '')]
    public mixed $translation = '';

    #[Url(history: true, except: '')]
    public mixed $studio = '';

    #[Url(as: 'year_from', history: true, except: '')]
    public mixed $yearFrom = '';

    #[Url(as: 'year_to', history: true, except: '')]
    public mixed $yearTo = '';

    #[Url(history: true, except: '')]
    public mixed $quality = '';

    #[Url(history: true, except: '')]
    public mixed $subtitles = '';

    #[Url(as: 'rating_min', history: true, except: '')]
    public mixed $ratingMin = '';

    #[Url(as: 'votes_min', history: true, except: '')]
    public mixed $votesMin = '';

    #[Url(history: true, except: 1)]
    public mixed $page = 1;

    #[Locked]
    public ?int $lastFeedbackTitleId = null;

    public ?string $notice = null;

    #[Locked]
    public string $diversityPreference = 'balanced';

    #[Locked]
    public string $freshnessPreference = 'balanced';

    protected CatalogRecommendationService $recommendations;

    protected CatalogRecommendationPresenter $presenter;

    protected CatalogTitleQuery $titles;

    protected CatalogFacetQuery $facets;

    protected CatalogSeoBuilder $seo;

    protected CatalogRecommendationFeedbackService $feedback;

    protected CatalogRecommendationPreferenceService $preferenceService;

    protected CatalogRecommendationPreferenceQuery $preferenceQuery;

    protected CatalogRecommendationFeedbackOptionQuery $feedbackOptions;

    protected AuthenticationRedirectService $authenticationRedirects;

    protected ?CatalogRecommendationResult $resolvedResult = null;

    protected bool $resolvedResultPrepared = false;

    public function boot(
        CatalogRecommendationService $recommendations,
        CatalogRecommendationPresenter $presenter,
        CatalogTitleQuery $titles,
        CatalogFacetQuery $facets,
        CatalogSeoBuilder $seo,
        CatalogRecommendationFeedbackService $feedback,
        CatalogRecommendationPreferenceService $preferenceService,
        CatalogRecommendationPreferenceQuery $preferenceQuery,
        CatalogRecommendationFeedbackOptionQuery $feedbackOptions,
        AuthenticationRedirectService $authenticationRedirects,
    ): void {
        $this->recommendations = $recommendations;
        $this->presenter = $presenter;
        $this->titles = $titles;
        $this->facets = $facets;
        $this->seo = $seo;
        $this->feedback = $feedback;
        $this->preferenceService = $preferenceService;
        $this->preferenceQuery = $preferenceQuery;
        $this->feedbackOptions = $feedbackOptions;
        $this->authenticationRedirects = $authenticationRedirects;
    }

    public function mount(string $type): void
    {
        $recommendationType = CatalogRecommendationType::tryFrom($type);

        abort_if($recommendationType === null, 404);
        abort_if(in_array($type, [CatalogRecommendationType::Similar->value, CatalogRecommendationType::Related->value], true), 404);
        $this->type = $type;
        $this->seed = in_array($recommendationType, [
            CatalogRecommendationType::Random,
            CatalogRecommendationType::Personalized,
        ], true) ? bin2hex(random_bytes(16)) : '';
        $this->normalizeState();
        $this->loadRecommendationPreferences();
    }

    public function updated(string $property): void
    {
        if (in_array($property, [
            'period', 'ratingSource', 'genre', 'country', 'tag', 'actor', 'director', 'translation', 'studio', 'yearFrom', 'yearTo',
            'quality', 'subtitles', 'ratingMin', 'votesMin',
        ], true)) {
            $this->normalizeState();
            $this->page = 1;
            $this->notice = null;
            $this->resetErrorBag();
        }
    }

    public function clearFilters(): void
    {
        $this->reset('genre', 'country', 'tag', 'actor', 'director', 'translation', 'studio', 'yearFrom', 'yearTo', 'quality', 'subtitles', 'ratingMin', 'votesMin');
        $this->page = 1;
        $this->notice = null;
        $this->resetErrorBag();
    }

    public function previousPage(): void
    {
        $this->page = max(1, (int) $this->page - 1);
        $this->notice = null;
    }

    public function nextPage(): void
    {
        $this->page = min(500, (int) $this->page + 1);
        $this->notice = null;
    }

    public function refreshRecommendations(): void
    {
        $this->seed = bin2hex(random_bytes(16));
        $this->page = 1;
        $this->notice = null;
        $this->resetErrorBag();
        $this->resolvedResultPrepared = true;

        try {
            $this->resolvedResult = $this->recommendations->discover($this->context());
            $this->recommendations->rememberShown($this->resolvedResult, $this->user());
        } catch (Throwable $exception) {
            report($exception);
            $this->resolvedResult = $this->emptyResult($this->selectedType());
            $this->addError('recommendations', __('recommendations.page.error'));
        }
    }

    public function setFeedback(mixed $catalogTitleId, mixed $feedback): void
    {
        $user = $this->user();

        if (! $user instanceof User) {
            $this->redirectRoute('login', navigate: true);

            return;
        }

        $titleId = filter_var($catalogTitleId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $feedback = is_string($feedback) ? CatalogRecommendationFeedback::tryFrom($feedback) : null;

        if (! is_int($titleId) || ! $feedback instanceof CatalogRecommendationFeedback) {
            $this->addError('recommendationFeedback', __('recommendations.feedback.error'));

            return;
        }

        if ($feedback === CatalogRecommendationFeedback::NotInterested) {
            $this->addError('recommendationFeedback', __('recommendations.feedback.reason_required'));

            return;
        }

        try {
            $title = $this->titles->visibleTo($user)->findOrFail($titleId);

            if ($feedback === CatalogRecommendationFeedback::MoreLikeThis) {
                $this->feedback->savePositive($user, $title);
                $this->notice = __('recommendations.feedback.saved_more_like_this');
            } else {
                $this->feedback->save($user, $title, CatalogRecommendationFeedbackReason::NotThisTitle);
                $this->notice = __('recommendations.feedback.saved_reason');
            }

            $this->lastFeedbackTitleId = $title->id;
            $this->resetErrorBag();
        } catch (ValidationException $exception) {
            $this->addError(
                'recommendationFeedback',
                (string) ($exception->errors()['recommendationFeedback'][0] ?? __('recommendations.feedback.error')),
            );
        } catch (Throwable $exception) {
            report($exception);
            $this->addError('recommendationFeedback', __('recommendations.feedback.error'));
        }
    }

    public function setFeedbackReason(
        mixed $catalogTitleId,
        mixed $reason,
        mixed $subjectId = null,
    ): void {
        $user = $this->user();

        if (! $user instanceof User) {
            $this->redirectRoute('login', navigate: true);

            return;
        }

        $titleId = filter_var($catalogTitleId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $reason = is_string($reason) ? CatalogRecommendationFeedbackReason::tryFrom($reason) : null;
        $subjectId = $subjectId === null
            ? null
            : filter_var($subjectId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        if (! is_int($titleId)
            || ! $reason instanceof CatalogRecommendationFeedbackReason
            || ($subjectId !== null && ! is_int($subjectId))) {
            $this->addError('recommendationFeedback', __('recommendations.feedback.invalid_reason'));

            return;
        }

        try {
            $title = $this->titles->visibleTo($user)->findOrFail($titleId);
            $this->feedback->save($user, $title, $reason, $subjectId);
            $this->lastFeedbackTitleId = $title->id;
            $this->notice = __('recommendations.feedback.saved_reason');
            $this->resetErrorBag();
        } catch (ValidationException $exception) {
            $this->addError(
                'recommendationFeedback',
                (string) (
                    $exception->errors()['recommendationFeedbackSubject'][0]
                    ?? $exception->errors()['recommendationFeedback'][0]
                    ?? __('recommendations.feedback.error')
                ),
            );
        } catch (Throwable $exception) {
            report($exception);
            $this->addError('recommendationFeedback', __('recommendations.feedback.error'));
        }
    }

    public function undoFeedback(): void
    {
        $user = $this->user();

        if (! $user instanceof User || $this->lastFeedbackTitleId === null) {
            return;
        }

        try {
            $title = $this->titles->visibleTo($user)->findOrFail($this->lastFeedbackTitleId);
            $this->feedback->undo($user, $title);
            $this->lastFeedbackTitleId = null;
            $this->notice = __('recommendations.feedback.undone');
            $this->resetErrorBag();
        } catch (ValidationException $exception) {
            $this->addError(
                'recommendationFeedback',
                (string) ($exception->errors()['recommendationFeedback'][0] ?? __('recommendations.feedback.error')),
            );
        } catch (Throwable $exception) {
            report($exception);
            $this->addError('recommendationFeedback', __('recommendations.feedback.error'));
        }
    }

    public function updateRecommendationPreferences(mixed $diversity, mixed $freshness): void
    {
        $user = $this->user();

        if (! $user instanceof User) {
            $this->redirectRoute('login', navigate: true);

            return;
        }

        $diversity = is_string($diversity)
            ? CatalogRecommendationDiversityPreference::tryFrom($diversity)
            : null;
        $freshness = is_string($freshness)
            ? CatalogRecommendationFreshnessPreference::tryFrom($freshness)
            : null;

        if (! $diversity instanceof CatalogRecommendationDiversityPreference
            || ! $freshness instanceof CatalogRecommendationFreshnessPreference) {
            $this->addError('recommendationPreferences', __('recommendations.preferences.invalid'));

            return;
        }

        try {
            $data = $this->preferenceService->update($user, $diversity, $freshness);
            $this->applyRecommendationPreferences($data);
            $this->notice = __('recommendations.preferences.saved');
            $this->refreshAfterPreferenceMutation();
            $this->resetErrorBag();
        } catch (ValidationException $exception) {
            $this->addError(
                'recommendationPreferences',
                (string) ($exception->errors()['recommendationPreferences'][0] ?? __('recommendations.feedback.error')),
            );
        } catch (Throwable $exception) {
            report($exception);
            $this->addError('recommendationPreferences', __('recommendations.feedback.error'));
        }
    }

    public function hideRecommendationGenre(mixed $genreId): void
    {
        $this->mutateRecommendationGenre($genreId, hide: true);
    }

    public function restoreRecommendationGenre(mixed $genreId): void
    {
        $this->mutateRecommendationGenre($genreId, hide: false);
    }

    public function resetRecommendationProfile(): void
    {
        $user = $this->user();

        if (! $user instanceof User) {
            $this->redirectRoute('login', navigate: true);

            return;
        }

        try {
            $this->applyRecommendationPreferences($this->preferenceService->reset($user));
            $this->notice = __('recommendations.preferences.reset_done');
            $this->lastFeedbackTitleId = null;
            $this->refreshAfterPreferenceMutation();
            $this->resetErrorBag();
        } catch (ValidationException $exception) {
            $this->addError(
                'recommendationPreferences',
                (string) ($exception->errors()['recommendationPreferences'][0] ?? __('recommendations.feedback.error')),
            );
        } catch (Throwable $exception) {
            report($exception);
            $this->addError('recommendationPreferences', __('recommendations.feedback.error'));
        }
    }

    public function render(): View
    {
        $this->normalizeState();
        $type = $this->selectedType();

        if ($this->resolvedResultPrepared) {
            $result = $this->resolvedResult ?? $this->emptyResult($type);
        } else {
            try {
                $result = $this->recommendations->discover($this->context());
            } catch (Throwable $exception) {
                report($exception);
                $this->addError('recommendations', __('recommendations.page.error'));
                $result = $this->emptyResult($type);
            }
        }

        $presentation = $this->presenter->type($type);
        $discoveryFacets = $this->facets->taxonomyGroups(
            ['genre', 'country', 'tag', 'actor', 'director', 'translation', 'studio'],
            ['genre' => 60, 'country' => 60, 'tag' => 40, 'actor' => 40, 'director' => 40, 'translation' => 60, 'studio' => 40],
            $this->user(),
        );
        $feedbackOptions = $this->user() !== null
            ? $this->feedbackOptions->forTitles($result->items->pluck('title'))
            : [];
        $viewItems = $result->items->map(fn (CatalogRecommendationItem $item): CatalogRecommendationListItem => new CatalogRecommendationListItem(
            title: $item->title,
            rank: $item->rank,
            reasonLabels: $this->presenter->explanations($item->explanations),
            score: $item->score,
            type: $item->type,
            source: $item->source,
            relationType: $item->relationType,
            canDismiss: $this->user() !== null,
            feedbackOptions: $feedbackOptions[$item->title->id] ?? [],
        ));
        $hasFilters = $this->hasFilters();
        $collectionStatefulVariant = request()->hasAny([
            'collections_q',
            'collections_sort',
            'collections_category',
            'collections_subcategory',
            'collectionsPage',
        ]);
        $seo = $this->seo->discovery($type, $result, $result->items, $hasFilters || $collectionStatefulVariant);

        return view('livewire.catalog-discovery-page', [
            'result' => $result,
            'viewItems' => $viewItems,
            'presentation' => $presentation,
            'typeLinks' => $this->typeLinks(),
            'genres' => $discoveryFacets->get('genre', collect()),
            'countries' => $discoveryFacets->get('country', collect()),
            'tags' => $discoveryFacets->get('tag', collect()),
            'actors' => $discoveryFacets->get('actor', collect()),
            'directors' => $discoveryFacets->get('director', collect()),
            'translations' => $discoveryFacets->get('translation', collect()),
            'studios' => $discoveryFacets->get('studio', collect()),
            'qualityOptions' => config('playback.supported_qualities', []),
            'maximumYear' => now()->year + 5,
            'hasFilters' => $hasFilters,
            'isAuthenticated' => $this->user() !== null,
            'showRecommendationPreferences' => $type === CatalogRecommendationType::Personalized && $this->user() !== null,
            'tasteOnboardingUrl' => $this->authenticationRedirects->guestUrl(
                'onboarding.tastes',
                locale: app()->currentLocale(),
            ),
            'hiddenRecommendationGenres' => $this->user() !== null
                ? $this->feedbackOptions->activeHiddenGenres($this->user())
                : collect(),
            'popularUrl' => $this->discoveryUrl(CatalogRecommendationType::Popular),
            'discoverySectionNavigation' => $type === CatalogRecommendationType::Popular
                ? [
                    ['url' => '#collections', 'label' => __('collections.navigation.collections')],
                    ['url' => '#popular-titles', 'label' => __('recommendations.page.popular_series')],
                ]
                : [],
            'collectionExplorerKey' => 'discovery-collections-'.app()->currentLocale(),
            'seo' => $seo,
        ])->extends('layouts.app', [
            'title' => $seo['title'],
            'seo' => $seo,
        ])->section('content');
    }

    private function context(): CatalogRecommendationContext
    {
        return new CatalogRecommendationContext(
            type: $this->selectedType(),
            user: $this->user(),
            locale: app()->currentLocale(),
            filters: array_filter([
                'genre' => $this->genre,
                'country' => $this->country,
                'tag' => $this->tag,
                'actor' => $this->actor,
                'director' => $this->director,
                'translation' => $this->translation,
                'studio' => $this->studio,
                'year_from' => $this->yearFrom,
                'year_to' => $this->yearTo,
                'quality' => $this->quality,
                'subtitles' => $this->subtitles,
                'rating_min' => $this->ratingMin,
                'votes_min' => $this->votesMin,
            ], fn (mixed $value): bool => $value !== '' && $value !== null),
            period: CatalogPopularityPeriod::tryFrom((string) $this->period) ?? CatalogPopularityPeriod::Week,
            ratingSource: (string) $this->ratingSource,
            page: (int) $this->page,
            perPage: max(1, (int) config('recommendations.page_size', 24)),
            seed: $this->seed !== '' ? $this->seed : null,
        );
    }

    private function selectedType(): CatalogRecommendationType
    {
        return CatalogRecommendationType::tryFrom($this->type) ?? CatalogRecommendationType::Popular;
    }

    private function emptyResult(CatalogRecommendationType $type): CatalogRecommendationResult
    {
        return new CatalogRecommendationResult(
            requestedType: $type,
            displayType: $type,
            items: collect(),
            page: $type === CatalogRecommendationType::Random ? 1 : (int) $this->page,
            perPage: max(1, (int) config('recommendations.page_size', 24)),
            hasMore: false,
            personalized: false,
            coldStart: $type === CatalogRecommendationType::Personalized,
        );
    }

    private function user(): ?User
    {
        $user = Auth::user();

        return $user instanceof User ? $user : null;
    }

    private function loadRecommendationPreferences(): void
    {
        $user = $this->user();

        if ($user instanceof User) {
            $this->applyRecommendationPreferences($this->preferenceQuery->forUser($user));
        }
    }

    private function applyRecommendationPreferences(
        CatalogRecommendationPreferenceData $data,
    ): void {
        $this->diversityPreference = $data->diversity->value;
        $this->freshnessPreference = $data->freshness->value;
    }

    private function mutateRecommendationGenre(mixed $genreId, bool $hide): void
    {
        $user = $this->user();
        $genreId = filter_var($genreId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        if (! $user instanceof User || ! is_int($genreId)) {
            $this->addError('recommendationPreferences', __('recommendations.preferences.invalid'));

            return;
        }

        try {
            $genre = Genre::query()->findOrFail($genreId);
            $data = $hide
                ? $this->preferenceService->hideGenre($user, $genre)
                : $this->preferenceService->restoreGenre($user, $genre);
            $this->applyRecommendationPreferences($data);
            $this->notice = __($hide
                ? 'recommendations.preferences.genre_hidden'
                : 'recommendations.preferences.genre_restored');
            $this->refreshAfterPreferenceMutation();
            $this->resetErrorBag();
        } catch (ValidationException $exception) {
            $this->addError(
                'recommendationPreferences',
                (string) ($exception->errors()['recommendationPreferences'][0] ?? __('recommendations.feedback.error')),
            );
        } catch (Throwable $exception) {
            report($exception);
            $this->addError('recommendationPreferences', __('recommendations.feedback.error'));
        }
    }

    private function refreshAfterPreferenceMutation(): void
    {
        $this->resolvedResult = null;
        $this->resolvedResultPrepared = false;
        $this->page = 1;
    }

    private function normalizeState(): void
    {
        $period = CatalogPopularityPeriod::tryFrom((string) $this->period) ?? CatalogPopularityPeriod::Week;
        $this->period = $period->value;
        $this->ratingSource = in_array($this->ratingSource, ['kinopoisk', 'imdb', 'portal'], true) ? $this->ratingSource : 'kinopoisk';
        $this->genre = $this->slug($this->genre);
        $this->country = $this->slug($this->country);
        $this->tag = $this->slug($this->tag);
        $this->actor = $this->slug($this->actor);
        $this->director = $this->slug($this->director);
        $this->translation = $this->slug($this->translation);
        $this->studio = $this->slug($this->studio);
        $this->quality = is_string($this->quality) && in_array($this->quality, config('playback.supported_qualities', []), true) ? $this->quality : '';
        $this->subtitles = $this->subtitles === 'available' ? 'available' : '';
        $this->yearFrom = $this->integer($this->yearFrom, 1900, now()->year + 5);
        $this->yearTo = $this->integer($this->yearTo, 1900, now()->year + 5);
        $this->ratingMin = $this->decimal($this->ratingMin, 0, 10);
        $this->votesMin = $this->integer($this->votesMin, 0, 100_000_000);
        $this->page = $this->integer($this->page, 1, 500) ?: 1;

        if ($this->selectedType() === CatalogRecommendationType::Random) {
            $this->page = 1;
        }

        if ($this->yearFrom !== '' && $this->yearTo !== '' && (int) $this->yearFrom > (int) $this->yearTo) {
            $this->yearTo = $this->yearFrom;
        }
    }

    /** @return list<array{type: CatalogRecommendationType, url: string, active: bool, label: string}> */
    private function typeLinks(): array
    {
        $types = [CatalogRecommendationType::Personalized, ...CatalogRecommendationType::publicCases()];

        return collect($types)->map(fn (CatalogRecommendationType $type): array => [
            'type' => $type,
            'url' => $this->discoveryUrl($type),
            'active' => $type === $this->selectedType(),
            'label' => $this->presenter->type($type)['title'],
        ])->all();
    }

    private function hasFilters(): bool
    {
        return collect([$this->genre, $this->country, $this->tag, $this->actor, $this->director, $this->translation, $this->studio, $this->yearFrom, $this->yearTo, $this->quality, $this->subtitles, $this->ratingMin, $this->votesMin])
            ->contains(fn (mixed $value): bool => $value !== '' && $value !== null);
    }

    private function discoveryUrl(CatalogRecommendationType $type): string
    {
        $query = array_filter([
            'period' => $this->period === 'week' ? null : $this->period,
            'rating_source' => $this->ratingSource === 'kinopoisk' ? null : $this->ratingSource,
            'genre' => $this->genre,
            'country' => $this->country,
            'tag' => $this->tag,
            'actor' => $this->actor,
            'director' => $this->director,
            'translation' => $this->translation,
            'studio' => $this->studio,
            'year_from' => $this->yearFrom,
            'year_to' => $this->yearTo,
            'quality' => $this->quality,
            'subtitles' => $this->subtitles,
            'rating_min' => $this->ratingMin,
            'votes_min' => $this->votesMin,
        ], fn (mixed $value): bool => $value !== '' && $value !== null);
        $localized = request()->routeIs('localized.discover.*');
        $route = $localized ? 'localized.discover.index' : 'discover.index';

        return route($route, [
            ...($localized ? ['locale' => app()->currentLocale()] : []),
            'type' => $type->value,
            ...$query,
        ]);
    }

    private function slug(mixed $value): string
    {
        if (! is_string($value)) {
            return '';
        }

        $value = Str::lower(Str::squish($value));

        return preg_match('/\A[a-z0-9][a-z0-9-]{0,79}\z/', $value) === 1 ? $value : '';
    }

    private function integer(mixed $value, int $minimum, int $maximum): int|string
    {
        if ($value === '' || $value === null) {
            return '';
        }

        $value = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => $minimum, 'max_range' => $maximum]]);

        return is_int($value) ? $value : '';
    }

    private function decimal(mixed $value, float $minimum, float $maximum): float|string
    {
        if ($value === '' || $value === null || ! is_numeric($value)) {
            return '';
        }

        $value = (float) $value;

        return $value >= $minimum && $value <= $maximum ? round($value, 1) : '';
    }
}
