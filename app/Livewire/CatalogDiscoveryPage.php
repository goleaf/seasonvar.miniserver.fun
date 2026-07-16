<?php

declare(strict_types=1);

namespace App\Livewire;

use App\DTOs\CatalogRecommendationContext;
use App\DTOs\CatalogRecommendationItem;
use App\DTOs\CatalogRecommendationListItem;
use App\DTOs\CatalogRecommendationResult;
use App\Enums\CatalogPopularityPeriod;
use App\Enums\CatalogRecommendationFeedback;
use App\Enums\CatalogRecommendationType;
use App\Models\CatalogTitle;
use App\Models\User;
use App\Services\Catalog\CatalogFacetQuery;
use App\Services\Catalog\CatalogRecommendationPresenter;
use App\Services\Catalog\CatalogRecommendationService;
use App\Services\Catalog\CatalogSeoBuilder;
use App\Services\Catalog\CatalogTitleQuery;
use App\Services\Catalog\CatalogUserStateService;
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

    protected CatalogRecommendationService $recommendations;

    protected CatalogRecommendationPresenter $presenter;

    protected CatalogTitleQuery $titles;

    protected CatalogUserStateService $userStates;

    protected CatalogFacetQuery $facets;

    protected CatalogSeoBuilder $seo;

    public function boot(
        CatalogRecommendationService $recommendations,
        CatalogRecommendationPresenter $presenter,
        CatalogTitleQuery $titles,
        CatalogUserStateService $userStates,
        CatalogFacetQuery $facets,
        CatalogSeoBuilder $seo,
    ): void {
        $this->recommendations = $recommendations;
        $this->presenter = $presenter;
        $this->titles = $titles;
        $this->userStates = $userStates;
        $this->facets = $facets;
        $this->seo = $seo;
    }

    public function mount(string $type): void
    {
        abort_if(CatalogRecommendationType::tryFrom($type) === null, 404);
        abort_if(in_array($type, [CatalogRecommendationType::Similar->value, CatalogRecommendationType::Related->value], true), 404);
        $this->type = $type;
        $this->seed = bin2hex(random_bytes(16));
        $this->normalizeState();
    }

    public function updated(string $property): void
    {
        if (in_array($property, [
            'period', 'ratingSource', 'genre', 'country', 'yearFrom', 'yearTo',
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
        $this->reset('genre', 'country', 'yearFrom', 'yearTo', 'quality', 'subtitles', 'ratingMin', 'votesMin');
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
        try {
            $result = $this->recommendations->discover($this->context());
            $this->recommendations->rememberShown($result, $this->user());
            $this->seed = bin2hex(random_bytes(16));
            $this->page = 1;
            $this->notice = null;
            $this->resetErrorBag();
        } catch (Throwable $exception) {
            report($exception);
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

        try {
            $title = $this->titles->visibleTo($user)->findOrFail($titleId);
            $this->userStates->setRecommendationFeedback($user, $title, $feedback);
            $this->lastFeedbackTitleId = $title->id;
            $this->notice = __("recommendations.feedback.saved_{$feedback->value}");
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

    public function undoFeedback(): void
    {
        $user = $this->user();

        if (! $user instanceof User || $this->lastFeedbackTitleId === null) {
            return;
        }

        try {
            $title = $this->titles->visibleTo($user)->findOrFail($this->lastFeedbackTitleId);
            $this->userStates->undoRecommendationFeedback($user, $title);
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

    public function render(): View
    {
        $this->normalizeState();
        $type = $this->selectedType();

        try {
            $result = $this->recommendations->discover($this->context());
        } catch (Throwable $exception) {
            report($exception);
            $this->addError('recommendations', __('recommendations.page.error'));
            $result = new CatalogRecommendationResult(
                requestedType: $type,
                displayType: $type,
                items: collect(),
                page: (int) $this->page,
                perPage: max(1, (int) config('recommendations.page_size', 24)),
                hasMore: false,
                personalized: false,
                coldStart: $type === CatalogRecommendationType::Personalized,
            );
        }

        $presentation = $this->presenter->type($result->displayType);
        $viewItems = $result->items->map(fn (CatalogRecommendationItem $item): CatalogRecommendationListItem => new CatalogRecommendationListItem(
            title: $item->title,
            rank: $item->rank,
            reasonLabels: $this->presenter->explanations($item->explanations),
            score: $item->score,
            type: $item->type,
            source: $item->source,
            relationType: $item->relationType,
            canDismiss: $this->user() !== null,
        ));
        $hasFilters = $this->hasFilters();
        $seo = $this->seo->discovery($type, $result, $result->items, $hasFilters);

        return view('livewire.catalog-discovery-page', [
            'result' => $result,
            'viewItems' => $viewItems,
            'presentation' => $presentation,
            'typeLinks' => $this->typeLinks(),
            'genres' => $this->facets->taxonomies('genre', 60),
            'countries' => $this->facets->taxonomies('country', 60),
            'qualityOptions' => config('playback.supported_qualities', []),
            'maximumYear' => now()->year + 5,
            'hasFilters' => $hasFilters,
            'isAuthenticated' => $this->user() !== null,
            'popularUrl' => $this->discoveryUrl(CatalogRecommendationType::Popular),
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
            seed: $this->seed,
        );
    }

    private function selectedType(): CatalogRecommendationType
    {
        return CatalogRecommendationType::tryFrom($this->type) ?? CatalogRecommendationType::Popular;
    }

    private function user(): ?User
    {
        $user = Auth::user();

        return $user instanceof User ? $user : null;
    }

    private function normalizeState(): void
    {
        $this->period = CatalogPopularityPeriod::tryFrom((string) $this->period)?->value ?? CatalogPopularityPeriod::Week->value;
        $this->ratingSource = in_array($this->ratingSource, ['kinopoisk', 'imdb', 'portal'], true) ? $this->ratingSource : 'kinopoisk';
        $this->genre = $this->slug($this->genre);
        $this->country = $this->slug($this->country);
        $this->quality = is_string($this->quality) && in_array($this->quality, config('playback.supported_qualities', []), true) ? $this->quality : '';
        $this->subtitles = $this->subtitles === 'available' ? 'available' : '';
        $this->yearFrom = $this->integer($this->yearFrom, 1900, now()->year + 5);
        $this->yearTo = $this->integer($this->yearTo, 1900, now()->year + 5);
        $this->ratingMin = $this->decimal($this->ratingMin, 0, 10);
        $this->votesMin = $this->integer($this->votesMin, 0, 100_000_000);
        $this->page = $this->integer($this->page, 1, 500) ?: 1;

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
        return collect([$this->genre, $this->country, $this->yearFrom, $this->yearTo, $this->quality, $this->subtitles, $this->ratingMin, $this->votesMin])
            ->contains(fn (mixed $value): bool => $value !== '' && $value !== null);
    }

    private function discoveryUrl(CatalogRecommendationType $type): string
    {
        $query = array_filter([
            'period' => $this->period === 'week' ? null : $this->period,
            'rating_source' => $this->ratingSource === 'kinopoisk' ? null : $this->ratingSource,
            'genre' => $this->genre,
            'country' => $this->country,
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
