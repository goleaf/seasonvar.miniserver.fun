<div class="space-y-5">
        <section class="rounded-panel border border-slate-200 bg-white p-4 shadow-panel sm:p-6">
            <div class="max-w-3xl">
                <p class="text-xs font-black uppercase tracking-[0.12em] text-emerald-700">{{ __('catalog.global_search.eyebrow') }}</p>
                <h1 class="mt-1 break-words text-2xl font-black tracking-tight text-slate-900 sm:text-3xl">{{ __('catalog.global_search.title') }}</h1>
                <p class="mt-2 text-sm leading-6 text-slate-600">{{ __('catalog.global_search.description') }}</p>

                <form action="{{ $searchUrl }}" method="GET" role="search" class="mt-4 flex min-w-0 gap-2">
                    <x-form.search-field
                        id="global-search"
                        name="q"
                        :value="$query"
                        :label="__('catalog.global_search.input_label')"
                        :placeholder="__('catalog.header_search.placeholder')"
                        container-class="min-w-0 flex-1"
                        input-class="min-h-11 min-w-0 flex-1 border-0 bg-transparent px-3 py-2.5 text-sm text-slate-700 outline-none placeholder:text-slate-500"
                    />
                    <button type="submit" class="inline-flex min-h-11 shrink-0 items-center justify-center gap-2 rounded-control bg-emerald-700 px-4 py-2.5 text-sm font-bold text-white transition hover:bg-emerald-600 focus-visible:outline-none focus-visible:ring-4 focus-visible:ring-emerald-200">
                        <x-ui.icon name="fa-solid fa-magnifying-glass" />
                        <span class="hidden sm:inline">{{ __('catalog.header_search.submit') }}</span>
                    </button>
                </form>

                @error('q')
                    <p class="mt-3 rounded-control bg-rose-50 px-3 py-2 text-sm font-semibold text-rose-800" role="alert">{{ $message }}</p>
                @enderror
            </div>
        </section>

        @if ($query === '')
            <div class="rounded-panel border border-slate-200 bg-white px-4 py-8 text-center shadow-panel">
                <x-ui.icon name="fa-solid fa-magnifying-glass text-2xl text-slate-300" />
                <p class="mt-3 font-bold text-slate-700">{{ __('catalog.global_search.prompt') }}</p>
            </div>
        @elseif ($failed)
            <div class="rounded-panel border border-rose-200 bg-rose-50 px-4 py-8 text-center shadow-panel" role="alert">
                <x-ui.icon name="fa-solid fa-triangle-exclamation text-2xl text-rose-500" />
                <p class="mt-3 font-bold text-rose-900">{{ __('catalog.global_search.temporary_error') }}</p>
                <a href="{{ route('titles.index') }}" class="mt-4 inline-flex min-h-11 items-center justify-center gap-2 rounded-control bg-white px-4 py-2 text-sm font-bold text-emerald-800 shadow-sm transition hover:bg-emerald-50 focus-visible:outline-none focus-visible:ring-4 focus-visible:ring-emerald-200">
                    <x-ui.icon name="fa-solid fa-table-cells-large" />
                    <span>{{ __('catalog.global_search.open_catalog') }}</span>
                </a>
            </div>
        @else
            <div class="flex flex-wrap items-end justify-between gap-3">
                <div>
                    <p class="text-xs font-black uppercase tracking-[0.12em] text-slate-500">{{ __('catalog.global_search.results') }}</p>
                    <h2 class="mt-1 break-words text-xl font-black text-slate-900">«{{ $query }}»</h2>
                    <p class="mt-1 text-sm font-semibold text-slate-600" role="status" aria-live="polite">
                        {{ __('catalog.global_search.result_summary', ['titles' => $title_count_label, 'portal' => $portal_count]) }}
                    </p>
                </div>
                <div class="flex flex-wrap gap-2">
                    <a href="{{ $searchUrl }}" class="inline-flex min-h-11 items-center gap-2 rounded-control px-3 py-2 text-sm font-bold text-slate-600 transition hover:bg-slate-100 focus-visible:outline-none focus-visible:ring-4 focus-visible:ring-slate-200">
                        <x-ui.icon name="fa-solid fa-xmark" />
                        <span>{{ __('catalog.global_search.clear') }}</span>
                    </a>
                    <a href="{{ route('titles.index', ['q' => $query]) }}" class="inline-flex min-h-11 items-center gap-2 rounded-control px-3 py-2 text-sm font-bold text-emerald-700 transition hover:bg-emerald-50 focus-visible:outline-none focus-visible:ring-4 focus-visible:ring-emerald-200">
                        <span>{{ __('catalog.global_search.all_titles') }}</span>
                        <x-ui.icon name="fa-solid fa-arrow-right" />
                    </a>
                </div>
            </div>

            @if ($titles->isNotEmpty())
                <section aria-labelledby="global-search-titles" class="overflow-hidden rounded-panel border border-slate-200 bg-white shadow-panel">
                    <h2 id="global-search-titles" class="border-b border-slate-200 bg-slate-50 px-4 py-3 text-lg font-black text-slate-900">{{ __('catalog.header_search.groups.titles') }}</h2>
                    <div class="grid min-w-0 divide-y divide-slate-200 lg:grid-cols-2 lg:divide-x lg:divide-y-0">
                        @foreach ($titles as $title)
                            <x-catalog.title-card :title="$title" layout="list" :show-description="false" readable />
                        @endforeach
                    </div>
                </section>
            @endif

            @if ($search_suggestions->isNotEmpty())
                <section aria-labelledby="global-search-suggestions" class="rounded-panel border border-amber-200 bg-amber-50 p-4 shadow-panel">
                    <h2 id="global-search-suggestions" class="text-lg font-black text-amber-950">{{ __('catalog.global_search.possible_matches') }}</h2>
                    <p class="mt-1 text-sm text-amber-900">{{ __('catalog.global_search.possible_matches_hint') }}</p>
                    <div class="mt-3 flex flex-wrap gap-2">
                        @foreach ($search_suggestions as $suggestion)
                            <a href="{{ route($searchRouteName, [...$searchRouteParameters, 'q' => $suggestion->display_title]) }}" class="inline-flex min-h-11 items-center rounded-control bg-white px-3 py-2 text-sm font-bold text-amber-950 shadow-sm transition hover:bg-amber-100 focus-visible:outline-none focus-visible:ring-4 focus-visible:ring-amber-200">
                                {{ $suggestion->display_title }}
                            </a>
                        @endforeach
                    </div>
                </section>
            @endif

            @if ($portal_groups->isNotEmpty())
                <section aria-labelledby="global-search-portal">
                    <h2 id="global-search-portal" class="mb-3 text-lg font-black text-slate-900">{{ __('catalog.global_search.portal_results') }}</h2>
                    <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
                        @foreach ($portal_groups as $group)
                            <section aria-label="{{ $group['label'] }}" class="min-w-0 rounded-panel border border-slate-200 bg-white p-2 shadow-panel">
                                <h3 class="px-2 py-2 text-xs font-black uppercase tracking-[0.12em] text-slate-500">{{ $group['label'] }}</h3>
                                <div class="space-y-0.5">
                                    @foreach ($group['items'] as $item)
                                        <a href="{{ $item['url'] }}" class="flex min-h-11 min-w-0 items-center justify-between gap-3 rounded-control px-2 py-2 text-sm transition hover:bg-emerald-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-emerald-300">
                                            <span class="min-w-0 break-words font-bold text-slate-800">{{ $item['label'] }}</span>
                                            <span class="shrink-0 text-xs font-semibold text-slate-500">{{ $item['meta'] }}</span>
                                        </a>
                                    @endforeach
                                </div>
                            </section>
                        @endforeach
                    </div>
                </section>
            @endif

            @if ($titles->isEmpty() && $portal_groups->isEmpty())
                <div class="rounded-panel border border-slate-200 bg-white px-4 py-8 text-center shadow-panel">
                    <p class="font-bold text-slate-700">{{ __('catalog.global_search.empty', ['query' => $query]) }}</p>
                    <p class="mx-auto mt-2 max-w-2xl text-sm leading-6 text-slate-600">{{ __('catalog.global_search.empty_hint') }}</p>
                    <div class="mt-5 flex flex-wrap justify-center gap-2">
                        <a href="{{ route('titles.index') }}" class="inline-flex min-h-11 items-center rounded-control bg-emerald-700 px-4 py-2 text-sm font-bold text-white transition hover:bg-emerald-600 focus-visible:outline-none focus-visible:ring-4 focus-visible:ring-emerald-200">{{ __('catalog.global_search.open_catalog') }}</a>
                        <a href="{{ route('discover.index', ['type' => 'popular']) }}" class="inline-flex min-h-11 items-center rounded-control bg-slate-100 px-4 py-2 text-sm font-bold text-slate-800 transition hover:bg-slate-200 focus-visible:outline-none focus-visible:ring-4 focus-visible:ring-slate-200">{{ __('catalog.global_search.open_popular') }}</a>
                        <a href="{{ route('discover.index', ['type' => 'recently_added']) }}" class="inline-flex min-h-11 items-center rounded-control bg-slate-100 px-4 py-2 text-sm font-bold text-slate-800 transition hover:bg-slate-200 focus-visible:outline-none focus-visible:ring-4 focus-visible:ring-slate-200">{{ __('catalog.global_search.open_new') }}</a>
                        <a href="{{ route('discover.index', ['type' => 'random']) }}" class="inline-flex min-h-11 items-center rounded-control bg-slate-100 px-4 py-2 text-sm font-bold text-slate-800 transition hover:bg-slate-200 focus-visible:outline-none focus-visible:ring-4 focus-visible:ring-slate-200">{{ __('catalog.global_search.open_random') }}</a>
                    </div>
                </div>
            @endif
        @endif
</div>
