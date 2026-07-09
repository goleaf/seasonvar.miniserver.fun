@extends('layouts.app', ['title' => 'Сериалы'])

@php
    $typeLabels = [
        'genre' => 'Жанры',
        'country' => 'Страны',
        'actor' => 'Актеры',
        'director' => 'Режиссеры',
        'tag' => 'Теги',
    ];
    $year = $year ?? null;
    $requestedYear = $requestedYear ?? null;
    $invalidYear = $invalidYear ?? false;
    $activeTaxonomies = $activeTaxonomies ?? collect();
    $activeFilterSlugs = $activeFilterSlugs ?? [];
    $invalidFilterSlugs = $invalidFilterSlugs ?? [];
    $filterQuery = function (string $filterType, ?string $slug = null) use ($activeFilterSlugs, $search, $year): array {
        $query = $activeFilterSlugs;

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

        return $query;
    };
    $withoutYearQuery = $activeFilterSlugs;

    if ($search !== '') {
        $withoutYearQuery['q'] = $search;
    }
    $yearQuery = function (?int $selectedYear) use ($activeFilterSlugs, $search): array {
        $query = $activeFilterSlugs;

        if ($search !== '') {
            $query['q'] = $search;
        }

        if ($selectedYear !== null) {
            $query['year'] = $selectedYear;
        }

        return $query;
    };
@endphp

