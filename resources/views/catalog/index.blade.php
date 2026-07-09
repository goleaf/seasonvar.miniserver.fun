@extends('layouts.app', ['title' => $seo['title'] ?? 'Сериалы тут', 'seo' => $seo ?? []])

@php
    $latestByDate = $latestTitles->groupBy(fn ($catalogTitle) => $catalogTitle->indexed_at?->format('d.m.Y') ?? now()->format('d.m.Y'));
@endphp

@section('content')
    <div class="space-y-5">
        <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
            <x-stat label="Сериалов" :value="$stats['titles']" icon="fa-solid fa-clapperboard" />
            <x-stat label="Серий" :value="$stats['episodes']" icon="fa-solid fa-circle-play" />
            <x-stat label="Жанров" :value="$stats['genres']" icon="fa-solid fa-masks-theater" />
            <x-stat label="Стран" :value="$stats['countries']" icon="fa-solid fa-earth-europe" />
        </div>

        <section class="grid gap-5 lg:grid-cols-[260px_minmax(0,1fr)] 2xl:grid-cols-[280px_minmax(0,1fr)]">
            <aside class="space-y-4 lg:order-1">
                <x-ui.panel title="Навигация" icon="fa-solid fa-compass">
                    <nav class="space-y-2">
                        <a href="{{ route('titles.index') }}" class="flex items-center gap-2 rounded-lg bg-emerald-50 px-3 py-2 text-sm font-bold text-emerald-700 ring-1 ring-emerald-100 hover:bg-emerald-100">
                            <i class="fa-solid fa-list" aria-hidden="true"></i>
                            <span>Все сериалы</span>
                        </a>
                        <a href="{{ route('titles.year', ['year' => now()->year]) }}" class="flex items-center gap-2 rounded-lg bg-white px-3 py-2 text-sm font-semibold text-slate-600 ring-1 ring-slate-200 hover:bg-emerald-50 hover:text-emerald-700">
                            <i class="fa-solid fa-star" aria-hidden="true"></i>
                            <span>Новинки</span>
                        </a>
                        @if (($subtitleTag?->catalog_titles_count ?? 0) > 0)
                            <a href="{{ route('titles.index', ['tag' => 'subtitry']) }}" class="flex items-center justify-between rounded-lg bg-white px-3 py-2 text-sm font-semibold text-slate-600 ring-1 ring-slate-200 hover:bg-emerald-50 hover:text-emerald-700">
                                <span class="inline-flex items-center gap-2">
                                    <i class="fa-solid fa-closed-captioning" aria-hidden="true"></i>
                                    <span>С субтитрами</span>
                                </span>
                                <span class="text-xs text-slate-400">{{ $subtitleTag->catalog_titles_count }}</span>
                            </a>
                        @else
                            <x-ui.taxonomy-chip muted count="0" icon="fa-solid fa-closed-captioning">С субтитрами</x-ui.taxonomy-chip>
                        @endif
                        <a href="{{ route('titles.index', ['genre' => 'otecestvennye']) }}" class="flex items-center gap-2 rounded-lg bg-white px-3 py-2 text-sm font-semibold text-slate-600 ring-1 ring-slate-200 hover:bg-emerald-50 hover:text-emerald-700">
                            <i class="fa-solid fa-flag" aria-hidden="true"></i>
                            <span>Отечественные</span>
                        </a>
                    </nav>
                </x-ui.panel>

                <x-ui.panel title="Фильтр сериалов" icon="fa-solid fa-filter">
                    <div class="space-y-2">
                        <a href="{{ route('titles.index') }}" class="flex items-center gap-2 rounded-lg bg-slate-50 px-3 py-2 text-sm font-semibold text-slate-600 ring-1 ring-slate-200 hover:bg-emerald-50 hover:text-emerald-700">
                            <i class="fa-solid fa-calendar-days" aria-hidden="true"></i>
                            <span>Любой год</span>
                        </a>
                        @foreach ($countries->take(4) as $country)
                            <a href="{{ route('titles.taxonomy', ['type' => $country->filterType(), 'taxonomy' => $country->slug]) }}" class="flex items-center justify-between rounded-lg bg-white px-3 py-2 text-sm text-slate-600 ring-1 ring-slate-200 hover:bg-emerald-50 hover:text-emerald-700">
                                <span class="inline-flex items-center gap-2">
                                    <i class="fa-solid fa-earth-europe text-slate-400" aria-hidden="true"></i>
                                    <span>{{ $country->name }}</span>
                                </span>
                                <span class="text-xs text-slate-400">{{ $country->catalog_titles_count }}</span>
                            </a>
                        @endforeach
                    </div>
                </x-ui.panel>

                <x-ui.panel title="Жанры" icon="fa-solid fa-masks-theater">
                    <div class="flex flex-wrap gap-2">
                        @forelse ($genres->take(14) as $genre)
                            <x-ui.taxonomy-chip :taxonomy="$genre" :count="$genre->catalog_titles_count" />
                        @empty
                            <span class="text-sm text-slate-500">Жанры появятся после обновления каталога.</span>
                        @endforelse
                    </div>
                </x-ui.panel>
            </aside>

            <x-ui.panel class="min-w-0 lg:order-2" :pad="false">
                <div class="flex flex-col gap-2 border-b border-slate-200 bg-slate-50 px-4 py-3 sm:flex-row sm:flex-wrap sm:items-center">
                    <h1 class="inline-flex items-center gap-2 text-2xl font-black text-slate-700">
                        <i class="fa-solid fa-clapperboard text-emerald-700" aria-hidden="true"></i>
                        <span>Сериалы тут</span>
                    </h1>
                </div>

                <div class="divide-y divide-slate-200">
                    @forelse ($latestByDate as $date => $titlesForDate)
                        <div class="flex items-center gap-2 bg-slate-50 px-4 py-2 text-sm font-bold text-slate-600">
                            <i class="fa-solid fa-calendar-days text-slate-400" aria-hidden="true"></i>
                            <span>{{ $date }}</span>
                        </div>

                        @foreach ($titlesForDate as $catalogTitle)
                            <x-title-list-row :title="$catalogTitle" />
                        @endforeach
                    @empty
                        <div class="p-6 text-sm text-slate-500">
                            Сериалы скоро появятся.
                        </div>
                    @endforelse
                </div>
            </x-ui.panel>
        </section>
    </div>
@endsection
