<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Services\Catalog\CatalogDirectoryPageBuilder;
use App\Services\Catalog\CatalogDirectoryRegistry;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Str;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;
use Normalizer;

class CatalogDirectoryBrowser extends Component
{
    use WithPagination;

    #[Locked]
    public string $directory = '';

    #[Url(as: 'q', history: true, except: '')]
    public mixed $search = '';

    #[Url(history: true, except: '')]
    public mixed $letter = '';

    #[Url(history: true, except: 'name_asc')]
    public mixed $sort = 'name_asc';

    #[Url(history: true, except: null)]
    public mixed $decade = null;

    private CatalogDirectoryRegistry $directories;

    private CatalogDirectoryPageBuilder $pages;

    public function boot(CatalogDirectoryRegistry $directories, CatalogDirectoryPageBuilder $pages): void
    {
        $this->directories = $directories;
        $this->pages = $pages;
    }

    public function mount(?string $directory = null): void
    {
        $this->directory = $directory ?: (string) request()->route('directory');
        abort_if($this->directories->find($this->directory) === null, 404);
        $this->normalizeState();
    }

    public function updatedSearch(): void
    {
        $this->search = $this->normalizeSearch($this->search);
        $this->resetPage();
    }

    public function updatedLetter(): void
    {
        $this->letter = $this->normalizeLetter($this->letter);
        $this->resetPage();
    }

    public function updatedSort(): void
    {
        $this->sort = $this->normalizeSort($this->sort);
        $this->resetPage();
    }

    public function updatedDecade(): void
    {
        $this->decade = $this->normalizeDecade($this->decade);
        $this->resetPage();
    }

    public function setLetter(mixed $letter): void
    {
        $letter = $this->normalizeLetter($letter);
        $this->letter = $letter === $this->letter ? '' : $letter;
        $this->resetPage();
    }

    public function setDecade(mixed $decade): void
    {
        $decade = $this->normalizeDecade($decade);
        $this->decade = $decade === $this->decade ? null : $decade;
        $this->resetPage();
    }

    public function clearSearch(): void
    {
        $this->search = '';
        $this->resetPage();
    }

    public function resetDirectoryFilters(): void
    {
        $this->search = '';
        $this->letter = '';
        $this->sort = 'name_asc';
        $this->decade = null;
        $this->resetPage();
    }

    public function render(): View
    {
        $definition = $this->directories->find($this->directory);
        abort_if($definition === null, 404);
        $this->normalizeState();
        $data = $this->pages->data(
            $definition,
            (string) $this->search,
            (string) $this->letter,
            (string) $this->sort,
            is_int($this->decade) ? $this->decade : null,
        );
        $data['searchMaxLength'] = $this->searchMaxLength();
        $items = $data['items'];

        if ($items->currentPage() > $items->lastPage()) {
            $page = max(1, $items->lastPage());
            $this->setPage($page);
            $this->redirect($this->url($definition->indexRouteName, $page), navigate: true);
        }

        return view('livewire.catalog-directory-browser', $data)
            ->extends('layouts.app', [
                'title' => $data['seo']['title'] ?? $definition->title,
                'seo' => $data['seo'] ?? [],
            ])
            ->section('content');
    }

    private function normalizeState(): void
    {
        $this->search = $this->normalizeSearch($this->search);
        $this->letter = $this->normalizeLetter($this->letter);
        $this->sort = $this->normalizeSort($this->sort);
        $this->decade = $this->normalizeDecade($this->decade);
    }

    private function normalizeSearch(mixed $value): string
    {
        if (! is_string($value)) {
            return '';
        }

        if (class_exists(Normalizer::class) && ! Normalizer::isNormalized($value, Normalizer::FORM_KC)) {
            $value = Normalizer::normalize($value, Normalizer::FORM_KC) ?: $value;
        }

        return Str::limit(Str::squish(strip_tags($value)), $this->searchMaxLength(), '');
    }

    private function normalizeLetter(mixed $value): string
    {
        if (! is_string($value) || preg_match('/^(?:[A-Za-zА-Яа-яЁё]|#)$/u', $value) !== 1) {
            return '';
        }

        return mb_strtoupper($value);
    }

    private function normalizeSort(mixed $value): string
    {
        return is_string($value) && in_array($value, ['name_asc', 'count_desc'], true)
            ? $value
            : 'name_asc';
    }

    private function normalizeDecade(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        $decade = filter_var($value, FILTER_VALIDATE_INT, [
            'options' => [
                'min_range' => (int) config('catalog.directories.minimum_year', 1900),
                'max_range' => $this->maximumYear(),
            ],
        ]);

        return $decade !== false && $decade % 10 === 0 ? $decade : null;
    }

    private function maximumYear(): int
    {
        $configured = config('catalog.directories.maximum_year');

        return is_numeric($configured) ? (int) $configured : now()->year + 1;
    }

    private function searchMaxLength(): int
    {
        return max(1, (int) config('catalog.directories.search_max_length', 80));
    }

    private function url(string $routeName, int $page): string
    {
        $query = array_filter([
            'q' => $this->search,
            'letter' => $this->letter,
            'sort' => $this->sort === 'name_asc' ? null : $this->sort,
            'decade' => $this->decade,
            'page' => $page > 1 ? $page : null,
        ], fn (mixed $value): bool => $value !== null && $value !== '');

        return route($routeName, $query);
    }
}
