<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Enums\CatalogFilterType;
use App\Enums\CatalogPublicationType;
use App\Enums\CatalogRecommendationFeedbackReason;
use App\Enums\CatalogSort;
use App\Enums\CatalogView;
use App\Http\Requests\CatalogTitlesRequest;
use App\Livewire\Forms\CatalogSeriesFilters;
use App\Models\User;
use App\Rules\CatalogFilterSlug;
use App\Services\Catalog\CatalogRecommendationFeedbackService;
use App\Services\Catalog\CatalogTitleQuery;
use App\Services\Catalog\CatalogTitlesPageBuilder;
use App\Services\Catalog\CatalogUserStateService;
use App\Services\Catalog\VisibleCatalogTitleCacheWarmScheduler;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithPagination;
use Throwable;

/**
 * @property-read array<string, mixed> $catalogPage
 * @property-read array<string, mixed> $catalogFacets
 */
class CatalogSeries extends Component
{
    use WithPagination;

    public CatalogSeriesFilters $filters;

    /** @var array{actor: string, director: string} */
    public array $optionSearch = [
        'actor' => '',
        'director' => '',
    ];

    public ?string $cardActionNotice = null;

    #[Locked]
    public ?int $routeYear = null;

    #[Locked]
    public ?string $routeFilterType = null;

    #[Locked]
    public ?string $routeTaxonomy = null;

    protected CatalogTitlesPageBuilder $pages;

    protected VisibleCatalogTitleCacheWarmScheduler $titleCacheWarm;

    protected CatalogTitleQuery $titles;

    protected CatalogUserStateService $userStates;

    protected CatalogRecommendationFeedbackService $feedback;

    public function boot(
        CatalogTitlesPageBuilder $pages,
        VisibleCatalogTitleCacheWarmScheduler $titleCacheWarm,
        CatalogTitleQuery $titles,
        CatalogUserStateService $userStates,
        CatalogRecommendationFeedbackService $feedback,
    ): void {
        $this->pages = $pages;
        $this->titleCacheWarm = $titleCacheWarm;
        $this->titles = $titles;
        $this->userStates = $userStates;
        $this->feedback = $feedback;
    }

    public function mount(?int $year = null, ?string $type = null, ?string $taxonomy = null): void
    {
        $normalizedTaxonomy = $taxonomy === null ? null : CatalogFilterSlug::normalize($taxonomy);

        abort_if($type !== null && CatalogFilterType::tryFrom($type) === null, 404);
        abort_if($taxonomy !== null && $normalizedTaxonomy === null, 404);

        $this->routeYear = $year;
        $this->routeFilterType = $type;
        $this->routeTaxonomy = $normalizedTaxonomy;
        $this->validateAndNormalizeState(Arr::except(request()->query->all(), ['page']));

        if ($this->initialPageNeedsCanonicalization() || $this->initialQueryUsesUnsupportedArraySyntax()) {
            $this->redirect($this->catalogUrl($this->initialPage()), navigate: true);
        }
    }

    public function updated(string $property): void
    {
        if (str_starts_with($property, 'optionSearch.')) {
            $filterType = str($property)->after('optionSearch.')->toString();

            if (! in_array($filterType, ['actor', 'director'], true)) {
                unset($this->optionSearch[$filterType]);

                return;
            }

            $this->optionSearch[$filterType] = Str::limit(
                Str::squish($this->optionSearch[$filterType]),
                80,
                '',
            );

            return;
        }

        if (! str_starts_with($property, 'filters.')) {
            return;
        }

        if ($this->routeTaxonomyWasRemoved($property)) {
            $this->leaveRouteTaxonomyContext();

            return;
        }

        $this->validateAndNormalizeState();
        $this->resetPage();
    }

    public function applySearch(): void
    {
        $this->validateAndNormalizeState();
        $this->resetPage();
    }

    public function applyFilters(): void
    {
        $this->validateAndNormalizeState();
        $this->resetPage();
    }

    public function sortBy(mixed $sort): void
    {
        $option = is_string($sort) ? CatalogSort::tryFrom($sort) : null;

        if ($option === null) {
            $this->addError('sort', __('catalog.search.validation.sort_supported'));

            return;
        }

        $this->filters->sort = $option->value;
        $this->resetErrorBag('sort');
        $this->resetPage();
    }

    public function setPerPage(mixed $perPage): void
    {
        $perPage = filter_var($perPage, FILTER_VALIDATE_INT);

        if (! in_array($perPage, [24, 48, 96], true)) {
            return;
        }

        $this->filters->perPage = $perPage;
        $this->resetPage();
    }

    public function setView(mixed $view): void
    {
        $option = is_string($view) ? CatalogView::tryFrom($view) : null;

        if ($option === null) {
            $this->addError('view', __('catalog.search.validation.view_supported'));

            return;
        }

        $this->filters->view = $option->value;
        $this->resetErrorBag('view');
        $this->resetPage();
    }

