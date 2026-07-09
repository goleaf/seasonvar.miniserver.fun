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
                        @if ($activeTaxonomies->isNotEmpty() || $titleContext !== null || $year !== null || $invalidYear || $invalidFilterSlugs !== [])
                            <div class="mt-3 space-y-3 text-sm">
                                <div class="flex flex-wrap items-center gap-2">
                                    @if ($titleContext !== null)
                                        <x-ui.taxonomy-chip :href="route('titles.index', $filterView->withoutTitleQuery)" active icon="fa-solid fa-clapperboard">Сериал: {{ $titleContext->title }} · убрать</x-ui.taxonomy-chip>
                                    @endif
                                    @if ($year !== null)
                                        <x-ui.taxonomy-chip :href="route('titles.index', $filterView->withoutYearQuery)" active icon="fa-solid fa-calendar-days">Год: {{ $year }} · убрать</x-ui.taxonomy-chip>
                                    @endif
                                    @if ($invalidYear)
                                        <x-ui.taxonomy-chip :href="route('titles.index', $filterView->withoutYearQuery)" active icon="fa-solid fa-calendar-days">Год: {{ $requestedYear }} не найден · убрать</x-ui.taxonomy-chip>
                                    @endif
                                    @foreach ($activeTaxonomies as $filterType => $taxonomy)
                                        <x-ui.taxonomy-chip :href="route('titles.index', $filterView->filterQuery($filterType, null))" :icon="$filterView->icon($filterType)" active>{{ $filterView->label($filterType) }}: {{ $taxonomy->name }} · убрать</x-ui.taxonomy-chip>
                                    @endforeach
                                    @foreach ($invalidFilterSlugs as $filterType => $slug)
                                        <x-ui.taxonomy-chip :href="route('titles.index', $filterView->invalidFilterQuery($filterType))" :icon="$filterView->icon($filterType)" active>{{ $filterView->label($filterType) }}: {{ $slug }} не найден · убрать</x-ui.taxonomy-chip>
                                    @endforeach
                                </div>
                                <div class="flex flex-wrap gap-3 text-slate-500">
                                    <span><i class="fa-solid fa-diagram-project text-slate-400" aria-hidden="true"></i> Активных связей: {{ $activeTaxonomies->count() }}</span>
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
                        @if ($titleContext !== null)
                            <input type="hidden" name="title" value="{{ $titleContext->slug }}">
                        @endif
                        @foreach ($activeFilterSlugs as $filterType => $slug)
                            <input type="hidden" name="{{ $filterType }}" value="{{ $slug }}">
                        @endforeach
                        @foreach ($invalidFilterSlugs as $filterType => $slug)
                            <input type="hidden" name="{{ $filterType }}" value="{{ $slug }}">
                        @endforeach
                        @if ($year !== null)
                            <input type="hidden" name="year" value="{{ $year }}">
                        @endif
                        @if ($invalidYear)
                            <input type="hidden" name="year" value="{{ $requestedYear }}">
                        @endif
                        @if ($sort !== 'updated')
                            <input type="hidden" name="sort" value="{{ $sort }}">
                        @endif
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
            </x-ui.panel>

            <div class="grid auto-rows-fr gap-3 sm:grid-cols-2 sm:gap-4 xl:grid-cols-3 2xl:grid-cols-4">
                @forelse ($titles as $catalogTitle)
                    <x-title-card :title="$catalogTitle" />
                @empty
                    <x-ui.panel class="col-span-full border-dashed">
                        <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                            <div>
                                <div class="inline-flex items-center gap-2 text-base font-bold text-slate-700">
                                    <i class="fa-solid fa-magnifying-glass text-slate-400" aria-hidden="true"></i>
                                    <span>Ничего не найдено.</span>
                                </div>
                                <p class="mt-1 text-sm text-slate-500">Попробуйте изменить поиск или сбросить выбранные фильтры.</p>
                            </div>
                            <a href="{{ route('titles.index') }}" class="inline-flex items-center justify-center gap-2 rounded-lg bg-emerald-50 px-4 py-2 text-sm font-bold text-emerald-700 ring-1 ring-emerald-100 hover:bg-emerald-100">
                                <i class="fa-solid fa-rotate-left" aria-hidden="true"></i>
                                <span>Сбросить фильтры</span>
                            </a>
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
