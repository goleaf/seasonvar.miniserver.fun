@extends('layouts.app', ['title' => $title->title])

@php
    $genres = $title->taxonomies->where('type', 'genre')->values();
    $countries = $title->taxonomies->where('type', 'country')->values();
    $actors = $title->taxonomies->where('type', 'actor')->values();
    $directors = $title->taxonomies->where('type', 'director')->values();
    $ageRatings = $title->taxonomies->where('type', 'age_rating')->values();
    $translations = $title->taxonomies->where('type', 'translation')->values();
    $statuses = $title->taxonomies->where('type', 'status')->values();
    $networks = $title->taxonomies->where('type', 'network')->values();
    $studios = $title->taxonomies->where('type', 'studio')->values();
    $tags = $title->taxonomies->where('type', 'tag')->values();
    $taxonomyLabels = [
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
    $seasons = $title->seasons->sortBy('number')->values();
    $mediaItems = $mediaItems ?? collect();
    $selectedMediaUrl = $selectedMedia ? ($selectedMedia->playback_url ?: $selectedMedia->path) : null;
@endphp

@section('content')
    <section class="overflow-hidden rounded border border-[#b9c2c8] bg-white text-zinc-900 shadow-sm">
        <div class="grid gap-0 lg:grid-cols-[1fr_280px]">
            <main class="min-w-0">
                <div class="border-b border-[#d4dce0] bg-[#f8fafb] px-4 py-3">
                    <a href="{{ route('home') }}" class="inline-flex items-center rounded bg-[#31424c] px-3 py-2 text-sm font-bold text-white hover:bg-[#26333b]">Вернуться</a>
                </div>

                <article class="p-4">
                    <div class="rounded border border-[#d4dce0] bg-white">
                        <div class="grid gap-4 p-4 md:grid-cols-[220px_1fr]">
                            <div>
                                <div class="overflow-hidden rounded border border-[#cbd5da] bg-[#eef3f5]">
                                    @if ($title->poster_url)
                                        <img src="{{ $title->poster_url }}" alt="{{ $title->title }}" class="aspect-[2/3] w-full object-cover">
                                    @else
                                        <div class="grid aspect-[2/3] place-items-center px-6 text-center text-sm text-zinc-500">Нет постера</div>
                                    @endif
                                </div>

	                                <div class="mt-3 grid grid-cols-2 gap-2 text-center text-xs font-bold">
	                                    <span class="rounded bg-emerald-100 px-2 py-2 text-emerald-800">метаданные</span>
	                                    <span class="rounded bg-orange-100 px-2 py-2 text-orange-800">{{ $selectedMedia ? 'плеер' : 'нет медиа' }}</span>
                                </div>
                            </div>

                            <div class="min-w-0">
                                <h1 class="text-2xl font-black leading-tight text-[#26333b]">Сериал {{ $title->title }} онлайн</h1>
                                <div class="mt-3 flex flex-wrap gap-2 text-xs font-bold">
                                    @if ($title->year)
                                        <a href="{{ route('titles.index', ['year' => $title->year]) }}" class="rounded bg-sky-100 px-2 py-1 text-sky-800 ring-1 ring-sky-200">{{ $title->year }}</a>
                                    @endif
                                    @foreach ($ageRatings as $ageRating)
                                        <a href="{{ route('titles.taxonomy', ['type' => $ageRating->type, 'taxonomy' => $ageRating->slug]) }}" class="rounded bg-orange-100 px-2 py-1 text-orange-800 ring-1 ring-orange-200">{{ $ageRating->name }}</a>
                                    @endforeach
                                    @if ($seasons->isNotEmpty())
                                        <span class="rounded bg-emerald-100 px-2 py-1 text-emerald-800 ring-1 ring-emerald-200">{{ $seasons->count() }} сезон(ов)</span>
                                    @endif
                                </div>

                                @if ($title->description)
                                    <p class="mt-4 text-sm leading-6 text-zinc-700">{{ $title->description }}</p>
                                @endif

                                @if ($actors->isNotEmpty())
                                    <div class="mt-5">
                                        <div class="text-sm font-bold text-[#31424c]">В ролях</div>
                                        <div class="mt-2 flex flex-wrap gap-2">
                                            @foreach ($actors->take(12) as $actor)
                                                <a href="{{ route('titles.taxonomy', ['type' => $actor->type, 'taxonomy' => $actor->slug]) }}" class="rounded border border-[#d4dce0] bg-[#f8fafb] px-3 py-1 text-xs font-semibold text-[#31424c] hover:bg-emerald-50">{{ $actor->name }}</a>
                                            @endforeach
                                        </div>
                                    </div>
                                @endif

                                <dl class="mt-5 space-y-2 text-sm">
                                    @if ($title->original_title)
                                        <div class="border-t border-[#e5eaed] pt-2">
                                            <dt class="inline font-bold text-zinc-500">Оригинал:</dt>
                                            <dd class="inline text-zinc-800">{{ $title->original_title }}</dd>
                                        </div>
                                    @endif

                                    @if ($genres->isNotEmpty())
                                        <div class="border-t border-[#e5eaed] pt-2">
                                            <dt class="inline font-bold text-zinc-500">Жанр:</dt>
                                            <dd class="inline-flex flex-wrap gap-1 align-middle">
                                                @foreach ($genres as $genre)
                                                    <a href="{{ route('titles.taxonomy', ['type' => $genre->type, 'taxonomy' => $genre->slug]) }}" class="rounded bg-[#eef3f5] px-2 py-0.5 text-xs font-semibold text-[#31424c] hover:bg-emerald-100">{{ $genre->name }}</a>
                                                @endforeach
                                            </dd>
                                        </div>
                                    @endif

                                    @if ($ageRatings->isNotEmpty())
                                        <div class="border-t border-[#e5eaed] pt-2">
                                            <dt class="inline font-bold text-zinc-500">Ограничение:</dt>
                                            <dd class="inline-flex flex-wrap gap-1 align-middle">
                                                @foreach ($ageRatings as $ageRating)
                                                    <a href="{{ route('titles.taxonomy', ['type' => $ageRating->type, 'taxonomy' => $ageRating->slug]) }}" class="rounded bg-orange-100 px-2 py-0.5 text-xs font-semibold text-orange-800 hover:bg-orange-200">{{ $ageRating->name }}</a>
                                                @endforeach
                                            </dd>
                                        </div>
                                    @endif

                                    @if ($countries->isNotEmpty())
                                        <div class="border-t border-[#e5eaed] pt-2">
                                            <dt class="inline font-bold text-zinc-500">Страна:</dt>
                                            <dd class="inline-flex flex-wrap gap-1 align-middle">
                                                @foreach ($countries as $country)
                                                    <a href="{{ route('titles.taxonomy', ['type' => $country->type, 'taxonomy' => $country->slug]) }}" class="rounded bg-[#eef3f5] px-2 py-0.5 text-xs font-semibold text-[#31424c] hover:bg-emerald-100">{{ $country->name }}</a>
                                                @endforeach
                                            </dd>
                                        </div>
                                    @endif

                                    @if ($title->year)
                                        <div class="border-t border-[#e5eaed] pt-2">
                                            <dt class="inline font-bold text-zinc-500">Вышел:</dt>
                                            <dd class="inline"><a href="{{ route('titles.index', ['year' => $title->year]) }}" class="font-semibold text-emerald-700 hover:text-emerald-600">{{ $title->year }}</a></dd>
                                        </div>
                                    @endif

                                    @if ($directors->isNotEmpty())
                                        <div class="border-t border-[#e5eaed] pt-2">
                                            <dt class="inline font-bold text-zinc-500">Режиссер:</dt>
                                            <dd class="inline-flex flex-wrap gap-1 align-middle">
                                                @foreach ($directors as $director)
                                                    <a href="{{ route('titles.taxonomy', ['type' => $director->type, 'taxonomy' => $director->slug]) }}" class="rounded bg-[#eef3f5] px-2 py-0.5 text-xs font-semibold text-[#31424c] hover:bg-emerald-100">{{ $director->name }}</a>
                                                @endforeach
                                            </dd>
                                        </div>
                                    @endif

                                    @if ($translations->isNotEmpty())
                                        <div class="border-t border-[#e5eaed] pt-2">
                                            <dt class="inline font-bold text-zinc-500">Перевод:</dt>
                                            <dd class="inline-flex flex-wrap gap-1 align-middle">
                                                @foreach ($translations as $translation)
                                                    <a href="{{ route('titles.taxonomy', ['type' => $translation->type, 'taxonomy' => $translation->slug]) }}" class="rounded bg-[#eef3f5] px-2 py-0.5 text-xs font-semibold text-[#31424c] hover:bg-emerald-100">{{ $translation->name }}</a>
                                                @endforeach
                                            </dd>
                                        </div>
                                    @endif

                                    @if ($statuses->isNotEmpty())
                                        <div class="border-t border-[#e5eaed] pt-2">
                                            <dt class="inline font-bold text-zinc-500">Статус:</dt>
                                            <dd class="inline-flex flex-wrap gap-1 align-middle">
                                                @foreach ($statuses as $status)
                                                    <a href="{{ route('titles.taxonomy', ['type' => $status->type, 'taxonomy' => $status->slug]) }}" class="rounded bg-[#eef3f5] px-2 py-0.5 text-xs font-semibold text-[#31424c] hover:bg-emerald-100">{{ $status->name }}</a>
                                                @endforeach
                                            </dd>
                                        </div>
                                    @endif

                                    @if ($networks->isNotEmpty())
                                        <div class="border-t border-[#e5eaed] pt-2">
                                            <dt class="inline font-bold text-zinc-500">Канал:</dt>
                                            <dd class="inline-flex flex-wrap gap-1 align-middle">
                                                @foreach ($networks as $network)
                                                    <a href="{{ route('titles.taxonomy', ['type' => $network->type, 'taxonomy' => $network->slug]) }}" class="rounded bg-[#eef3f5] px-2 py-0.5 text-xs font-semibold text-[#31424c] hover:bg-emerald-100">{{ $network->name }}</a>
                                                @endforeach
                                            </dd>
                                        </div>
                                    @endif

                                    @if ($studios->isNotEmpty())
                                        <div class="border-t border-[#e5eaed] pt-2">
                                            <dt class="inline font-bold text-zinc-500">Студия:</dt>
                                            <dd class="inline-flex flex-wrap gap-1 align-middle">
                                                @foreach ($studios as $studio)
                                                    <a href="{{ route('titles.taxonomy', ['type' => $studio->type, 'taxonomy' => $studio->slug]) }}" class="rounded bg-[#eef3f5] px-2 py-0.5 text-xs font-semibold text-[#31424c] hover:bg-emerald-100">{{ $studio->name }}</a>
                                                @endforeach
                                            </dd>
                                        </div>
                                    @endif
                                </dl>

                                <div class="mt-5 flex flex-wrap gap-2">
                                    @foreach ($genres->merge($countries)->merge($ageRatings)->merge($translations)->merge($statuses)->merge($tags)->take(16) as $taxonomy)
                                        <a href="{{ route('titles.taxonomy', ['type' => $taxonomy->type, 'taxonomy' => $taxonomy->slug]) }}" class="rounded bg-[#eef3f5] px-2 py-1 text-xs font-semibold text-[#31424c] hover:bg-emerald-100">{{ $taxonomy->name }}</a>
                                    @endforeach
                                </div>
                            </div>
                        </div>
                    </div>

                    <section class="mt-4 rounded border border-[#d4dce0] bg-white">
                        <div class="bg-[#31424c] px-4 py-2 text-sm font-bold text-white">Сезоны</div>
                        <div class="divide-y divide-[#e5eaed]">
	                            @forelse ($seasons as $season)
	                                @php
	                                    $seasonEpisodeCount = (int) $season->episodes->count();
	                                @endphp
	                                <div class="px-4 py-3">
	                                    <div class="flex flex-col gap-1 sm:flex-row sm:items-center sm:justify-between">
	                                        <h2 class="font-bold text-[#26333b]">
	                                            Сезон {{ $season->number }}
	                                        </h2>
	                                        @if ($seasonEpisodeCount > 0)
	                                            <span class="text-xs font-semibold text-zinc-500">{{ $seasonEpisodeCount }} серий</span>
	                                        @else
	                                            <span class="text-xs font-semibold text-zinc-500">серии разбираются</span>
	                                        @endif
                                    </div>
                                </div>
                            @empty
                                <p class="p-4 text-sm text-zinc-500">Сезоны еще не распарсены.</p>
                            @endforelse
                        </div>
                    </section>

                    <section class="mt-4 rounded border border-[#d4dce0] bg-white">
                        <div class="flex flex-wrap items-center gap-2 border-b border-[#d4dce0] bg-[#eef3f5] px-4 py-2 text-xs font-bold text-[#31424c]">
                            <span class="rounded bg-white px-2 py-1 ring-1 ring-[#d4dce0]">Отметка на серии</span>
                            <span class="rounded bg-white px-2 py-1 ring-1 ring-[#d4dce0]">Отметка на моменте</span>
                            <span class="rounded bg-white px-2 py-1 ring-1 ring-[#d4dce0]">Хочу посмотреть</span>
                            <span class="ml-auto rounded bg-[#31424c] px-2 py-1 text-white">Меню сезона</span>
                        </div>

                        <div class="bg-black">
                            @if ($selectedMedia && $selectedMediaUrl)
                                <video controls playsinline preload="metadata" poster="{{ $title->poster_url }}" class="aspect-video w-full bg-black">
                                    <source src="{{ $selectedMediaUrl }}">
	                                    Ваш браузер не поддерживает воспроизведение видео.
                                </video>
                            @else
                                <div class="grid aspect-video place-items-center p-6 text-center text-zinc-300">
                                    <div>
                                        <div class="text-lg font-bold text-white">Файлы для просмотра ещё не подключены</div>
                                    </div>
                                </div>
                            @endif
                        </div>

                        <div class="flex flex-wrap gap-2 border-t border-[#d4dce0] bg-[#f8fafb] px-4 py-3 text-xs font-bold text-[#31424c]">
                            <span class="rounded bg-white px-3 py-1 ring-1 ring-[#d4dce0]">Стандартный</span>
                            @if ($selectedMedia)
                                <span class="rounded bg-emerald-100 px-3 py-1 text-emerald-800 ring-1 ring-emerald-200">{{ $selectedMedia->title }}</span>
                            @endif
                            <span class="ml-auto text-zinc-500">Выберите файл</span>
                        </div>

                        @if ($mediaItems->isNotEmpty())
                            <div class="border-t border-[#d4dce0] bg-white">
                                <div class="bg-[#eef3f5] px-4 py-2 text-sm font-bold text-[#31424c]">Файлы плейлиста</div>
                                <div class="max-h-80 divide-y divide-[#e5eaed] overflow-y-auto">
                                    @foreach ($mediaItems as $media)
                                        @php
                                            $mediaDetails = collect([
                                                $media->season ? 'Сезон '.$media->season->number : null,
                                                $media->episode ? 'Серия '.$media->episode->number : null,
                                            ])->filter()->implode(' / ');
                                        @endphp
                                        <a href="{{ route('titles.show', ['catalogTitle' => $title, 'media' => $media->id]) }}" @class([
                                            'block px-4 py-3 hover:bg-emerald-50',
                                            'bg-emerald-50' => $selectedMedia?->id === $media->id,
                                        ])>
                                            <div class="flex flex-col gap-1 sm:flex-row sm:items-center sm:justify-between">
                                                <span class="font-semibold text-[#26333b]">{{ $media->title }}</span>
                                                <span class="text-xs font-semibold text-zinc-500">{{ $mediaDetails !== '' ? $mediaDetails : 'Файл сериала' }}</span>
                                            </div>
                                        </a>
                                    @endforeach
                                </div>
                            </div>
                        @endif
                    </section>

                    <section class="mt-4 rounded border border-[#d4dce0] bg-white">
                        <div class="bg-[#31424c] px-4 py-2 text-sm font-bold text-white">Советуем посмотреть</div>
                        <div class="divide-y divide-[#e5eaed]">
                            @forelse ($recommendedTitles as $recommendedTitle)
                                <a href="{{ route('titles.show', $recommendedTitle) }}" class="block p-4 hover:bg-emerald-50">
                                    <div class="flex gap-3">
                                        <div class="h-20 w-14 shrink-0 overflow-hidden rounded bg-[#eef3f5]">
                                            @if ($recommendedTitle->poster_url)
                                                <img src="{{ $recommendedTitle->poster_url }}" alt="{{ $recommendedTitle->title }}" class="h-full w-full object-cover">
                                            @endif
                                        </div>
                                        <div class="min-w-0">
                                            <div class="font-bold text-[#26333b]">{{ $recommendedTitle->title }}</div>
                                            @if ($recommendedTitle->description)
                                                <p class="mt-1 line-clamp-2 text-sm leading-5 text-zinc-600">{{ $recommendedTitle->description }}</p>
                                            @endif
                                        </div>
                                    </div>
                                </a>
                            @empty
	                                <p class="p-4 text-sm text-zinc-500">Рекомендации появятся после полной синхронизации.</p>
                            @endforelse
                        </div>
                    </section>

                    <section class="mt-4 rounded border border-[#d4dce0] bg-white">
                        <div class="flex border-b border-[#d4dce0] bg-[#eef3f5] text-sm font-bold text-[#31424c]">
                            <span class="border-r border-[#d4dce0] bg-white px-4 py-2">Комментарии</span>
                            <span class="px-4 py-2">О сериях</span>
                        </div>
                        <div class="p-4 text-sm leading-6 text-zinc-600">
	                            Комментарии не импортируются. Здесь остается локальный блок страницы, чтобы макет соответствовал структуре страницы сериала.
                        </div>
                    </section>
                </article>
            </main>

            <aside class="border-t border-[#d4dce0] bg-[#f6f8f9] p-4 lg:border-l lg:border-t-0">
                <div class="rounded border border-[#d4dce0] bg-white">
                    <div class="bg-[#31424c] px-3 py-2 text-sm font-bold text-white">Источник</div>
                    <dl class="divide-y divide-[#e5eaed] text-sm">
                        <div class="px-3 py-2">
	                            <dt class="text-zinc-500">Внешний номер</dt>
	                            <dd class="font-bold text-[#26333b]">{{ $title->external_id ?? 'Неизвестно' }}</dd>
                        </div>
                        <div class="px-3 py-2">
	                            <dt class="text-zinc-500">Индексация</dt>
	                            <dd class="font-bold text-[#26333b]">{{ $title->indexed_at?->format('d.m.Y H:i') ?? 'Не индексировалось' }}</dd>
                        </div>
                        <div class="px-3 py-2">
	                            <dt class="text-zinc-500">Источник</dt>
                            <dd><a href="{{ $title->source_url }}" rel="nofollow noopener" class="break-all font-semibold text-emerald-700 hover:text-emerald-600">{{ $title->source_url }}</a></dd>
                        </div>
                    </dl>
                </div>

                <div class="mt-4 rounded border border-[#d4dce0] bg-white">
                    <div class="bg-[#31424c] px-3 py-2 text-sm font-bold text-white">Теги</div>
                    <div class="flex flex-wrap gap-2 p-3">
                        @forelse ($title->taxonomies as $taxonomy)
                            <a href="{{ route('titles.taxonomy', ['type' => $taxonomy->type, 'taxonomy' => $taxonomy->slug]) }}" class="rounded bg-[#eef3f5] px-2 py-1 text-xs font-semibold text-[#31424c] hover:bg-emerald-100">{{ $taxonomy->name }}</a>
                        @empty
                            <span class="text-sm text-zinc-500">Нет тегов.</span>
                        @endforelse
                    </div>
                </div>

                <div class="mt-4 rounded border border-[#d4dce0] bg-white">
                    <div class="bg-[#31424c] px-3 py-2 text-sm font-bold text-white">Синхронизация</div>
                    <div class="space-y-3 p-3 text-sm text-zinc-600">
                        <code class="block rounded bg-[#eef3f5] p-3 text-xs font-semibold text-emerald-700">php artisan seasonvar:full-sync</code>
	                        <p>Обновляет зеркало карты сайта, страницы, метаданные, постеры, сезоны и серии.</p>
                    </div>
                </div>
            </aside>
        </div>
    </section>
@endsection
