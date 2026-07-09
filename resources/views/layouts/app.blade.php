@use('Illuminate\Support\Facades\Vite')
<!DOCTYPE html>
<html lang="{{ $htmlLang }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="robots" content="{{ $robots }}">
        <meta name="description" content="{{ $seoDescription }}">
        <meta name="author" content="{{ $siteName }}">
        <meta name="application-name" content="{{ $siteName }}">
        <meta name="generator" content="{{ $siteName }}">
        <meta name="url" content="{{ $canonicalUrl }}">
        <meta name="identifier-URL" content="{{ $canonicalUrl }}">
        <meta name="summary" content="{{ $seoDescription }}">
        <meta name="owner" content="{{ $siteName }}">
        <meta name="answer-count" content="{{ $quickAnswers->count() }}">
        <meta name="toc-count" content="{{ $seoSections->count() }}">
        <meta name="query-count" content="{{ $longTailQueries->count() }}">
        <meta name="related-collection-count" content="{{ $relatedCollections->count() }}">
        <meta name="expanded-keyword-count" content="{{ $expandedKeywords->count() }}">
        <meta name="action-count" content="{{ $seoActions->count() }}">
        <meta name="glossary-count" content="{{ $semanticGlossary->count() }}">
        <meta name="semantic-hub-count" content="{{ $semanticHubs->count() }}">
        <meta name="snippet-count" content="{{ $snippetBlocks->count() }}">
        <meta name="content-signal-count" content="{{ $contentSignals->count() }}">
        <meta name="content-signal-summary" content="{{ $contentSignals->map(fn ($signal) => $signal['name'].': '.$signal['value'])->implode(', ') }}">
        <meta name="audience-path-count" content="{{ $audiencePaths->count() }}">
        <meta name="also-search-count" content="{{ $alsoSearches->count() }}">
        <meta name="discovery-signal-count" content="{{ $discoverySignals->count() }}">
        <meta name="query-matrix-count" content="{{ $queryMatrix->sum(fn ($group) => $group['items']->count()) }}">
        <meta name="media-signal-count" content="{{ $mediaSignals->count() }}">
        <meta name="publisher-signal-count" content="{{ $publisherSignals->count() }}">
        <meta name="freshness-query-count" content="{{ $freshnessQueries->count() }}">
        <meta name="freshness-year" content="{{ $currentSeoYear }}">
        <meta name="russian-query-variant-count" content="{{ $russianQueryVariants->count() }}">
        <meta name="catalog-direction-count" content="{{ $catalogDirections->count() }}">
        <meta name="comparison-query-count" content="{{ $comparisonQueries->count() }}">
        <meta name="episode-intent-count" content="{{ $episodeIntentQueries->count() }}">
        <meta name="watch-mode-count" content="{{ $watchModeQueries->count() }}">
        <meta name="translation-query-count" content="{{ $translationQueries->count() }}">
        <meta name="voice-search-query-count" content="{{ $voiceSearchQueries->count() }}">
        <meta name="topic-authority-count" content="{{ $topicAuthoritySignals->count() }}">
        <meta name="release-calendar-query-count" content="{{ $releaseCalendarQueries->count() }}">
        @if ($expandedKeywords->isNotEmpty())
            <meta name="keywords" content="{{ $expandedKeywords->take(60)->implode(', ') }}">
            <meta name="news_keywords" content="{{ $newsKeywords->implode(', ') }}">
            <meta name="keyphrases" content="{{ $keywordAliases->take(40)->implode(', ') }}">
            <meta name="topic-keywords" content="{{ $topicTerms->take(30)->implode(', ') }}">
            <meta name="content-keywords" content="{{ $expandedKeywords->slice(20)->take(40)->implode(', ') }}">
        @endif
        @if ($topicTerms->isNotEmpty())
            <meta name="subject" content="{{ $topicTerms->take(12)->implode(', ') }}">
            <meta name="classification" content="{{ $topicTerms->take(16)->implode(', ') }}">
            <meta name="category" content="{{ $seo['section'] ?? $topicTerms->first() }}">
            <meta name="page-topic" content="{{ $topicTerms->take(10)->implode(', ') }}">
            <meta name="audience" content="зрители сериалов онлайн">
            <meta name="coverage" content="Worldwide">
            <meta name="distribution" content="Global">
            <meta name="revisit-after" content="1 days">
            <meta name="abstract" content="{{ $seoDescription }}">
            <meta name="topic" content="{{ $topicTerms->take(14)->implode(', ') }}">
            <meta name="target" content="{{ $seoIntents->take(18)->implode(', ') }}">
            <meta name="search-intent" content="{{ $seoIntents->take(18)->implode(', ') }}">
            <meta name="long-tail-keywords" content="{{ $longTailQueries->take(20)->implode(', ') }}">
            <meta name="keyword-aliases" content="{{ $keywordAliases->take(30)->implode(', ') }}">
            <meta name="defined-terms" content="{{ $semanticGlossary->pluck('term')->take(20)->implode(', ') }}">
            <meta name="semantic-hubs" content="{{ $semanticHubs->pluck('title')->implode(', ') }}">
            <meta name="snippet-topics" content="{{ $snippetBlocks->pluck('query')->take(20)->implode(', ') }}">
            <meta name="content-signals" content="{{ $contentSignals->pluck('name')->implode(', ') }}">
            <meta name="audience-paths" content="{{ $audiencePaths->pluck('name')->implode(', ') }}">
            <meta name="also-searches" content="{{ $alsoSearches->take(30)->implode(', ') }}">
            <meta name="discovery-signals" content="{{ $discoverySignals->pluck('name')->implode(', ') }}">
            <meta name="query-matrix" content="{{ $queryMatrix->pluck('name')->implode(', ') }}">
            <meta name="query-matrix-keywords" content="{{ $queryMatrix->flatMap(fn ($group) => $group['items'])->take(30)->implode(', ') }}">
            <meta name="media-assets" content="{{ $mediaSignals->pluck('name')->implode(', ') }}">
            <meta name="publisher-signals" content="{{ $publisherSignals->pluck('name')->implode(', ') }}">
            <meta name="freshness-keywords" content="{{ $freshnessQueries->pluck('query')->take(30)->implode(', ') }}">
            <meta name="russian-query-variants" content="{{ $russianQueryVariants->take(35)->implode(', ') }}">
            <meta name="catalog-directions" content="{{ $catalogDirections->pluck('name')->implode(', ') }}">
            <meta name="catalog-direction-keywords" content="{{ $catalogDirections->pluck('query')->take(30)->implode(', ') }}">
            <meta name="comparison-keywords" content="{{ $comparisonQueries->pluck('query')->take(30)->implode(', ') }}">
            <meta name="episode-keywords" content="{{ $episodeIntentQueries->pluck('query')->take(35)->implode(', ') }}">
            <meta name="watch-mode-keywords" content="{{ $watchModeQueries->pluck('query')->take(35)->implode(', ') }}">
            <meta name="translation-keywords" content="{{ $translationQueries->pluck('query')->take(35)->implode(', ') }}">
            <meta name="voice-search-keywords" content="{{ $voiceSearchQueries->pluck('query')->take(35)->implode(', ') }}">
            <meta name="topic-authority-keywords" content="{{ $topicAuthoritySignals->pluck('query')->take(35)->implode(', ') }}">
            <meta name="release-calendar-keywords" content="{{ $releaseCalendarQueries->pluck('query')->take(35)->implode(', ') }}">
            <meta name="resource-type" content="document">
            <meta name="language" content="Russian">
        @endif
        @foreach ($topicTerms->take(10) as $term)
            <meta name="entity" content="{{ $term }}">
        @endforeach
        @if (! empty($seo['section']))
            <meta property="article:section" content="{{ $seo['section'] }}">
        @endif
        @foreach ($topicTerms->take(12) as $term)
            <meta property="article:tag" content="{{ $term }}">
        @endforeach
        <meta name="theme-color" content="#ecfdf5">
        <link rel="canonical" href="{{ $canonicalUrl }}">
        <link rel="alternate" hreflang="ru" href="{{ $canonicalUrl }}">
        <link rel="alternate" hreflang="x-default" href="{{ $canonicalUrl }}">
        <link rel="sitemap" type="application/xml" href="{{ route('sitemap.index') }}">
        <link rel="sitemap" type="application/xml" href="{{ route('sitemap.landings') }}">
        <link rel="alternate" type="application/rss+xml" title="{{ $siteName }}" href="{{ route('feed') }}">
        <link rel="search" type="application/opensearchdescription+xml" title="{{ $siteName }}" href="{{ route('opensearch') }}">
        <link rel="alternate" type="text/plain" title="LLMs" href="{{ route('llms') }}">
        <meta name="rating" content="general">
        <meta name="referrer" content="strict-origin-when-cross-origin">
        <meta name="DC.title" content="{{ $fullTitle }}">
        <meta name="DC.description" content="{{ $seoDescription }}">
        <meta name="DC.language" content="ru">
        @if (! empty($seo['prev']))
            <link rel="prev" href="{{ $seo['prev'] }}">
        @endif
        @if (! empty($seo['next']))
            <link rel="next" href="{{ $seo['next'] }}">
        @endif
        <meta property="og:locale" content="{{ $seoLocale }}">
        <meta property="og:site_name" content="{{ $siteName }}">
        <meta property="og:type" content="{{ $seoType }}">
        <meta property="og:title" content="{{ $fullTitle }}">
        <meta property="og:description" content="{{ $seoDescription }}">
        <meta property="og:url" content="{{ $canonicalUrl }}">
        @if ($seoImage)
            <link rel="image_src" href="{{ $seoImage }}">
            <meta property="og:image" content="{{ $seoImage }}">
            <meta property="og:image:alt" content="{{ $seo['image_alt'] ?? $fullTitle }}">
            <meta name="twitter:card" content="summary_large_image">
            <meta name="twitter:image" content="{{ $seoImage }}">
        @else
            <meta name="twitter:card" content="summary">
        @endif
        @if ($seoVideo)
            <meta property="og:video" content="{{ $seoVideo }}">
            <meta property="og:video:secure_url" content="{{ $seoVideo }}">
        @endif
        @if (! empty($seo['published_time']))
            <meta property="article:published_time" content="{{ $seo['published_time'] }}">
        @endif
        @if (! empty($seo['updated_time']))
            <meta name="last-modified" content="{{ $seo['updated_time'] }}">
            <meta property="og:updated_time" content="{{ $seo['updated_time'] }}">
            <meta property="article:modified_time" content="{{ $seo['updated_time'] }}">
        @endif
        @foreach ($seoTags as $tag)
            <meta property="article:tag" content="{{ $tag }}">
        @endforeach
        <meta name="twitter:title" content="{{ $fullTitle }}">
        <meta name="twitter:description" content="{{ $seoDescription }}">
        <title>{{ $fullTitle }}</title>
        {{ Vite::fonts('instrument-sans') }}
        @foreach ($jsonLdItems as $jsonLd)
            <script type="application/ld+json">{!! json_encode($jsonLd, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) !!}</script>
        @endforeach
        @if (request()->routeIs('stats'))
            @livewireStyles
        @endif
        @stack('head')
        @vite('resources/js/app.js')
    </head>
    <body class="min-h-screen bg-slate-50 text-slate-700 antialiased">
        <header class="border-b border-slate-200 bg-white shadow-sm shadow-slate-200/70">
            <div class="mx-auto flex max-w-[1760px] flex-col gap-3 px-3 py-4 sm:px-6 lg:flex-row lg:items-center lg:px-8">
                <a href="{{ route('home') }}" class="flex min-w-0 items-center gap-3">
                    <span class="grid h-11 w-11 shrink-0 place-items-center rounded-lg bg-emerald-50 text-xl font-black text-emerald-700 ring-1 ring-emerald-100 sm:h-12 sm:w-12">
                        <i class="fa-solid fa-film" aria-hidden="true"></i>
                    </span>
                    <span>
                        <span class="block text-xl font-black tracking-tight text-slate-700 sm:text-2xl">Каталог сериалов</span>
                    </span>
                </a>

                <form action="{{ route('titles.index') }}" method="GET" class="flex min-w-0 w-full flex-1 overflow-hidden rounded-lg border border-slate-200 bg-white lg:mx-6">
                    <span class="flex shrink-0 items-center pl-4 text-slate-400">
                        <i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i>
                    </span>
                    <input type="search" name="q" value="{{ request('q') }}" placeholder="Поиск сериала..." class="min-w-0 flex-1 border-0 px-3 py-3 text-sm text-slate-700 outline-none placeholder:text-slate-400">
                    <button type="submit" class="inline-flex shrink-0 items-center gap-2 bg-emerald-50 px-4 text-sm font-bold text-emerald-700 hover:bg-emerald-100 sm:px-5">
                        <i class="fa-solid fa-arrow-right" aria-hidden="true"></i>
                        <span>Найти</span>
                    </button>
                </form>

                <nav class="flex w-full flex-wrap items-center gap-2 text-sm font-semibold lg:w-auto">
                    <a href="{{ route('home') }}" class="inline-flex items-center gap-2 rounded-lg bg-slate-50 px-3 py-2 text-slate-600 ring-1 ring-slate-200 hover:bg-emerald-50 hover:text-emerald-700">
                        <i class="fa-solid fa-house" aria-hidden="true"></i>
                        <span>Главная</span>
                    </a>
                    <a href="{{ route('titles.index') }}" class="inline-flex items-center gap-2 rounded-lg bg-slate-50 px-3 py-2 text-slate-600 ring-1 ring-slate-200 hover:bg-emerald-50 hover:text-emerald-700">
                        <i class="fa-solid fa-list" aria-hidden="true"></i>
                        <span>Все сериалы</span>
                    </a>
                </nav>
            </div>
        </header>

        <main id="main-content" class="mx-auto max-w-[1760px] px-3 py-4 sm:px-6 sm:py-6 lg:px-8" itemscope itemtype="https://schema.org/WebPageElement" itemid="{{ $canonicalUrl }}#main-content">
            @if ($breadcrumbs->count() > 1)
                <nav aria-label="Хлебные крошки" class="mb-4 rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm shadow-sm shadow-slate-200/60">
                    <ol class="flex flex-wrap items-center gap-2 text-slate-500">
                        @foreach ($breadcrumbs as $breadcrumb)
                            <li class="inline-flex min-w-0 items-center gap-2">
                                @if (! $loop->first)
                                    <i class="fa-solid fa-chevron-right shrink-0 text-[0.7em] text-slate-300" aria-hidden="true"></i>
                                @endif
                                @if ($loop->last)
                                    <span class="break-words font-semibold text-slate-700" aria-current="page">{{ $breadcrumb['name'] }}</span>
                                @else
                                    <a href="{{ $breadcrumb['url'] }}" class="break-words font-semibold text-emerald-700 hover:text-emerald-600">{{ $breadcrumb['name'] }}</a>
                                @endif
                            </li>
                        @endforeach
                    </ol>
                </nav>
            @endif
            @yield('content')
            @if ($seoSections->isNotEmpty())
                <nav id="table-of-contents" class="mt-5 rounded-lg border border-slate-200 bg-white p-4 shadow-sm shadow-slate-200/60" aria-label="Содержание страницы" itemscope itemtype="https://schema.org/SiteNavigationElement">
                    <div class="flex items-center gap-2 text-sm font-bold text-slate-700">
                        <i class="fa-solid fa-table-list text-emerald-700" aria-hidden="true"></i>
                        <span itemprop="name">Содержание страницы</span>
                    </div>
                    <div class="mt-3 flex flex-wrap gap-2">
                        @foreach ($seoSections as $section)
                            @continue(($section['id'] ?? null) === 'discovery-signals' && ! request()->routeIs('stats'))
                            <a href="#{{ $section['id'] }}" itemprop="url" class="inline-flex items-center gap-1 rounded-full bg-slate-50 px-2.5 py-1 text-xs font-semibold text-slate-700 ring-1 ring-slate-200 hover:bg-emerald-50 hover:text-emerald-700">
                                <i class="fa-solid fa-bookmark text-[0.8em] text-slate-400" aria-hidden="true"></i>
                                <span itemprop="name">{{ $section['name'] }}</span>
                            </a>
                        @endforeach
                    </div>
                </nav>
            @endif
            @if (! empty($seo['seo_text']) || ! empty($seo['related_links']))
                <section id="seo-summary" class="mt-5 rounded-lg border border-slate-200 bg-white p-4 shadow-sm shadow-slate-200/60" aria-label="SEO описание страницы" data-seo-summary>
                    <div class="flex items-center gap-2 text-sm font-bold text-slate-700">
                        <i class="fa-solid fa-file-lines text-emerald-700" aria-hidden="true"></i>
                        <span>Описание страницы</span>
                    </div>
                    @if (! empty($seo['seo_text']))
                        <div class="mt-3 space-y-2 text-sm leading-6 text-slate-600">
                            @foreach (collect($seo['seo_text'])->filter()->take(4) as $paragraph)
                                <p>{{ $paragraph }}</p>
                            @endforeach
                        </div>
                    @endif
                    @if (! empty($seo['related_links']))
                        <div class="mt-4 flex flex-wrap gap-2">
                            @foreach (collect($seo['related_links'])->filter()->take(14) as $link)
                                <a href="{{ $link['url'] }}" class="inline-flex items-center gap-1 rounded-full bg-emerald-50 px-2.5 py-1 text-xs font-semibold text-emerald-700 ring-1 ring-emerald-100 hover:bg-emerald-100">
                                    <i class="fa-solid fa-link text-[0.8em]" aria-hidden="true"></i>
                                    <span>{{ $link['name'] }}</span>
                                </a>
                            @endforeach
                        </div>
                    @endif
                </section>
            @endif
            @if ($topicTerms->isNotEmpty())
                <section id="key-topics" class="mt-5 rounded-lg border border-slate-200 bg-white p-4 shadow-sm shadow-slate-200/60" aria-label="Ключевые темы" itemscope itemtype="https://schema.org/SiteNavigationElement">
                    <div class="flex items-center gap-2 text-sm font-bold text-slate-700">
                        <i class="fa-solid fa-tags text-emerald-700" aria-hidden="true"></i>
                        <span itemprop="name">Ключевые темы</span>
                    </div>
                    <div class="mt-3 flex flex-wrap gap-2">
                        @foreach ($topicTerms as $term)
                            <a href="{{ $seoSearchUrl($term) }}" itemprop="url" class="inline-flex items-center gap-1 rounded-full bg-amber-50 px-2.5 py-1 text-xs font-semibold text-amber-800 ring-1 ring-amber-100 hover:bg-emerald-50 hover:text-emerald-700 hover:ring-emerald-100">
                                <i class="fa-solid fa-hashtag text-[0.8em] text-amber-500" aria-hidden="true"></i>
                                <span itemprop="name">{{ $term }}</span>
                            </a>
                        @endforeach
                    </div>
                </section>
            @endif
            @if ($semanticGlossary->isNotEmpty())
                <section id="semantic-glossary" class="mt-5 rounded-lg border border-slate-200 bg-white p-4 shadow-sm shadow-slate-200/60" aria-label="Глоссарий страницы" itemscope itemtype="https://schema.org/DefinedTermSet">
                    <div class="flex items-center gap-2 text-sm font-bold text-slate-700">
                        <i class="fa-solid fa-book-open text-emerald-700" aria-hidden="true"></i>
                        <span itemprop="name">Глоссарий страницы</span>
                    </div>
                    <div class="mt-3 grid gap-3 md:grid-cols-2 xl:grid-cols-3">
                        @foreach ($semanticGlossary as $item)
                            <article class="rounded-lg border border-slate-200 bg-slate-50 p-3" itemprop="hasDefinedTerm" itemscope itemtype="https://schema.org/DefinedTerm">
                                <a href="{{ $item['url'] }}" class="text-sm font-bold text-slate-800 hover:text-emerald-700" itemprop="url">
                                    <span itemprop="name">{{ $item['term'] }}</span>
                                </a>
                                <p class="mt-2 text-xs leading-5 text-slate-600" itemprop="description">{{ $item['description'] }}</p>
                            </article>
                        @endforeach
                    </div>
                </section>
            @endif
            @if ($seoIntents->isNotEmpty())
                <section id="query-navigation" class="mt-5 rounded-lg border border-slate-200 bg-white p-4 shadow-sm shadow-slate-200/60" aria-label="Навигация по запросам" itemscope itemtype="https://schema.org/SiteNavigationElement">
                    <div class="flex items-center gap-2 text-sm font-bold text-slate-700">
                        <i class="fa-solid fa-route text-emerald-700" aria-hidden="true"></i>
                        <span itemprop="name">Навигация по запросам</span>
                    </div>
                    <div class="mt-3 grid gap-2 sm:grid-cols-2 lg:grid-cols-4">
                        @foreach ($seoIntents->take(16) as $intent)
                            <a href="{{ $seoSearchUrl($intent) }}" itemprop="url" class="flex items-center gap-2 rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 text-xs font-semibold text-slate-700 hover:border-emerald-100 hover:bg-emerald-50 hover:text-emerald-700">
                                <i class="fa-solid fa-arrow-up-right-from-square text-[0.8em] text-slate-400" aria-hidden="true"></i>
                                <span itemprop="name">{{ $intent }}</span>
                            </a>
                        @endforeach
                    </div>
                </section>
            @endif
            @if ($longTailQueries->isNotEmpty())
                <section id="long-tail-queries" class="mt-5 rounded-lg border border-slate-200 bg-white p-4 shadow-sm shadow-slate-200/60" aria-label="Поисковые формулировки" itemscope itemtype="https://schema.org/SiteNavigationElement">
                    <div class="flex items-center gap-2 text-sm font-bold text-slate-700">
                        <i class="fa-solid fa-keyboard text-emerald-700" aria-hidden="true"></i>
                        <span itemprop="name">Поисковые формулировки</span>
                    </div>
                    <div class="mt-3 grid gap-2 sm:grid-cols-2 lg:grid-cols-4">
                        @foreach ($longTailQueries->take(24) as $query)
                            <a href="{{ $seoSearchUrl($query) }}" itemprop="url" class="flex items-center gap-2 rounded-lg border border-slate-200 bg-white px-3 py-2 text-xs font-semibold text-slate-700 hover:border-emerald-100 hover:bg-emerald-50 hover:text-emerald-700">
                                <i class="fa-solid fa-magnifying-glass text-[0.8em] text-slate-400" aria-hidden="true"></i>
                                <span itemprop="name">{{ $query }}</span>
                            </a>
                        @endforeach
                    </div>
                </section>
            @endif
            @if ($relatedCollections->isNotEmpty())
                <section id="related-collections" class="mt-5 rounded-lg border border-slate-200 bg-white p-4 shadow-sm shadow-slate-200/60" aria-label="Связанные подборки" itemscope itemtype="https://schema.org/CollectionPage">
                    <div class="flex items-center gap-2 text-sm font-bold text-slate-700">
                        <i class="fa-solid fa-layer-group text-emerald-700" aria-hidden="true"></i>
                        <span itemprop="name">Связанные подборки</span>
                    </div>
                    <div class="mt-3 grid gap-3 md:grid-cols-2 xl:grid-cols-3">
                        @foreach ($relatedCollections->take(18) as $collection)
                            <a href="{{ $seoSearchUrl($collection['query']) }}" class="block rounded-lg border border-slate-200 bg-slate-50 p-3 hover:border-emerald-100 hover:bg-emerald-50" itemprop="hasPart" itemscope itemtype="https://schema.org/CollectionPage">
                                <span class="flex items-center gap-2 text-sm font-bold text-slate-800" itemprop="name">
                                    <i class="fa-solid fa-folder-open text-[0.85em] text-emerald-700" aria-hidden="true"></i>
                                    {{ $collection['name'] }}
                                </span>
                                <span class="mt-2 block text-xs leading-5 text-slate-600" itemprop="description">{{ $collection['description'] }}</span>
                                <meta itemprop="url" content="{{ $seoSearchUrl($collection['query']) }}">
                            </a>
                        @endforeach
                    </div>
                </section>
            @endif
            @if ($semanticHubs->isNotEmpty())
                <section id="semantic-hubs" class="mt-5 rounded-lg border border-slate-200 bg-white p-4 shadow-sm shadow-slate-200/60" aria-label="Тематические хабы">
                    <div class="flex items-center gap-2 text-sm font-bold text-slate-700">
                        <i class="fa-solid fa-sitemap text-emerald-700" aria-hidden="true"></i>
                        <span>Тематические хабы</span>
                    </div>
                    <div class="mt-3 grid gap-3 lg:grid-cols-2">
                        @foreach ($semanticHubs as $hub)
                            <article class="rounded-lg border border-slate-200 bg-slate-50 p-3">
                                <h2 class="text-sm font-bold text-slate-800">{{ $hub['title'] }}</h2>
                                <p class="mt-1 text-xs leading-5 text-slate-600">{{ $hub['description'] }}</p>
                                <div class="mt-3 flex flex-wrap gap-2">
                                    @foreach ($hub['items'] as $item)
                                        <a href="{{ $seoSearchUrl($item['query']) }}" class="inline-flex items-center gap-1 rounded-full bg-white px-2.5 py-1 text-xs font-semibold text-slate-700 ring-1 ring-slate-200 hover:bg-emerald-50 hover:text-emerald-700">
                                            <i class="fa-solid fa-link text-[0.8em] text-slate-400" aria-hidden="true"></i>
                                            <span>{{ $item['name'] }}</span>
                                        </a>
                                    @endforeach
                                </div>
                            </article>
                        @endforeach
                    </div>
                </section>
            @endif
            @if ($seoActions->isNotEmpty())
                <section id="page-actions" class="mt-5 rounded-lg border border-slate-200 bg-white p-4 shadow-sm shadow-slate-200/60" aria-label="Действия на странице" itemscope itemtype="https://schema.org/SiteNavigationElement">
                    <div class="flex items-center gap-2 text-sm font-bold text-slate-700">
                        <i class="fa-solid fa-bolt text-emerald-700" aria-hidden="true"></i>
                        <span itemprop="name">Действия на странице</span>
                    </div>
                    <div class="mt-3 grid gap-2 sm:grid-cols-2 lg:grid-cols-4">
                        @foreach ($seoActions->take(16) as $action)
                            <a href="{{ $action['url'] }}" itemprop="url" class="flex items-center gap-2 rounded-lg border border-slate-200 bg-white px-3 py-2 text-xs font-semibold text-slate-700 hover:border-emerald-100 hover:bg-emerald-50 hover:text-emerald-700">
                                <i class="fa-solid fa-circle-arrow-right text-[0.85em] text-emerald-700" aria-hidden="true"></i>
                                <span itemprop="name">{{ $action['label'] ?? $action['name'] }}</span>
                            </a>
                        @endforeach
                    </div>
                </section>
            @endif
            @if ($snippetBlocks->isNotEmpty())
                <section id="snippet-blocks" class="mt-5 rounded-lg border border-slate-200 bg-white p-4 shadow-sm shadow-slate-200/60" aria-label="Короткие тезисы страницы">
                    <div class="flex items-center gap-2 text-sm font-bold text-slate-700">
                        <i class="fa-solid fa-quote-left text-emerald-700" aria-hidden="true"></i>
                        <span>Короткие тезисы</span>
                    </div>
                    <div class="mt-3 grid gap-3 md:grid-cols-2 xl:grid-cols-4">
                        @foreach ($snippetBlocks as $block)
                            <article class="rounded-lg border border-slate-200 bg-slate-50 p-3">
                                <h2 class="text-sm font-bold text-slate-800">{{ $block['title'] }}</h2>
                                <p class="mt-2 text-xs leading-5 text-slate-600">{{ $block['text'] }}</p>
                                <a href="{{ $seoSearchUrl($block['query']) }}" class="mt-3 inline-flex items-center gap-1 text-xs font-bold text-emerald-700 hover:text-emerald-600">
                                    <i class="fa-solid fa-magnifying-glass text-[0.8em]" aria-hidden="true"></i>
                                    <span>Найти: {{ $block['query'] }}</span>
                                </a>
                            </article>
                        @endforeach
                    </div>
                </section>
            @endif
            @if ($contentSignals->isNotEmpty())
                <section id="content-signals" class="mt-5 rounded-lg border border-slate-200 bg-white p-4 shadow-sm shadow-slate-200/60" aria-label="Сигналы страницы">
                    <div class="flex items-center gap-2 text-sm font-bold text-slate-700">
                        <i class="fa-solid fa-chart-simple text-emerald-700" aria-hidden="true"></i>
                        <span>Сигналы страницы</span>
                    </div>
                    <div class="mt-3 grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
                        @foreach ($contentSignals as $signal)
                            <article class="rounded-lg border border-slate-200 bg-slate-50 p-3">
                                <div class="flex items-start justify-between gap-3">
                                    <h2 class="text-sm font-bold text-slate-800">{{ $signal['name'] }}</h2>
                                    <span class="rounded-full bg-emerald-50 px-2 py-0.5 text-xs font-bold text-emerald-700 ring-1 ring-emerald-100">{{ $signal['value'] }}</span>
                                </div>
                                <p class="mt-2 text-xs leading-5 text-slate-600">{{ $signal['description'] }}</p>
                                <a href="{{ $seoSearchUrl($signal['query']) }}" class="mt-3 inline-flex items-center gap-1 text-xs font-bold text-emerald-700 hover:text-emerald-600">
                                    <i class="fa-solid fa-arrow-up-right-from-square text-[0.8em]" aria-hidden="true"></i>
                                    <span>Открыть связанный поиск</span>
                                </a>
                            </article>
                        @endforeach
                    </div>
                </section>
            @endif
            @if ($audiencePaths->isNotEmpty())
                <section id="audience-paths" class="mt-5 rounded-lg border border-slate-200 bg-white p-4 shadow-sm shadow-slate-200/60" aria-label="Пути поиска">
                    <div class="flex items-center gap-2 text-sm font-bold text-slate-700">
                        <i class="fa-solid fa-signs-post text-emerald-700" aria-hidden="true"></i>
                        <span>Пути поиска</span>
                    </div>
                    <div class="mt-3 grid gap-3 lg:grid-cols-2">
                        @foreach ($audiencePaths as $path)
                            <article class="rounded-lg border border-slate-200 bg-slate-50 p-3">
                                <div class="flex items-start justify-between gap-3">
                                    <div>
                                        <h2 class="text-sm font-bold text-slate-800">{{ $path['name'] }}</h2>
                                        <p class="mt-1 text-xs leading-5 text-slate-600">{{ $path['description'] }}</p>
                                    </div>
                                    <a href="{{ $seoSearchUrl($path['query']) }}" class="shrink-0 rounded-full bg-emerald-50 px-2 py-1 text-xs font-bold text-emerald-700 ring-1 ring-emerald-100 hover:bg-emerald-100">
                                        Открыть
                                    </a>
                                </div>
                                <div class="mt-3 flex flex-wrap gap-2">
                                    @foreach ($path['items'] as $item)
                                        <a href="{{ $seoSearchUrl($item) }}" class="inline-flex items-center gap-1 rounded-full bg-white px-2.5 py-1 text-xs font-semibold text-slate-700 ring-1 ring-slate-200 hover:bg-emerald-50 hover:text-emerald-700">
                                            <i class="fa-solid fa-compass text-[0.8em] text-slate-400" aria-hidden="true"></i>
                                            <span>{{ $item }}</span>
                                        </a>
                                    @endforeach
                                </div>
                            </article>
                        @endforeach
                    </div>
                </section>
            @endif
            @if ($alsoSearches->isNotEmpty())
                <section id="also-searches" class="mt-5 rounded-lg border border-slate-200 bg-white p-4 shadow-sm shadow-slate-200/60" aria-label="Также ищут" itemscope itemtype="https://schema.org/SiteNavigationElement">
                    <div class="flex items-center gap-2 text-sm font-bold text-slate-700">
                        <i class="fa-solid fa-binoculars text-emerald-700" aria-hidden="true"></i>
                        <span itemprop="name">Также ищут</span>
                    </div>
                    <div class="mt-3 flex flex-wrap gap-2">
                        @foreach ($alsoSearches->take(36) as $query)
                            <a href="{{ $seoSearchUrl($query) }}" itemprop="url" class="inline-flex items-center gap-1 rounded-full bg-white px-2.5 py-1 text-xs font-semibold text-slate-700 ring-1 ring-slate-200 hover:bg-emerald-50 hover:text-emerald-700">
                                <i class="fa-solid fa-magnifying-glass-plus text-[0.8em] text-slate-400" aria-hidden="true"></i>
                                <span itemprop="name">{{ $query }}</span>
                            </a>
                        @endforeach
                    </div>
                </section>
            @endif
            @if ($discoverySignals->isNotEmpty() && request()->routeIs('stats'))
                <section id="discovery-signals" class="mt-5 rounded-lg border border-slate-200 bg-white p-4 shadow-sm shadow-slate-200/60" aria-label="Индексация и обновления">
                    <div class="flex items-center gap-2 text-sm font-bold text-slate-700">
                        <i class="fa-solid fa-satellite-dish text-emerald-700" aria-hidden="true"></i>
                        <span>Индексация и обновления</span>
                    </div>
                    <div class="mt-3 grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
                        @foreach ($discoverySignals as $signal)
                            <article class="rounded-lg border border-slate-200 bg-slate-50 p-3">
                                <h2 class="text-sm font-bold text-slate-800">{{ $signal['name'] }}</h2>
                                <p class="mt-1 break-words text-xs font-semibold text-emerald-700">{{ $signal['value'] }}</p>
                                <p class="mt-2 text-xs leading-5 text-slate-600">{{ $signal['description'] }}</p>
                                <a href="{{ $signal['url'] }}" class="mt-3 inline-flex items-center gap-1 text-xs font-bold text-emerald-700 hover:text-emerald-600">
                                    <i class="fa-solid fa-arrow-up-right-from-square text-[0.8em]" aria-hidden="true"></i>
                                    <span>Открыть</span>
                                </a>
                            </article>
                        @endforeach
                    </div>
                </section>
            @endif
            @if ($queryMatrix->isNotEmpty())
                <section id="query-matrix" class="mt-5 rounded-lg border border-slate-200 bg-white p-4 shadow-sm shadow-slate-200/60" aria-label="Матрица запросов">
                    <div class="flex items-center gap-2 text-sm font-bold text-slate-700">
                        <i class="fa-solid fa-table-cells-large text-emerald-700" aria-hidden="true"></i>
                        <span>Матрица запросов</span>
                    </div>
                    <div class="mt-3 grid gap-3 lg:grid-cols-2">
                        @foreach ($queryMatrix as $group)
                            <article class="rounded-lg border border-slate-200 bg-slate-50 p-3">
                                <h2 class="text-sm font-bold text-slate-800">{{ $group['name'] }}</h2>
                                <p class="mt-1 text-xs leading-5 text-slate-600">{{ $group['description'] }}</p>
                                <div class="mt-3 flex flex-wrap gap-2">
                                    @foreach ($group['items'] as $query)
                                        <a href="{{ $seoSearchUrl($query) }}" class="inline-flex items-center gap-1 rounded-full bg-white px-2.5 py-1 text-xs font-semibold text-slate-700 ring-1 ring-slate-200 hover:bg-emerald-50 hover:text-emerald-700">
                                            <i class="fa-solid fa-table-cells text-[0.8em] text-slate-400" aria-hidden="true"></i>
                                            <span>{{ $query }}</span>
                                        </a>
                                    @endforeach
                                </div>
                            </article>
                        @endforeach
                    </div>
                </section>
            @endif
            @if ($mediaSignals->isNotEmpty())
                <section id="media-signals" class="mt-5 rounded-lg border border-slate-200 bg-white p-4 shadow-sm shadow-slate-200/60" aria-label="Медиа и превью">
                    <div class="flex items-center gap-2 text-sm font-bold text-slate-700">
                        <i class="fa-solid fa-photo-film text-emerald-700" aria-hidden="true"></i>
                        <span>Медиа и превью</span>
                    </div>
                    <div class="mt-3 grid gap-3 md:grid-cols-2">
                        @foreach ($mediaSignals as $signal)
                            <article class="rounded-lg border border-slate-200 bg-slate-50 p-3">
                                <h2 class="text-sm font-bold text-slate-800">{{ $signal['name'] }}</h2>
                                <p class="mt-2 text-xs leading-5 text-slate-600">{{ $signal['description'] }}</p>
                                <a href="{{ $signal['url'] }}" class="mt-3 inline-flex items-center gap-1 break-all text-xs font-bold text-emerald-700 hover:text-emerald-600">
                                    <i class="fa-solid fa-arrow-up-right-from-square text-[0.8em]" aria-hidden="true"></i>
                                    <span>{{ $signal['type'] === 'video' ? 'Открыть видео' : 'Открыть изображение' }}</span>
                                </a>
                            </article>
                        @endforeach
                    </div>
                </section>
            @endif
            @if ($publisherSignals->isNotEmpty())
                <section id="publisher-trust" class="mt-5 rounded-lg border border-slate-200 bg-white p-4 shadow-sm shadow-slate-200/60" aria-label="Доверие и индексация">
                    <div class="flex items-center gap-2 text-sm font-bold text-slate-700">
                        <i class="fa-solid fa-shield-halved text-emerald-700" aria-hidden="true"></i>
                        <span>Доверие и индексация</span>
                    </div>
                    <div class="mt-3 grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
                        @foreach ($publisherSignals as $signal)
                            <article class="rounded-lg border border-slate-200 bg-slate-50 p-3">
                                <h2 class="text-sm font-bold text-slate-800">{{ $signal['name'] }}</h2>
                                <p class="mt-1 break-words text-xs font-semibold text-emerald-700">{{ $signal['value'] }}</p>
                                <p class="mt-2 text-xs leading-5 text-slate-600">{{ $signal['description'] }}</p>
                                <a href="{{ $signal['url'] }}" class="mt-3 inline-flex items-center gap-1 text-xs font-bold text-emerald-700 hover:text-emerald-600">
                                    <i class="fa-solid fa-arrow-up-right-from-square text-[0.8em]" aria-hidden="true"></i>
                                    <span>Открыть</span>
                                </a>
                            </article>
                        @endforeach
                    </div>
                </section>
            @endif
            @if ($freshnessQueries->isNotEmpty())
                <section id="freshness-seo" class="mt-5 rounded-lg border border-slate-200 bg-white p-4 shadow-sm shadow-slate-200/60" aria-label="Актуальные запросы">
                    <div class="flex items-center gap-2 text-sm font-bold text-slate-700">
                        <i class="fa-solid fa-clock-rotate-left text-emerald-700" aria-hidden="true"></i>
                        <span>Актуальные запросы {{ $currentSeoYear }}</span>
                    </div>
                    <div class="mt-3 grid gap-3 md:grid-cols-2 xl:grid-cols-3">
                        @foreach ($freshnessQueries as $item)
                            <article class="rounded-lg border border-slate-200 bg-slate-50 p-3">
                                <h2 class="text-sm font-bold text-slate-800">{{ $item['name'] }}</h2>
                                <p class="mt-2 text-xs leading-5 text-slate-600">{{ $item['description'] }}</p>
                                <a href="{{ $item['url'] }}" class="mt-3 inline-flex items-center gap-1 text-xs font-bold text-emerald-700 hover:text-emerald-600">
                                    <i class="fa-solid fa-arrow-up-right-from-square text-[0.8em]" aria-hidden="true"></i>
                                    <span>{{ $item['query'] }}</span>
                                </a>
                            </article>
                        @endforeach
                    </div>
                </section>
            @endif
            @if ($russianQueryVariants->isNotEmpty())
                <section id="russian-query-variants" class="mt-5 rounded-lg border border-slate-200 bg-white p-4 shadow-sm shadow-slate-200/60" aria-label="Русские варианты поиска" itemscope itemtype="https://schema.org/SiteNavigationElement">
                    <div class="flex items-center gap-2 text-sm font-bold text-slate-700">
                        <i class="fa-solid fa-language text-emerald-700" aria-hidden="true"></i>
                        <span itemprop="name">Русские варианты поиска</span>
                    </div>
                    <div class="mt-3 flex flex-wrap gap-2">
                        @foreach ($russianQueryVariants->take(42) as $query)
                            <a href="{{ $seoSearchUrl($query) }}" itemprop="url" class="inline-flex items-center gap-1 rounded-full bg-white px-2.5 py-1 text-xs font-semibold text-slate-700 ring-1 ring-slate-200 hover:bg-emerald-50 hover:text-emerald-700">
                                <i class="fa-solid fa-spell-check text-[0.8em] text-slate-400" aria-hidden="true"></i>
                                <span itemprop="name">{{ $query }}</span>
                            </a>
                        @endforeach
                    </div>
                </section>
            @endif
            @if ($catalogDirections->isNotEmpty())
                <section id="catalog-directions" class="mt-5 rounded-lg border border-slate-200 bg-white p-4 shadow-sm shadow-slate-200/60" aria-label="Направления каталога">
                    <div class="flex items-center gap-2 text-sm font-bold text-slate-700">
                        <i class="fa-solid fa-compass-drafting text-emerald-700" aria-hidden="true"></i>
                        <span>Направления каталога</span>
                    </div>
                    <div class="mt-3 grid gap-3 md:grid-cols-2 xl:grid-cols-3">
                        @foreach ($catalogDirections as $item)
                            <article class="rounded-lg border border-slate-200 bg-slate-50 p-3">
                                <h2 class="text-sm font-bold text-slate-800">{{ $item['name'] }}</h2>
                                <p class="mt-2 text-xs leading-5 text-slate-600">{{ $item['description'] }}</p>
                                <a href="{{ $item['url'] }}" class="mt-3 inline-flex items-center gap-1 text-xs font-bold text-emerald-700 hover:text-emerald-600">
                                    <i class="fa-solid fa-arrow-up-right-from-square text-[0.8em]" aria-hidden="true"></i>
                                    <span>{{ $item['query'] }}</span>
                                </a>
                            </article>
                        @endforeach
                    </div>
                </section>
            @endif
            @if ($comparisonQueries->isNotEmpty())
                <section id="comparison-seo" class="mt-5 rounded-lg border border-slate-200 bg-white p-4 shadow-sm shadow-slate-200/60" aria-label="Похожие и сравнения">
                    <div class="flex items-center gap-2 text-sm font-bold text-slate-700">
                        <i class="fa-solid fa-code-compare text-emerald-700" aria-hidden="true"></i>
                        <span>Похожие и сравнения</span>
                    </div>
                    <div class="mt-3 grid gap-3 md:grid-cols-2 xl:grid-cols-3">
                        @foreach ($comparisonQueries as $item)
                            <article class="rounded-lg border border-slate-200 bg-slate-50 p-3">
                                <h2 class="text-sm font-bold text-slate-800">{{ $item['name'] }}</h2>
                                <p class="mt-2 text-xs leading-5 text-slate-600">{{ $item['description'] }}</p>
                                <a href="{{ $seoSearchUrl($item['query']) }}" class="mt-3 inline-flex items-center gap-1 text-xs font-bold text-emerald-700 hover:text-emerald-600">
                                    <i class="fa-solid fa-magnifying-glass text-[0.8em]" aria-hidden="true"></i>
                                    <span>{{ $item['query'] }}</span>
                                </a>
                            </article>
                        @endforeach
                    </div>
                </section>
            @endif
            @if ($episodeIntentQueries->isNotEmpty())
                <section id="episode-intents" class="mt-5 rounded-lg border border-slate-200 bg-white p-4 shadow-sm shadow-slate-200/60" aria-label="Серии и сезоны">
                    <div class="flex items-center gap-2 text-sm font-bold text-slate-700">
                        <i class="fa-solid fa-film text-emerald-700" aria-hidden="true"></i>
                        <span>Серии и сезоны</span>
                    </div>
                    <div class="mt-3 grid gap-3 md:grid-cols-2 xl:grid-cols-4">
                        @foreach ($episodeIntentQueries as $item)
                            <article class="rounded-lg border border-slate-200 bg-slate-50 p-3">
                                <h2 class="text-sm font-bold text-slate-800">{{ $item['name'] }}</h2>
                                <p class="mt-2 text-xs leading-5 text-slate-600">{{ $item['description'] }}</p>
                                <a href="{{ $seoSearchUrl($item['query']) }}" class="mt-3 inline-flex items-center gap-1 text-xs font-bold text-emerald-700 hover:text-emerald-600">
                                    <i class="fa-solid fa-magnifying-glass text-[0.8em]" aria-hidden="true"></i>
                                    <span>{{ $item['query'] }}</span>
                                </a>
                            </article>
                        @endforeach
                    </div>
                </section>
            @endif
            @if ($watchModeQueries->isNotEmpty())
                <section id="watch-mode-seo" class="mt-5 rounded-lg border border-slate-200 bg-white p-4 shadow-sm shadow-slate-200/60" aria-label="Способы просмотра">
                    <div class="flex items-center gap-2 text-sm font-bold text-slate-700">
                        <i class="fa-solid fa-display text-emerald-700" aria-hidden="true"></i>
                        <span>Способы просмотра</span>
                    </div>
                    <div class="mt-3 grid gap-3 md:grid-cols-2 xl:grid-cols-4">
                        @foreach ($watchModeQueries as $item)
                            <article class="rounded-lg border border-slate-200 bg-slate-50 p-3">
                                <h2 class="text-sm font-bold text-slate-800">{{ $item['name'] }}</h2>
                                <p class="mt-2 text-xs leading-5 text-slate-600">{{ $item['description'] }}</p>
                                <a href="{{ $seoSearchUrl($item['query']) }}" class="mt-3 inline-flex items-center gap-1 text-xs font-bold text-emerald-700 hover:text-emerald-600">
                                    <i class="fa-solid fa-play text-[0.8em]" aria-hidden="true"></i>
                                    <span>{{ $item['query'] }}</span>
                                </a>
                            </article>
                        @endforeach
                    </div>
                </section>
            @endif
            @if ($translationQueries->isNotEmpty())
                <section id="translation-seo" class="mt-5 rounded-lg border border-slate-200 bg-white p-4 shadow-sm shadow-slate-200/60" aria-label="Переводы и озвучки">
                    <div class="flex items-center gap-2 text-sm font-bold text-slate-700">
                        <i class="fa-solid fa-microphone-lines text-emerald-700" aria-hidden="true"></i>
                        <span>Переводы и озвучки</span>
                    </div>
                    <div class="mt-3 grid gap-3 md:grid-cols-2 xl:grid-cols-4">
                        @foreach ($translationQueries as $item)
                            <article class="rounded-lg border border-slate-200 bg-slate-50 p-3">
                                <h2 class="text-sm font-bold text-slate-800">{{ $item['name'] }}</h2>
                                <p class="mt-2 text-xs leading-5 text-slate-600">{{ $item['description'] }}</p>
                                <a href="{{ $seoSearchUrl($item['query']) }}" class="mt-3 inline-flex items-center gap-1 text-xs font-bold text-emerald-700 hover:text-emerald-600">
                                    <i class="fa-solid fa-language text-[0.8em]" aria-hidden="true"></i>
                                    <span>{{ $item['query'] }}</span>
                                </a>
                            </article>
                        @endforeach
                    </div>
                </section>
            @endif
            @if ($voiceSearchQueries->isNotEmpty())
                <section id="voice-search-seo" class="mt-5 rounded-lg border border-slate-200 bg-white p-4 shadow-sm shadow-slate-200/60" aria-label="Голосовые запросы">
                    <div class="flex items-center gap-2 text-sm font-bold text-slate-700">
                        <i class="fa-solid fa-comments text-emerald-700" aria-hidden="true"></i>
                        <span>Голосовые запросы</span>
                    </div>
                    <div class="mt-3 grid gap-3 md:grid-cols-2 xl:grid-cols-4">
                        @foreach ($voiceSearchQueries as $item)
                            <article class="rounded-lg border border-slate-200 bg-slate-50 p-3">
                                <h2 class="text-sm font-bold text-slate-800">{{ $item['name'] }}</h2>
                                <p class="mt-2 text-xs leading-5 text-slate-600">{{ $item['description'] }}</p>
                                <a href="{{ $seoSearchUrl($item['query']) }}" class="mt-3 inline-flex items-center gap-1 text-xs font-bold text-emerald-700 hover:text-emerald-600">
                                    <i class="fa-solid fa-magnifying-glass text-[0.8em]" aria-hidden="true"></i>
                                    <span>{{ $item['query'] }}</span>
                                </a>
                            </article>
                        @endforeach
                    </div>
                </section>
            @endif
            @if ($topicAuthoritySignals->isNotEmpty())
                <section id="topic-authority-seo" class="mt-5 rounded-lg border border-slate-200 bg-white p-4 shadow-sm shadow-slate-200/60" aria-label="Тематический авторитет">
                    <div class="flex items-center gap-2 text-sm font-bold text-slate-700">
                        <i class="fa-solid fa-award text-emerald-700" aria-hidden="true"></i>
                        <span>Тематический авторитет</span>
                    </div>
                    <div class="mt-3 grid gap-3 md:grid-cols-2 xl:grid-cols-4">
                        @foreach ($topicAuthoritySignals as $item)
                            <article class="rounded-lg border border-slate-200 bg-slate-50 p-3">
                                <h2 class="text-sm font-bold text-slate-800">{{ $item['name'] }}</h2>
                                <p class="mt-2 text-xs leading-5 text-slate-600">{{ $item['description'] }}</p>
                                <a href="{{ $seoSearchUrl($item['query']) }}" class="mt-3 inline-flex items-center gap-1 text-xs font-bold text-emerald-700 hover:text-emerald-600">
                                    <i class="fa-solid fa-magnifying-glass text-[0.8em]" aria-hidden="true"></i>
                                    <span>{{ $item['query'] }}</span>
                                </a>
                            </article>
                        @endforeach
                    </div>
                </section>
            @endif
            @if ($releaseCalendarQueries->isNotEmpty())
                <section id="release-calendar-seo" class="mt-5 rounded-lg border border-slate-200 bg-white p-4 shadow-sm shadow-slate-200/60" aria-label="Календарь релизов">
                    <div class="flex items-center gap-2 text-sm font-bold text-slate-700">
                        <i class="fa-solid fa-calendar-days text-emerald-700" aria-hidden="true"></i>
                        <span>Календарь релизов</span>
                    </div>
                    <div class="mt-3 grid gap-3 md:grid-cols-2 xl:grid-cols-4">
                        @foreach ($releaseCalendarQueries as $item)
                            <article class="rounded-lg border border-slate-200 bg-slate-50 p-3">
                                <h2 class="text-sm font-bold text-slate-800">{{ $item['name'] }}</h2>
                                <p class="mt-2 text-xs leading-5 text-slate-600">{{ $item['description'] }}</p>
                                <a href="{{ $seoSearchUrl($item['query']) }}" class="mt-3 inline-flex items-center gap-1 text-xs font-bold text-emerald-700 hover:text-emerald-600">
                                    <i class="fa-solid fa-clock text-[0.8em]" aria-hidden="true"></i>
                                    <span>{{ $item['query'] }}</span>
                                </a>
                            </article>
                        @endforeach
                    </div>
                </section>
            @endif
            @if ($quickAnswers->isNotEmpty())
                <section class="mt-5 rounded-lg border border-slate-200 bg-white p-4 shadow-sm shadow-slate-200/60" aria-label="Быстрые ответы" id="quick-answers">
                    <div class="flex items-center gap-2 text-sm font-bold text-slate-700">
                        <i class="fa-solid fa-circle-question text-emerald-700" aria-hidden="true"></i>
                        <span>Быстрые ответы</span>
                    </div>
                    <div class="mt-3 grid gap-3 lg:grid-cols-3">
                        @foreach ($quickAnswers as $item)
                            <article class="rounded-lg border border-slate-200 bg-slate-50 p-3">
                                <h2 class="text-sm font-bold text-slate-800">{{ $item['question'] }}</h2>
                                <p class="mt-2 text-sm leading-6 text-slate-600">{{ $item['answer'] }}</p>
                            </article>
                        @endforeach
                    </div>
                </section>
            @endif
            @if (! empty($seo['keyword_clusters']))
                <section id="semantic-clusters" class="mt-5 rounded-lg border border-slate-200 bg-white p-4 shadow-sm shadow-slate-200/60" aria-label="Семантические кластеры">
                    <div class="flex items-center gap-2 text-sm font-bold text-slate-700">
                        <i class="fa-solid fa-diagram-project text-emerald-700" aria-hidden="true"></i>
                        <span>Семантические подборки</span>
                    </div>
                    <div class="mt-3 grid gap-3 md:grid-cols-3">
                        @foreach (collect($seo['keyword_clusters'])->filter()->take(6) as $cluster)
                            <div class="rounded-lg bg-slate-50 p-3 ring-1 ring-slate-200">
                                <div class="text-xs font-bold uppercase tracking-wide text-slate-500">{{ $cluster['title'] }}</div>
                                <div class="mt-2 flex flex-wrap gap-1.5">
                                    @foreach (collect($cluster['items'] ?? [])->filter()->unique()->take(8) as $item)
                                        <a href="{{ $seoSearchUrl($item) }}" class="rounded-full bg-white px-2 py-1 text-xs font-semibold text-slate-600 ring-1 ring-slate-200 hover:bg-emerald-50 hover:text-emerald-700">{{ $item }}</a>
                                    @endforeach
                                </div>
                            </div>
                        @endforeach
                    </div>
                </section>
            @endif
            @if (! empty($seo['search_phrases']))
                <section id="popular-searches" class="mt-5 rounded-lg border border-slate-200 bg-white p-4 shadow-sm shadow-slate-200/60" aria-label="Популярные поисковые запросы">
                    <div class="flex items-center gap-2 text-sm font-bold text-slate-700">
                        <i class="fa-solid fa-magnifying-glass-chart text-emerald-700" aria-hidden="true"></i>
                        <span>Популярные запросы</span>
                    </div>
                    <div class="mt-3 flex flex-wrap gap-2">
                        @foreach (collect($seo['search_phrases'])->filter()->unique()->take(18) as $phrase)
                            <a href="{{ $seoSearchUrl($phrase) }}" class="inline-flex items-center gap-1 rounded-full bg-slate-50 px-2.5 py-1 text-xs font-semibold text-slate-600 ring-1 ring-slate-200 hover:bg-emerald-50 hover:text-emerald-700">
                                <i class="fa-solid fa-key text-[0.8em] text-slate-400" aria-hidden="true"></i>
                                <span>{{ $phrase }}</span>
                            </a>
                        @endforeach
                    </div>
                </section>
            @endif
        </main>
        @if (request()->routeIs('stats'))
            @livewireScripts
        @endif
    </body>
</html>
