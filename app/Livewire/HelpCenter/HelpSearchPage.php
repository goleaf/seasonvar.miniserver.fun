<?php

declare(strict_types=1);

namespace App\Livewire\HelpCenter;

use App\DTOs\Help\HelpSearchCriteria;
use App\Models\User;
use App\Services\Catalog\Search\CatalogSearchNormalizer;
use App\Services\HelpCenter\HelpCenterQuery;
use App\Services\HelpCenter\HelpCenterSchema;
use App\Services\HelpCenter\HelpEscalationService;
use App\Services\HelpCenter\HelpLocale;
use App\Services\HelpCenter\HelpSearchService;
use App\Services\HelpCenter\HelpSeoPresenter;
use App\Services\HelpCenter\HelpUrlGenerator;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Contracts\View\View;
use Illuminate\Pagination\LengthAwarePaginator as Paginator;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;
use Throwable;

final class HelpSearchPage extends Component
{
    use WithPagination;

    #[Url(as: 'q', history: true, except: '')]
    public string $query = '';

    #[Url(as: 'category', history: true, except: '')]
    public string $category = '';

    public bool $queryFailed = false;

    public function updatedQuery(): void
    {
        $this->resetPage();
    }

    public function updatedCategory(): void
    {
        $this->resetPage();
    }

    public function clearSearch(): void
    {
        $this->reset('query', 'category');
        $this->resetPage();
    }

    public function render(
        HelpCenterSchema $schema,
        HelpSearchService $search,
        HelpCenterQuery $queryService,
        HelpLocale $locales,
        HelpUrlGenerator $urls,
        HelpSeoPresenter $seo,
        HelpEscalationService $escalations,
        CatalogSearchNormalizer $normalizer,
    ): View {
        $this->query = mb_substr($normalizer->display($this->query), 0, 120);
        $this->category = preg_match('/^[a-z0-9][a-z0-9-]{1,63}$/D', $this->category) === 1 ? $this->category : '';
        $routeLocale = request()->route('locale');
        $routeLocale = is_string($routeLocale) ? $routeLocale : null;
        $locale = $locales->normalize($routeLocale ?? app()->getLocale());
        $user = auth()->user();
        $user = $user instanceof User ? $user : null;
        $results = $this->emptyPaginator();
        $categories = $popular = [];
        $this->queryFailed = false;

        if ($schema->ready()) {
            try {
                $categories = $queryService->categories($locale, $routeLocale, $user);
                $popular = $queryService->popular($locale, $routeLocale, $user);

                if (mb_strlen($normalizer->key($this->query)) >= 2) {
                    $results = $search->search(new HelpSearchCriteria(
                        query: $this->query,
                        locale: $locale,
                        categoryCode: $this->category !== '' ? $this->category : null,
                        page: max(1, Paginator::resolveCurrentPage()),
                        perPage: max(1, (int) config('help-center.articles_per_page', 12)),
                    ), $routeLocale, $user);
                }
            } catch (Throwable $exception) {
                report($exception);
                $this->queryFailed = true;
            }
        }

        return view('livewire.help-center.search', [
            'schemaReady' => $schema->ready(),
            'results' => $results,
            'categories' => $categories,
            'popular' => $popular,
            'homeUrl' => $urls->home($routeLocale),
            'searchUrl' => $urls->search($routeLocale),
            'suggestionsUrl' => route('api.v1.help.suggestions'),
            'locale' => $locale,
            'technicalSupportUrl' => $escalations->technicalSupportUrl(),
            'contentRequestUrl' => $escalations->contentRequestUrl($routeLocale),
        ])->extends('layouts.app', [
            'title' => __('help.search.title'),
            'seo' => $seo->search($routeLocale),
        ])->section('content');
    }

    /** @return LengthAwarePaginator<int, mixed> */
    private function emptyPaginator(): LengthAwarePaginator
    {
        return new Paginator([], 0, max(1, (int) config('help-center.articles_per_page', 12)), 1, [
            'path' => request()->url(), 'query' => request()->query(),
        ]);
    }
}
