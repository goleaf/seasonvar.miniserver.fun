@extends('layouts.app', ['title' => $seo['title'] ?? 'Сериалы онлайн', 'seo' => $seo ?? []])

@section('content')
    <div class="space-y-5">
        <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-5">
            <x-stat label="Сериалов" :value="$stats['titles']" icon="fa-solid fa-clapperboard" />
            <x-stat label="Серий" :value="$stats['episodes']" icon="fa-solid fa-circle-play" />
            <x-stat label="Видео" :value="$stats['videos']" icon="fa-solid fa-file-video" />
            <x-stat label="Жанров" :value="$stats['genres']" icon="fa-solid fa-masks-theater" />
            <x-stat label="Стран" :value="$stats['countries']" icon="fa-solid fa-earth-europe" />
        </div>

        <section class="grid gap-5 xl:grid-cols-[300px_minmax(0,1fr)] 2xl:grid-cols-[320px_minmax(0,1fr)]">
            <main class="min-w-0 space-y-5 xl:order-2">
                <x-ui.panel :pad="false">
                    <div class="grid gap-4 p-4 lg:grid-cols-[minmax(0,1fr)_auto] lg:items-center lg:p-5">
                        <div class="min-w-0">
                            <h1 class="inline-flex items-start gap-2 text-2xl font-black leading-tight text-slate-700 sm:text-3xl">
                                <i class="fa-solid fa-clapperboard mt-1 text-emerald-700" aria-hidden="true"></i>
                                <span>Сериалы онлайн</span>
                            </h1>
                            <p class="mt-2 max-w-3xl text-sm leading-6 text-slate-600">
                                Быстрый каталог сериалов: свежие обновления, серии, жанры, страны и подборки по годам.
                            </p>
                        </div>

                        <div class="flex flex-wrap gap-2">
                            <a href="{{ route('titles.index') }}" class="inline-flex items-center justify-center gap-2 rounded-lg bg-emerald-50 px-3 py-2 text-sm font-bold text-emerald-700 ring-1 ring-emerald-100 hover:bg-emerald-100">
                                <i class="fa-solid fa-list" aria-hidden="true"></i>
                                <span>Все сериалы</span>
                            </a>
                            <a href="{{ route('titles.year', ['year' => now()->year]) }}" class="inline-flex items-center justify-center gap-2 rounded-lg bg-sky-50 px-3 py-2 text-sm font-bold text-sky-700 ring-1 ring-sky-100 hover:bg-sky-100">
                                <i class="fa-solid fa-fire" aria-hidden="true"></i>
                                <span>Новинки</span>
                            </a>
                            @if (($subtitleTag?->catalog_titles_count ?? 0) > 0)
                                <a href="{{ route('titles.index', ['tag' => 'subtitry']) }}" class="inline-flex items-center justify-center gap-2 rounded-lg bg-amber-50 px-3 py-2 text-sm font-bold text-amber-700 ring-1 ring-amber-100 hover:bg-amber-100">
                                    <i class="fa-solid fa-closed-captioning" aria-hidden="true"></i>
                                    <span>С субтитрами</span>
                                </a>
                            @endif
                        </div>
                    </div>
                </x-ui.panel>

                <x-ui.panel title="Последние обновления" icon="fa-solid fa-clock-rotate-left" :pad="false">
                    <div class="grid auto-rows-fr gap-3 p-3 sm:grid-cols-2 md:grid-cols-3 xl:grid-cols-4 2xl:grid-cols-6">
                        @forelse ($featuredTitles as $catalogTitle)
                            <x-title-card :title="$catalogTitle" />
                        @empty
                            <div class="col-span-full rounded-lg border border-dashed border-slate-200 p-4 text-sm text-slate-500">
                                Сериалы пока не добавлены.
                            </div>
                        @endforelse
                    </div>
                </x-ui.panel>

                <x-ui.panel title="Новые серии" icon="fa-solid fa-circle-play" :pad="false">
                    <div class="grid gap-3 p-3 lg:grid-cols-2">
                        @forelse ($latestMedia as $media)
                            @if ($media->catalogTitle)
                                <a href="{{ route('titles.show', ['catalogTitle' => $media->catalogTitle, 'episode' => $media->episode_id, 'media' => $media->id]) }}#player" class="group flex min-w-0 gap-3 rounded-lg border border-slate-200 bg-white p-3 shadow-sm shadow-slate-200/60 transition hover:border-emerald-300 hover:bg-emerald-50">
                                    <x-title-poster :title="$media->catalogTitle" class="h-24 w-16 shrink-0" empty-class="grid h-full place-items-center text-[10px] text-slate-400" />
                                    <div class="min-w-0 flex-1">
                                        <div class="font-bold leading-5 text-slate-700 group-hover:text-emerald-700">{{ $media->catalogTitle->title }}</div>
                                        <div class="mt-2 flex flex-wrap gap-1 text-xs font-semibold">
                                            @if ($media->season)
                                                <span class="inline-flex items-center gap-1 rounded-full bg-emerald-50 px-2 py-1 text-emerald-700 ring-1 ring-emerald-100">
                                                    <i class="fa-solid fa-layer-group" aria-hidden="true"></i>
                                                    <span>Сезон {{ $media->season->number }}</span>
                                                </span>
                                            @endif
                                            @if ($media->episode)
                                                <span class="inline-flex items-center gap-1 rounded-full bg-sky-50 px-2 py-1 text-sky-700 ring-1 ring-sky-100">
                                                    <i class="fa-solid fa-list-ol" aria-hidden="true"></i>
                                                    <span>{{ $media->episode->number }} серия</span>
                                                </span>
                                            @endif
                                            @if ($media->quality)
                                                <span class="inline-flex items-center gap-1 rounded-full bg-amber-50 px-2 py-1 text-amber-700 ring-1 ring-amber-100">
                                                    <i class="fa-solid fa-display" aria-hidden="true"></i>
                                                    <span>{{ strtoupper($media->quality) }}</span>
                                                </span>
                                            @endif
                                        </div>
                                        <div class="mt-2 text-xs text-slate-500">
                                            {{ collect([$media->translation_name, $media->format ? strtoupper($media->format) : null, $media->published_at?->format('d.m.Y')])->filter()->implode(' / ') ?: 'Видео сериала' }}
                                        </div>
                                    </div>
                                </a>
                            @endif
                        @empty
                            <div class="rounded-lg border border-dashed border-slate-200 p-4 text-sm text-slate-500 lg:col-span-2">
                                Новых серий пока нет.
                            </div>
                        @endforelse
                    </div>
                </x-ui.panel>

                <div class="grid gap-5 2xl:grid-cols-2">
                    <x-ui.panel title="Доступно к просмотру" icon="fa-solid fa-file-video" :pad="false">
                        <div class="grid auto-rows-fr gap-3 p-3 sm:grid-cols-2 xl:grid-cols-4 2xl:grid-cols-2">
                            @forelse ($videoTitles as $catalogTitle)
                                <x-title-card :title="$catalogTitle" />
                            @empty
                                <div class="col-span-full rounded-lg border border-dashed border-slate-200 p-4 text-sm text-slate-500">
                                    Видео пока нет.
                                </div>
                            @endforelse
                        </div>
                    </x-ui.panel>

                    <x-ui.panel title="Быстрый выбор" icon="fa-solid fa-bolt" :pad="false">
                        <div class="grid gap-3 p-3 sm:grid-cols-2">
                            <div class="rounded-lg border border-slate-200 bg-slate-50 p-3">
                                <div class="mb-2 inline-flex items-center gap-2 text-sm font-bold text-slate-700">
                                    <i class="fa-solid fa-list-ol text-emerald-700" aria-hidden="true"></i>
                                    <span>Многосерийные</span>
                                </div>
                                <div class="space-y-2">
                                    @forelse ($longRunningTitles->take(5) as $catalogTitle)
                                        <x-title-list-row :title="$catalogTitle" compact :show-description="false" />
                                    @empty
                                        <p class="text-sm text-slate-500">Подборка пока пуста.</p>
                                    @endforelse
                                </div>
                            </div>

                            <div class="rounded-lg border border-slate-200 bg-slate-50 p-3">
                                <div class="mb-2 inline-flex items-center gap-2 text-sm font-bold text-slate-700">
                                    <i class="fa-solid fa-stopwatch text-emerald-700" aria-hidden="true"></i>
                                    <span>Короткие сериалы</span>
                                </div>
                                <div class="space-y-2">
                                    @forelse ($shortTitles->take(5) as $catalogTitle)
                                        <x-title-list-row :title="$catalogTitle" compact :show-description="false" />
                                    @empty
                                        <p class="text-sm text-slate-500">Подборка пока пуста.</p>
                                    @endforelse
                                </div>
                            </div>
                        </div>
                    </x-ui.panel>
                </div>

                <x-ui.panel title="Лента обновлений по датам" icon="fa-solid fa-calendar-days" :pad="false">
                    <div class="divide-y divide-slate-200">
                        @forelse ($latestByDate as $date => $titlesForDate)
                            <div class="flex items-center gap-2 bg-slate-50 px-4 py-2 text-sm font-bold text-slate-600">
                                <i class="fa-solid fa-calendar-days text-slate-400" aria-hidden="true"></i>
                                <span>{{ $date }}</span>
                            </div>

                            @foreach ($titlesForDate->take(8) as $catalogTitle)
                                <x-title-list-row :title="$catalogTitle" />
                            @endforeach
                        @empty
                            <div class="p-6 text-sm text-slate-500">
                                Сериалы пока не добавлены.
                            </div>
                        @endforelse
                    </div>
                </x-ui.panel>
            </main>

            <aside class="space-y-4 xl:order-1">
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
                            <span class="text-sm text-slate-500">Жанры не указаны.</span>
                        @endforelse
                    </div>
                </x-ui.panel>

                <x-ui.panel title="Годы" icon="fa-solid fa-calendar-days">
                    <div class="flex flex-wrap gap-2">
                        @forelse ($yearBuckets as $bucket)
                            <a href="{{ route('titles.year', ['year' => $bucket->year]) }}" class="inline-flex items-center gap-1 rounded-full bg-white px-2.5 py-1 text-xs font-semibold text-slate-600 ring-1 ring-slate-200 hover:bg-emerald-50 hover:text-emerald-700">
                                <i class="fa-solid fa-calendar-days text-[0.8em] text-slate-400" aria-hidden="true"></i>
                                <span>{{ $bucket->year }}</span>
                                <span class="text-slate-400">{{ $bucket->titles_count }}</span>
                            </a>
                        @empty
                            <span class="text-sm text-slate-500">Годы не указаны.</span>
                        @endforelse
                    </div>
                </x-ui.panel>
            </aside>
        </section>
    </div>
@endsection
