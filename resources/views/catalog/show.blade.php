@extends('layouts.app', ['title' => $title->title])

@php
    $taxonomiesByType = $taxonomiesByType ?? collect([
        'genre' => $title->relationLoaded('genres') ? $title->genres : collect(),
        'country' => $title->relationLoaded('countries') ? $title->countries : collect(),
        'actor' => $title->relationLoaded('actors') ? $title->actors : collect(),
        'director' => $title->relationLoaded('directors') ? $title->directors : collect(),
        'age_rating' => $title->relationLoaded('ageRatings') ? $title->ageRatings : collect(),
        'translation' => $title->relationLoaded('translations') ? $title->translations : collect(),
        'status' => $title->relationLoaded('statuses') ? $title->statuses : collect(),
        'network' => $title->relationLoaded('networks') ? $title->networks : collect(),
        'studio' => $title->relationLoaded('studios') ? $title->studios : collect(),
        'tag' => $title->relationLoaded('tags') ? $title->tags : collect(),
    ]);
    $taxonomyGroups = $taxonomyGroups ?? $taxonomiesByType;
    $genres = $taxonomiesByType->get('genre', collect());
    $countries = $taxonomiesByType->get('country', collect());
    $actors = $taxonomiesByType->get('actor', collect());
    $directors = $taxonomiesByType->get('director', collect());
    $ageRatings = $taxonomiesByType->get('age_rating', collect());
    $translations = $taxonomiesByType->get('translation', collect());
    $statuses = $taxonomiesByType->get('status', collect());
    $networks = $taxonomiesByType->get('network', collect());
    $studios = $taxonomiesByType->get('studio', collect());
    $tags = $taxonomiesByType->get('tag', collect());
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
    $taxonomyRows = [
        ['label' => 'Жанр', 'items' => $genres],
        ['label' => 'Ограничение', 'items' => $ageRatings],
        ['label' => 'Страна', 'items' => $countries],
        ['label' => 'Режиссер', 'items' => $directors],
        ['label' => 'Перевод', 'items' => $translations],
        ['label' => 'Статус', 'items' => $statuses],
        ['label' => 'Канал', 'items' => $networks],
        ['label' => 'Студия', 'items' => $studios],
    ];
    $seasons = $seasons ?? $title->seasons->sortBy('number')->values();
    $mediaItems = $mediaItems ?? collect();
    $selectedEpisode = $selectedEpisode ?? null;
    $mediaByEpisodeId = $mediaItems->whereNotNull('episode_id')->groupBy('episode_id');
    $selectedMediaUrl = $selectedMedia ? ($selectedMedia->playback_url ?: $selectedMedia->path) : null;
    $episodeCount = $episodeCount ?? $seasons->sum(fn ($season) => (int) $season->episodes->count());
    $taxonomyCount = $taxonomyCount ?? $taxonomiesByType->sum(fn ($items) => $items->count());
    $mediaCount = $mediaCount ?? $mediaItems->count();
    $parsedSeasonCount = $parsedSeasonCount ?? $seasons->filter(fn ($season) => $season->episodes->isNotEmpty())->count();
    $topTaxonomies = $genres->merge($countries)->merge($ageRatings)->merge($translations)->merge($statuses)->merge($tags)->take(16);
@endphp

