@props(['siteName', 'footer'])

<footer data-site-footer {{ $attributes->class(['mt-10 border-t border-slate-200 bg-white']) }}>
    <div class="mx-auto max-w-[1760px] px-3 sm:px-6 lg:px-8">
        <div class="grid gap-8 py-8 md:grid-cols-2 md:py-10 xl:grid-cols-[minmax(0,1.3fr)_minmax(0,0.7fr)_minmax(0,1fr)_minmax(0,0.7fr)] xl:gap-10">
            <div data-site-footer-brand class="min-w-0 md:col-span-2 xl:col-span-1 xl:max-w-xl">
                <a href="{{ $footer['home_url'] }}" class="inline-flex min-w-0 items-center gap-3 rounded-control">
                    <span class="grid h-12 w-12 shrink-0 place-items-center rounded-control bg-emerald-50 text-lg text-emerald-700">
                        <x-ui.icon name="fa-solid fa-film" />
                    </span>
                    <span class="min-w-0 break-words text-lg font-black tracking-tight text-slate-800">{{ $siteName }}</span>
                </a>

                <p class="mt-4 max-w-md text-sm leading-6 text-slate-600">
                    {{ __('catalog.layout.footer_description') }}
                </p>

                <a href="{{ $footer['catalog_url'] }}" class="mt-5 inline-flex min-h-11 items-center gap-2 rounded-control bg-emerald-50 px-4 py-2.5 text-sm font-bold text-emerald-700 transition hover:bg-emerald-100">
                    <x-ui.icon name="fa-solid fa-list-ul" />
                    <span>{{ __('catalog.layout.open_catalog') }}</span>
                    <x-ui.icon name="fa-solid fa-arrow-right text-xs" />
                </a>
            </div>

            <nav aria-labelledby="footer-catalog-navigation" class="min-w-0">
                <h2 id="footer-catalog-navigation" class="flex items-center gap-2 text-sm font-bold text-slate-800">
                    <x-ui.icon name="fa-solid fa-compass" class="text-emerald-700" />
                    <span>{{ __('catalog.layout.navigation') }}</span>
                </h2>
                <ul class="mt-3 space-y-1">
                    @foreach ($footer['navigation'] as $item)
                        <li>
                            <a href="{{ $item->url }}" class="{{ $item->className }}" @if ($item->ariaCurrent !== null) aria-current="{{ $item->ariaCurrent }}" @endif>
                                <x-ui.icon :name="$item->icon" />
                                <span>{{ $item->label }}</span>
                            </a>
                        </li>
                    @endforeach
                </ul>
            </nav>

            <nav aria-labelledby="footer-directory-navigation" class="min-w-0">
                <h2 id="footer-directory-navigation" class="flex items-center gap-2 text-sm font-bold text-slate-800">
                    <x-ui.icon name="fa-solid fa-folder-tree" class="text-emerald-700" />
                    <span>{{ $footer['directory_label'] }}</span>
                </h2>
                <ul class="mt-3 grid grid-cols-2 gap-x-4 gap-y-1">
                    @foreach ($footer['directories'] as $directory)
                        <li>
                            <a href="{{ $directory->url }}" class="{{ $directory->className }}" @if ($directory->ariaCurrent !== null) aria-current="{{ $directory->ariaCurrent }}" @endif>
                                <x-ui.icon :name="$directory->icon" />
                                <span class="min-w-0 break-words">{{ $directory->label }}</span>
                            </a>
                        </li>
                    @endforeach
                </ul>
            </nav>

            <nav aria-labelledby="footer-service-navigation" class="min-w-0">
                <h2 id="footer-service-navigation" class="flex items-center gap-2 text-sm font-bold text-slate-800">
                    <x-ui.icon name="fa-solid fa-screwdriver-wrench" class="text-emerald-700" />
                    <span>{{ __('catalog.layout.service_pages') }}</span>
                </h2>
                <ul class="mt-3 space-y-1">
                    @foreach ($footer['service_links'] as $item)
                        <li>
                            <a href="{{ $item->url }}" class="{{ $item->className }}" @if ($item->ariaCurrent !== null) aria-current="{{ $item->ariaCurrent }}" @endif>
                                <x-ui.icon :name="$item->icon" />
                                <span>{{ $item->label }}</span>
                            </a>
                        </li>
                    @endforeach
                </ul>
            </nav>
        </div>

        <div data-site-footer-bottom class="flex flex-col gap-3 border-t border-slate-200 py-5 text-sm text-slate-500 sm:flex-row sm:items-center sm:justify-between">
            <p class="min-w-0 break-words">© {{ $footer['current_year'] }} {{ $siteName }}</p>
            <a href="#main-content" class="inline-flex min-h-11 items-center gap-2 self-start rounded-control px-3 py-2 font-semibold text-slate-600 transition hover:bg-emerald-50 hover:text-emerald-700 sm:self-auto">
                <x-ui.icon name="fa-solid fa-arrow-up" />
                <span>{{ __('catalog.layout.back_to_top') }}</span>
            </a>
        </div>
    </div>
</footer>
