@props(['siteName', 'searchQuery' => '', 'header'])

<header
    data-site-header
    data-compact="false"
    {{ $attributes->class(['sticky top-0 z-50 border-b border-slate-200 bg-white shadow-elevated']) }}
>
    <div data-site-header-row class="app-safe-inline mx-auto flex min-h-16 max-w-[1760px] min-w-0 items-center gap-2 py-2 lg:gap-3 lg:py-3">
        <a
            data-site-header-brand
            href="{{ $header['home_url'] }}"
            aria-label="{{ $siteName }}"
            class="group flex min-h-11 min-w-11 shrink-0 items-center gap-2 rounded-control px-1 focus-visible:outline-none focus-visible:ring-4 focus-visible:ring-emerald-200"
        >
            <span class="grid h-9 w-9 shrink-0 place-items-center rounded-control bg-emerald-700 text-base text-white transition group-hover:bg-emerald-800">
                <x-ui.icon name="fa-solid fa-play" />
            </span>
            <span data-site-header-wordmark class="hidden text-lg font-semibold tracking-tight text-slate-900 sm:block">{{ $header['brand_name'] }}</span>
        </a>

        <nav
            data-site-header-primary-navigation
            aria-label="{{ __('catalog.layout.primary_navigation') }}"
            class="hidden shrink-0 items-stretch gap-0.5 lg:flex"
        >
            @foreach ($header['primary_navigation'] as $item)
                <a
                    data-desktop-primary-item
                    href="{{ $item->url }}"
                    @class([
                        'relative inline-flex min-h-11 items-center justify-center rounded-control border-b-2 px-2.5 py-2 text-sm transition xl:px-3',
                        'border-emerald-700 font-semibold text-slate-900' => $item->ariaCurrent !== null,
                        'border-transparent font-medium text-slate-700 hover:bg-slate-100 hover:text-slate-900' => $item->ariaCurrent === null,
                    ])
                    @if ($item->ariaCurrent !== null) aria-current="{{ $item->ariaCurrent }}" @endif
                >
                    <span>{{ $item->label }}</span>
                </a>
            @endforeach

            <details data-site-header-more data-header-menu class="group relative">
                <summary
                    @class([
                        'flex min-h-11 list-none items-center gap-2 rounded-control border-b-2 px-2.5 py-2 text-sm marker:hidden xl:px-3',
                        'border-emerald-700 font-semibold text-slate-900' => $header['more_active'],
                        'border-transparent font-medium text-slate-700 hover:bg-slate-100 hover:text-slate-900' => ! $header['more_active'],
                    ])
                >
                    <span>{{ __('catalog.layout.more') }}</span>
                    <x-ui.icon name="fa-solid fa-chevron-down text-slate-600 transition group-open:rotate-180" />
                </summary>
                <div class="absolute left-0 top-[calc(100%+0.5rem)] z-[70] min-w-56 rounded-control border border-slate-200 bg-white p-2 shadow-elevated">
                    @foreach ($header['more_navigation'] as $item)
                        <a href="{{ $item->url }}" class="{{ $item->className }} w-full justify-start" @if ($item->ariaCurrent !== null) aria-current="{{ $item->ariaCurrent }}" @endif>
                            <x-ui.icon :name="$item->icon" />
                            <span>{{ $item->label }}</span>
                        </a>
                    @endforeach
                </div>
            </details>
        </nav>

        <x-layout.header-search
            :initial-query="$searchQuery"
            :search-url="$header['search_url']"
            :catalog-search-url="$header['catalog_search_url']"
            :request-create-url="$header['request_create_url']"
            class="hidden lg:block"
        />

        <button
            type="button"
            data-header-search-open
            data-mobile-search-action
            class="ml-auto grid min-h-11 min-w-11 place-items-center rounded-control text-slate-700 transition hover:bg-slate-100 hover:text-emerald-800 lg:hidden"
            aria-label="{{ __('catalog.header_search.open') }}"
            aria-controls="site-search-dialog"
        >
            <x-ui.icon name="fa-solid fa-magnifying-glass" />
        </button>

        <div data-site-header-actions class="flex shrink-0 items-center gap-1">
            @if ($header['notification_action'] !== null)
                <a
                    href="{{ $header['notification_action']->url }}"
                    aria-label="{{ $header['notification_action']->label }}"
                    class="{{ $header['notification_action']->className }} hidden px-2 lg:inline-flex"
                    @if ($header['notification_action']->ariaCurrent !== null) aria-current="{{ $header['notification_action']->ariaCurrent }}" @endif
                >
                    <x-ui.icon :name="$header['notification_action']->icon" />
                </a>
            @endif

            <details data-header-account-menu data-header-menu class="group relative">
                <summary
                    class="flex min-h-11 min-w-11 list-none items-center justify-center gap-2 rounded-control px-1 text-slate-700 marker:hidden hover:bg-slate-100 hover:text-slate-900 lg:px-2"
                    aria-label="{{ $header['account_label'] }}"
                >
                    <span class="grid h-9 w-9 shrink-0 place-items-center rounded-full bg-slate-100 text-sm font-semibold text-slate-700">
                        @if ($header['account_initial'] !== null)
                            {{ $header['account_initial'] }}
                        @else
                            <x-ui.icon name="fa-regular fa-user" />
                        @endif
                    </span>
                    <x-ui.icon name="fa-solid fa-chevron-down hidden text-xs text-slate-600 transition group-open:rotate-180 lg:inline-block" />
                </summary>
                <div class="absolute right-0 top-[calc(100%+0.5rem)] z-[70] w-72 max-w-[calc(100vw-1.5rem)] rounded-control border border-slate-200 bg-white p-2 shadow-elevated">
                    @if ($header['is_authenticated'])
                        <p class="break-words border-b border-slate-200 px-3 py-2 text-sm font-semibold text-slate-900">{{ $header['account_label'] }}</p>
                    @endif
                    <nav aria-label="{{ __('catalog.layout.account_navigation') }}" class="py-1">
                        @foreach ($header['account_navigation'] as $item)
                            <a href="{{ $item->url }}" class="{{ $item->className }} w-full justify-start" @if ($item->ariaCurrent !== null) aria-current="{{ $item->ariaCurrent }}" @endif>
                                <x-ui.icon :name="$item->icon" />
                                <span class="min-w-0 break-words">{{ $item->label }}</span>
                            </a>
                        @endforeach
                    </nav>
                    @if ($header['show_logout'])
                        <div class="border-t border-slate-200 pt-1">
                            <livewire:auth.logout-button />
                        </div>
                    @endif
                </div>
            </details>
        </div>
    </div>
