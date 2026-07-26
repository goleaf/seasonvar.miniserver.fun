<?php

declare(strict_types=1);

namespace App\Livewire\Administration;

use App\Enums\AdminPermission;
use App\Livewire\Concerns\InteractsWithPaginationIslands;
use App\Services\Catalog\Quality\CatalogQualityQueueQuery;
use Illuminate\Contracts\View\View;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;
use Throwable;

final class CatalogQualityCenterPage extends Component
{
    use InteractsWithPaginationIslands;
    use WithPagination;

    #[Url(as: 'quality_queue', history: true, except: 'all')]
    public string $queue = 'all';

    #[Url(as: 'quality_q', history: true, except: '')]
    public string $search = '';

    #[Url(as: 'quality_min', history: true, except: null)]
    public ?int $minimumScore = null;

    #[Url(as: 'quality_max', history: true, except: null)]
    public ?int $maximumScore = null;

    #[Url(as: 'quality_sort', history: true, except: 'score_asc')]
    public string $sort = 'score_asc';

    #[Url(as: 'quality_per_page', history: true, except: 25)]
    public int $perPage = 25;

    public function mount(): void
    {
        Gate::authorize(AdminPermission::ContentView->value);
        $this->normalizeSearch();
    }

    public function updated(string $property): void
    {
        if (! in_array($property, [
            'queue',
            'search',
            'minimumScore',
            'maximumScore',
            'sort',
            'perPage',
        ], true)) {
            return;
        }

        if ($property === 'search') {
            $this->normalizeSearch();
        }

        $this->resetPage('qualityPage');
    }

    public function resetFilters(): void
    {
        $this->reset([
            'queue',
            'search',
            'minimumScore',
            'maximumScore',
            'sort',
            'perPage',
        ]);
        $this->resetValidation();
        $this->resetPage('qualityPage');
    }

    public function render(CatalogQualityQueueQuery $quality): View
    {
        Gate::authorize(AdminPermission::ContentView->value);
        $state = $this->validatedState();
        $queryFailed = false;
        $available = $quality->available();
        $summary = [];
        $page = $this->emptyPaginator($state['per_page']);

        if ($available) {
            try {
                $summary = $quality->summary();
                $page = $quality->paginate(
                    queue: $state['queue'],
                    search: $state['search'],
                    minimumScore: $state['minimum_score'],
                    maximumScore: $state['maximum_score'],
                    sort: $state['sort'],
                    perPage: $state['per_page'],
                    page: $this->getPage('qualityPage'),
                );
            } catch (Throwable $exception) {
                report($exception);
                $queryFailed = true;
            }
        }

        return view('livewire.administration.catalog-quality-center', [
            'items' => $page,
            'summary' => $summary,
            'queryFailed' => $queryFailed,
            'available' => $available,
            'activeFilterCount' => collect([
                $state['queue'] !== 'all',
                $state['search'] !== '',
                $state['minimum_score'] !== null,
                $state['maximum_score'] !== null,
                $state['sort'] !== 'score_asc',
                $state['per_page'] !== 25,
            ])->filter()->count(),
        ])->extends('layouts.app', [
            'title' => __('catalog-quality.title'),
            'seo' => [
                'title' => __('catalog-quality.title'),
                'description' => __('catalog-quality.description'),
                'robots' => 'noindex,nofollow',
                'canonical' => route('admin.quality'),
                'alternates' => [],
                'social' => false,
                'jsonLd' => [],
            ],
        ])->section('content');
    }

    /**
     * @return array{
     *     queue: string,
     *     search: string,
     *     minimum_score: int|null,
     *     maximum_score: int|null,
     *     sort: string,
     *     per_page: int
     * }
     */
    private function validatedState(): array
    {
        $validator = Validator::make(
            [
                'queue' => $this->queue,
                'search' => $this->search,
                'minimum_score' => $this->minimumScore,
                'maximum_score' => $this->maximumScore,
                'sort' => $this->sort,
                'per_page' => $this->perPage,
            ],
            [
                'queue' => ['required', 'string', Rule::in(CatalogQualityQueueQuery::queues())],
                'search' => ['nullable', 'string', 'max:80'],
                'minimum_score' => ['nullable', 'integer', 'between:0,100'],
                'maximum_score' => ['nullable', 'integer', 'between:0,100'],
                'sort' => ['required', 'string', Rule::in(CatalogQualityQueueQuery::sorts())],
                'per_page' => ['required', 'integer', Rule::in([15, 25, 50])],
            ],
            [
                'queue.in' => __('catalog-quality.validation.queue'),
                'search.max' => __('catalog-quality.validation.search'),
                'minimum_score.between' => __('catalog-quality.validation.score'),
                'maximum_score.between' => __('catalog-quality.validation.score'),
                'sort.in' => __('catalog-quality.validation.sort'),
                'per_page.in' => __('catalog-quality.validation.per_page'),
            ],
        );
        $validator->after(function ($validator): void {
            if ($this->minimumScore !== null
                && $this->maximumScore !== null
                && $this->minimumScore > $this->maximumScore) {
                $validator->errors()->add(
                    'maximum_score',
                    __('catalog-quality.validation.range'),
                );
            }
        });

        $this->resetValidation([
            'queue',
            'search',
            'minimum_score',
            'maximum_score',
            'sort',
            'per_page',
        ]);

        if ($validator->fails()) {
            foreach ($validator->errors()->messages() as $field => $messages) {
                foreach ($messages as $message) {
                    $this->addError($field, $message);
                }
            }
        }

        $minimumScore = $this->minimumScore;
        $maximumScore = $this->maximumScore;

        if ($minimumScore !== null
            && $maximumScore !== null
            && $minimumScore > $maximumScore) {
            $minimumScore = null;
            $maximumScore = null;
        }

        return [
            'queue' => in_array($this->queue, CatalogQualityQueueQuery::queues(), true)
                ? $this->queue
                : 'all',
            'search' => mb_strlen($this->search) <= 80 ? $this->search : '',
            'minimum_score' => $minimumScore !== null && $minimumScore >= 0 && $minimumScore <= 100
                ? $minimumScore
                : null,
            'maximum_score' => $maximumScore !== null && $maximumScore >= 0 && $maximumScore <= 100
                ? $maximumScore
                : null,
            'sort' => in_array($this->sort, CatalogQualityQueueQuery::sorts(), true)
                ? $this->sort
                : 'score_asc',
            'per_page' => in_array($this->perPage, [15, 25, 50], true)
                ? $this->perPage
                : 25,
        ];
    }

    private function normalizeSearch(): void
    {
        $normalized = preg_replace('/\s+/u', ' ', trim($this->search)) ?? '';
        $this->search = str_replace(['%', '_', '\\'], '', $normalized);
    }

    /** @return LengthAwarePaginator<int, never> */
    private function emptyPaginator(int $perPage): LengthAwarePaginator
    {
        return new LengthAwarePaginator([], 0, $perPage, $this->getPage('qualityPage'), [
            'path' => request()->url(),
            'query' => request()->query(),
            'pageName' => 'qualityPage',
        ]);
    }
}
