@extends('layouts.app', ['title' => 'Сериалы тут'])

@php
    $latestByDate = $latestTitles->groupBy(fn ($catalogTitle) => $catalogTitle->indexed_at?->format('d.m.Y') ?? now()->format('d.m.Y'));
@endphp

@section('content')
    <div class="-mx-4 -my-6 bg-[#dfe7eb] px-4 py-6 sm:-mx-6 sm:px-6 lg:-mx-8 lg:px-8">
    <section class="overflow-hidden rounded border border-[#9facb3] bg-white text-[#26333b] shadow-sm">
        <div class="border-b border-[#9facb3] bg-[#eef3f5] px-4 py-2 text-sm text-[#31424c]">
            На сайте <span class="font-bold text-emerald-700">{{ number_format($stats['titles']) }}</span> сериалов,
            <span class="font-bold text-emerald-700">{{ number_format($stats['episodes']) }}</span> серий,
            <span class="font-bold text-emerald-700">{{ number_format($stats['sourcePages']) }}</span> страниц источника.
            @if ($stats['pendingPages'] > 0)
                В очереди <span class="font-bold text-orange-600">{{ number_format($stats['pendingPages']) }}</span>.
            @endif
        </div>

        <div class="grid gap-0 lg:grid-cols-[220px_minmax(0,1fr)_300px]">
            <aside class="border-b border-[#d4dce0] bg-[#f6f8f9] p-3 lg:border-b-0 lg:border-r">
                <nav class="space-y-2">
                    <a href="{{ route('titles.index') }}" class="block rounded bg-[#31424c] px-3 py-2 text-sm font-bold text-white hover:bg-[#26333b]">Все сериалы</a>
                    <a href="{{ route('titles.index', ['year' => now()->year]) }}" class="block rounded bg-white px-3 py-2 text-sm font-semibold text-[#31424c] ring-1 ring-[#d4dce0] hover:bg-emerald-50">Новинки</a>
                    @if (($subtitleTag?->catalog_titles_count ?? 0) > 0)
                        <a href="{{ route('titles.index', ['tag' => 'subtitry']) }}" class="flex items-center justify-between rounded bg-white px-3 py-2 text-sm font-semibold text-[#31424c] ring-1 ring-[#d4dce0] hover:bg-emerald-50">
                            <span>С субтитрами</span>
                            <span class="text-xs text-zinc-500">{{ $subtitleTag->catalog_titles_count }}</span>
                        </a>
                    @else
                        <span class="flex items-center justify-between rounded bg-[#eef3f5] px-3 py-2 text-sm font-semibold text-zinc-400 ring-1 ring-[#d4dce0]">
                            <span>С субтитрами</span>
                            <span class="text-xs">0</span>
                        </span>
                    @endif
                    <a href="{{ route('titles.index', ['genre' => 'otecestvennye']) }}" class="block rounded bg-white px-3 py-2 text-sm font-semibold text-[#31424c] ring-1 ring-[#d4dce0] hover:bg-emerald-50">Отечественные</a>
                </nav>

                <div class="mt-4 rounded border border-[#d4dce0] bg-white">
                    <div class="bg-[#31424c] px-3 py-2 text-sm font-bold text-white">Фильтр сериалов</div>
                    <div class="space-y-2 p-3">
                        <a href="{{ route('titles.index') }}" class="block rounded bg-[#eef3f5] px-3 py-2 text-sm font-semibold text-[#31424c] hover:bg-emerald-100">Любой год</a>
                        @foreach ($countries->take(4) as $country)
                            <a href="{{ route('titles.taxonomy', ['type' => $country->type, 'taxonomy' => $country->slug]) }}" class="flex items-center justify-between rounded bg-[#f8fafb] px-3 py-2 text-sm text-[#31424c] ring-1 ring-[#e5eaed] hover:bg-emerald-50">
                                <span>{{ $country->name }}</span>
                                <span class="text-xs text-zinc-500">{{ $country->catalog_titles_count }}</span>
                            </a>
                        @endforeach
                    </div>
                </div>

                <div class="mt-4 rounded border border-[#d4dce0] bg-white">
                    <div class="bg-[#31424c] px-3 py-2 text-sm font-bold text-white">Жанры</div>
                    <div class="flex flex-wrap gap-2 p-3">
                        @forelse ($genres->take(14) as $genre)
                            <a href="{{ route('titles.taxonomy', ['type' => $genre->type, 'taxonomy' => $genre->slug]) }}" class="rounded bg-[#eef3f5] px-2 py-1 text-xs font-semibold text-[#31424c] hover:bg-emerald-100">{{ $genre->name }}</a>
                        @empty
                            <span class="text-sm text-zinc-500">Жанры появятся после синхронизации.</span>
                        @endforelse
                    </div>
                </div>
            </aside>

            <section class="min-w-0 bg-white p-3 sm:p-4">
                <div class="mb-3 flex flex-wrap items-center gap-2 border-b border-[#d4dce0] pb-3">
                    <h1 class="mr-auto text-2xl font-black text-[#26333b]">Сериалы тут</h1>
                    <span class="rounded bg-[#eef3f5] px-3 py-1 text-xs font-bold text-[#31424c] ring-1 ring-[#d4dce0]">плитка</span>
                    <span class="rounded bg-[#31424c] px-3 py-1 text-xs font-bold text-white">список</span>
                </div>

                <div class="rounded border border-[#d4dce0]">
                    @forelse ($latestByDate as $date => $titlesForDate)
                        <div class="bg-[#eef3f5] px-4 py-2 text-sm font-bold text-[#31424c]">{{ $date }}</div>

                        @foreach ($titlesForDate as $catalogTitle)
                            @php
                                $seasonsCount = (int) ($catalogTitle->seasons_count ?? $catalogTitle->seasons->count());
                                $episodesCount = (int) ($catalogTitle->episodes_count ?? 0);
                            @endphp
                            <a href="{{ route('titles.show', $catalogTitle) }}" class="block border-t border-[#e5eaed] px-4 py-2.5 hover:bg-emerald-50">
                                <div class="flex flex-col gap-1 sm:flex-row sm:items-center">
                                    <div class="min-w-0 flex-1">
                                        <span class="font-bold text-[#26333b]">{{ $catalogTitle->title }}</span>
                                        @if ($catalogTitle->original_title)
                                            <span class="text-zinc-500"> / {{ $catalogTitle->original_title }}</span>
                                        @endif
                                        @if ($catalogTitle->seasons->isNotEmpty())
                                            <span class="ml-1 text-sm text-zinc-500">({{ $catalogTitle->seasons->sortByDesc('number')->first()->number }} сезон)</span>
                                        @endif
                                    </div>
                                    <div class="flex shrink-0 flex-wrap gap-2 text-xs font-semibold">
                                        @if ($catalogTitle->year)
                                            <span class="rounded bg-[#f8fafb] px-2 py-1 text-zinc-500 ring-1 ring-[#e5eaed]">{{ $catalogTitle->year }}</span>
                                        @endif
                                        <span class="rounded bg-emerald-100 px-2 py-1 text-emerald-800">{{ $seasonsCount }} сезон(ов)</span>
                                        <span class="rounded bg-sky-100 px-2 py-1 text-sky-800">{{ $episodesCount }} серий</span>
                                    </div>
                                </div>
                            </a>
                        @endforeach
                    @empty
                        <div class="p-6 text-sm text-zinc-600">
                            Нет сериалов. Запусти <code class="rounded bg-[#eef3f5] px-2 py-1 font-semibold text-emerald-700">php artisan seasonvar:full-sync</code>.
                        </div>
                    @endforelse
                </div>
            </section>

            <aside class="border-t border-[#d4dce0] bg-[#f6f8f9] p-3 lg:border-l lg:border-t-0">
                <div class="rounded border border-[#d4dce0] bg-white">
                    <div class="flex items-center justify-between bg-[#31424c] px-3 py-2 text-sm font-bold text-white">
                        <span>Новые серии</span>
                        <span class="rounded bg-emerald-400 px-2 py-0.5 text-xs text-[#17242c]">{{ $posterTitles->count() }}</span>
                    </div>

                    <div class="divide-y divide-[#e5eaed]">
                        @forelse ($posterTitles as $posterTitle)
                            <a href="{{ route('titles.show', $posterTitle) }}" class="flex gap-3 p-3 hover:bg-emerald-50">
                                <div class="h-24 w-16 shrink-0 overflow-hidden rounded bg-[#d4dce0]">
                                    <img src="{{ $posterTitle->poster_url }}" alt="{{ $posterTitle->title }}" class="h-full w-full object-cover">
                                </div>
                                <div class="min-w-0 flex-1">
                                    <div class="line-clamp-2 text-sm font-bold leading-5 text-[#26333b]">{{ $posterTitle->title }}</div>
                                    @if ($posterTitle->original_title)
                                        <div class="mt-0.5 line-clamp-1 text-xs text-zinc-500">{{ $posterTitle->original_title }}</div>
                                    @endif
                                    <div class="mt-2 text-xs text-zinc-500">
                                        @if ($posterTitle->seasons->isNotEmpty())
                                            Сезон {{ $posterTitle->seasons->sortByDesc('number')->first()->number }}
                                        @else
                                            Сезон разбирается
                                        @endif
                                    </div>
                                    <div class="mt-2 inline-flex rounded bg-emerald-500 px-2 py-1 text-xs font-bold text-white">Смотреть</div>
                                </div>
                            </a>
                        @empty
                            <p class="p-3 text-sm text-zinc-500">Постеры появятся после синхронизации.</p>
                        @endforelse
                    </div>
                </div>

                <div class="mt-4 rounded border border-[#d4dce0] bg-white">
                    <div class="bg-[#31424c] px-3 py-2 text-sm font-bold text-white">Состояние базы</div>
                    <dl class="divide-y divide-[#e5eaed] text-sm">
                        <div class="flex items-center justify-between px-3 py-2">
                            <dt class="text-zinc-500">Сериалы</dt>
                            <dd class="font-bold text-[#26333b]">{{ number_format($stats['titles']) }}</dd>
                        </div>
                        <div class="flex items-center justify-between px-3 py-2">
                            <dt class="text-zinc-500">Серии</dt>
                            <dd class="font-bold text-[#26333b]">{{ number_format($stats['episodes']) }}</dd>
                        </div>
                        <div class="flex items-center justify-between px-3 py-2">
                            <dt class="text-zinc-500">Медиа</dt>
                            <dd class="font-bold text-[#26333b]">{{ number_format($stats['licensedMedia']) }}</dd>
                        </div>
                    </dl>
                </div>
            </aside>
        </div>
    </section>
    </div>
@endsection