@section('content')
    <section class="grid min-w-0 gap-5 lg:grid-cols-[minmax(0,1fr)_280px] xl:grid-cols-[minmax(0,1fr)_300px]">
        <main class="min-w-0 space-y-5">
            <x-ui.panel :pad="false">
                <div class="border-b border-slate-200 bg-slate-50 px-4 py-3">
                    <a href="{{ route('home') }}" class="inline-flex items-center rounded-lg bg-white px-3 py-2 text-sm font-bold text-slate-600 ring-1 ring-slate-200 hover:bg-emerald-50 hover:text-emerald-700">Вернуться</a>
                </div>

                <article class="grid gap-4 p-3 sm:p-4 md:grid-cols-[minmax(150px,220px)_minmax(0,1fr)] md:gap-5">
                    <div>
                        <x-title-poster :title="$title" class="mx-auto aspect-[2/3] w-44 max-w-full border border-slate-200 sm:w-52 md:w-full" empty-class="grid h-full place-items-center px-6 text-center text-sm text-slate-400" />

                        <div class="mt-3 grid grid-cols-2 gap-2 text-center text-xs font-bold">
                            <span class="rounded-lg bg-emerald-50 px-2 py-2 text-emerald-700 ring-1 ring-emerald-100">{{ $taxonomyCount > 0 ? $taxonomyCount.' связей' : 'нет связей' }}</span>
                            <span class="rounded-lg bg-orange-50 px-2 py-2 text-orange-700 ring-1 ring-orange-100">{{ $selectedMedia ? 'плеер' : 'нет медиа' }}</span>
                        </div>
                    </div>

                    <div class="min-w-0">
                        <h1 class="text-xl font-black leading-tight text-slate-700 sm:text-2xl">Сериал {{ $title->title }} онлайн</h1>
                        <div class="mt-3 flex flex-wrap gap-2 text-xs font-bold">
                            @if ($title->year)
                                <x-ui.taxonomy-chip :href="route('titles.index', ['year' => $title->year])" active>{{ $title->year }}</x-ui.taxonomy-chip>
                            @endif
                            @foreach ($ageRatings as $ageRating)
                                <x-ui.taxonomy-chip :taxonomy="$ageRating" active />
                            @endforeach
                            @if ($seasons->isNotEmpty())
                                <x-ui.taxonomy-chip>{{ $seasons->count() }} сезон(ов)</x-ui.taxonomy-chip>
                            @endif
                        </div>

                        <div class="mt-4 rounded-lg border border-slate-200 bg-slate-50">
                            <div class="border-b border-slate-200 px-3 py-2 text-sm font-bold text-slate-700">Описание</div>
                            @if ($title->description)
                                <p class="px-3 py-3 text-sm leading-6 text-slate-600">{{ $title->description }}</p>
                            @else
                                <p class="px-3 py-3 text-sm leading-6 text-slate-500">Описание пока не распарсено. Команда синхронизации обновит блок после обработки страницы источника.</p>
                            @endif
                        </div>

                        @if ($actors->isNotEmpty())
                            <div class="mt-5">
                                <div class="text-sm font-bold text-slate-700">В ролях</div>
                                <div class="mt-2 flex flex-wrap gap-2">
                                    @foreach ($actors->take(12) as $actor)
                                        <x-ui.taxonomy-chip :taxonomy="$actor" />
                                    @endforeach
                                </div>
                            </div>
                        @endif

                        <dl class="mt-5 divide-y divide-slate-200 text-sm">
                            @if ($title->original_title)
                                <div class="grid gap-2 py-2 sm:grid-cols-[120px_1fr]">
                                    <dt class="font-bold text-slate-500">Оригинал</dt>
                                    <dd class="text-slate-700">{{ $title->original_title }}</dd>
                                </div>
                            @endif

                            @foreach ($taxonomyRows as $row)
                                @if ($row['items']->isNotEmpty())
                                    <div class="grid gap-2 py-2 sm:grid-cols-[120px_1fr]">
                                        <dt class="font-bold text-slate-500">{{ $row['label'] }}</dt>
                                        <dd class="flex flex-wrap gap-1">
                                            @foreach ($row['items'] as $taxonomy)
                                                <x-ui.taxonomy-chip :taxonomy="$taxonomy" />
                                            @endforeach
                                        </dd>
                                    </div>
                                @endif
                            @endforeach

                            @if ($title->year)
                                <div class="grid gap-2 py-2 sm:grid-cols-[120px_1fr]">
                                    <dt class="font-bold text-slate-500">Вышел</dt>
                                    <dd><a href="{{ route('titles.index', ['year' => $title->year]) }}" class="font-semibold text-emerald-700 hover:text-emerald-600">{{ $title->year }}</a></dd>
                                </div>
                            @endif
                        </dl>

                        @if ($topTaxonomies->isNotEmpty())
                            <div class="mt-5 flex flex-wrap gap-2">
                                @foreach ($topTaxonomies as $taxonomy)
                                    <x-ui.taxonomy-chip :taxonomy="$taxonomy" />
                                @endforeach
                            </div>
                        @endif
                    </div>
                </article>
            </x-ui.panel>

            <x-ui.panel title="Сезоны" :pad="false">
                <div class="divide-y divide-slate-200">
                    @forelse ($seasons as $season)
                        @php
                            $seasonEpisodeCount = (int) $season->episodes->count();
                            $releasedEpisodeLabel = null;

                            if ($season->episodes_released !== null) {
                                $releasedEpisodeLabel = $season->episodes_released.' '.match (true) {
                                    $season->episodes_released % 10 === 1 && $season->episodes_released % 100 !== 11 => 'серия',
                                    in_array($season->episodes_released % 10, [2, 3, 4], true) && ! in_array($season->episodes_released % 100, [12, 13, 14], true) => 'серии',
                                    default => 'серий',
                                };
                            }

                            $totalEpisodeLabel = $season->episodes_released !== null
                                ? ($season->episodes_total !== null ? 'из '.$season->episodes_total : (str_contains((string) $season->release_status_text, '??') ? 'из ??' : null))
                                : null;
                            $seasonStatusBadges = collect([
                                $season->latest_episode_released_at?->format('d.m.Y'),
                                $releasedEpisodeLabel,
                                $totalEpisodeLabel,
                                $season->translation_name,
                            ])->filter()->values();
                        @endphp
                        <div class="px-4 py-3">
                            <div class="flex flex-col gap-1 sm:flex-row sm:items-start sm:justify-between">
                                <div>
                                    <h2 class="font-bold text-slate-700">Сезон {{ $season->number }}</h2>
                                    @if ($season->title && $season->title !== 'Сезон '.$season->number)
                                        <p class="mt-1 text-xs font-semibold text-slate-500">{{ $season->title }}</p>
                                    @endif
                                    @if ($seasonStatusBadges->isNotEmpty())
                                        <div class="mt-2 flex flex-wrap gap-1">
                                            @foreach ($seasonStatusBadges as $badge)
                                                <span class="rounded-full bg-emerald-50 px-2 py-1 text-xs font-bold text-emerald-700 ring-1 ring-emerald-100">{{ $badge }}</span>
                                            @endforeach
                                        </div>
                                    @endif
                                    @if ($season->release_status_text)
                                        <p class="mt-2 text-xs font-semibold text-slate-500">Источник: {{ $season->release_status_text }}</p>
                                    @endif
                                </div>
                                <span class="text-xs font-semibold text-slate-500">{{ $seasonEpisodeCount > 0 ? $seasonEpisodeCount.' серий' : 'серии разбираются' }}</span>
                            </div>

                            @if ($seasonEpisodeCount > 0)
                                <div class="mt-3 grid gap-2 md:grid-cols-2 xl:grid-cols-3">
                                    @foreach ($season->episodes->sortBy('number')->values() as $episode)
                                        @php
                                            $episodeMediaItems = $mediaByEpisodeId->get($episode->id, collect());
                                            $episodeHasMedia = $episodeMediaItems->isNotEmpty();
                                            $isSelectedEpisode = $selectedEpisode?->id === $episode->id;
                                        @endphp
                                        <a href="{{ route('titles.show', ['catalogTitle' => $title, 'episode' => $episode->id]) }}#player" @class([
                                            'block rounded-lg px-3 py-2 text-sm ring-1 transition',
                                            'bg-emerald-50 ring-emerald-200' => $isSelectedEpisode,
                                            'bg-slate-50 ring-slate-200 hover:bg-emerald-50 hover:ring-emerald-200' => ! $isSelectedEpisode,
                                        ]) @if ($isSelectedEpisode) aria-current="true" @endif>
                                            <div class="flex items-start justify-between gap-2">
                                                <div class="font-bold text-slate-700">{{ $episode->number }} серия</div>
                                                <span @class([
                                                    'shrink-0 rounded-full px-2 py-0.5 text-[11px] font-bold ring-1',
                                                    'bg-emerald-100 text-emerald-700 ring-emerald-200' => $episodeHasMedia,
                                                    'bg-white text-slate-500 ring-slate-200' => ! $episodeHasMedia,
                                                ])>{{ $episodeHasMedia ? 'видео' : 'без файла' }}</span>
                                            </div>
                                            @if ($episode->title)
                                                <div class="mt-0.5 line-clamp-2 text-xs text-slate-500">{{ $episode->title }}</div>
                                            @endif
                                            @if ($episode->released_at)
                                                <div class="mt-1 text-xs font-semibold text-emerald-700">{{ $episode->released_at->format('d.m.Y') }}</div>
                                            @endif
                                        </a>
                                    @endforeach
                                </div>
                            @endif
                        </div>
                    @empty
                        <p class="p-4 text-sm text-slate-500">Сезоны еще не распарсены.</p>
                    @endforelse
                </div>
            </x-ui.panel>

            <x-ui.panel id="player" title="Просмотр" subtitle="Выберите серию выше. Плеер покажет подключенный файл этой серии.">
                <div class="flex flex-wrap items-center gap-2 text-xs font-bold text-slate-600">
                    @if ($selectedEpisode)
                        <span class="rounded-full bg-emerald-50 px-2 py-1 text-emerald-700 ring-1 ring-emerald-100">Выбрана {{ $selectedEpisode->number }} серия</span>
                        @if ($selectedEpisode->title)
                            <span class="rounded-full bg-white px-2 py-1 ring-1 ring-slate-200">{{ $selectedEpisode->title }}</span>
                        @endif
                    @else
                        <span class="rounded-full bg-white px-2 py-1 ring-1 ring-slate-200">Серия не выбрана</span>
                    @endif
                    <span class="sm:ml-auto rounded-full bg-slate-50 px-2 py-1 ring-1 ring-slate-200">{{ $selectedMedia ? 'файл подключен' : 'файл не подключен' }}</span>
                </div>

                <div class="mt-3 overflow-hidden rounded-lg border border-slate-200 bg-slate-50">
                    @if ($selectedMedia && $selectedMediaUrl)
                        <video controls playsinline preload="metadata" poster="{{ $title->poster_url }}" class="aspect-video w-full bg-slate-100">
                            <source src="{{ $selectedMediaUrl }}">
                            Ваш браузер не поддерживает воспроизведение видео.
                        </video>
                    @else
                        <div class="grid aspect-video place-items-center p-6 text-center text-slate-500">
                            <div>
                                <div class="text-lg font-bold text-slate-700">
                                    {{ $selectedEpisode ? 'Файл для выбранной серии еще не подключен' : 'Выберите серию для просмотра' }}
                                </div>
                                <p class="mt-1 text-sm">Когда внешний плейлист будет импортирован, видео появится в этом блоке.</p>
                            </div>
                        </div>
                    @endif
                </div>

                <div class="mt-3 flex flex-wrap gap-2 text-xs font-bold text-slate-600">
                    <span class="rounded-full bg-white px-3 py-1 ring-1 ring-slate-200">Стандартный</span>
                    @if ($selectedMedia)
                        <span class="rounded-full bg-emerald-50 px-3 py-1 text-emerald-700 ring-1 ring-emerald-100">{{ $selectedMedia->title }}</span>
                    @endif
                    <span class="sm:ml-auto text-slate-500">Выберите файл</span>
                </div>

                @if ($mediaItems->isNotEmpty())
                    <div class="mt-3 rounded-lg border border-slate-200 bg-white">
                        <div class="border-b border-slate-200 bg-slate-50 px-4 py-2 text-sm font-bold text-slate-700">Файлы плейлиста</div>
                        <div class="max-h-80 divide-y divide-slate-200 overflow-y-auto">
                            @foreach ($mediaItems as $media)
                                @php
                                    $mediaDetails = collect([
                                        $media->season ? 'Сезон '.$media->season->number : null,
                                        $media->episode ? 'Серия '.$media->episode->number : null,
                                    ])->filter()->implode(' / ');
                                    $mediaQuery = ['catalogTitle' => $title, 'media' => $media->id];

                                    if ($media->episode_id) {
                                        $mediaQuery['episode'] = $media->episode_id;
                                    }
                                @endphp
                                <a href="{{ route('titles.show', $mediaQuery) }}#player" @class([
                                    'block px-4 py-3 hover:bg-emerald-50',
                                    'bg-emerald-50' => $selectedMedia?->id === $media->id,
                                ])>
                                    <div class="flex flex-col gap-1 sm:flex-row sm:items-center sm:justify-between">
                                        <span class="font-semibold text-slate-700">{{ $media->title }}</span>
                                        <span class="text-xs font-semibold text-slate-500">{{ $mediaDetails !== '' ? $mediaDetails : 'Файл сериала' }}</span>
                                    </div>
                                </a>
                            @endforeach
                        </div>
                    </div>
                @endif
            </x-ui.panel>

            <x-ui.panel title="Советуем посмотреть" :pad="false">
                <div class="divide-y divide-slate-200">
                    @forelse ($recommendedTitles as $recommendedTitle)
                        <a href="{{ route('titles.show', $recommendedTitle) }}" class="block p-4 hover:bg-emerald-50">
                            <div class="flex gap-3">
                                <x-title-poster :title="$recommendedTitle" class="h-20 w-14 shrink-0" empty-label="" />
                                <div class="min-w-0">
                                    <div class="font-bold text-slate-700">{{ $recommendedTitle->title }}</div>
                                    @if (isset($recommendedTitle->shared_taxonomies_count) && $recommendedTitle->shared_taxonomies_count > 0)
                                        <div class="mt-1 inline-flex rounded-full bg-emerald-50 px-2 py-0.5 text-xs font-bold text-emerald-700 ring-1 ring-emerald-100">{{ $recommendedTitle->shared_taxonomies_count }} общих связей</div>
                                    @endif
                                    @if ($recommendedTitle->description)
                                        <p class="mt-1 line-clamp-2 text-sm leading-5 text-slate-500">{{ $recommendedTitle->description }}</p>
                                    @endif
                                </div>
                            </div>
                        </a>
                    @empty
                        <p class="p-4 text-sm text-slate-500">Рекомендации появятся после полной синхронизации.</p>
                    @endforelse
                </div>
            </x-ui.panel>

            <x-ui.panel title="Комментарии">
                <p class="text-sm leading-6 text-slate-500">Комментарии не импортируются. Здесь остается локальный блок страницы, чтобы макет соответствовал структуре страницы сериала.</p>
            </x-ui.panel>
        </main>

        <aside class="space-y-4">
            <x-ui.panel title="Данные страницы" :pad="false">
                <div class="grid grid-cols-2 gap-2 p-3 text-center text-xs font-bold">
                    <div class="rounded-lg bg-slate-50 px-2 py-3 text-slate-600 ring-1 ring-slate-200">
                        <div class="text-lg text-emerald-700">{{ $seasons->count() }}</div>
                        <div>сезонов</div>
                    </div>
                    <div class="rounded-lg bg-slate-50 px-2 py-3 text-slate-600 ring-1 ring-slate-200">
                        <div class="text-lg text-emerald-700">{{ $episodeCount }}</div>
                        <div>серий</div>
                    </div>
                    <div class="rounded-lg bg-slate-50 px-2 py-3 text-slate-600 ring-1 ring-slate-200">
                        <div class="text-lg text-emerald-700">{{ $taxonomyCount }}</div>
                        <div>связей</div>
                    </div>
                    <div class="rounded-lg bg-slate-50 px-2 py-3 text-slate-600 ring-1 ring-slate-200">
                        <div class="text-lg text-emerald-700">{{ $mediaCount }}</div>
                        <div>файлов</div>
                    </div>
                </div>
                <div class="border-t border-slate-200 px-3 py-2 text-xs font-semibold text-slate-500">
                    Серии разобраны в {{ $parsedSeasonCount }} из {{ $seasons->count() }} сезонов
                </div>
            </x-ui.panel>

            <x-ui.panel title="Источник" :pad="false">
                <dl class="divide-y divide-slate-200 text-sm">
                    <div class="px-3 py-2">
                        <dt class="text-slate-500">Внешний номер</dt>
                        <dd class="font-bold text-slate-700">{{ $title->external_id ?? 'Неизвестно' }}</dd>
                    </div>
                    <div class="px-3 py-2">
                        <dt class="text-slate-500">Индексация</dt>
                        <dd class="font-bold text-slate-700">{{ $title->indexed_at?->format('d.m.Y H:i') ?? 'Не индексировалось' }}</dd>
                    </div>
                    <div class="px-3 py-2">
                        <dt class="text-slate-500">Источник</dt>
                        <dd><a href="{{ $title->source_url }}" rel="nofollow noopener" class="break-all font-semibold text-emerald-700 hover:text-emerald-600">{{ $title->source_url }}</a></dd>
                    </div>
                    @if ($title->sourcePage)
                        <div class="px-3 py-2">
                            <dt class="text-slate-500">Статус парсинга</dt>
                            <dd class="font-bold text-slate-700">{{ $title->sourcePage->parse_status }}</dd>
                        </div>
                        <div class="px-3 py-2">
                            <dt class="text-slate-500">Последняя проверка</dt>
                            <dd class="font-bold text-slate-700">{{ $title->sourcePage->last_crawled_at?->format('d.m.Y H:i') ?? 'Не проверялось' }}</dd>
                        </div>
                    @endif
                </dl>
            </x-ui.panel>

            <x-ui.panel title="Связи каталога">
                <div class="space-y-3">
                    @forelse ($taxonomyGroups as $taxonomyType => $taxonomies)
                        <div>
                            <div class="mb-2 flex items-center justify-between gap-2 text-xs font-bold uppercase tracking-wide text-slate-500">
                                <span>{{ $taxonomyLabels[$taxonomyType] ?? $taxonomyType }}</span>
                                <span class="rounded-full bg-slate-50 px-2 py-0.5 text-slate-500 ring-1 ring-slate-200">{{ $taxonomies->count() }}</span>
                            </div>
                            <div class="flex flex-wrap gap-2">
                                @foreach ($taxonomies as $taxonomy)
                                    <x-ui.taxonomy-chip :taxonomy="$taxonomy" />
                                @endforeach
                            </div>
                        </div>
                    @empty
                        <span class="text-sm text-slate-500">Связи появятся после синхронизации.</span>
                    @endforelse
                </div>
            </x-ui.panel>

            <x-ui.panel title="Синхронизация">
                <div class="space-y-3 text-sm text-slate-500">
                    <code class="block rounded-lg bg-slate-50 p-3 text-xs font-semibold text-emerald-700 ring-1 ring-slate-200">php artisan seasonvar:full-sync</code>
                    <p>Обновляет зеркало карты сайта, страницы, метаданные, постеры, сезоны и серии.</p>
                </div>
            </x-ui.panel>
        </aside>
    </section>
@endsection
