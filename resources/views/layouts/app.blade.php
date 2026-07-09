@php
    $siteName = config('app.name', 'Каталог сериалов');
    $seo = $seo ?? [];
    $pageTitle = trim((string) ($seo['title'] ?? $title ?? $siteName));
    $pageTitle = $pageTitle !== '' ? $pageTitle : $siteName;
    $fullTitle = \Illuminate\Support\Str::contains(\Illuminate\Support\Str::lower($pageTitle), \Illuminate\Support\Str::lower($siteName))
        ? $pageTitle
        : $pageTitle.' - '.$siteName;
    $seoDescription = trim((string) ($seo['description'] ?? 'Каталог сериалов онлайн с фильтрами по жанрам, странам, актерам, годам, сезонам и сериям.'));
    $seoDescription = \Illuminate\Support\Str::limit(preg_replace('/\s+/u', ' ', strip_tags($seoDescription)) ?: $seoDescription, 180, '...');
    $canonicalUrl = $seo['canonical'] ?? url()->current();
    $robots = $seo['robots'] ?? 'index,follow,max-image-preview:large,max-snippet:-1,max-video-preview:-1';
    $seoType = $seo['type'] ?? 'website';
    $seoImage = $seo['image'] ?? null;
    $seoVideo = $seo['video'] ?? null;
    $seoLocale = $seo['locale'] ?? 'ru_RU';
    $htmlLang = $seo['htmlLang'] ?? 'ru';
    $seoTags = collect($seo['tags'] ?? [])->filter()->unique()->take(25)->values();
    $seoClusterTerms = collect($seo['keyword_clusters'] ?? [])
        ->flatMap(fn ($cluster) => collect($cluster['items'] ?? []));
    $topicTerms = collect($seo['topic_terms'] ?? [])
        ->merge($seoTags)
        ->merge($seo['search_phrases'] ?? [])
        ->merge($seoClusterTerms)
        ->filter()
        ->map(fn ($term) => trim((string) $term))
        ->filter()
        ->unique()
        ->take(40)
        ->values();
    $seoIntents = collect([
        'смотреть онлайн',
        'сериал онлайн',
        'все серии',
        'сезоны и серии',
        'описание сериала',
        'актеры и роли',
        'жанры и страны',
    ])->merge($topicTerms->take(12))->unique()->values();
    $semanticEntities = $topicTerms->take(24)->map(fn ($term) => [
        '@type' => 'Thing',
        'name' => $term,
        'url' => route('titles.index', ['q' => $term]),
    ])->values();
    $longTailQueries = $topicTerms->take(10)
        ->flatMap(fn ($term) => collect([
            $term.' смотреть онлайн',
            $term.' сериал онлайн',
            $term.' все серии',
            $term.' сезоны и серии',
            $term.' описание и актеры',
        ]))
        ->merge($seoIntents->take(8)->map(fn ($intent) => trim($pageTitle.' '.$intent)))
        ->map(fn ($query) => trim(preg_replace('/\s+/u', ' ', (string) $query)))
        ->filter(fn ($query) => $query !== '' && mb_strlen($query) <= 100)
        ->unique()
        ->take(40)
        ->values();
    $relatedCollections = $topicTerms->take(8)
        ->flatMap(fn ($term) => collect([
            [
                'name' => 'Сериалы по теме: '.$term,
                'query' => $term.' сериалы',
                'description' => 'Связанная подборка сериалов и страниц каталога по теме «'.$term.'».',
            ],
            [
                'name' => $term.' смотреть онлайн',
                'query' => $term.' смотреть онлайн',
                'description' => 'Поиск страниц, сезонов, серий и описаний по запросу «'.$term.' смотреть онлайн».',
            ],
            [
                'name' => $term.' актеры и описание',
                'query' => $term.' актеры описание',
                'description' => 'Подборка материалов с описанием, актерами, ролями и связанными сериалами по теме «'.$term.'».',
            ],
        ]))
        ->merge($seoIntents->take(6)->map(fn ($intent) => [
            'name' => $pageTitle.' - '.$intent,
            'query' => $pageTitle.' '.$intent,
            'description' => 'Связанная поисковая подборка по запросу «'.$pageTitle.' '.$intent.'».',
        ]))
        ->filter(fn ($item) => ! empty($item['name']) && ! empty($item['query']))
        ->unique('name')
        ->take(30)
        ->values();
    $quickAnswers = collect([
        [
            'question' => 'Что есть на странице «'.$pageTitle.'»?',
            'answer' => $seoDescription,
        ],
        [
            'question' => 'Как найти похожие сериалы и подборки?',
            'answer' => $topicTerms->isNotEmpty()
                ? 'Используйте связанные темы: '.$topicTerms->take(8)->implode(', ').'.'
                : 'Используйте поиск, жанры, страны, годы выпуска, актеров и режиссеров в каталоге.',
        ],
        [
            'question' => 'Какие запросы связаны с этой страницей?',
            'answer' => $seoIntents->isNotEmpty()
                ? $seoIntents->take(10)->implode(', ').'.'
                : 'Сериалы онлайн, все серии, сезоны, описание, актеры и жанры.',
        ],
    ])->filter(fn ($item) => ! empty($item['question']) && ! empty($item['answer']))->values();
    $seoSections = collect([
        ['id' => 'seo-summary', 'name' => 'Описание страницы', 'enabled' => ! empty($seo['seo_text']) || ! empty($seo['related_links'])],
        ['id' => 'key-topics', 'name' => 'Ключевые темы', 'enabled' => $topicTerms->isNotEmpty()],
        ['id' => 'query-navigation', 'name' => 'Навигация по запросам', 'enabled' => $seoIntents->isNotEmpty()],
        ['id' => 'long-tail-queries', 'name' => 'Поисковые формулировки', 'enabled' => $longTailQueries->isNotEmpty()],
        ['id' => 'related-collections', 'name' => 'Связанные подборки', 'enabled' => $relatedCollections->isNotEmpty()],
        ['id' => 'quick-answers', 'name' => 'Быстрые ответы', 'enabled' => $quickAnswers->isNotEmpty()],
        ['id' => 'semantic-clusters', 'name' => 'Семантические подборки', 'enabled' => ! empty($seo['keyword_clusters'])],
        ['id' => 'popular-searches', 'name' => 'Популярные запросы', 'enabled' => ! empty($seo['search_phrases'])],
    ])->filter(fn ($section) => $section['enabled'])->values();
    $breadcrumbs = collect($seo['breadcrumbs'] ?? [])->filter(fn ($item) => is_array($item) && ! empty($item['name']) && ! empty($item['url']))->values();

    if ($seoImage && ! \Illuminate\Support\Str::startsWith($seoImage, ['http://', 'https://'])) {
        $seoImage = url($seoImage);
    }

    $jsonLdItems = $seo['jsonLd'] ?? [];

    if ($jsonLdItems !== [] && ! array_is_list($jsonLdItems)) {
        $jsonLdItems = [$jsonLdItems];
    }

    if ($topicTerms->isNotEmpty()) {
        $jsonLdItems[] = [
            '@context' => 'https://schema.org',
            '@type' => 'DefinedTermSet',
            'name' => $fullTitle.' - ключевые темы',
            'hasDefinedTerm' => $topicTerms->take(35)->map(fn ($term) => [
                '@type' => 'DefinedTerm',
                'name' => $term,
                'url' => route('titles.index', ['q' => $term]),
            ])->values()->all(),
        ];
        $jsonLdItems[] = [
            '@context' => 'https://schema.org',
            '@type' => 'ItemList',
            'name' => $fullTitle.' - тематическая навигация',
            'itemListElement' => $topicTerms->take(30)->values()->map(fn ($term, $index) => [
                '@type' => 'ListItem',
                'position' => $index + 1,
                'name' => $term,
                'url' => route('titles.index', ['q' => $term]),
            ])->values()->all(),
        ];
        $jsonLdItems[] = [
            '@context' => 'https://schema.org',
            '@type' => 'WebPage',
            '@id' => $canonicalUrl.'#semantic-page',
            'url' => $canonicalUrl,
            'name' => $fullTitle,
            'description' => $seoDescription,
            'inLanguage' => 'ru',
            'keywords' => $topicTerms->take(30)->implode(', '),
            'about' => $semanticEntities->take(8)->values()->all(),
            'mentions' => $semanticEntities->slice(8)->values()->all(),
            'isPartOf' => [
                '@type' => 'WebSite',
                'name' => $siteName,
                'url' => route('home'),
            ],
        ];
    }

    if ($quickAnswers->isNotEmpty()) {
        $jsonLdItems[] = [
            '@context' => 'https://schema.org',
            '@type' => 'FAQPage',
            '@id' => $canonicalUrl.'#quick-answers',
            'mainEntity' => $quickAnswers->map(fn ($item) => [
                '@type' => 'Question',
                'name' => $item['question'],
                'acceptedAnswer' => [
                    '@type' => 'Answer',
                    'text' => $item['answer'],
                ],
            ])->values()->all(),
        ];
    }

    if ($longTailQueries->isNotEmpty()) {
        $jsonLdItems[] = [
            '@context' => 'https://schema.org',
            '@type' => 'ItemList',
            '@id' => $canonicalUrl.'#long-tail-queries-list',
            'name' => $fullTitle.' - поисковые формулировки',
            'itemListElement' => $longTailQueries->take(35)->values()->map(fn ($query, $index) => [
                '@type' => 'ListItem',
                'position' => $index + 1,
                'name' => $query,
                'url' => route('titles.index', ['q' => $query]),
            ])->values()->all(),
        ];
    }

    if ($relatedCollections->isNotEmpty()) {
        $jsonLdItems[] = [
            '@context' => 'https://schema.org',
            '@type' => 'CollectionPage',
            '@id' => $canonicalUrl.'#related-collections-schema',
            'name' => $fullTitle.' - связанные подборки',
            'url' => $canonicalUrl.'#related-collections',
            'description' => 'Автоматические тематические подборки и внутренние ссылки по странице «'.$pageTitle.'».',
            'hasPart' => $relatedCollections->take(24)->map(fn ($collection) => [
                '@type' => 'CollectionPage',
                'name' => $collection['name'],
                'description' => $collection['description'] ?? $collection['name'],
                'url' => route('titles.index', ['q' => $collection['query']]),
            ])->values()->all(),
        ];
    }

    if ($seoSections->isNotEmpty()) {
        $jsonLdItems[] = [
            '@context' => 'https://schema.org',
            '@type' => 'ItemList',
            '@id' => $canonicalUrl.'#table-of-contents',
            'name' => $fullTitle.' - содержание страницы',
            'itemListElement' => $seoSections->map(fn ($section, $index) => [
                '@type' => 'ListItem',
                'position' => $index + 1,
                'name' => $section['name'],
                'url' => $canonicalUrl.'#'.$section['id'],
            ])->values()->all(),
        ];
        $jsonLdItems[] = [
            '@context' => 'https://schema.org',
            '@type' => 'WebPage',
            '@id' => $canonicalUrl.'#page-sections',
            'name' => $fullTitle.' - структура страницы',
            'hasPart' => $seoSections->map(fn ($section) => [
                '@type' => 'WebPageElement',
                '@id' => $canonicalUrl.'#'.$section['id'],
                'name' => $section['name'],
                'url' => $canonicalUrl.'#'.$section['id'],
            ])->values()->all(),
        ];
    }
