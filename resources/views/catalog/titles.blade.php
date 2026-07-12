@extends('layouts.app', ['title' => $seo['title'] ?? 'Сериалы', 'seo' => $seo ?? []])

@section('content')
    <section class="grid gap-5 lg:grid-cols-[260px_minmax(0,1fr)] xl:grid-cols-[280px_minmax(0,1fr)]">
        <aside id="catalog-filters" class="order-2 scroll-mt-24 space-y-4 lg:sticky lg:top-24 lg:order-1 lg:max-h-[calc(100vh-7rem)] lg:self-start lg:overflow-y-auto lg:pr-1">
            <x-ui.panel title="Фильтры каталога" icon="fa-solid fa-sliders">
                <div class="space-y-5">
                    <x-catalog.filter-section title="Годы" icon="fa-solid fa-calendar-days" empty="Годы не указаны.">
                        @forelse ($yearBuckets as $bucket)
                            <x-catalog.filter-link
                                :href="route('titles.index', $filterView->isActiveYear($bucket) ? $filterView->yearQuery(null) : $filterView->yearQuery($filterView->bucketYear($bucket)))"
                                icon="fa-solid fa-calendar-days"
                                :label="$filterView->bucketYear($bucket)"
                                :active="$filterView->isActiveYear($bucket)"
                                :count="$bucket->context_titles_count"
                                :total="$bucket->titles_count"
                            />
                        @empty
                            <p class="text-sm text-slate-500">Годы не указаны.</p>
                        @endforelse
                    </x-catalog.filter-section>

                    @foreach ($filterView->typeLabels as $filterType => $label)
                        <x-catalog.filter-section :title="$label" :icon="$filterView->icon($filterType)">
                            @forelse ($filterTaxonomies->get($filterType, collect()) as $taxonomy)
                                <x-catalog.filter-link
                                    :href="route('titles.index', $filterView->filterQuery($filterType, $filterView->isActiveTaxonomy($filterType, $taxonomy) ? null : $taxonomy->slug))"
                                    :icon="$filterView->icon($filterType)"
                                    :label="$taxonomy->name"
                                    :active="$filterView->isActiveTaxonomy($filterType, $taxonomy)"
                                    :count="$taxonomy->context_titles_count"
                                    :total="$taxonomy->catalog_titles_count"
                                />
                            @empty
                                <p class="text-sm text-slate-500">Нет данных.</p>
                            @endforelse
                        </x-catalog.filter-section>
                    @endforeach
                </div>
            </x-ui.panel>
        </aside>

        <div class="order-1 min-w-0 space-y-5 lg:order-2">
            <x-ui.panel>
                <div class="flex flex-col gap-5 sm:flex-row sm:items-end sm:justify-between">
                    <div class="min-w-0">
                        <h1 class="inline-flex min-w-0 items-start gap-2 text-3xl font-bold leading-tight text-slate-700">
                            <i class="fa-solid fa-clapperboard mt-1 shrink-0 text-emerald-700" aria-hidden="true"></i>
                            <span class="min-w-0 break-words">{{ $seo['h1'] ?? 'Сериалы' }}</span>
                        </h1>
                        <p class="mt-2 max-w-3xl text-sm leading-6 text-slate-500">{{ $seo['lead'] ?? 'Поиск по названиям, описаниям, актерам, жанрам и связям каталога.' }}</p>

                        <x-catalog.active-filters
                            :search="$search"
                            :title-context="$titleContext"
                            :year="$year"
                            :requested-year="$requestedYear"
                            :invalid-year="$invalidYear"
                            :selected-taxonomies="$selectedTaxonomies"
                            :excluded-taxonomies="$excludedTaxonomies"
                            :invalid-filter-slugs="$invalidFilterSlugs"
                            :filter-view="$filterView"
                            :total="$titles->total()"
                        />

                        <a href="#catalog-filters" class="mt-3 inline-flex min-h-11 items-center gap-2 rounded-control bg-white px-3 py-2 text-sm font-bold text-emerald-700 ring-1 ring-emerald-100 hover:bg-emerald-50 lg:hidden">
                            <i class="fa-solid fa-sliders" aria-hidden="true"></i>
                            <span>К фильтрам</span>
                        </a>
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
                        <button class="inline-flex min-h-11 items-center justify-center gap-2 rounded-control bg-emerald-50 px-4 py-2 text-sm font-semibold text-emerald-700 ring-1 ring-emerald-100 hover:bg-emerald-100">
                            <i class="fa-solid fa-arrow-right" aria-hidden="true"></i>
                            <span>Найти</span>
                        </button>
                    </form>
                </div>

                <x-catalog.catalog-toolbar
                    class="mt-5"
                    :titles="$titles"
                    :sort="$sort"
                    :view="$view"
                    :per-page="$perPage"
                    :filter-view="$filterView"
                />

                <x-catalog.alphabet-nav class="mt-4" :filter-view="$filterView" />

                <x-catalog.advanced-filters
                    class="mt-4"
                    :filter-view="$filterView"
                    :title-context="$titleContext"
                    :search="$search"
                    :sort="$sort"
                    :view="$view"
                    :per-page="$perPage"
                />
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
                    <x-catalog.empty-results
                        :search="$search"
                        :insufficient-search="$insufficientSearch"
                        :filter-view="$filterView"
                        :title-context="$titleContext"
                        :year="$year"
                        :invalid-year="$invalidYear"
                    />
                @endforelse
            </div>

            <div>
                {{ $titles->links() }}
            </div>
        </div>
    </section>
@endsection
