@props(['siteName', 'searchQuery' => '', 'header'])

<header data-site-header {{ $attributes->class(['border-b border-slate-200 bg-white shadow-panel lg:sticky lg:top-0 lg:z-50']) }}>
    <div data-site-header-primary class="mx-auto flex max-w-[1760px] min-w-0 items-center gap-3 px-3 py-3 sm:px-6 lg:px-8">
        <a href="{{ $header['home_url'] }}" class="flex min-w-11 shrink-0 items-center gap-3 rounded-control sm:min-w-0 sm:max-w-48 lg:max-w-72">
            <span class="grid h-11 w-11 shrink-0 place-items-center rounded-control bg-emerald-50 text-lg text-emerald-700">
                <x-ui.icon name="fa-solid fa-film" />
            </span>
            <span class="hidden min-w-0 break-words text-base font-black tracking-tight text-slate-800 sm:block lg:text-lg">{{ $siteName }}</span>
        </a>

        <form action="{{ $header['search_url'] }}" method="GET" role="search" aria-label="{{ __('catalog.layout.search_label') }}" class="flex min-w-0 flex-1 items-start gap-2">
            <x-form.search-field
                id="site-search"
                name="q"
                :value="$searchQuery"
                :label="__('catalog.layout.search_label')"
                :placeholder="__('catalog.layout.search_placeholder')"
                container-class="min-w-0 flex-1"
                input-class="min-h-11 min-w-0 flex-1 border-0 bg-transparent px-3 py-2.5 text-sm text-slate-700 outline-none placeholder:text-slate-500"
            />
            <button type="submit" class="inline-flex min-h-11 min-w-11 shrink-0 items-center justify-center gap-2 rounded-control bg-emerald-700 px-4 py-2.5 text-sm font-bold text-white hover:bg-emerald-600">
                <x-ui.icon name="fa-solid fa-magnifying-glass" />
                <span class="sr-only sm:not-sr-only">{{ __('catalog.layout.search_action') }}</span>
            </button>
        </form>
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
            <div aria-label="{{ __('catalog.locale.switcher') }}" class="flex min-h-11 items-center gap-1 rounded-control border border-slate-200 bg-slate-50 p-1" role="group">
                @foreach ($header['locales'] as $locale)
                    @if ($locale['active'])
                        <span aria-current="true" class="inline-flex min-h-9 items-center rounded-control bg-white px-2.5 py-1 text-emerald-700 shadow-sm" title="{{ __('catalog.locale.active', ['language' => $locale['label']]) }}">
                            {{ str($locale['code'])->upper() }}
                        </span>
                    @else
                        <form action="{{ $header['locale_switch_url'] }}" method="POST">
                            @csrf
                            <input type="hidden" name="locale" value="{{ $locale['code'] }}">
                            <input type="hidden" name="return_to" value="{{ $locale['return_to'] }}">
                            <button type="submit" class="inline-flex min-h-9 items-center rounded-control px-2.5 py-1 text-slate-600 transition hover:bg-white hover:text-emerald-700" aria-label="{{ __('catalog.locale.switch_to', ['language' => $locale['label']]) }}" title="{{ __('catalog.locale.switch_to', ['language' => $locale['label']]) }}">
                                {{ str($locale['code'])->upper() }}
                            </button>
                        </form>
                    @endif
                @endforeach
            </div>
        </nav>
    </div>
</header>
