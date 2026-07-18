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
        <link rel="alternate" type="text/plain" title="{{ __('catalog.layout.llms_document') }}" href="{{ $layoutHeadUrls['llms'] }}">
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
        <meta name="csrf-token" content="{{ csrf_token() }}">
        @stack('head')
        @vite('resources/js/app.js')
    </head>
    <body @class([
        'app-shell bg-slate-50 text-slate-700 antialiased',
        'account-reduced-motion' => $accountReducedMotion ?? false,
    ]) data-account-settings-version="{{ $accountSettingsVersion ?? 1 }}" data-account-storage-key="{{ $accountAnonymousStorageKey ?? '' }}" @if ($isPrivatePage) data-private-page="1" @endif @if (($accountPreferenceMigrationUrl ?? null) !== null) data-account-migration-url="{{ $accountPreferenceMigrationUrl }}" data-account-migration-scope="{{ $accountPreferenceMigrationScope }}" @endif>
        <a href="#main-content" data-skip-link class="fixed z-[100] -translate-y-24 rounded-control bg-emerald-700 px-4 py-3 font-bold text-white shadow-lg transition focus:translate-y-0">
            {{ __('catalog.layout.skip_to_content') }}
        </a>

        <x-layout.site-header :site-name="$siteName" :search-query="$layoutSearchQuery" :header="$layoutHeader" />

        <main id="main-content" class="app-safe-inline app-shell-main mx-auto max-w-[1760px] py-4 sm:py-6" itemscope itemtype="https://schema.org/WebPageElement" itemid="{{ $canonicalUrl }}#main-content">
            @if ($showBreadcrumbs)
                <nav aria-label="{{ __('catalog.layout.breadcrumbs') }}" class="mb-4 rounded-panel border border-slate-200 bg-white px-3 py-2 text-sm shadow-panel">
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

        <div data-connection-status hidden role="status" aria-live="polite" aria-atomic="true" class="fixed inset-x-3 z-[90] mx-auto max-w-lg rounded-control border border-slate-200 bg-white px-4 py-3 text-sm font-bold text-slate-800 shadow-panel sm:inset-x-6">
            <span data-connection-offline hidden class="items-center gap-2">
                <x-ui.icon name="fa-solid fa-wifi text-rose-700" />
                <span>{{ __('mobile.network.offline') }}</span>
            </span>
            <span data-connection-restored hidden class="items-center gap-2">
                <x-ui.icon name="fa-solid fa-circle-check text-emerald-700" />
                <span>{{ __('mobile.network.restored') }}</span>
            </span>
        </div>
        <p data-route-announcer data-route-announcement="{{ __('mobile.navigation.page_loaded') }}" class="sr-only" role="status" aria-live="polite" aria-atomic="true"></p>
        @livewireScripts
    </body>
</html>
