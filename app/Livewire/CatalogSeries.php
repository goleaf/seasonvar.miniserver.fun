<?php

namespace App\Livewire;

use App\Enums\CatalogFilterType;
use App\Enums\CatalogSort;
use App\Http\Requests\CatalogTitlesRequest;
use App\Livewire\Forms\CatalogSeriesFilters;
use App\Rules\CatalogFilterSlug;
use App\Services\Catalog\CatalogTitlesPageBuilder;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithPagination;

class CatalogSeries extends Component
{
    use WithPagination;

    public CatalogSeriesFilters $filters;

    #[Locked]
    public ?int $routeYear = null;

    #[Locked]
    public ?string $routeFilterType = null;

    #[Locked]
    public ?string $routeTaxonomy = null;

    protected CatalogTitlesPageBuilder $pages;

    public function boot(CatalogTitlesPageBuilder $pages): void
    {
        $this->pages = $pages;
    }

    public function mount(?int $year = null, ?string $type = null, ?string $taxonomy = null): void
    {
        abort_if($type !== null && CatalogFilterType::tryFrom($type) === null, 404);
        abort_if($taxonomy !== null && CatalogFilterSlug::normalize($taxonomy) === null, 404);

        $this->routeYear = $year;
        $this->routeFilterType = $type;
        $this->routeTaxonomy = $taxonomy;
        $this->validateAndNormalizeState(Arr::except(request()->query->all(), ['page']));
    }

    public function updated(string $property): void
    {
        if (! str_starts_with($property, 'filters.')) {
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

    public function sortBy(string $sort): void
    {
        $option = CatalogSort::tryFrom($sort);

        if ($option === null) {
            $this->addError('sort', 'Выбрана неподдерживаемая сортировка.');

            return;
        }

        $this->filters->sort = $option->value;
        $this->resetErrorBag('sort');
        $this->resetPage();
    }

    public function setView(string $view): void
    {
        if (! in_array($view, ['grid', 'list'], true)) {
            return;
        }

        $this->filters->view = $view;
        $this->resetPage();
    }

    public function setPerPage(int $perPage): void
    {
        if (! in_array($perPage, [24, 48, 96], true)) {
            return;
        }

        $this->filters->perPage = $perPage;
        $this->resetPage();
    }

    public function setLetter(string $letter): void
    {
        if (preg_match('/^(?:latin|[A-Za-zА-Яа-яЁё]|#)$/u', $letter) !== 1) {
            return;
        }

        $this->filters->letter = mb_strtoupper((string) $this->filters->letter) === mb_strtoupper($letter)
            ? ''
            : $letter;
        $this->resetPage();
    }

    public function resetGroup(string $group): mixed
    {
        if ($group === 'year' && $this->routeYear !== null) {
            return $this->redirectRoute('titles.index', navigate: true);
        }

        if ($group === $this->routeFilterType) {
            return $this->redirectRoute('titles.index', navigate: true);
        }

        if ($this->filters->resetGroup($group)) {
            $this->resetErrorBag($group);
            $this->resetPage();
        }

        return null;
    }

    public function resetAdvanced(string $key): void
    {
        if ($this->filters->resetAdvanced($key)) {
            $this->resetErrorBag($key);
            $this->resetPage();
        }
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

    public function removeYear(int $year): mixed
    {
        if ($this->routeYear === $year) {
            return $this->redirectRoute('titles.index', navigate: true);
        }

        $this->filters->years = array_values(array_diff($this->filters->years, [$year, (string) $year]));
        $this->resetPage();

        return null;
    }

    public function removeTaxonomy(string $type, string $slug): mixed
    {
        if (CatalogFilterType::tryFrom($type) === null || CatalogFilterSlug::normalize($slug) === null) {
            return null;
        }

        if ($type === $this->routeFilterType && $slug === $this->routeTaxonomy) {
            return $this->redirectRoute('titles.index', navigate: true);
        }

        if ($this->filters->removeTaxonomy($type, $slug)) {
            $this->resetPage();
        }

        return null;
    }

    public function removeExcluded(string $type, string $slug): void
    {
        if (CatalogFilterSlug::normalize($slug) === null) {
            return;
        }

        if ($this->filters->removeExcluded($type, $slug)) {
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
        $data = $this->pages->data(
            $this->catalogRequest($this->renderInput()),
            $this->routeFilterType,
            $this->routeTaxonomy,
            $this->getErrorBag()->isNotEmpty(),
        );

        return view('catalog.titles', $data)
            ->extends('layouts.app', [
                'title' => $data['seo']['title'] ?? 'Сериалы',
                'seo' => $data['seo'] ?? [],
            ])
            ->section('content');
    }

    public function paginationView(): string
    {
        return 'vendor.livewire.tailwind';
    }

    /** @param array<string, mixed>|null $input */
    private function validateAndNormalizeState(?array $input = null): bool
    {
        $request = $this->catalogRequest($input ?? $this->filters->toRequestInput());

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
}
