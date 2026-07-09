@extends('layouts.app', ['title' => 'Сериалы'])

@php
    $typeLabels = [
        'genre' => 'Жанры',
        'country' => 'Страны',
        'actor' => 'Актеры',
        'director' => 'Режиссеры',
        'age_rating' => 'Возраст',
        'translation' => 'Перевод',
        'status' => 'Статус',
        'network' => 'Каналы',
        'studio' => 'Студии',
        'tag' => 'Теги',
    ];
    $year = $year ?? null;
    $requestedYear = $requestedYear ?? null;
    $invalidYear = $invalidYear ?? false;
    $activeTaxonomies = $activeTaxonomies ?? collect();
    $activeFilterSlugs = $activeFilterSlugs ?? [];
    $invalidFilterSlugs = $invalidFilterSlugs ?? [];
    $allFilterSlugs = array_merge($activeFilterSlugs, $invalidFilterSlugs);
    $filterQuery = function (string $filterType, ?string $slug = null) use ($allFilterSlugs, $search, $year, $invalidYear, $requestedYear): array {
        $query = $allFilterSlugs;

        if ($slug === null) {
            unset($query[$filterType]);
        } else {
            $query[$filterType] = $slug;
        }

        if ($search !== '') {
            $query['q'] = $search;
        }

        if ($year !== null) {
            $query['year'] = $year;
        }

        if ($invalidYear) {
            $query['year'] = $requestedYear;
        }

        return $query;
    };
    $withoutYearQuery = $allFilterSlugs;

    if ($search !== '') {
        $withoutYearQuery['q'] = $search;
    }
    $yearQuery = function (?int $selectedYear) use ($allFilterSlugs, $search): array {
        $query = $allFilterSlugs;

        if ($search !== '') {
            $query['q'] = $search;
        }

        if ($selectedYear !== null) {
            $query['year'] = $selectedYear;
        }

        return $query;
    };
    $invalidFilterQuery = function (string $filterType) use ($allFilterSlugs, $search, $year, $invalidYear, $requestedYear): array {
        $query = $allFilterSlugs;
        unset($query[$filterType]);

        if ($search !== '') {
            $query['q'] = $search;
        }

        if ($year !== null) {
            $query['year'] = $year;
        }

        if ($invalidYear) {
            $query['year'] = $requestedYear;
        }

        return $query;
    };
@endphp

