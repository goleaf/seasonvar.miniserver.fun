@extends('layouts.app', ['title' => $seo['title'] ?? 'Сериалы', 'seo' => $seo ?? []])

@section('content')
    <section class="grid gap-5 lg:grid-cols-[260px_minmax(0,1fr)] xl:grid-cols-[280px_minmax(0,1fr)]">
        <aside class="order-2 space-y-4 lg:order-1">
            <x-ui.panel title="Фильтры каталога" icon="fa-solid fa-sliders">
                <div class="space-y-5">
                    <div>
                        <div class="mb-2 inline-flex items-center gap-2 text-xs font-bold uppercase tracking-wide text-slate-500">
                            <i class="fa-solid fa-calendar-days text-slate-400" aria-hidden="true"></i>
                            <span>Годы</span>
                        </div>
                        <div class="space-y-1">
                            @forelse ($yearBuckets as $bucket)
                                <a href="{{ route('titles.index', $filterView->isActiveYear($bucket) ? $filterView->yearQuery(null) : $filterView->yearQuery($filterView->bucketYear($bucket))) }}" @class([
                                    'flex items-center justify-between rounded-lg px-3 py-2 text-sm ring-1 transition',
                                    'bg-emerald-50 font-bold text-emerald-700 ring-emerald-100' => $filterView->isActiveYear($bucket),
                                    'bg-white text-slate-600 ring-slate-200 hover:bg-emerald-50 hover:text-emerald-700' => ! $filterView->isActiveYear($bucket),
                                ])>
                                    <span class="inline-flex min-w-0 items-center gap-2">
                                        <i class="fa-solid fa-calendar-days shrink-0 text-[0.85em] text-slate-400" aria-hidden="true"></i>
                                        <span>{{ $filterView->bucketYear($bucket) }}</span>
                                    </span>
                                    <span class="flex items-center gap-1 text-xs">
                                        <span class="font-bold">{{ $bucket->context_titles_count }}</span>
                                        <span class="text-slate-400">/ {{ $bucket->titles_count }}</span>
                                    </span>
                                </a>
                            @empty
                                <p class="text-sm text-slate-500">Годы не указаны.</p>
                            @endforelse
                        </div>
                    </div>

                    @foreach ($filterView->typeLabels as $filterType => $label)
                        <div>
                            <div class="mb-2 inline-flex items-center gap-2 text-xs font-bold uppercase tracking-wide text-slate-500">
                                <i class="{{ $filterView->icon($filterType) }} text-slate-400" aria-hidden="true"></i>
                                <span>{{ $label }}</span>
                            </div>
                            <div class="space-y-1">
                                @forelse ($filterTaxonomies->get($filterType, collect()) as $taxonomy)
                                    <a href="{{ route('titles.index', $filterView->filterQuery($filterType, $filterView->isActiveTaxonomy($filterType, $taxonomy) ? null : $taxonomy->slug)) }}" @class([
                                        'flex items-center justify-between rounded-lg px-3 py-2 text-sm ring-1 transition',
                                        'bg-emerald-50 font-bold text-emerald-700 ring-emerald-100' => $filterView->isActiveTaxonomy($filterType, $taxonomy),
                                        'bg-white text-slate-600 ring-slate-200 hover:bg-emerald-50 hover:text-emerald-700' => ! $filterView->isActiveTaxonomy($filterType, $taxonomy),
                                    ])>
                                        <span class="inline-flex min-w-0 items-center gap-2">
                                            <i class="{{ $filterView->icon($filterType) }} shrink-0 text-[0.85em] text-slate-400" aria-hidden="true"></i>
                                            <span>{{ $taxonomy->name }}</span>
                                        </span>
                                        <span class="flex items-center gap-1 text-xs">
                                            <span class="font-bold">{{ $taxonomy->context_titles_count }}</span>
                                            <span class="text-slate-400">/ {{ $taxonomy->catalog_titles_count }}</span>
                                        </span>
                                    </a>
                                @empty
                                    <p class="text-sm text-slate-500">Нет данных.</p>
                                @endforelse
                            </div>
                        </div>
                    @endforeach
                </div>
            </x-ui.panel>
        </aside>

        <div class="order-1 min-w-0 space-y-5 lg:order-2">
            <x-ui.panel>
                <div class="flex flex-col gap-5 sm:flex-row sm:items-end sm:justify-between">
                    <div>
                        <h1 class="inline-flex items-center gap-2 text-3xl font-bold text-slate-700">
                            <i class="fa-solid fa-clapperboard text-emerald-700" aria-hidden="true"></i>
                            <span>{{ $seo['h1'] ?? 'Сериалы' }}</span>
                        </h1>
                        <p class="mt-2 text-sm text-slate-500">{{ $seo['lead'] ?? 'Поиск по названиям, описаниям, актерам, жанрам и связям каталога.' }}</p>
                        @if ($search !== '' || $selectedTaxonomies->isNotEmpty() || $excludedTaxonomies->isNotEmpty() || $filterView->advancedFilterChips() !== [] || $titleContext !== null || $year !== null || $invalidYear || $invalidFilterSlugs !== [])
                            <div class="mt-3 space-y-3 text-sm">
                                <div class="flex flex-wrap items-center gap-2">
                                    @if ($search !== '')
                                        <x-ui.taxonomy-chip :href="route('titles.index', $filterView->withoutSearchQuery)" active icon="fa-solid fa-magnifying-glass">Поиск: {{ $search }} · очистить</x-ui.taxonomy-chip>
                                    @endif
                                    @if ($titleContext !== null)
                                        <x-ui.taxonomy-chip :href="route('titles.index', $filterView->withoutTitleQuery)" active icon="fa-solid fa-clapperboard">Сериал: {{ $titleContext->title }} · убрать</x-ui.taxonomy-chip>
                                    @endif
                                    @if ($year !== null)
                                        <x-ui.taxonomy-chip :href="route('titles.index', $filterView->withoutYearQuery)" active icon="fa-solid fa-calendar-days">Год: {{ $year }} · убрать</x-ui.taxonomy-chip>
                                    @endif
                                    @if ($invalidYear)
                                        <x-ui.taxonomy-chip :href="route('titles.index', $filterView->withoutYearQuery)" active icon="fa-solid fa-calendar-days">Год: {{ $requestedYear }} не найден · убрать</x-ui.taxonomy-chip>
                                    @endif
                                    @foreach ($selectedTaxonomies as $filterType => $taxonomies)
                                        @foreach ($taxonomies as $taxonomy)
                                            <x-ui.taxonomy-chip :href="route('titles.index', $filterView->filterQuery($filterType, $taxonomy->slug))" :icon="$filterView->icon($filterType)" active>{{ $filterView->label($filterType) }}: {{ $taxonomy->name }} · убрать</x-ui.taxonomy-chip>
                                        @endforeach
                                    @endforeach
                                    @foreach ($excludedTaxonomies as $filterType => $taxonomies)
                                        @foreach ($taxonomies as $taxonomy)
                                            <x-ui.taxonomy-chip :href="route('titles.index', $filterView->exclusionQuery($filterType, $taxonomy->slug))" active icon="fa-solid fa-minus">Без {{ $filterView->label($filterType) }}: {{ $taxonomy->name }} · убрать</x-ui.taxonomy-chip>
                                        @endforeach
                                    @endforeach
                                    @foreach ($filterView->advancedFilterChips() as $chip)
                                        <x-ui.taxonomy-chip :href="route('titles.index', $filterView->withoutCatalogState($chip['key']))" active icon="fa-solid fa-sliders">
                                            {{ $chip['label'] }}: {{ $chip['value'] }} · убрать
                                        </x-ui.taxonomy-chip>
                                    @endforeach
                                    @foreach ($invalidFilterSlugs as $filterType => $slug)
                                        <x-ui.taxonomy-chip :href="route('titles.index', $filterView->invalidFilterQuery($filterType))" :icon="$filterView->icon($filterType)" active>{{ $filterView->label($filterType) }}: {{ $slug }} не найден · убрать</x-ui.taxonomy-chip>
                                    @endforeach
                                </div>
                                <div class="flex flex-wrap gap-3 text-slate-500">
                                    <span><i class="fa-solid fa-diagram-project text-slate-400" aria-hidden="true"></i> Активных связей: {{ $selectedTaxonomies->sum(fn ($taxonomies) => $taxonomies->count()) + $excludedTaxonomies->sum(fn ($taxonomies) => $taxonomies->count()) }}</span>
                                    @if ($invalidFilterSlugs !== [])
                                        <span><i class="fa-solid fa-triangle-exclamation text-amber-600" aria-hidden="true"></i> Ошибочных фильтров: {{ count($invalidFilterSlugs) }}</span>
                                    @endif
                                    @if ($invalidYear)
                                        <span><i class="fa-solid fa-calendar-xmark text-amber-600" aria-hidden="true"></i> Ошибочный год: {{ $requestedYear }}</span>
                                    @endif
                                    @if ($year !== null)
                                        <span><i class="fa-solid fa-calendar-days text-slate-400" aria-hidden="true"></i> Год: {{ $year }}</span>
                                    @endif
                                    @if ($titleContext !== null)
                                        <span><i class="fa-solid fa-clapperboard text-slate-400" aria-hidden="true"></i> Сериал: {{ $titleContext->title }}</span>
                                    @endif
                                    <span><i class="fa-solid fa-magnifying-glass text-slate-400" aria-hidden="true"></i> Найдено сейчас: {{ $titles->total() }}</span>
                                    <a href="{{ route('titles.index') }}" class="inline-flex items-center gap-1 font-semibold text-emerald-700 hover:text-emerald-600">
                                        <i class="fa-solid fa-rotate-left" aria-hidden="true"></i>
                                        <span>Сбросить все</span>
                                    </a>
                                </div>
                            </div>
                        @else
                            <div class="mt-3 text-sm text-slate-500">
                                <i class="fa-solid fa-magnifying-glass text-slate-400" aria-hidden="true"></i>
                                Найдено: {{ $titles->total() }}
                            </div>
                        @endif
                    </div>

                    <form method="GET" action="{{ route('titles.index') }}" class="flex w-full max-w-md flex-col gap-2 sm:flex-row">
                        @foreach ($filterView->searchFormState() as $stateKey => $stateValue)
                            @if (is_array($stateValue))
                                @foreach ($stateValue as $stateItem)
                                    <input type="hidden" name="{{ $stateKey }}[]" value="{{ $stateItem }}">
                                @endforeach
                            @else
                                <input type="hidden" name="{{ $stateKey }}" value="{{ $stateValue }}">
                            @endif
                        @endforeach
                        <x-form.search-field
                            id="catalog-search"
                            name="q"
                            :value="$search"
                            label="Поиск по каталогу"
                            placeholder="Название, описание или тег"
                            container-class="min-w-0 flex-1"
                        />
                        <button class="inline-flex items-center justify-center gap-2 rounded-lg bg-emerald-50 px-4 py-2 text-sm font-semibold text-emerald-700 ring-1 ring-emerald-100 hover:bg-emerald-100">
                            <i class="fa-solid fa-arrow-right" aria-hidden="true"></i>
                            <span>Найти</span>
                        </button>
                    </form>
                </div>

                <div class="mt-5 grid gap-3 sm:grid-cols-3">
                    <div class="rounded-lg border border-slate-200 bg-slate-50 p-3">
                        <div class="text-xs font-bold uppercase tracking-wide text-slate-400">Найдено</div>
                        <div class="mt-1 inline-flex items-center gap-2 text-lg font-black text-slate-700">
                            <i class="fa-solid fa-magnifying-glass text-emerald-700" aria-hidden="true"></i>
                            <span>{{ $titles->total() }}</span>
                        </div>
                    </div>
                    <div class="rounded-lg border border-slate-200 bg-slate-50 p-3">
                        <div class="text-xs font-bold uppercase tracking-wide text-slate-400">На странице</div>
                        <div class="mt-1 inline-flex items-center gap-2 text-lg font-black text-slate-700">
                            <i class="fa-solid fa-table-cells-large text-sky-700" aria-hidden="true"></i>
                            <span>{{ $titles->count() }}</span>
                        </div>
                    </div>
                    <div class="rounded-lg border border-slate-200 bg-slate-50 p-3">
                        <div class="text-xs font-bold uppercase tracking-wide text-slate-400">Сортировка</div>
                        <div class="mt-1 inline-flex items-center gap-2 text-lg font-black text-slate-700">
                            <i class="{{ $filterView->sortIcon($sort) }} text-amber-700" aria-hidden="true"></i>
                            <span>{{ $filterView->sortLabel($sort) }}</span>
                        </div>
                    </div>
                </div>

                <div class="mt-4 flex flex-wrap gap-2">
                    @foreach ($filterView->sortLabels as $sortKey => $sortLabel)
                        <a href="{{ route('titles.index', $filterView->sortQuery($sortKey)) }}" @class([
                            'inline-flex items-center gap-1 rounded-full px-2.5 py-1 text-xs font-bold ring-1',
                            'bg-emerald-50 text-emerald-700 ring-emerald-100' => $filterView->isActiveSort($sortKey),
                            'bg-white text-slate-600 ring-slate-200 hover:bg-emerald-50 hover:text-emerald-700' => ! $filterView->isActiveSort($sortKey),
                        ])>
                            <i class="{{ $filterView->sortIcon($sortKey) }}" aria-hidden="true"></i>
                            <span>{{ $sortLabel }}</span>
                        </a>
                    @endforeach
                </div>

                <div class="mt-3 flex flex-wrap items-center gap-2 text-xs font-bold">
                    <span class="text-slate-400">Вид:</span>
                    @foreach (['grid' => 'Карточки', 'list' => 'Список'] as $viewKey => $viewLabel)
                        <a href="{{ route('titles.index', $filterView->viewQuery($viewKey)) }}" @class([
                            'rounded-full px-2.5 py-1 ring-1',
                            'bg-emerald-50 text-emerald-700 ring-emerald-100' => $view === $viewKey,
                            'bg-white text-slate-600 ring-slate-200 hover:bg-emerald-50 hover:text-emerald-700' => $view !== $viewKey,
                        ])>{{ $viewLabel }}</a>
                    @endforeach
                    <span class="ml-2 text-slate-400">На странице:</span>
                    @foreach ([24, 48, 96] as $pageSize)
                        <a href="{{ route('titles.index', $filterView->perPageQuery($pageSize)) }}" @class([
                            'rounded-full px-2.5 py-1 ring-1',
                            'bg-emerald-50 text-emerald-700 ring-emerald-100' => $perPage === $pageSize,
                            'bg-white text-slate-600 ring-slate-200 hover:bg-emerald-50 hover:text-emerald-700' => $perPage !== $pageSize,
                        ])>{{ $pageSize }}</a>
                    @endforeach
                </div>

                <nav class="mt-4 flex flex-wrap items-center gap-1.5" aria-label="Алфавитный переход по названиям">
                    <span class="mr-1 text-xs font-bold uppercase tracking-wide text-slate-400">Алфавит:</span>
                    @foreach ($filterView->alphabet as $letter)
                        <a href="{{ route('titles.index', $filterView->alphabetQuery($letter)) }}" @class([
                            'inline-flex min-h-9 min-w-9 items-center justify-center rounded-full px-2 text-xs font-bold ring-1 transition',
                            'bg-emerald-50 text-emerald-700 ring-emerald-100' => $filterView->isActiveLetter($letter),
                            'bg-white text-slate-600 ring-slate-200 hover:bg-emerald-50 hover:text-emerald-700' => ! $filterView->isActiveLetter($letter),
                        ])>{{ $letter === 'latin' ? 'A–Z' : $letter }}</a>
                    @endforeach
                </nav>

                <details class="mt-4 rounded-lg border border-slate-200 bg-slate-50 p-3">
                    <summary class="cursor-pointer text-sm font-bold text-slate-700">Расширенные фильтры</summary>
                    <form method="GET" action="{{ route('titles.index') }}" class="mt-3 grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                        @if ($titleContext !== null)
                            <input type="hidden" name="title" value="{{ $titleContext->slug }}">
                        @endif
                        @if ($search !== '')
                            <input type="hidden" name="q" value="{{ $search }}">
                        @endif
                        @foreach ($filterView->selectedFilterSlugs as $filterType => $slugs)
                            @foreach ($slugs as $slug)
                                <input type="hidden" name="{{ $filterType }}[]" value="{{ $slug }}">
                            @endforeach
                        @endforeach
                        @foreach (['exclude_country', 'exclude_genre'] as $excludedType)
                            @foreach (($filterView->catalogQueryState[$excludedType] ?? []) as $slug)
                                <input type="hidden" name="{{ $excludedType }}[]" value="{{ $slug }}">
                            @endforeach
                        @endforeach
                        @foreach (($filterView->catalogQueryState['year'] ?? []) as $selectedYear)
                            <input type="hidden" name="year[]" value="{{ $selectedYear }}">
                        @endforeach
                        @if ($sort !== 'updated')
                            <input type="hidden" name="sort" value="{{ $sort }}">
                        @endif
                        @if ($view !== 'grid')
                            <input type="hidden" name="view" value="{{ $view }}">
                        @endif
                        @if ($perPage !== 24)
                            <input type="hidden" name="per_page" value="{{ $perPage }}">
                        @endif

                        <label class="text-sm font-semibold text-slate-600">
                            <span class="mb-1 block">Год от</span>
                            <input type="number" name="year_from" min="1900" max="{{ now()->year + 1 }}" value="{{ $filterView->catalogQueryState['year_from'] ?? '' }}" class="min-h-11 w-full rounded-control border border-slate-200 bg-white px-3 py-2 text-slate-700">
                        </label>
                        <label class="text-sm font-semibold text-slate-600">
                            <span class="mb-1 block">Год до</span>
                            <input type="number" name="year_to" min="1900" max="{{ now()->year + 1 }}" value="{{ $filterView->catalogQueryState['year_to'] ?? '' }}" class="min-h-11 w-full rounded-control border border-slate-200 bg-white px-3 py-2 text-slate-700">
                        </label>
                        <label class="text-sm font-semibold text-slate-600">
                            <span class="mb-1 block">Сезонов от</span>
                            <input type="number" name="seasons_min" min="0" value="{{ $filterView->catalogQueryState['seasons_min'] ?? '' }}" class="min-h-11 w-full rounded-control border border-slate-200 bg-white px-3 py-2 text-slate-700">
                        </label>
                        <label class="text-sm font-semibold text-slate-600">
                            <span class="mb-1 block">Сезонов до</span>
                            <input type="number" name="seasons_max" min="0" value="{{ $filterView->catalogQueryState['seasons_max'] ?? '' }}" class="min-h-11 w-full rounded-control border border-slate-200 bg-white px-3 py-2 text-slate-700">
                        </label>
                        <label class="text-sm font-semibold text-slate-600">
                            <span class="mb-1 block">Серий от</span>
                            <input type="number" name="episodes_min" min="0" value="{{ $filterView->catalogQueryState['episodes_min'] ?? '' }}" class="min-h-11 w-full rounded-control border border-slate-200 bg-white px-3 py-2 text-slate-700">
                        </label>
                        <label class="text-sm font-semibold text-slate-600">
                            <span class="mb-1 block">Серий до</span>
                            <input type="number" name="episodes_max" min="0" value="{{ $filterView->catalogQueryState['episodes_max'] ?? '' }}" class="min-h-11 w-full rounded-control border border-slate-200 bg-white px-3 py-2 text-slate-700">
                        </label>
                        <label class="text-sm font-semibold text-slate-600">
                            <span class="mb-1 block">Видео</span>
                            <select name="video" class="min-h-11 w-full rounded-control border border-slate-200 bg-white px-3 py-2 text-slate-700">
                                <option value="">Любое</option>
                                <option value="available" @selected(($filterView->catalogQueryState['video'] ?? '') === 'available')>Есть видео</option>
                                <option value="missing" @selected(($filterView->catalogQueryState['video'] ?? '') === 'missing')>Нет видео</option>
                            </select>
                        </label>
                        <label class="text-sm font-semibold text-slate-600">
                            <span class="mb-1 block">Субтитры</span>
                            <select name="subtitles" class="min-h-11 w-full rounded-control border border-slate-200 bg-white px-3 py-2 text-slate-700">
                                <option value="">Любые</option>
                                <option value="available" @selected(($filterView->catalogQueryState['subtitles'] ?? '') === 'available')>Есть субтитры</option>
                                <option value="missing" @selected(($filterView->catalogQueryState['subtitles'] ?? '') === 'missing')>Нет субтитров</option>
                            </select>
                        </label>
                        <label class="text-sm font-semibold text-slate-600">
                            <span class="mb-1 block">Источник рейтинга</span>
                            <select name="rating_source" class="min-h-11 w-full rounded-control border border-slate-200 bg-white px-3 py-2 text-slate-700">
                                <option value="">Любой</option>
                                <option value="kinopoisk" @selected(($filterView->catalogQueryState['rating_source'] ?? '') === 'kinopoisk')>КиноПоиск</option>
                                <option value="imdb" @selected(($filterView->catalogQueryState['rating_source'] ?? '') === 'imdb')>IMDb</option>
                            </select>
                        </label>
                        <label class="text-sm font-semibold text-slate-600">
                            <span class="mb-1 block">Рейтинг от</span>
                            <input type="number" name="rating_min" min="0" max="10" step="0.1" value="{{ $filterView->catalogQueryState['rating_min'] ?? '' }}" class="min-h-11 w-full rounded-control border border-slate-200 bg-white px-3 py-2 text-slate-700">
                        </label>
                        <label class="text-sm font-semibold text-slate-600">
                            <span class="mb-1 block">Голосов от</span>
                            <input type="number" name="votes_min" min="0" value="{{ $filterView->catalogQueryState['votes_min'] ?? '' }}" class="min-h-11 w-full rounded-control border border-slate-200 bg-white px-3 py-2 text-slate-700">
                        </label>
                        <label class="text-sm font-semibold text-slate-600">
                            <span class="mb-1 block">Обновлено</span>
                            <select name="updated" class="min-h-11 w-full rounded-control border border-slate-200 bg-white px-3 py-2 text-slate-700">
                                <option value="">За всё время</option>
                                <option value="day" @selected(($filterView->catalogQueryState['updated'] ?? '') === 'day')>За день</option>
                                <option value="week" @selected(($filterView->catalogQueryState['updated'] ?? '') === 'week')>За неделю</option>
                                <option value="month" @selected(($filterView->catalogQueryState['updated'] ?? '') === 'month')>За месяц</option>
                                <option value="year" @selected(($filterView->catalogQueryState['updated'] ?? '') === 'year')>За год</option>
                            </select>
                        </label>
                        <fieldset class="sm:col-span-2 xl:col-span-4">
                            <legend class="mb-2 text-sm font-semibold text-slate-600">Качество видео</legend>
                            <div class="flex flex-wrap gap-2">
                                @foreach (['2160p', '1440p', '1080p', '720p', '480p', '360p', '240p'] as $quality)
                                    <label class="inline-flex min-h-11 items-center gap-2 rounded-full border border-slate-200 bg-white px-3 py-2 text-sm font-semibold text-slate-600">
                                        <input type="checkbox" name="quality[]" value="{{ $quality }}" @checked(in_array($quality, $filterView->catalogQueryState['quality'] ?? [], true))>
                                        <span>{{ $quality }}</span>
                                    </label>
                                @endforeach
                            </div>
                        </fieldset>
                        <div class="flex items-end sm:col-span-2 xl:col-span-4">
                            <button type="submit" class="inline-flex min-h-11 items-center gap-2 rounded-control bg-emerald-700 px-4 py-2 text-sm font-bold text-white hover:bg-emerald-600">
                                <i class="fa-solid fa-filter" aria-hidden="true"></i>
                                <span>Применить фильтры</span>
                            </button>
                        </div>
                    </form>
                </details>
            </x-ui.panel>

            <div @class([
                'divide-y divide-slate-200 overflow-hidden rounded-panel border border-slate-200 bg-white' => $view === 'list',
                'grid auto-rows-fr gap-3 sm:grid-cols-2 sm:gap-4 xl:grid-cols-3 2xl:grid-cols-4' => $view !== 'list',
            ])>
                @forelse ($titles as $catalogTitle)
                    @if ($view === 'list')
                        <x-title-list-row :title="$catalogTitle" readable />
                    @else
                        <x-title-card :title="$catalogTitle" />
                    @endif
                @empty
                    <x-ui.panel class="col-span-full border-dashed">
                        <div class="flex flex-col gap-4">
                            <div>
                                <div class="inline-flex items-center gap-2 text-base font-bold text-slate-700">
                                    <i class="fa-solid fa-magnifying-glass text-slate-400" aria-hidden="true"></i>
                                    @if ($insufficientSearch)
                                        <span>Запрос «{{ $search }}» слишком общий.</span>
                                    @elseif ($search !== '')
                                        <span>По запросу «{{ $search }}» ничего не найдено.</span>
                                    @else
                                        <span>Ничего не найдено.</span>
                                    @endif
                                </div>
                                @if ($insufficientSearch)
                                    <p class="mt-1 text-sm text-slate-500">Добавьте название, имя актера, режиссера или жанр.</p>
                                @elseif ($search !== '')
                                    <p class="mt-1 text-sm text-slate-500">Проверьте написание или измените фильтры.</p>
                                @else
                                    <p class="mt-1 text-sm text-slate-500">Измените или сбросьте выбранные фильтры.</p>
                                @endif
                            </div>
                            <div class="flex flex-wrap gap-2">
                                @if ($search !== '')
                                    <a href="{{ route('titles.index', $filterView->withoutSearchQuery) }}" class="inline-flex min-h-11 items-center justify-center gap-2 rounded-control bg-white px-4 py-2 text-sm font-bold text-slate-600 ring-1 ring-slate-200 hover:bg-emerald-50 hover:text-emerald-700">
                                        <i class="fa-solid fa-magnifying-glass-minus" aria-hidden="true"></i>
                                        <span>Очистить поиск</span>
                                    </a>
                                @endif
                                @if ($filterView->hasActiveFilters() || $titleContext !== null || $year !== null || $invalidYear)
                                    <a href="{{ route('titles.index', $filterView->withoutFiltersQuery) }}" class="inline-flex min-h-11 items-center justify-center gap-2 rounded-control bg-white px-4 py-2 text-sm font-bold text-slate-600 ring-1 ring-slate-200 hover:bg-emerald-50 hover:text-emerald-700">
                                        <i class="fa-solid fa-filter-circle-xmark" aria-hidden="true"></i>
                                        <span>Сбросить фильтры</span>
                                    </a>
                                @endif
                                <a href="{{ route('titles.index') }}" class="inline-flex min-h-11 items-center justify-center gap-2 rounded-control bg-emerald-50 px-4 py-2 text-sm font-bold text-emerald-700 ring-1 ring-emerald-100 hover:bg-emerald-100">
                                    <i class="fa-solid fa-table-cells-large" aria-hidden="true"></i>
                                    <span>Показать весь каталог</span>
                                </a>
                            </div>
                        </div>
                    </x-ui.panel>
                @endforelse
            </div>

            <div>
                {{ $titles->links() }}
            </div>
        </div>
    </section>
@endsection
