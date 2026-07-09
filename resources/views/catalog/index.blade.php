@extends('layouts.app')

@section('content')
    <section class="overflow-hidden rounded border border-[#b9c2c8] bg-white shadow-sm">
        <div class="border-b border-[#b9c2c8] bg-[#eef3f5] px-4 py-2 text-sm text-[#31424c]">
            На сайте <span class="font-bold text-emerald-700">{{ number_format($stats['titles']) }}</span> сериалов,
            страниц источника <span class="font-bold text-emerald-700">{{ number_format($stats['sourcePages']) }}</span>,
            ожидают парсинга <span class="font-bold text-orange-600">{{ number_format($stats['pendingPages']) }}</span>,
            подключено медиа <span class="font-bold text-emerald-700">{{ number_format($stats['licensedMedia']) }}</span>.
        </div>

        <div class="grid gap-0 lg:grid-cols-[230px_1fr_260px]">
            <aside class="border-b border-[#d4dce0] bg-[#f6f8f9] p-4 lg:border-b-0 lg:border-r">
                <div class="space-y-2">
                    <a href="{{ route('titles.index') }}" class="block rounded bg-[#31424c] px-3 py-2 text-sm font-bold text-white hover:bg-[#26333b]">Все сериалы</a>
                    <a href="{{ route('titles.index', ['q' => '2026']) }}" class="block rounded bg-white px-3 py-2 text-sm font-semibold text-[#31424c] ring-1 ring-[#d4dce0] hover:bg-emerald-50">Новинки</a>
                    <a href="{{ route('titles.index', ['q' => 'HD']) }}" class="block rounded bg-white px-3 py-2 text-sm font-semibold text-[#31424c] ring-1 ring-[#d4dce0] hover:bg-emerald-50">Высокое качество</a>
                    <span class="block rounded bg-white px-3 py-2 text-sm font-semibold text-zinc-500 ring-1 ring-[#d4dce0]">На чем остановились</span>
                </div>

                <div class="mt-5">
                    <div class="rounded-t bg-[#31424c] px-3 py-2 text-sm font-bold text-white">Фильтр сериалов</div>
                    <div class="space-y-3 rounded-b border border-[#d4dce0] bg-white p-3">
                        <select class="w-full rounded border border-[#cbd5da] bg-[#f8fafb] px-3 py-2 text-sm text-zinc-700">
                            <option>Любая страна</option>
                        </select>
                        <select class="w-full rounded border border-[#cbd5da] bg-[#f8fafb] px-3 py-2 text-sm text-zinc-700">
                            <option>Любой жанр</option>
                        </select>
                        <select class="w-full rounded border border-[#cbd5da] bg-[#f8fafb] px-3 py-2 text-sm text-zinc-700">
                            <option>Любой год</option>
                        </select>
                    </div>
                </div>

                <div class="mt-5">
                    <div class="rounded-t bg-[#31424c] px-3 py-2 text-sm font-bold text-white">Жанры</div>
                    <div class="flex flex-wrap gap-2 rounded-b border border-[#d4dce0] bg-white p-3">
                        @forelse ($genres->take(10) as $genre)
                            <a href="{{ route('titles.index', ['taxonomy' => $genre->slug, 'type' => $genre->type]) }}" class="rounded bg-[#eef3f5] px-2 py-1 text-xs font-semibold text-[#31424c] hover:bg-emerald-100">{{ $genre->name }}</a>
                        @empty
                            <span class="text-sm text-zinc-500">Жанры появятся после синхронизации.</span>
                        @endforelse
                    </div>
                </div>
            </aside>

            <section class="min-w-0 bg-white p-4">
                <div class="mb-4 flex flex-col gap-3 border-b border-[#d4dce0] pb-3 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <h1 class="text-2xl font-black text-[#26333b]">Сериалы онлайн</h1>
                        <p class="mt-1 text-sm text-zinc-500">Последние обновления каталога из карты сайта Seasonvar.</p>
                    </div>
                    <a href="{{ route('titles.index') }}" class="rounded bg-emerald-500 px-4 py-2 text-center text-sm font-bold text-white hover:bg-emerald-600">Открыть каталог</a>
                </div>

                <div class="rounded border border-[#d4dce0]">
                    <div class="bg-[#eef3f5] px-4 py-2 text-sm font-bold text-[#31424c]">{{ now()->format('d.m.Y') }}</div>

                    @forelse ($latestTitles as $catalogTitle)
                        <a href="{{ route('titles.show', $catalogTitle) }}" class="block border-t border-[#e5eaed] px-4 py-3 hover:bg-emerald-50">
                            <div class="flex gap-3">
                                <div class="h-16 w-11 shrink-0 overflow-hidden rounded bg-[#d4dce0]">
                                    @if ($catalogTitle->poster_url)
                                        <img src="{{ $catalogTitle->poster_url }}" alt="{{ $catalogTitle->title }}" class="h-full w-full object-cover">
                                    @endif
                                </div>
                                <div class="min-w-0 flex-1">
                                    <div class="flex flex-wrap items-center gap-2">
                                        <span class="font-bold text-[#26333b]">{{ $catalogTitle->title }}</span>
                                        @if ($catalogTitle->original_title)
                                            <span class="text-sm text-zinc-500">/ {{ $catalogTitle->original_title }}</span>
                                        @endif
                                    </div>
                                    <div class="mt-1 flex flex-wrap gap-2 text-xs text-zinc-500">
                                        @if ($catalogTitle->year)
                                            <span>{{ $catalogTitle->year }}</span>
                                        @endif
                                        <span>{{ $catalogTitle->seasons->count() }} сезон(ов)</span>
                                        @foreach ($catalogTitle->taxonomies->take(3) as $taxonomy)
                                            <span>{{ $taxonomy->name }}</span>
                                        @endforeach
                                    </div>
                                    @if ($catalogTitle->description)
                                        <p class="mt-1 line-clamp-2 text-sm leading-5 text-zinc-600">{{ $catalogTitle->description }}</p>
                                    @endif
                                </div>
                            </div>
                        </a>
                    @empty
                        <div class="border-t border-[#e5eaed] p-6 text-sm text-zinc-600">
                            Нет сериалов. Запусти <code class="rounded bg-[#eef3f5] px-2 py-1 font-semibold text-emerald-700">php artisan seasonvar:full-sync</code>.
                        </div>
                    @endforelse
                </div>

                <div class="mt-5 rounded border border-[#d4dce0] bg-[#f8fafb] p-4">
                    <h2 class="font-bold text-[#26333b]">Что делает синхронизация</h2>
                    <p class="mt-2 text-sm leading-6 text-zinc-600">
                        Команда <code class="rounded bg-white px-2 py-1 text-emerald-700">php artisan seasonvar:full-sync</code>
                        скачивает архивы карты сайта, распаковывает XML, объединяет ссылки, сохраняет страницы и бережно разбирает метаданные.
                    </p>
                </div>
            </section>

            <aside class="border-t border-[#d4dce0] bg-[#f6f8f9] p-4 lg:border-l lg:border-t-0">
                <div class="rounded border border-[#d4dce0] bg-white">
                    <div class="bg-[#31424c] px-3 py-2 text-sm font-bold text-white">Состояние базы</div>
                    <dl class="divide-y divide-[#e5eaed] text-sm">
                        <div class="flex items-center justify-between px-3 py-2">
                            <dt class="text-zinc-500">Сериалы</dt>
                            <dd class="font-bold text-[#26333b]">{{ number_format($stats['titles']) }}</dd>
                        </div>
                        <div class="flex items-center justify-between px-3 py-2">
                            <dt class="text-zinc-500">Страницы источника</dt>
                            <dd class="font-bold text-[#26333b]">{{ number_format($stats['sourcePages']) }}</dd>
                        </div>
                        <div class="flex items-center justify-between px-3 py-2">
                            <dt class="text-zinc-500">В очереди</dt>
                            <dd class="font-bold text-orange-600">{{ number_format($stats['pendingPages']) }}</dd>
                        </div>
                        <div class="flex items-center justify-between px-3 py-2">
                            <dt class="text-zinc-500">Медиа</dt>
                            <dd class="font-bold text-[#26333b]">{{ number_format($stats['licensedMedia']) }}</dd>
                        </div>
                    </dl>
                </div>

                <div class="mt-4 rounded border border-[#d4dce0] bg-white">
                    <div class="bg-[#31424c] px-3 py-2 text-sm font-bold text-white">Команда</div>
                    <div class="space-y-3 p-3 text-sm text-zinc-600">
                        <code class="block rounded bg-[#eef3f5] p-3 text-xs font-semibold text-emerald-700">php artisan seasonvar:full-sync</code>
                        <p>Одна команда для зеркала карты сайта, индекса ссылок, разбора страниц и обновления каталога.</p>
                    </div>
                </div>

                <div class="mt-4 rounded border border-[#d4dce0] bg-white">
                    <div class="bg-[#31424c] px-3 py-2 text-sm font-bold text-white">Топ жанры</div>
                    <div class="divide-y divide-[#e5eaed]">
                        @forelse ($genres->take(8) as $genre)
                            <a href="{{ route('titles.index', ['taxonomy' => $genre->slug, 'type' => $genre->type]) }}" class="flex items-center justify-between px-3 py-2 text-sm hover:bg-emerald-50">
                                <span class="font-semibold text-[#31424c]">{{ $genre->name }}</span>
                                <span class="text-xs text-zinc-500">{{ $genre->catalog_titles_count }}</span>
                            </a>
                        @empty
                            <p class="p-3 text-sm text-zinc-500">Нет жанров.</p>
                        @endforelse
                    </div>
                </div>
            </aside>
        </div>
    </section>
@endsection
