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
    $breadcrumbs = collect($seo['breadcrumbs'] ?? [])->filter(fn ($item) => is_array($item) && ! empty($item['name']) && ! empty($item['url']))->values();

    if ($seoImage && ! \Illuminate\Support\Str::startsWith($seoImage, ['http://', 'https://'])) {
        $seoImage = url($seoImage);
    }

    $jsonLdItems = $seo['jsonLd'] ?? [];

    if ($jsonLdItems !== [] && ! array_is_list($jsonLdItems)) {
        $jsonLdItems = [$jsonLdItems];
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
        @if (! empty($seo['keywords']))
            <meta name="keywords" content="{{ $seo['keywords'] }}">
        @endif
        <meta name="theme-color" content="#ecfdf5">
        <link rel="canonical" href="{{ $canonicalUrl }}">
        <link rel="alternate" hreflang="ru" href="{{ $canonicalUrl }}">
        <link rel="alternate" hreflang="x-default" href="{{ $canonicalUrl }}">
        <link rel="sitemap" type="application/xml" href="{{ route('sitemap.index') }}">
        <link rel="alternate" type="application/rss+xml" title="{{ $siteName }}" href="{{ route('feed') }}">
        <link rel="search" type="application/opensearchdescription+xml" title="{{ $siteName }}" href="{{ route('opensearch') }}">
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
        </main>
    </body>
</html>