@section('content')
    <section class="grid gap-6 lg:grid-cols-[260px_1fr]">
        <aside class="space-y-4">
            <div class="rounded border border-white/10 bg-white/[0.04]">
                <div class="border-b border-white/10 px-4 py-3">
                    <h2 class="font-semibold text-white">Фильтры каталога</h2>
                    <p class="mt-1 text-xs text-zinc-400">Главная цифра справа - сколько будет найдено с текущими фильтрами.</p>
                </div>

                <div class="space-y-4 p-4">
                    <div>
                        <div class="mb-2 text-xs font-bold uppercase tracking-wide text-sky-300">Годы</div>
                        <div class="space-y-1">
                            @forelse ($yearBuckets as $bucket)
                                @php
                                    $bucketYear = (int) $bucket->year;
                                    $isActiveYear = $year === $bucketYear;
                                @endphp
                                <a href="{{ route('titles.index', $isActiveYear ? $yearQuery(null) : $yearQuery($bucketYear)) }}" @class([
                                    'flex items-center justify-between rounded px-3 py-2 text-sm',
                                    'bg-sky-300 font-bold text-zinc-950' => $isActiveYear,
                                    'bg-white/[0.04] text-zinc-300 hover:bg-white/[0.08] hover:text-white' => ! $isActiveYear,
                                ])>
                                    <span>{{ $bucketYear }}</span>
                                    <span class="flex items-center gap-1 text-xs">
                                        <span class="font-bold">{{ $bucket->context_titles_count }}</span>
                                        <span class="opacity-50">/ {{ $bucket->titles_count }}</span>
                                    </span>
                                </a>
                            @empty
                                <p class="text-sm text-zinc-500">Годы появятся после синхронизации.</p>
                            @endforelse
                        </div>
                    </div>

                    @foreach ($typeLabels as $filterType => $label)
                        @php
                            $currentTaxonomy = $activeTaxonomies->get($filterType);
                        @endphp
                        <div>
                            <div class="mb-2 text-xs font-bold uppercase tracking-wide text-emerald-300">{{ $label }}</div>
                            <div class="space-y-1">
                                @forelse ($filterTaxonomies->get($filterType, collect()) as $taxonomy)
                                    @php
                                        $isActive = $currentTaxonomy?->id === $taxonomy->id;
                                    @endphp
                                    <a href="{{ route('titles.index', $filterQuery($filterType, $isActive ? null : $taxonomy->slug)) }}" @class([
                                        'flex items-center justify-between rounded px-3 py-2 text-sm',
                                        'bg-emerald-400 font-bold text-zinc-950' => $isActive,
                                        'bg-white/[0.04] text-zinc-300 hover:bg-white/[0.08] hover:text-white' => ! $isActive,
                                    ])>
                                        <span>{{ $taxonomy->name }}</span>
                                        <span class="flex items-center gap-1 text-xs">
                                            <span class="font-bold">{{ $taxonomy->context_titles_count }}</span>
                                            <span class="opacity-50">/ {{ $taxonomy->catalog_titles_count }}</span>
                                        </span>
                                    </a>
                                @empty
                                    <p class="text-sm text-zinc-500">Нет данных.</p>
                                @endforelse
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
        </aside>

        <div>
            <div class="flex flex-col gap-5 sm:flex-row sm:items-end sm:justify-between">
                <div>
                    <h1 class="text-3xl font-semibold text-white">Сериалы</h1>
                    <p class="mt-2 text-sm text-zinc-400">Поиск по названиям, описаниям, актерам, жанрам и связям каталога.</p>
                    @if ($activeTaxonomies->isNotEmpty() || $year !== null || $invalidYear || $invalidFilterSlugs !== [])
                        <div class="mt-3 space-y-3 text-sm">
                            <div class="flex flex-wrap items-center gap-2">
                                @if ($year !== null)
                                    <a href="{{ route('titles.index', $withoutYearQuery) }}" class="rounded bg-sky-300 px-3 py-1 font-semibold text-zinc-950 hover:bg-sky-200">
                                        Год: {{ $year }} x
                                    </a>
                                @endif
                                @if ($invalidYear)
                                    <span class="rounded bg-red-400 px-3 py-1 font-semibold text-zinc-950">
                                        Год: {{ $requestedYear }} не найден
                                    </span>
                                @endif
                                @foreach ($activeTaxonomies as $filterType => $taxonomy)
                                    <a href="{{ route('titles.index', $filterQuery($filterType, null)) }}" class="rounded bg-emerald-400 px-3 py-1 font-semibold text-zinc-950 hover:bg-emerald-300">
                                        {{ $typeLabels[$filterType] ?? $filterType }}: {{ $taxonomy->name }} x
                                    </a>
                                @endforeach
                                @foreach ($invalidFilterSlugs as $filterType => $slug)
                                    <span class="rounded bg-red-400 px-3 py-1 font-semibold text-zinc-950">
                                        {{ $typeLabels[$filterType] ?? $filterType }}: {{ $slug }} не найден
                                    </span>
                                @endforeach
                            </div>
                            <div class="flex flex-wrap gap-3 text-zinc-400">
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
                                <a href="{{ route('titles.index') }}" class="text-emerald-300 hover:text-emerald-200">Сбросить все</a>
                            </div>
                        </div>
                    @else
                        <div class="mt-3 text-sm text-zinc-400">Найдено: {{ $titles->total() }}</div>
                    @endif
                </div>

                <form method="GET" action="{{ route('titles.index') }}" class="flex w-full max-w-md gap-2">
                    @foreach ($activeFilterSlugs as $filterType => $slug)
                        <input type="hidden" name="{{ $filterType }}" value="{{ $slug }}">
                    @endforeach
                    @if ($year !== null)
                        <input type="hidden" name="year" value="{{ $year }}">
                    @endif
                    <input
                        name="q"
                        value="{{ $search }}"
                        placeholder="Название, описание или тег"
                        class="min-w-0 flex-1 rounded-md border border-white/10 bg-white/[0.05] px-3 py-2 text-sm text-white placeholder:text-zinc-500 focus:border-emerald-300 focus:outline-none"
                    >
                    <button class="rounded-md bg-emerald-400 px-4 py-2 text-sm font-semibold text-zinc-950 hover:bg-emerald-300">Найти</button>
                </form>
            </div>

            <div class="mt-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
                @forelse ($titles as $catalogTitle)
                    <x-title-card :title="$catalogTitle" />
                @empty
                    <div class="col-span-full border border-dashed border-white/15 bg-white/[0.03] p-8 text-sm text-zinc-300">
                        Ничего не найдено.
                    </div>
                @endforelse
            </div>

            <div class="mt-8">
                {{ $titles->links() }}
            </div>
        </div>
    </section>
@endsection