@endphp

<!DOCTYPE html>
<html lang="{{ $htmlLang }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="robots" content="{{ $robots }}">
        <meta name="description" content="{{ $seoDescription }}">
        <meta name="author" content="{{ $siteName }}">
        <meta name="application-name" content="{{ $siteName }}">
        <meta name="generator" content="Laravel">
        <meta name="url" content="{{ $canonicalUrl }}">
        <meta name="identifier-URL" content="{{ $canonicalUrl }}">
        <meta name="summary" content="{{ $seoDescription }}">
        <meta name="owner" content="{{ $siteName }}">
        <meta name="answer-count" content="{{ $quickAnswers->count() }}">
        <meta name="toc-count" content="{{ $seoSections->count() }}">
        <meta name="query-count" content="{{ $longTailQueries->count() }}">
        <meta name="related-collection-count" content="{{ $relatedCollections->count() }}">
        @if (! empty($seo['keywords']))
            <meta name="keywords" content="{{ $seo['keywords'] }}">
            <meta name="news_keywords" content="{{ $seo['news_keywords'] ?? $seo['keywords'] }}">
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
        @foreach ($jsonLdItems as $jsonLd)
            <script type="application/ld+json">{!! json_encode($jsonLd, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) !!}</script>
        @endforeach
        @stack('head')
        @vite(['resources/css/app.css', 'resources/js/app.js'])
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

        <main class="mx-auto max-w-[1760px] px-3 py-4 sm:px-6 sm:py-6 lg:px-8">
            @if ($breadcrumbs->count() > 1)
                <nav aria-label="Хлебные крошки" class="mb-4 overflow-x-auto rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm shadow-sm shadow-slate-200/60">
                    <ol class="flex min-w-max items-center gap-2 text-slate-500">
                        @foreach ($breadcrumbs as $breadcrumb)
                            <li class="inline-flex items-center gap-2">
                                @if (! $loop->first)
                                    <i class="fa-solid fa-chevron-right text-[0.7em] text-slate-300" aria-hidden="true"></i>
                                @endif
                                @if ($loop->last)
                                    <span class="font-semibold text-slate-700" aria-current="page">{{ $breadcrumb['name'] }}</span>
                                @else
                                    <a href="{{ $breadcrumb['url'] }}" class="font-semibold text-emerald-700 hover:text-emerald-600">{{ $breadcrumb['name'] }}</a>
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
                            <a href="{{ route('titles.index', ['q' => $term]) }}" itemprop="url" class="inline-flex items-center gap-1 rounded-full bg-amber-50 px-2.5 py-1 text-xs font-semibold text-amber-800 ring-1 ring-amber-100 hover:bg-emerald-50 hover:text-emerald-700 hover:ring-emerald-100">
                                <i class="fa-solid fa-hashtag text-[0.8em] text-amber-500" aria-hidden="true"></i>
                                <span itemprop="name">{{ $term }}</span>
                            </a>
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
                            <a href="{{ route('titles.index', ['q' => $intent]) }}" itemprop="url" class="flex items-center gap-2 rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 text-xs font-semibold text-slate-700 hover:border-emerald-100 hover:bg-emerald-50 hover:text-emerald-700">
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
                            <a href="{{ route('titles.index', ['q' => $query]) }}" itemprop="url" class="flex items-center gap-2 rounded-lg border border-slate-200 bg-white px-3 py-2 text-xs font-semibold text-slate-700 hover:border-emerald-100 hover:bg-emerald-50 hover:text-emerald-700">
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
                            <a href="{{ route('titles.index', ['q' => $collection['query']]) }}" class="block rounded-lg border border-slate-200 bg-slate-50 p-3 hover:border-emerald-100 hover:bg-emerald-50" itemprop="hasPart" itemscope itemtype="https://schema.org/CollectionPage">
                                <span class="flex items-center gap-2 text-sm font-bold text-slate-800" itemprop="name">
                                    <i class="fa-solid fa-folder-open text-[0.85em] text-emerald-700" aria-hidden="true"></i>
                                    {{ $collection['name'] }}
                                </span>
                                <span class="mt-2 block text-xs leading-5 text-slate-600" itemprop="description">{{ $collection['description'] }}</span>
                                <meta itemprop="url" content="{{ route('titles.index', ['q' => $collection['query']]) }}">
                            </a>
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
                                        <a href="{{ route('titles.index', ['q' => $item]) }}" class="rounded-full bg-white px-2 py-1 text-xs font-semibold text-slate-600 ring-1 ring-slate-200 hover:bg-emerald-50 hover:text-emerald-700">{{ $item }}</a>
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
                            <a href="{{ route('titles.index', ['q' => $phrase]) }}" class="inline-flex items-center gap-1 rounded-full bg-slate-50 px-2.5 py-1 text-xs font-semibold text-slate-600 ring-1 ring-slate-200 hover:bg-emerald-50 hover:text-emerald-700">
                                <i class="fa-solid fa-key text-[0.8em] text-slate-400" aria-hidden="true"></i>
                                <span>{{ $phrase }}</span>
                            </a>
                        @endforeach
                    </div>
                </section>
            @endif
        </main>
    </body>
</html>
