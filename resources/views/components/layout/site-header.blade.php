@props(['siteName', 'searchQuery' => '', 'header'])

<header data-site-header {{ $attributes->class(['border-b border-slate-200 bg-white shadow-panel lg:sticky lg:top-0 lg:z-50']) }}>
    <div data-site-header-primary class="app-safe-inline mx-auto flex max-w-[1760px] min-w-0 flex-wrap items-center gap-2 py-3 sm:gap-3">
        <a href="{{ $header['home_url'] }}" aria-label="{{ $siteName }}" class="flex min-w-11 shrink-0 items-center gap-3 rounded-control sm:min-w-0 sm:max-w-48 lg:max-w-72">
            <span class="grid h-11 w-11 shrink-0 place-items-center rounded-control bg-emerald-50 text-lg text-emerald-700">
                <x-ui.icon name="fa-solid fa-film" />
            </span>
            <span class="hidden min-w-0 break-words text-base font-black tracking-tight text-slate-800 sm:block lg:text-lg">{{ $siteName }}</span>
        </a>

        <x-layout.header-search :initial-query="$searchQuery" :search-url="$header['search_url']" class="order-3 basis-full sm:order-none sm:basis-auto" />

        <div data-site-header-actions class="order-2 ml-auto flex shrink-0 items-center gap-1.5 sm:order-none">
            @foreach ($header['actions'] as $item)
                <a href="{{ $item->url }}" class="{{ $item->className }}" @if ($item->ariaCurrent !== null) aria-current="{{ $item->ariaCurrent }}" @endif>
                    <x-ui.icon :name="$item->icon" />
                    <span class="sr-only sm:not-sr-only">{{ $item->label }}</span>
                </a>
            @endforeach
            @if ($header['show_logout'])
                <livewire:auth.logout-button />
            @endif
        </div>
    </div>

    <div class="border-t border-slate-200 bg-slate-50">
        <div class="app-safe-inline mx-auto max-w-[1760px] py-2">
            <details data-mobile-navigation class="group sm:hidden">
                <summary class="flex min-h-11 list-none items-center justify-between gap-3 rounded-control bg-white px-3 py-2 text-sm font-black text-slate-700 marker:hidden">
                    <span class="inline-flex items-center gap-2">
                        <x-ui.icon name="fa-solid fa-bars text-emerald-700" />
                        <span>{{ __('mobile.navigation.menu') }}</span>
                    </span>
                    <x-ui.icon name="fa-solid fa-chevron-down text-slate-400 transition group-open:rotate-180" />
                </summary>
                <nav data-site-header-navigation aria-label="{{ __('catalog.layout.primary_navigation') }}" class="mt-2 grid grid-cols-1 gap-1.5 text-sm font-bold">
                    @foreach ($header['navigation'] as $item)
                        <a href="{{ $item->url }}" class="{{ $item->className }} min-w-0 justify-start px-2" @if ($item->ariaCurrent !== null) aria-current="{{ $item->ariaCurrent }}" @endif>
                            <x-ui.icon :name="$item->icon" />
                            <span class="min-w-0 break-words">{{ $item->label }}</span>
                        </a>
                    @endforeach
                </nav>
            </details>

            <nav data-site-header-navigation aria-label="{{ __('catalog.layout.primary_navigation') }}" class="hidden flex-wrap items-center gap-1.5 text-sm font-bold sm:flex">
                @foreach ($header['navigation'] as $item)
                    <a href="{{ $item->url }}" class="{{ $item->className }}" @if ($item->ariaCurrent !== null) aria-current="{{ $item->ariaCurrent }}" @endif>
                        <x-ui.icon :name="$item->icon" />
                        <span>{{ $item->label }}</span>
                    </a>
                @endforeach
            </nav>
        </div>
    </div>
</header>
