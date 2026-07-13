@props(['siteName'])

<footer data-site-footer {{ $attributes->class(['mt-10 border-t border-slate-200 bg-white']) }}>
    <div class="mx-auto max-w-[1760px] px-3 sm:px-6 lg:px-8">
        <div class="grid gap-8 py-8 md:grid-cols-2 md:py-10 xl:grid-cols-[minmax(0,1.5fr)_minmax(0,0.75fr)_minmax(0,0.75fr)] xl:gap-12">
            <div data-site-footer-brand class="min-w-0 md:col-span-2 xl:col-span-1 xl:max-w-xl">
                <a href="{{ route('home') }}" class="inline-flex min-w-0 items-center gap-3 rounded-control">
                    <span class="grid h-12 w-12 shrink-0 place-items-center rounded-control bg-emerald-50 text-lg text-emerald-700">
                        <x-ui.icon name="fa-solid fa-film" />
                    </span>
                    <span class="min-w-0 break-words text-lg font-black tracking-tight text-slate-800">{{ $siteName }}</span>
                </a>

                <p class="mt-4 max-w-md text-sm leading-6 text-slate-600">
                    Каталог сериалов, сезонов и серий.
                </p>

                <a href="{{ route('titles.index') }}" class="mt-5 inline-flex min-h-11 items-center gap-2 rounded-control bg-emerald-50 px-4 py-2.5 text-sm font-bold text-emerald-700 transition hover:bg-emerald-100">
                    <x-ui.icon name="fa-solid fa-table-cells-large" />
                    <span>Открыть каталог</span>
                    <x-ui.icon name="fa-solid fa-arrow-right text-xs" />
                </a>
            </div>

            <nav aria-labelledby="footer-catalog-navigation" class="min-w-0">
                <h2 id="footer-catalog-navigation" class="flex items-center gap-2 text-sm font-bold text-slate-800">
                    <x-ui.icon name="fa-solid fa-compass" class="text-emerald-700" />
                    <span>Навигация</span>
                </h2>
                <ul class="mt-3 space-y-1">
                    <li>
                        <a href="{{ route('home') }}" @class([
                            '-mx-3 flex min-h-11 items-center gap-3 rounded-control px-3 py-2 text-sm font-semibold transition',
                            'bg-emerald-50 text-emerald-700' => request()->routeIs('home'),
                            'text-slate-600 hover:bg-slate-50 hover:text-emerald-700' => ! request()->routeIs('home'),
                        ]) @if (request()->routeIs('home')) aria-current="page" @endif>
                            <x-ui.icon name="fa-solid fa-house text-slate-400" />
                            <span>Главная</span>
                        </a>
                    </li>
                    <li>
                        <a href="{{ route('titles.index') }}" @class([
                            '-mx-3 flex min-h-11 items-center gap-3 rounded-control px-3 py-2 text-sm font-semibold transition',
                            'bg-emerald-50 text-emerald-700' => request()->routeIs('titles.*'),
                            'text-slate-600 hover:bg-slate-50 hover:text-emerald-700' => ! request()->routeIs('titles.*'),
                        ]) @if (request()->routeIs('titles.*')) aria-current="page" @endif>
                            <x-ui.icon name="fa-solid fa-table-cells-large text-slate-400" />
                            <span>Каталог</span>
                        </a>
                    </li>
                    @auth
                        <li>
                            <a href="{{ route('viewing-activity') }}" @class([
                                '-mx-3 flex min-h-11 items-center gap-3 rounded-control px-3 py-2 text-sm font-semibold transition',
                                'bg-emerald-50 text-emerald-700' => request()->routeIs('viewing-activity'),
                                'text-slate-600 hover:bg-slate-50 hover:text-emerald-700' => ! request()->routeIs('viewing-activity'),
                            ]) @if (request()->routeIs('viewing-activity')) aria-current="page" @endif>
                                <x-ui.icon name="fa-solid fa-clock-rotate-left text-slate-400" />
                                <span>Мои просмотры</span>
                            </a>
                        </li>
                    @endauth
                </ul>
            </nav>

            <nav aria-labelledby="footer-service-navigation" class="min-w-0">
                <h2 id="footer-service-navigation" class="flex items-center gap-2 text-sm font-bold text-slate-800">
                    <x-ui.icon name="fa-solid fa-screwdriver-wrench" class="text-emerald-700" />
                    <span>Служебные страницы</span>
                </h2>
                <ul class="mt-3 space-y-1">
                    <li>
                        <a href="{{ route('stats') }}" @class([
                            '-mx-3 flex min-h-11 items-center gap-3 rounded-control px-3 py-2 text-sm font-semibold transition',
                            'bg-emerald-50 text-emerald-700' => request()->routeIs('stats'),
                            'text-slate-600 hover:bg-slate-50 hover:text-emerald-700' => ! request()->routeIs('stats'),
                        ]) @if (request()->routeIs('stats')) aria-current="page" @endif>
                            <x-ui.icon name="fa-solid fa-chart-simple text-slate-400" />
                            <span>Статистика каталога</span>
                        </a>
                    </li>
                    <li>
                        <a href="{{ route('sitemap') }}" class="-mx-3 flex min-h-11 items-center gap-3 rounded-control px-3 py-2 text-sm font-semibold text-slate-600 transition hover:bg-slate-50 hover:text-emerald-700">
                            <x-ui.icon name="fa-solid fa-sitemap text-slate-400" />
                            <span>Карта сайта</span>
                        </a>
                    </li>
                    <li>
                        <a href="{{ route('feed') }}" class="-mx-3 flex min-h-11 items-center gap-3 rounded-control px-3 py-2 text-sm font-semibold text-slate-600 transition hover:bg-slate-50 hover:text-emerald-700">
                            <x-ui.icon name="fa-solid fa-rss text-slate-400" />
                            <span>RSS-лента</span>
                        </a>
                    </li>
                </ul>
            </nav>
        </div>

        <div data-site-footer-bottom class="flex flex-col gap-3 border-t border-slate-200 py-5 text-sm text-slate-500 sm:flex-row sm:items-center sm:justify-between">
            <p class="min-w-0 break-words">© {{ now()->year }} {{ $siteName }}</p>
            <a href="#main-content" class="inline-flex min-h-11 items-center gap-2 self-start rounded-control px-3 py-2 font-semibold text-slate-600 transition hover:bg-emerald-50 hover:text-emerald-700 sm:self-auto">
                <x-ui.icon name="fa-solid fa-arrow-up" />
                <span>К началу страницы</span>
            </a>
        </div>
    </div>
</footer>
