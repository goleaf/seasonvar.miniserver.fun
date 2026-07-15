@props(['siteName', 'searchQuery' => '', 'header'])

<header data-site-header {{ $attributes->class(['border-b border-slate-200 bg-white shadow-panel lg:sticky lg:top-0 lg:z-50']) }}>
    <div class="mx-auto grid max-w-[1760px] grid-cols-[minmax(0,1fr)_auto] items-center gap-3 px-3 py-3 sm:px-6 lg:grid-cols-[auto_minmax(280px,1fr)_auto] lg:px-8">
        <a href="{{ $header['home_url'] }}" class="order-1 flex min-w-0 items-center gap-3 rounded-control">
            <span class="grid h-11 w-11 shrink-0 place-items-center rounded-control bg-emerald-50 text-lg text-emerald-700">
                <x-ui.icon name="fa-solid fa-film" />
            </span>
            <span class="min-w-0 break-words text-lg font-black tracking-tight text-slate-800">{{ $siteName }}</span>
        </a>

        <form action="{{ $header['search_url'] }}" method="GET" role="search" aria-label="Поиск по названию" class="order-3 col-span-2 flex min-w-0 items-start gap-2 lg:order-2 lg:col-span-1 lg:mx-6">
            <x-form.search-field
                id="site-search"
                name="q"
                :value="$searchQuery"
                label="Поиск по названию"
                placeholder="Название сериала"
                container-class="min-w-0 flex-1"
                input-class="min-h-11 min-w-0 flex-1 border-0 bg-transparent px-3 py-2.5 text-sm text-slate-700 outline-none placeholder:text-slate-500"
            />
            <button type="submit" class="inline-flex min-h-11 min-w-11 shrink-0 items-center justify-center gap-2 rounded-control bg-emerald-700 px-4 py-2.5 text-sm font-bold text-white hover:bg-emerald-600">
                <x-ui.icon name="fa-solid fa-magnifying-glass" />
                <span class="sr-only sm:not-sr-only">Найти</span>
            </button>
        </form>

        <nav aria-label="Основная навигация" class="order-2 col-span-2 flex flex-wrap items-center justify-end gap-1.5 text-sm font-bold sm:col-span-1 lg:order-3">
            @foreach ($header['navigation'] as $item)
                <a href="{{ $item->url }}" class="{{ $item->className }}" @if ($item->ariaCurrent !== null) aria-current="{{ $item->ariaCurrent }}" @endif>
                    <x-ui.icon :name="$item->icon" />
                    <span class="sr-only xl:not-sr-only">{{ $item->label }}</span>
                </a>
            @endforeach
            @if ($header['show_logout'])
                <livewire:auth.logout-button />
            @endif
        </nav>
    </div>
</header>