    public function setCardWatchlist(mixed $catalogTitleId, mixed $inWatchlist): void
    {
        $user = request()->user();

        if (! $user instanceof User) {
            $this->redirectRoute('login', navigate: true);

            return;
        }

        $titleId = $this->positiveInteger($catalogTitleId);
        $desiredState = $this->booleanValue($inWatchlist);

        if ($titleId === null || $desiredState === null) {
            $this->invalidCardAction();

            return;
        }

        $title = $this->titles
            ->visibleTo($user)
            ->select(['catalog_titles.id', 'catalog_titles.slug'])
            ->findOrFail($titleId);

        try {
            $this->userStates->setWatchlist($user, $title, $desiredState);
            $this->cardActionNotice = $desiredState
                ? __('catalog.title.card_actions.added_to_library')
                : __('catalog.title.card_actions.removed_from_library');
            $this->resetErrorBag('cardAction');
        } catch (AuthorizationException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            report($exception);
            $this->addError('cardAction', __('catalog.title.card_actions.failed'));
        }
    }

    public function setCardFeedbackReason(mixed $catalogTitleId, mixed $reason): void
    {
        $user = request()->user();

        if (! $user instanceof User) {
            $this->redirectRoute('login', navigate: true);

            return;
        }

        $titleId = $this->positiveInteger($catalogTitleId);
        $reason = is_string($reason)
            ? CatalogRecommendationFeedbackReason::tryFrom($reason)
            : null;

        if ($titleId === null
            || ! $reason instanceof CatalogRecommendationFeedbackReason
            || $reason->requiresSubject()) {
            $this->invalidCardAction();

            return;
        }

        $title = $this->titles
            ->visibleTo($user)
            ->select(['catalog_titles.id', 'catalog_titles.slug'])
            ->findOrFail($titleId);

        try {
            $this->feedback->save($user, $title, $reason);
            $this->cardActionNotice = __('recommendations.feedback.saved_reason');
            $this->resetErrorBag('cardAction');
        } catch (AuthorizationException $exception) {
            throw $exception;
        } catch (ValidationException $exception) {
            $this->addError(
                'cardAction',
                (string) (
                    $exception->errors()['recommendationFeedback'][0]
                    ?? __('recommendations.feedback.error')
                ),
            );
        } catch (Throwable $exception) {
            report($exception);
            $this->addError('cardAction', __('recommendations.feedback.error'));
        }
    }

    public function setLetter(mixed $letter): void
    {
        if ($letter === '') {
            $this->filters->letter = '';
            $this->resetPage();

            return;
        }

        if (! is_string($letter) || preg_match('/^(?:latin|[A-Za-zА-Яа-яЁё]|#)$/u', $letter) !== 1) {
            return;
        }

        $this->filters->letter = mb_strtoupper((string) $this->filters->letter) === mb_strtoupper($letter)
            ? ''
            : $letter;
        $this->resetPage();
    }

    public function resetGroup(mixed $group): mixed
    {
        if (! is_string($group)) {
            return null;
        }

        if ($group === 'year' && $this->routeYear !== null) {
            return $this->redirectRoute('titles.index', navigate: true);
        }

        if ($group === $this->routeFilterType) {
            $this->filters->resetGroup($group);
            $this->leaveRouteTaxonomyContext();

            return null;
        }

        if ($this->filters->resetGroup($group)) {
            $this->resetErrorBag($group);
            $this->resetPage();
        }

        return null;
    }

    public function resetAdvanced(mixed $key): void
    {
        if (! is_string($key)) {
            return;
        }

        if ($this->filters->resetAdvanced($key)) {
            $this->resetErrorBag($key);
            $this->resetPage();
        }
    }

    public function resetAdvancedFilters(): void
    {
        $this->filters->resetAdvancedFilters();

        foreach (array_keys(CatalogSeriesFilters::ADVANCED_REQUEST_PROPERTIES) as $key) {
            $this->resetErrorBag($key);
        }

        $this->resetErrorBag('quality');
        $this->resetPage();
    }

    public function clearSearch(): void
    {
        $this->filters->search = '';
        $this->resetErrorBag('q');
        $this->resetPage();
    }

    public function clearTitleContext(): void
    {
        $this->filters->titleContext = '';
        $this->resetPage();
    }

