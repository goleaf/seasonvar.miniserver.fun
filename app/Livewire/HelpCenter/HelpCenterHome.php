<?php

declare(strict_types=1);

namespace App\Livewire\HelpCenter;

use App\Models\User;
use App\Services\HelpCenter\HelpCenterQuery;
use App\Services\HelpCenter\HelpCenterSchema;
use App\Services\HelpCenter\HelpEscalationService;
use App\Services\HelpCenter\HelpLocale;
use App\Services\HelpCenter\HelpSeoPresenter;
use App\Services\HelpCenter\HelpUrlGenerator;
use Illuminate\Contracts\View\View;
use Livewire\Component;
use Throwable;

final class HelpCenterHome extends Component
{
    public bool $queryFailed = false;

    public function render(
        HelpCenterSchema $schema,
        HelpCenterQuery $query,
        HelpLocale $locales,
        HelpUrlGenerator $urls,
        HelpSeoPresenter $seo,
        HelpEscalationService $escalations,
    ): View {
        $routeLocale = request()->route('locale');
        $routeLocale = is_string($routeLocale) ? $routeLocale : null;
        $locale = $locales->normalize($routeLocale ?? app()->getLocale());
        $user = auth()->user();
        $user = $user instanceof User ? $user : null;
        $categories = $featured = $popular = [];
        $this->queryFailed = false;

        if ($schema->ready()) {
            try {
                $categories = $query->categories($locale, $routeLocale, $user);
                $featured = $query->featured($locale, $routeLocale, $user);
                $popular = $query->popular($locale, $routeLocale, $user);
            } catch (Throwable $exception) {
                report($exception);
                $this->queryFailed = true;
            }
        }

        return view('livewire.help-center.home', [
            'schemaReady' => $schema->ready(),
            'categories' => $categories,
            'featured' => $featured,
            'popular' => $popular,
            'searchUrl' => $urls->search($routeLocale),
            'suggestionsUrl' => route('api.v1.help.suggestions'),
            'locale' => $locale,
            'technicalSupportUrl' => $escalations->technicalSupportUrl(),
            'contentRequestUrl' => $escalations->contentRequestUrl($routeLocale),
        ])->extends('layouts.app', [
            'title' => __('help.title'),
            'seo' => $schema->ready() ? $seo->home($routeLocale) : [...$seo->home($routeLocale), 'robots' => 'noindex, follow'],
        ])->section('content');
    }
}
