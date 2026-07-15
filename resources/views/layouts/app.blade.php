<!DOCTYPE html>
<html lang="{{ $htmlLang }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
        <meta name="robots" content="{{ $robots }}">
        <meta name="description" content="{{ $seoDescription }}">
        <meta name="application-name" content="{{ $siteName }}">
        <meta name="theme-color" content="#ecfdf5">
        @if ($seoSection !== null)
            <meta property="article:section" content="{{ $seoSection }}">
        @endif
        <link rel="canonical" href="{{ $canonicalUrl }}">
        @foreach ($alternateUrls as $alternate)
            <link rel="alternate" hreflang="{{ $alternate['hreflang'] }}" href="{{ $alternate['url'] }}">
        @endforeach
        <link rel="sitemap" type="application/xml" href="{{ $layoutHeadUrls['sitemap'] }}">
        <link rel="sitemap" type="application/xml" href="{{ $layoutHeadUrls['landing_sitemap'] }}">
        <link rel="alternate" type="application/rss+xml" title="{{ $siteName }}" href="{{ $layoutHeadUrls['feed'] }}">
        <link rel="search" type="application/opensearchdescription+xml" title="{{ $siteName }}" href="{{ $layoutHeadUrls['opensearch'] }}">
        <link rel="alternate" type="text/plain" title="LLMs" href="{{ $layoutHeadUrls['llms'] }}">
        <meta name="referrer" content="strict-origin-when-cross-origin">
        @if ($previousPageUrl !== null)
            <link rel="prev" href="{{ $previousPageUrl }}">
        @endif
        @if ($nextPageUrl !== null)
            <link rel="next" href="{{ $nextPageUrl }}">
        @endif
        @if ($showSocialMetadata)
            <meta property="og:locale" content="{{ $seoLocale }}">
            <meta property="og:site_name" content="{{ $siteName }}">
            <meta property="og:type" content="{{ $seoType }}">
            <meta property="og:title" content="{{ $fullTitle }}">
            <meta property="og:description" content="{{ $seoDescription }}">
            <meta property="og:url" content="{{ $canonicalUrl }}">
            @if ($seoImage)
                <link rel="image_src" href="{{ $seoImage }}">
                <meta property="og:image" content="{{ $seoImage }}">
                <meta property="og:image:alt" content="{{ $seoImageAlt }}">
                <meta name="twitter:card" content="summary_large_image">
                <meta name="twitter:image" content="{{ $seoImage }}">
            @else
                <meta name="twitter:card" content="summary">
            @endif
            @if ($seoVideo)
                <meta property="og:video" content="{{ $seoVideo }}">
                <meta property="og:video:secure_url" content="{{ $seoVideo }}">
            @endif
        @endif
        @if ($publishedTime !== null)
            <meta property="article:published_time" content="{{ $publishedTime }}">
        @endif
        @if ($updatedTime !== null)
            <meta name="last-modified" content="{{ $updatedTime }}">
            @if ($showSocialMetadata)
                <meta property="og:updated_time" content="{{ $updatedTime }}">
            @endif
            <meta property="article:modified_time" content="{{ $updatedTime }}">
        @endif
        @if ($showSocialMetadata)
            <meta name="twitter:title" content="{{ $fullTitle }}">
            <meta name="twitter:description" content="{{ $seoDescription }}">
        @endif
        <title>{{ $fullTitle }}</title>
        @foreach ($jsonLdScripts as $jsonLd)
            <script type="application/ld+json">{!! $jsonLd !!}</script>
        @endforeach
        @livewireStyles
        @stack('head')
        @vite('resources/js/app.js')
    </head>
    <body class="min-h-screen bg-slate-50 text-slate-700 antialiased">
        <a href="#main-content" class="fixed left-3 top-3 z-[100] -translate-y-24 rounded-control bg-emerald-700 px-4 py-3 font-bold text-white shadow-lg transition focus:translate-y-0">
            Перейти к содержанию
        </a>

        <x-layout.site-header :site-name="$siteName" :search-query="$layoutSearchQuery" :header="$layoutHeader" />

        <main id="main-content" class="mx-auto max-w-[1760px] px-3 py-4 sm:px-6 sm:py-6 lg:px-8" itemscope itemtype="https://schema.org/WebPageElement" itemid="{{ $canonicalUrl }}#main-content">
            @if ($showBreadcrumbs)
                <nav aria-label="Хлебные крошки" class="mb-4 rounded-panel border border-slate-200 bg-white px-3 py-2 text-sm shadow-panel">
                    <ol class="flex flex-wrap items-center gap-2 text-slate-500">
                        @foreach ($breadcrumbs as $breadcrumb)
                            <li class="inline-flex min-w-0 items-center gap-2">
                                @if (! $loop->first)
                                    <x-ui.icon name="fa-solid fa-chevron-right text-[0.7em] text-slate-300" />
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
        </main>
        <x-layout.site-footer :site-name="$siteName" :footer="$layoutFooter" />
        @livewireScripts
    </body>
</html>