    public function removeYear(mixed $year): mixed
    {
        $year = filter_var($year, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1900, 'max_range' => 2100]]);

        if ($year === false) {
            return null;
        }

        if ($this->routeYear === $year) {
            return $this->redirectRoute('titles.index', navigate: true);
        }

        $this->filters->years = array_values(array_diff($this->filters->years, [$year, (string) $year]));
        $this->resetPage();

        return null;
    }

    public function removeTaxonomy(mixed $type, mixed $slug): mixed
    {
        if (! is_string($type)
            || ! is_string($slug)
            || CatalogFilterType::tryFrom($type) === null
            || CatalogFilterSlug::normalize($slug) === null) {
            return null;
        }

        if ($type === $this->routeFilterType && $slug === $this->routeTaxonomy) {
            $this->filters->removeTaxonomy($type, $slug);
            $this->leaveRouteTaxonomyContext();

            return null;
        }

        if ($this->filters->removeTaxonomy($type, $slug)) {
            $this->resetPage();
        }

        return null;
    }

    public function removeExcluded(mixed $type, mixed $slug): void
    {
        if (! is_string($type) || ! is_string($slug) || CatalogFilterSlug::normalize($slug) === null) {
            return;
        }

        if ($this->filters->removeExcluded($type, $slug)) {
            $this->resetPage();
        }
    }

    public function removeChoice(mixed $group, mixed $value): void
    {
        if (! is_string($group) || ! is_string($value)) {
            return;
        }

        $valid = match ($group) {
            'publication_type' => CatalogPublicationType::tryFrom($value) !== null,
            'subtitles' => in_array($value, ['available', 'missing'], true),
            'quality' => in_array($value, ['2160p', '1440p', '1080p', '720p', '480p', '360p', '240p'], true),
            default => false,
        };

        if ($valid && $this->filters->removeChoice($group, $value)) {
            $this->resetErrorBag($group);
            $this->resetPage();
        }
    }

    public function resetAll(): mixed
    {
        if ($this->routeYear !== null || $this->routeFilterType !== null) {
            return $this->redirectRoute('titles.index', navigate: true);
        }

        $this->filters->resetAllFilters();
        $this->resetErrorBag();
        $this->resetPage();

        return null;
    }

    public function render(): View
    {
        $data = $this->catalogPage;
        $titles = $data['titles'];

        if ($titles->currentPage() > $titles->lastPage()) {
            $this->setPage(max(1, $titles->lastPage()));
            $this->redirect($this->catalogUrl($titles->lastPage()), navigate: true);
        } else {
            $this->titleCacheWarm->capture(
                $titles->getCollection()->pluck('id')->all(),
                request(),
            );
        }

        return view('catalog.titles', $data)
            ->extends('layouts.app', [
                'title' => $data['seo']['title'] ?? __('catalog.navigation.all_titles'),
                'seo' => $data['seo'] ?? [],
            ])
            ->section('content');
    }

    /** @return array<string, mixed> */
    #[Computed]
    public function catalogPage(): array
    {
        return $this->pages->data(
            $this->catalogRequest($this->renderInput()),
            $this->routeFilterType,
            $this->routeTaxonomy,
            $this->getErrorBag()->isNotEmpty(),
            includeFacets: false,
            includeDescription: $this->filters->view === CatalogView::List->value,
        );
    }

    /** @return array<string, mixed> */
    #[Computed]
    public function catalogFacets(): array
    {
        return $this->pages->facets(
            $this->catalogRequest($this->renderInput()),
            $this->routeFilterType,
            $this->routeTaxonomy,
            $this->getErrorBag()->isNotEmpty(),
            $this->facetSearch(),
        );
    }

    private function initialPageNeedsCanonicalization(): bool
    {
        if (! request()->query->has('page')) {
            return false;
        }

        $page = request()->query('page');

        return ! is_scalar($page)
            || filter_var($page, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false
            || (int) $page === 1;
    }

    private function initialQueryUsesUnsupportedArraySyntax(): bool
    {
        $queryString = (string) request()->server('QUERY_STRING', '');

        foreach (explode('&', $queryString) as $part) {
            $key = rawurldecode(explode('=', $part, 2)[0]);

            if (str_ends_with($key, '[]') || str_ends_with($key, '.')) {
                return true;
            }
        }

        return false;
    }

    private function initialPage(): int
    {
        $page = request()->query('page');

        return is_scalar($page)
            && filter_var($page, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) !== false
                ? (int) $page
                : 1;
    }

    private function catalogUrl(int $page = 1): string
    {
        $query = $this->withoutRouteContext($this->filters->toRequestInput());

        if ($page > 1) {
            $query['page'] = $page;
        }

        if ($this->routeYear !== null) {
            $url = route('titles.year', ['year' => $this->routeYear]);

            return $query === [] ? $url : $url.'?'.Arr::query($query);
        }

        if ($this->routeFilterType !== null && $this->routeTaxonomy !== null) {
            return route('titles.taxonomy', [
                'type' => $this->routeFilterType,
                'taxonomy' => $this->routeTaxonomy,
                ...$query,
            ]);
        }

        return route('titles.index', $query);
    }

    public function paginationView(): string
    {
        return 'vendor.livewire.tailwind';
    }

    /** @param array<string, mixed>|null $input */
    private function validateAndNormalizeState(?array $input = null): bool
    {
        $request = $this->catalogRequest(
            $this->withRouteContext($input ?? $this->filters->toRequestInput()),
        );

        try {
            $request->validateResolved();
        } catch (ValidationException $exception) {
            $this->setErrorBag($exception->validator->errors());

            return false;
        }

        $this->filters->fillFromRequest($request);
        $this->resetErrorBag();

        return true;
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function withRouteContext(array $input): array
    {
        if ($this->routeYear !== null) {
            $input['year'] = $this->prependRouteValue($this->routeYear, $input['year'] ?? []);
        }

        if ($this->routeFilterType !== null
            && $this->routeTaxonomy !== null
            && array_key_exists($this->routeFilterType, CatalogSeriesFilters::TAXONOMY_PROPERTIES)) {
            $input[$this->routeFilterType] = $this->prependRouteValue(
                $this->routeTaxonomy,
                $input[$this->routeFilterType] ?? [],
            );
        }

        return $input;
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function withoutRouteContext(array $input): array
    {
        if ($this->routeYear !== null) {
            $input = $this->withoutListValue($input, 'year', $this->routeYear);
        }

        if ($this->routeFilterType !== null && $this->routeTaxonomy !== null) {
            $input = $this->withoutListValue($input, $this->routeFilterType, $this->routeTaxonomy);
        }

        return $input;
    }

    /** @return list<mixed> */
    private function prependRouteValue(int|string $routeValue, mixed $values): array
    {
        $values = is_array($values) ? array_values($values) : [$values];

        return collect([$routeValue, ...$values])->uniqueStrict()->values()->all();
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function withoutListValue(array $input, string $key, int|string $routeValue): array
    {
        $values = is_array($input[$key] ?? null) ? $input[$key] : [];
        $values = array_values(array_filter(
            $values,
            fn (mixed $value): bool => $value !== $routeValue,
        ));

        if ($values === []) {
            unset($input[$key]);
        } else {
            $input[$key] = $values;
        }

        return $input;
    }

    private function routeTaxonomyWasRemoved(string $property): bool
    {
        if ($this->routeFilterType === null || $this->routeTaxonomy === null) {
            return false;
        }

        $filterProperty = CatalogSeriesFilters::TAXONOMY_PROPERTIES[$this->routeFilterType] ?? null;

        return $filterProperty !== null
            && $property === 'filters.'.$filterProperty
            && ! in_array($this->routeTaxonomy, $this->filters->{$filterProperty}, true);
    }

    private function leaveRouteTaxonomyContext(): void
    {
        $routeFilterType = $this->routeFilterType;
        $routeTaxonomy = $this->routeTaxonomy;
        $this->routeFilterType = null;
        $this->routeTaxonomy = null;

        if (! $this->validateAndNormalizeState()) {
            $this->routeFilterType = $routeFilterType;
            $this->routeTaxonomy = $routeTaxonomy;

            return;
        }

        $this->resetPage();
        $this->redirect($this->catalogUrl(), navigate: true);
    }

    /** @return array<string, mixed> */
    private function renderInput(): array
    {
        $input = $this->filters->toRequestInput();

        foreach ($this->getErrorBag()->keys() as $key) {
            Arr::forget($input, str($key)->before('.')->toString());
        }

        if ($this->routeYear !== null) {
            $years = is_array($input['year'] ?? null) ? $input['year'] : [];
            $input['year'] = collect([$this->routeYear, ...$years])->unique()->values()->all();
        }

        return $input;
    }

    /** @param array<string, mixed> $input */
    private function catalogRequest(array $input): CatalogTitlesRequest
    {
        $request = CatalogTitlesRequest::create(route('titles.index'), 'GET', $input);
        $request->setContainer(app())->setRedirector(app('redirect'));
        $user = request()->user();
        $request->setUserResolver(fn () => $user);

        return $request;
    }

    /** @return array{actor?: string, director?: string} */
    private function facetSearch(): array
    {
        return collect($this->optionSearch)
            ->only(['actor', 'director'])
            ->map(fn (mixed $term): string => Str::limit(Str::squish((string) $term), 80, ''))
            ->filter(fn (string $term): bool => mb_strlen($term) >= 2)
            ->all();
    }

    private function positiveInteger(mixed $value): ?int
    {
        $value = filter_var($value, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1],
        ]);

        return is_int($value) ? $value : null;
    }

    private function booleanValue(mixed $value): ?bool
    {
        return match (true) {
            is_bool($value) => $value,
            $value === 1, $value === '1' => true,
            $value === 0, $value === '0' => false,
            default => null,
        };
    }

    private function invalidCardAction(): void
    {
        $this->cardActionNotice = null;
        $this->addError('cardAction', __('catalog.title.card_actions.invalid'));
    }
}
