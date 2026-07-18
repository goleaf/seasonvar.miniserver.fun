<?php

declare(strict_types=1);

namespace App\Livewire\HelpCenter;

use App\Models\HelpCategory;
use App\Models\HelpCategoryTranslation;
use App\Models\User;
use App\Services\HelpCenter\HelpCenterQuery;
use App\Services\HelpCenter\HelpCenterSchema;
use App\Services\HelpCenter\HelpLocale;
use App\Services\HelpCenter\HelpSeoPresenter;
use App\Services\HelpCenter\HelpUrlGenerator;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Pagination\LengthAwarePaginator as Paginator;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithPagination;
use Throwable;

final class HelpCategoryPage extends Component
{
    use WithPagination;

    #[Locked]
    public string $categorySlug = '';

    public bool $queryFailed = false;

    public function mount(
        string $categorySlug,
        HelpCenterQuery $query,
        HelpLocale $locales,
        HelpUrlGenerator $urls,
    ): void {
        $this->categorySlug = mb_substr($categorySlug, 0, 180);
        $routeLocale = request()->route('locale');
        $routeLocale = is_string($routeLocale) ? $routeLocale : null;
        $locale = $locales->normalize($routeLocale ?? app()->getLocale());
        $category = $query->categoryBySlug($this->categorySlug, $locale);
        $translation = $category?->translations->firstWhere('locale', $locale)
            ?? $category?->translations->firstWhere('locale', $locales->fallback());

        if ($translation instanceof HelpCategoryTranslation && $translation->slug !== $this->categorySlug) {
            throw new HttpResponseException(new RedirectResponse($urls->category($translation, $routeLocale), 301));
        }
    }

    public function render(
        HelpCenterSchema $schema,
        HelpCenterQuery $query,
        HelpLocale $locales,
        HelpUrlGenerator $urls,
        HelpSeoPresenter $seo,
    ): View {
        abort_unless($schema->ready(), 404);
        $routeLocale = request()->route('locale');
        $routeLocale = is_string($routeLocale) ? $routeLocale : null;
        $locale = $locales->normalize($routeLocale ?? app()->getLocale());
        $user = auth()->user();
        $user = $user instanceof User ? $user : null;
        $category = $query->categoryBySlug($this->categorySlug, $locale);
        abort_unless($category instanceof HelpCategory, 404);
        $data = $query->categoryData($category, $locale, $routeLocale, $user);
        $articles = $this->emptyPaginator();
        $this->queryFailed = false;

        try {
            $articles = $query->categoryArticles($category, $locale, $routeLocale, $user);
        } catch (Throwable $exception) {
            report($exception);
            $this->queryFailed = true;
        }

        return view('livewire.help-center.category', [
            'category' => $data,
            'articles' => $articles,
            'homeUrl' => $urls->home($routeLocale),
        ])->extends('layouts.app', [
            'title' => $data->title,
            'seo' => $seo->category($category, $data, $routeLocale),
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