</header>

<nav
    data-mobile-bottom-navigation
    aria-label="{{ __('mobile.navigation.primary') }}"
    class="fixed inset-x-0 bottom-0 z-50 grid grid-cols-5 border-t border-slate-200 bg-white shadow-elevated lg:hidden"
>
    @foreach (['home', 'catalog'] as $key)
        <a
            data-mobile-navigation-item
            href="{{ $header['mobile_navigation'][$key]->url }}"
            @class([
                'flex min-h-14 min-w-0 flex-col items-center justify-center gap-0.5 px-1 pt-1 text-[0.6875rem] font-semibold',
                'bg-emerald-50 text-emerald-800' => $header['mobile_navigation'][$key]->ariaCurrent !== null,
                'text-slate-600' => $header['mobile_navigation'][$key]->ariaCurrent === null,
            ])
            @if ($header['mobile_navigation'][$key]->ariaCurrent !== null) aria-current="{{ $header['mobile_navigation'][$key]->ariaCurrent }}" @endif
        >
            <x-ui.icon :name="$header['mobile_navigation'][$key]->icon" />
            <span class="max-w-full">{{ $header['mobile_navigation'][$key]->label }}</span>
        </a>
    @endforeach

    <button
        type="button"
        data-mobile-navigation-item
        data-header-search-open
        data-mobile-search-action
        @class([
            'flex min-h-14 min-w-0 flex-col items-center justify-center gap-0.5 px-1 pt-1 text-[0.6875rem] font-semibold',
            'bg-emerald-50 text-emerald-800' => $header['search_active'],
            'text-slate-600' => ! $header['search_active'],
        ])
        aria-label="{{ __('catalog.navigation.search') }}"
        aria-controls="site-search-dialog"
        @if ($header['search_active']) aria-current="page" @endif
    >
        <x-ui.icon name="fa-solid fa-magnifying-glass" />
        <span>{{ __('catalog.navigation.search') }}</span>
    </button>

    @foreach (['calendar', 'library'] as $key)
        <a
            data-mobile-navigation-item
            href="{{ $header['mobile_navigation'][$key]->url }}"
            @class([
                'flex min-h-14 min-w-0 flex-col items-center justify-center gap-0.5 px-1 pt-1 text-[0.6875rem] font-semibold',
                'bg-emerald-50 text-emerald-800' => $header['mobile_navigation'][$key]->ariaCurrent !== null,
                'text-slate-600' => $header['mobile_navigation'][$key]->ariaCurrent === null,
            ])
            @if ($header['mobile_navigation'][$key]->ariaCurrent !== null) aria-current="{{ $header['mobile_navigation'][$key]->ariaCurrent }}" @endif
        >
            <x-ui.icon :name="$header['mobile_navigation'][$key]->icon" />
            <span class="max-w-full">{{ $header['mobile_navigation'][$key]->label }}</span>
        </a>
    @endforeach
</nav>
