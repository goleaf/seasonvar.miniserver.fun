@props(['siteName', 'searchQuery' => ''])

<header data-site-header {{ $attributes->class(['border-b border-slate-200 bg-white shadow-panel lg:sticky lg:top-0 lg:z-50']) }}>
    <div class="mx-auto grid max-w-[1760px] grid-cols-[minmax(0,1fr)_auto] items-center gap-3 px-3 py-3 sm:px-6 lg:grid-cols-[auto_minmax(280px,1fr)_auto] lg:px-8">
        <a href="{{ route('home') }}" class="order-1 flex min-w-0 items-center gap-3 rounded-control">
            <span class="grid h-11 w-11 shrink-0 place-items-center rounded-control bg-emerald-50 text-lg text-emerald-700">
                <i class="fa-solid fa-film" aria-hidden="true"></i>
            </span>
            <span class="min-w-0 break-words text-lg font-black tracking-tight text-slate-800">{{ $siteName }}</span>
        </a>

        <form action="{{ route('titles.index') }}" method="GET" role="search" aria-label="Поиск по всему каталогу" class="order-3 col-span-2 flex min-w-0 items-start gap-2 lg:order-2 lg:col-span-1 lg:mx-6">
            <x-form.search-field
                id="site-search"
                name="q"
                :value="$searchQuery"
                label="Поиск по всему каталогу"
                placeholder="Название, актер или жанр"
                container-class="min-w-0 flex-1"
                input-class="min-h-11 min-w-0 flex-1 border-0 bg-transparent px-3 py-2.5 text-sm text-slate-700 outline-none placeholder:text-slate-500"
            />
            <button type="submit" class="inline-flex min-h-11 min-w-11 shrink-0 items-center justify-center gap-2 rounded-control bg-emerald-700 px-4 py-2.5 text-sm font-bold text-white hover:bg-emerald-600">
                <i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i>
                <span class="sr-only sm:not-sr-only">Найти</span>
            </button>
        </form>

        <nav aria-label="Основная навигация" class="order-2 flex items-center gap-1.5 text-sm font-bold lg:order-3">
            <a href="{{ route('home') }}" @class([
                'inline-flex min-h-11 min-w-11 items-center justify-center gap-2 rounded-control px-3 py-2',
                'bg-emerald-50 text-emerald-700' => request()->routeIs('home'),
                'text-slate-600 hover:bg-slate-50 hover:text-emerald-700' => ! request()->routeIs('home'),
            ]) @if (request()->routeIs('home')) aria-current="page" @endif>
                <i class="fa-solid fa-house" aria-hidden="true"></i>
                <span class="sr-only xl:not-sr-only">Главная</span>
            </a>
            <a href="{{ route('titles.index') }}" @class([
                'inline-flex min-h-11 min-w-11 items-center justify-center gap-2 rounded-control px-3 py-2',
                'bg-emerald-50 text-emerald-700' => request()->routeIs('titles.*'),
                'text-slate-600 hover:bg-slate-50 hover:text-emerald-700' => ! request()->routeIs('titles.*'),
            ]) @if (request()->routeIs('titles.*')) aria-current="page" @endif>
                <i class="fa-solid fa-table-cells-large" aria-hidden="true"></i>
                <span class="sr-only xl:not-sr-only">Каталог</span>
            </a>
            @auth
                <a href="{{ route('viewing-activity') }}" @class([
                    'inline-flex min-h-11 min-w-11 items-center justify-center gap-2 rounded-control px-3 py-2',
                    'bg-emerald-50 text-emerald-700' => request()->routeIs('viewing-activity'),
                    'text-slate-600 hover:bg-slate-50 hover:text-emerald-700' => ! request()->routeIs('viewing-activity'),
                ]) @if (request()->routeIs('viewing-activity')) aria-current="page" @endif>
                    <i class="fa-solid fa-clock-rotate-left" aria-hidden="true"></i>
                    <span class="sr-only xl:not-sr-only">Мои просмотры</span>
                </a>
                @can('manage-seasonvar-imports')
                    <a href="{{ route('admin.imports') }}" @class([
                        'inline-flex min-h-11 min-w-11 items-center justify-center gap-2 rounded-control px-3 py-2',
                        'bg-emerald-50 text-emerald-700' => request()->routeIs('admin.imports'),
                        'text-slate-600 hover:bg-slate-50 hover:text-emerald-700' => ! request()->routeIs('admin.imports'),
                    ]) @if (request()->routeIs('admin.imports')) aria-current="page" @endif>
                        <i class="fa-solid fa-cloud-arrow-down" aria-hidden="true"></i>
                        <span class="sr-only xl:not-sr-only">Импорт</span>
                    </a>
                @endcan
            @endauth
        </nav>
    </div>
</header>
