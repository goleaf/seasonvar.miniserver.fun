@extends('layouts.app', ['title' => 'Сериалы тут'])

@php
    $latestByDate = $latestTitles->groupBy(fn ($catalogTitle) => $catalogTitle->indexed_at?->format('d.m.Y') ?? now()->format('d.m.Y'));
@endphp

@section('content')
    <div class="space-y-5">
        <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
            <x-stat label="Сериалов" :value="$stats['titles']" />
            <x-stat label="Серий" :value="$stats['episodes']" />
            <x-stat label="Страниц источника" :value="$stats['sourcePages']" />
            <x-stat label="В очереди" :value="$stats['pendingPages']" />
        </div>

        <section class="grid gap-5 lg:grid-cols-[260px_minmax(0,1fr)] xl:grid-cols-[260px_minmax(0,1fr)_minmax(420px,520px)] 2xl:grid-cols-[280px_minmax(0,1fr)_minmax(520px,620px)]">
            <aside class="space-y-4 lg:order-1">
                <x-ui.panel title="Навигация">
                    <nav class="space-y-2">
                        <a href="{{ route('titles.index') }}" class="block rounded-lg bg-emerald-50 px-3 py-2 text-sm font-bold text-emerald-700 ring-1 ring-emerald-100 hover:bg-emerald-100">Все сериалы</a>
                        <a href="{{ route('titles.index', ['year' => now()->year]) }}" class="block rounded-lg bg-white px-3 py-2 text-sm font-semibold text-slate-600 ring-1 ring-slate-200 hover:bg-emerald-50 hover:text-emerald-700">Новинки</a>
                        @if (($subtitleTag?->catalog_titles_count ?? 0) > 0)
                            <a href="{{ route('titles.index', ['tag' => 'subtitry']) }}" class="flex items-center justify-between rounded-lg bg-white px-3 py-2 text-sm font-semibold text-slate-600 ring-1 ring-slate-200 hover:bg-emerald-50 hover:text-emerald-700">
                                <span>С субтитрами</span>
                                <span class="text-xs text-slate-400">{{ $subtitleTag->catalog_titles_count }}</span>
                            </a>
                        @else
                            <x-ui.taxonomy-chip muted count="0">С субтитрами</x-ui.taxonomy-chip>
                        @endif
                        <a href="{{ route('titles.index', ['genre' => 'otecestvennye']) }}" class="block rounded-lg bg-white px-3 py-2 text-sm font-semibold text-slate-600 ring-1 ring-slate-200 hover:bg-emerald-50 hover:text-emerald-700">Отечественные</a>
                    </nav>
                </x-ui.panel>

                <x-ui.panel title="Фильтр сериалов">
                    <div class="space-y-2">
                        <a href="{{ route('titles.index') }}" class="block rounded-lg bg-slate-50 px-3 py-2 text-sm font-semibold text-slate-600 ring-1 ring-slate-200 hover:bg-emerald-50 hover:text-emerald-700">Любой год</a>
                        @foreach ($countries->take(4) as $country)
                            <a href="{{ route('titles.taxonomy', ['type' => $country->filterType(), 'taxonomy' => $country->slug]) }}" class="flex items-center justify-between rounded-lg bg-white px-3 py-2 text-sm text-slate-600 ring-1 ring-slate-200 hover:bg-emerald-50 hover:text-emerald-700">
                                <span>{{ $country->name }}</span>
                                <span class="text-xs text-slate-400">{{ $country->catalog_titles_count }}</span>
                            </a>
                        @endforeach
                    </div>
                </x-ui.panel>

                <x-ui.panel title="Жанры">
                    <div class="flex flex-wrap gap-2">
                        @forelse ($genres->take(14) as $genre)
                            <x-ui.taxonomy-chip :taxonomy="$genre" :count="$genre->catalog_titles_count" />
                        @empty
                            <span class="text-sm text-slate-500">Жанры появятся после синхронизации.</span>
                        @endforelse
                    </div>
                </x-ui.panel>
            </aside>

            <x-ui.panel class="min-w-0 lg:order-2" :pad="false">
                <div class="flex flex-col gap-2 border-b border-slate-200 bg-slate-50 px-4 py-3 sm:flex-row sm:flex-wrap sm:items-center">
                    <h1 class="mr-auto text-2xl font-black text-slate-700">Сериалы тут</h1>
                    <div class="flex flex-wrap gap-2">
                        <span class="rounded-full bg-emerald-50 px-3 py-1 text-xs font-bold text-emerald-700 ring-1 ring-emerald-100">список</span>
                        <span class="rounded-full bg-slate-50 px-3 py-1 text-xs font-bold text-slate-500 ring-1 ring-slate-200">светлая тема</span>
                    </div>
                </div>

                <div class="divide-y divide-slate-200">
                    @forelse ($latestByDate as $date => $titlesForDate)
                        <div class="bg-slate-50 px-4 py-2 text-sm font-bold text-slate-600">{{ $date }}</div>

                        @foreach ($titlesForDate as $catalogTitle)
                            <x-title-list-row :title="$catalogTitle" />
                        @endforeach
                    @empty
                        <div class="p-6 text-sm text-slate-500">
                            Нет сериалов. Запусти <code class="rounded bg-slate-50 px-2 py-1 font-semibold text-emerald-700">php artisan seasonvar:full-sync</code>.
                        </div>
                    @endforelse
                </div>
            </x-ui.panel>

            <aside class="space-y-4 lg:order-3 lg:col-span-2 xl:col-span-1">
                <x-ui.panel title="Состояние базы">
                    <dl class="space-y-2 text-sm">
                        <div class="flex items-center justify-between rounded-lg bg-slate-50 px-3 py-2">
                            <dt class="text-slate-500">Сериалы</dt>
                            <dd class="font-bold text-slate-700">{{ number_format($stats['titles']) }}</dd>
                        </div>
                        <div class="flex items-center justify-between rounded-lg bg-slate-50 px-3 py-2">
                            <dt class="text-slate-500">Серии</dt>
                            <dd class="font-bold text-slate-700">{{ number_format($stats['episodes']) }}</dd>
                        </div>
                        <div class="flex items-center justify-between rounded-lg bg-slate-50 px-3 py-2">
                            <dt class="text-slate-500">Медиа</dt>
                            <dd class="font-bold text-slate-700">{{ number_format($stats['licensedMedia']) }}</dd>
                        </div>
                    </dl>
                </x-ui.panel>
            </aside>
        </section>
    </div>
@endsection
