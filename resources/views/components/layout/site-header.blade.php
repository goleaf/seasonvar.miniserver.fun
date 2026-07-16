@props(['siteName', 'searchQuery' => '', 'header'])

<header data-site-header {{ $attributes->class(['border-b border-slate-200 bg-white shadow-panel lg:sticky lg:top-0 lg:z-50']) }}>
    <div data-site-header-primary class="mx-auto flex max-w-[1760px] min-w-0 items-center gap-3 px-3 py-3 sm:px-6 lg:px-8">
        <a href="{{ $header['home_url'] }}" aria-label="{{ $siteName }}" class="flex min-w-11 shrink-0 items-center gap-3 rounded-control sm:min-w-0 sm:max-w-48 lg:max-w-72">
            <span class="grid h-11 w-11 shrink-0 place-items-center rounded-control bg-emerald-50 text-lg text-emerald-700">
                <x-ui.icon name="fa-solid fa-film" />
            </span>
            <span class="hidden min-w-0 break-words text-base font-black tracking-tight text-slate-800 sm:block lg:text-lg">{{ $siteName }}</span>
        </a>

        <x-layout.header-search :initial-query="$searchQuery" :search-url="$header['search_url']" />
    </div>

    <div class="border-t border-slate-200 bg-slate-50">
        <nav data-site-header-navigation aria-label="{{ __('catalog.layout.primary_navigation') }}" class="mx-auto flex flex-wrap max-w-[1760px] items-center justify-center gap-1.5 px-3 py-2 text-sm font-bold sm:justify-start sm:px-6 lg:px-8">
            @foreach ($header['navigation'] as $item)
                <a href="{{ $item->url }}" class="{{ $item->className }}" @if ($item->ariaCurrent !== null) aria-current="{{ $item->ariaCurrent }}" @endif>
                    <x-ui.icon :name="$item->icon" />
                    <span class="sr-only sm:not-sr-only">{{ $item->label }}</span>
                </a>
            @endforeach
            @if ($header['show_logout'])
                <livewire:auth.logout-button />
            @endif
        </nav>
    </div>
</header>