@section('content')
    <section class="grid gap-5 lg:grid-cols-[260px_minmax(0,1fr)] xl:grid-cols-[280px_minmax(0,1fr)]">
        <aside class="space-y-4">
            <x-ui.panel title="Фильтры каталога" subtitle="Главная цифра справа - сколько будет найдено с текущими фильтрами.">
                <div class="space-y-5">
                    <div>
                        <div class="mb-2 text-xs font-bold uppercase tracking-wide text-slate-500">Годы</div>
                        <div class="space-y-1">
                            @forelse ($yearBuckets as $bucket)
                                @php
                                    $bucketYear = (int) $bucket->year;
                                    $isActiveYear = $year === $bucketYear;
                                @endphp
                                <a href="{{ route('titles.index', $isActiveYear ? $yearQuery(null) : $yearQuery($bucketYear)) }}" @class([
                                    'flex items-center justify-between rounded-lg px-3 py-2 text-sm ring-1 transition',
                                    'bg-emerald-50 font-bold text-emerald-700 ring-emerald-100' => $isActiveYear,
                                    'bg-white text-slate-600 ring-slate-200 hover:bg-emerald-50 hover:text-emerald-700' => ! $isActiveYear,
                                ])>
                                    <span>{{ $bucketYear }}</span>
                                    <span class="flex items-center gap-1 text-xs">
                                        <span class="font-bold">{{ $bucket->context_titles_count }}</span>
                                        <span class="text-slate-400">/ {{ $bucket->titles_count }}</span>
                                    </span>
                                </a>
                            @empty
                                <p class="text-sm text-slate-500">Годы появятся после синхронизации.</p>
                            @endforelse
                        </div>
                    </div>

                    @foreach ($typeLabels as $filterType => $label)
                        @php
                            $currentTaxonomy = $activeTaxonomies->get($filterType);
                        @endphp
                        <div>
                            <div class="mb-2 text-xs font-bold uppercase tracking-wide text-slate-500">{{ $label }}</div>
                            <div class="space-y-1">
                                @forelse ($filterTaxonomies->get($filterType, collect()) as $taxonomy)
                                    @php
                                        $isActive = $currentTaxonomy?->id === $taxonomy->id;
                                    @endphp
                                    <a href="{{ route('titles.index', $filterQuery($filterType, $isActive ? null : $taxonomy->slug)) }}" @class([
                                        'flex items-center justify-between rounded-lg px-3 py-2 text-sm ring-1 transition',
                                        'bg-emerald-50 font-bold text-emerald-700 ring-emerald-100' => $isActive,
                                        'bg-white text-slate-600 ring-slate-200 hover:bg-emerald-50 hover:text-emerald-700' => ! $isActive,
                                    ])>
                                        <span>{{ $taxonomy->name }}</span>
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

        <div class="min-w-0 space-y-5">
            <x-ui.panel>
                <div class="flex flex-col gap-5 sm:flex-row sm:items-end sm:justify-between">
                    <div>
                        <h1 class="text-3xl font-bold text-slate-700">Сериалы</h1>
                        <p class="mt-2 text-sm text-slate-500">Поиск по названиям, описаниям, актерам, жанрам и связям каталога.</p>
                        @if ($activeTaxonomies->isNotEmpty() || $year !== null || $invalidYear || $invalidFilterSlugs !== [])
                            <div class="mt-3 space-y-3 text-sm">
                                <div class="flex flex-wrap items-center gap-2">
                                    @if ($year !== null)
                                        <x-ui.taxonomy-chip :href="route('titles.index', $withoutYearQuery)" active>Год: {{ $year }} x</x-ui.taxonomy-chip>
                                    @endif
                                    @if ($invalidYear)
                                        <x-ui.taxonomy-chip :href="route('titles.index', $withoutYearQuery)" active>Год: {{ $requestedYear }} не найден</x-ui.taxonomy-chip>
                                    @endif
                                    @foreach ($activeTaxonomies as $filterType => $taxonomy)
                                        <x-ui.taxonomy-chip :href="route('titles.index', $filterQuery($filterType, null))" active>{{ $typeLabels[$filterType] ?? $filterType }}: {{ $taxonomy->name }} x</x-ui.taxonomy-chip>
                                    @endforeach
                                    @foreach ($invalidFilterSlugs as $filterType => $slug)
                                        <x-ui.taxonomy-chip :href="route('titles.index', $invalidFilterQuery($filterType))" active>{{ $typeLabels[$filterType] ?? $filterType }}: {{ $slug }} не найден</x-ui.taxonomy-chip>
                                    @endforeach
                                </div>
                                <div class="flex flex-wrap gap-3 text-slate-500">
                                    <span>Активных связей: {{ $activeTaxonomies->count() }}</span>
                                    @if ($invalidFilterSlugs !== [])
                                        <span>Ошибочных фильтров: {{ count($invalidFilterSlugs) }}</span>
                                    @endif
                                    @if ($invalidYear)
                                        <span>Ошибочный год: {{ $requestedYear }}</span>
                                    @endif
                                    @if ($year !== null)
                                        <span>Год: {{ $year }}</span>
                                    @endif
                                    <span>Найдено сейчас: {{ $titles->total() }}</span>
                                    <a href="{{ route('titles.index') }}" class="font-semibold text-emerald-700 hover:text-emerald-600">Сбросить все</a>
                                </div>
                            </div>
                        @else
                            <div class="mt-3 text-sm text-slate-500">Найдено: {{ $titles->total() }}</div>
                        @endif
                    </div>

                    <form method="GET" action="{{ route('titles.index') }}" class="flex w-full max-w-md flex-col gap-2 sm:flex-row">
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
                        <input
                            name="q"
                            value="{{ $search }}"
                            placeholder="Название, описание или тег"
                            class="min-w-0 flex-1 rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm text-slate-700 placeholder:text-slate-400 focus:border-emerald-300 focus:outline-none"
                        >
                        <button class="rounded-lg bg-emerald-50 px-4 py-2 text-sm font-semibold text-emerald-700 ring-1 ring-emerald-100 hover:bg-emerald-100">Найти</button>
                    </form>
                </div>
            </x-ui.panel>

            <div class="grid auto-rows-fr gap-3 sm:grid-cols-2 sm:gap-4 xl:grid-cols-3 2xl:grid-cols-4">
                @forelse ($titles as $catalogTitle)
                    <x-title-card :title="$catalogTitle" />
                @empty
                    <x-ui.panel class="col-span-full border-dashed">
                        <p class="text-sm text-slate-500">Ничего не найдено.</p>
                    </x-ui.panel>
                @endforelse
            </div>

            <div>
                {{ $titles->links() }}
            </div>
        </div>
    </section>
@endsection
