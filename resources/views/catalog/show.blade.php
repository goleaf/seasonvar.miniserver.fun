@extends('layouts.app', ['title' => $seo['title'] ?? $title->title, 'seo' => $seo ?? []])

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
    $taxonomyIcons = [
        'genre' => 'fa-solid fa-masks-theater',
        'country' => 'fa-solid fa-earth-europe',
        'actor' => 'fa-solid fa-user-group',
        'director' => 'fa-solid fa-video',
        'age_rating' => 'fa-solid fa-shield-halved',
        'translation' => 'fa-solid fa-language',
        'status' => 'fa-solid fa-signal',
        'network' => 'fa-solid fa-tower-broadcast',
        'studio' => 'fa-solid fa-building',
        'tag' => 'fa-solid fa-tag',
    ];
    $taxonomyRows = [
        ['label' => 'Жанр', 'items' => $genres, 'icon' => $taxonomyIcons['genre']],
        ['label' => 'Ограничение', 'items' => $ageRatings, 'icon' => $taxonomyIcons['age_rating']],
        ['label' => 'Страна', 'items' => $countries, 'icon' => $taxonomyIcons['country']],
        ['label' => 'Режиссер', 'items' => $directors, 'icon' => $taxonomyIcons['director']],
        ['label' => 'Перевод', 'items' => $translations, 'icon' => $taxonomyIcons['translation']],
        ['label' => 'Статус', 'items' => $statuses, 'icon' => $taxonomyIcons['status']],
        ['label' => 'Канал', 'items' => $networks, 'icon' => $taxonomyIcons['network']],
        ['label' => 'Студия', 'items' => $studios, 'icon' => $taxonomyIcons['studio']],
    ];
    $seasons = $seasons ?? $title->seasons->sortBy('number')->values();
    $mediaItems = $mediaItems ?? collect();
    $selectedEpisode = $selectedEpisode ?? null;
    $mediaByEpisodeId = $mediaItems->whereNotNull('episode_id')->groupBy('episode_id');
    $selectedMediaUrl = $selectedMedia ? ($selectedMedia->playback_url ?: $selectedMedia->path) : null;
    $selectedMediaFormat = strtolower($selectedMedia?->format ?: pathinfo((string) parse_url((string) $selectedMediaUrl, PHP_URL_PATH), PATHINFO_EXTENSION));
    $selectedMediaType = match ($selectedMediaFormat) {
        'm3u8' => 'application/x-mpegURL',
        'mp4', 'm4v' => 'video/mp4',
        'webm' => 'video/webm',
        'mov' => 'video/quicktime',
        default => null,
    };
    $selectedEpisodeMediaItems = $selectedEpisode ? $mediaByEpisodeId->get($selectedEpisode->id, collect()) : collect();
    $selectedSeasonId = $selectedEpisode?->season_id ?? $selectedMedia?->season_id;
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
                    <a href="{{ route('home') }}" class="inline-flex items-center gap-2 rounded-lg bg-white px-3 py-2 text-sm font-bold text-slate-600 ring-1 ring-slate-200 hover:bg-emerald-50 hover:text-emerald-700">
                        <i class="fa-solid fa-arrow-left" aria-hidden="true"></i>
                        <span>Вернуться</span>
                    </a>
                </div>

                <article class="grid gap-4 p-3 sm:p-4 md:grid-cols-[minmax(150px,220px)_minmax(0,1fr)] md:gap-5">
                    <div>
                        <x-title-poster :title="$title" class="mx-auto aspect-[2/3] w-44 max-w-full border border-slate-200 sm:w-52 md:w-full" empty-class="grid h-full place-items-center px-6 text-center text-sm text-slate-400" />

                        <div class="mt-3 grid grid-cols-2 gap-2 text-center text-xs font-bold">
                            <span class="inline-flex items-center justify-center gap-1 rounded-lg bg-emerald-50 px-2 py-2 text-emerald-700 ring-1 ring-emerald-100">
                                <i class="fa-solid fa-diagram-project" aria-hidden="true"></i>
                                <span>{{ $taxonomyCount > 0 ? $taxonomyCount.' связей' : 'нет связей' }}</span>
                            </span>
                            <span @class([
                                'inline-flex items-center justify-center gap-1 rounded-lg px-2 py-2 ring-1',
                                'bg-emerald-50 text-emerald-700 ring-emerald-100' => $selectedMedia,
                                'bg-amber-50 text-amber-700 ring-amber-100' => ! $selectedMedia,
                            ])>
                                <i class="{{ $selectedMedia ? 'fa-solid fa-circle-check' : 'fa-solid fa-triangle-exclamation' }}" aria-hidden="true"></i>
                                <span>{{ $selectedMedia ? 'плеер готов' : 'видео готовится' }}</span>
                            </span>
                        </div>
                    </div>

                    <div class="min-w-0">
                        <h1 class="inline-flex items-start gap-2 text-xl font-black leading-tight text-slate-700 sm:text-2xl">
                            <i class="fa-solid fa-clapperboard mt-1 text-emerald-700" aria-hidden="true"></i>
                            <span>Сериал {{ $title->title }} онлайн</span>
                        </h1>
                        <div class="mt-3 flex flex-wrap gap-2 text-xs font-bold">
                            @if ($title->year)
                                <x-ui.taxonomy-chip :href="route('titles.year', ['year' => $title->year])" active icon="fa-solid fa-calendar-days">{{ $title->year }}</x-ui.taxonomy-chip>
                            @endif
                            @foreach ($ageRatings as $ageRating)
                                <x-ui.taxonomy-chip :taxonomy="$ageRating" active />
                            @endforeach
                            @if ($seasons->isNotEmpty())
                                <x-ui.taxonomy-chip icon="fa-solid fa-layer-group">{{ $seasons->count() }} сезонов</x-ui.taxonomy-chip>
                            @endif
                        </div>

                        <div class="mt-4 rounded-lg border border-slate-200 bg-slate-50">
                            <div class="flex items-center gap-2 border-b border-slate-200 px-3 py-2 text-sm font-bold text-slate-700">
                                <i class="fa-solid fa-book-open text-slate-400" aria-hidden="true"></i>
                                <span>Описание</span>
                            </div>
                            @if ($title->description)
                                <p class="px-3 py-3 text-sm leading-6 text-slate-600">{{ $title->description }}</p>
                            @else
                                <p class="px-3 py-3 text-sm leading-6 text-slate-500">Описание скоро появится. Мы уже дополняем страницу информацией.</p>
                            @endif
                        </div>

                        @if ($actors->isNotEmpty())
                            <div class="mt-5">
                                <div class="inline-flex items-center gap-2 text-sm font-bold text-slate-700">
                                    <i class="fa-solid fa-user-group text-slate-400" aria-hidden="true"></i>
                                    <span>В ролях</span>
                                </div>
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
                                    <dt class="inline-flex items-center gap-2 font-bold text-slate-500">
                                        <i class="fa-solid fa-language text-slate-400" aria-hidden="true"></i>
                                        <span>Оригинал</span>
                                    </dt>
                                    <dd class="text-slate-700">{{ $title->original_title }}</dd>
                                </div>
                            @endif

                            @foreach ($taxonomyRows as $row)
                                @if ($row['items']->isNotEmpty())
                                    <div class="grid gap-2 py-2 sm:grid-cols-[120px_1fr]">
                                        <dt class="inline-flex items-center gap-2 font-bold text-slate-500">
                                            <i class="{{ $row['icon'] ?? 'fa-solid fa-tag' }} text-slate-400" aria-hidden="true"></i>
                                            <span>{{ $row['label'] }}</span>
                                        </dt>
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
                                    <dt class="inline-flex items-center gap-2 font-bold text-slate-500">
                                        <i class="fa-solid fa-calendar-days text-slate-400" aria-hidden="true"></i>
                                        <span>Вышел</span>
                                    </dt>
                                    <dd>
                                        <a href="{{ route('titles.year', ['year' => $title->year]) }}" class="inline-flex items-center gap-1 font-semibold text-emerald-700 hover:text-emerald-600">
                                            <i class="fa-solid fa-calendar-days" aria-hidden="true"></i>
                                            <span>{{ $title->year }}</span>
                                        </a>
                                    </dd>
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

            <x-ui.panel title="Сезоны" icon="fa-solid fa-layer-group" :pad="false">
                <div class="divide-y divide-slate-200">
                    @forelse ($seasons as $season)
                        @php
                            $seasonEpisodeCount = (int) $season->episodes->count();
                            $isSelectedSeason = (int) $selectedSeasonId === (int) $season->id || ($selectedSeasonId === null && $loop->first);
                            $releasedEpisodeLabel = null;

                            if ($season->episodes_released !== null) {
                                $releasedEpisodeLabel = $season->episodes_released.' '.match (true) {
                                    $season->episodes_released % 10 === 1 && $season->episodes_released % 100 !== 11 => 'серия',
                                    in_array($season->episodes_released % 10, [2, 3, 4], true) && ! in_array($season->episodes_released % 100, [12, 13, 14], true) => 'серии',
                                    default => 'серий',
                                };
                            }

                            $totalEpisodeLabel = $season->episodes_released !== null
                                ? ($season->episodes_total !== null ? 'из '.$season->episodes_total : null)
                                : null;
                            $seasonStatusBadges = collect([
                                $season->latest_episode_released_at?->format('d.m.Y'),
                                $releasedEpisodeLabel,
                                $totalEpisodeLabel,
                                $season->translation_name,
                            ])->filter()->values();
                        @endphp

                        <details class="group" @if ($isSelectedSeason) open @endif>
                            <summary class="flex cursor-pointer list-none flex-col gap-2 px-4 py-3 hover:bg-emerald-50 sm:flex-row sm:items-center sm:justify-between">
                                <span class="min-w-0">
                                    <span class="inline-flex items-center gap-2 font-bold text-slate-700">
                                        <i class="fa-solid fa-chevron-down text-xs text-slate-400 transition group-open:rotate-180" aria-hidden="true"></i>
                                        <span>Сезон {{ $season->number }}</span>
                                    </span>
                                    @if ($season->title && $season->title !== 'Сезон '.$season->number)
                                        <span class="mt-1 block text-xs font-semibold text-slate-500">{{ $season->title }}</span>
                                    @endif
                                </span>
                                <span class="flex flex-wrap gap-1">
                                    <span class="rounded-full bg-slate-50 px-2 py-1 text-xs font-bold text-slate-600 ring-1 ring-slate-200">
                                        {{ $seasonEpisodeCount > 0 ? $seasonEpisodeCount.' серий' : 'серии скоро появятся' }}
                                    </span>
                                    @foreach ($seasonStatusBadges as $badge)
                                        <span class="rounded-full bg-emerald-50 px-2 py-1 text-xs font-bold text-emerald-700 ring-1 ring-emerald-100">{{ $badge }}</span>
                                    @endforeach
                                </span>
                            </summary>

                            <div class="px-4 pb-4">
                                @if ($seasonEpisodeCount > 0)
                                    <div id="season-{{ $season->number }}" class="grid gap-2 md:grid-cols-2 xl:grid-cols-3">
                                        @foreach ($season->episodes->sortBy('number')->values() as $episode)
                                            @php
                                                $episodeMediaItems = $mediaByEpisodeId->get($episode->id, collect());
                                                $episodeHasMedia = $episodeMediaItems->isNotEmpty();
                                                $isSelectedEpisode = $selectedEpisode?->id === $episode->id;
                                            @endphp
                                            <a href="{{ route('titles.show', ['catalogTitle' => $title, 'episode' => $episode->id]) }}#player" @class([
                                                'block rounded-lg px-3 py-2 text-sm ring-1 transition',
                                                'bg-emerald-50 ring-emerald-200' => $isSelectedEpisode,
                                                'bg-white ring-slate-200 hover:bg-emerald-50 hover:ring-emerald-200' => ! $isSelectedEpisode,
                                            ]) @if ($isSelectedEpisode) aria-current="true" @endif>
                                                <div class="flex items-start justify-between gap-2">
                                                    <div class="inline-flex min-w-0 items-center gap-2 font-bold text-slate-700">
                                                        <i class="fa-solid fa-circle-play text-emerald-700" aria-hidden="true"></i>
                                                        <span>{{ $episode->number }} серия</span>
                                                    </div>
                                                    <span @class([
                                                        'inline-flex shrink-0 items-center gap-1 rounded-full px-2 py-0.5 text-[11px] font-bold ring-1',
                                                        'bg-emerald-100 text-emerald-700 ring-emerald-200' => $episodeHasMedia,
                                                        'bg-slate-50 text-slate-500 ring-slate-200' => ! $episodeHasMedia,
                                                    ])>
                                                        <i class="{{ $episodeHasMedia ? 'fa-solid fa-play' : 'fa-solid fa-clock' }}" aria-hidden="true"></i>
                                                        <span>{{ $episodeHasMedia ? 'видео' : 'готовится' }}</span>
                                                    </span>
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
                                @else
                                    <div class="rounded-lg bg-slate-50 p-4 text-sm text-slate-500 ring-1 ring-slate-200">
                                        Серии этого сезона скоро появятся на странице.
                                    </div>
                                @endif
                            </div>
                        </details>
                    @empty
                        <p class="p-4 text-sm text-slate-500">Сезоны скоро появятся на странице.</p>
                    @endforelse
                </div>
            </x-ui.panel>

            <x-ui.panel id="player" title="Просмотр" subtitle="Выберите серию выше. Плеер покажет доступное видео этой серии." icon="fa-solid fa-circle-play">
                <div class="flex flex-wrap items-center gap-2 text-xs font-bold text-slate-600">
                    @if ($selectedEpisode)
                        <span class="inline-flex items-center gap-1 rounded-full bg-emerald-50 px-2 py-1 text-emerald-700 ring-1 ring-emerald-100">
                            <i class="fa-solid fa-circle-play" aria-hidden="true"></i>
                            <span>Выбрана {{ $selectedEpisode->number }} серия</span>
                        </span>
                        @if ($selectedEpisode->title)
                            <span class="inline-flex items-center gap-1 rounded-full bg-white px-2 py-1 ring-1 ring-slate-200">
                                <i class="fa-solid fa-file-lines text-slate-400" aria-hidden="true"></i>
                                <span>{{ $selectedEpisode->title }}</span>
                            </span>
                        @endif
                    @else
                        <span class="inline-flex items-center gap-1 rounded-full bg-white px-2 py-1 ring-1 ring-slate-200">
                            <i class="fa-solid fa-circle-info text-slate-400" aria-hidden="true"></i>
                            <span>Серия не выбрана</span>
                        </span>
                    @endif
                    <span @class([
                        'inline-flex items-center gap-1 sm:ml-auto rounded-full px-3 py-1 ring-1',
                        'bg-emerald-50 text-emerald-700 ring-emerald-100' => $selectedMedia,
                        'bg-amber-50 text-amber-700 ring-amber-100' => ! $selectedMedia,
                    ])>
                        <i class="{{ $selectedMedia ? 'fa-solid fa-circle-check' : 'fa-solid fa-clock' }}" aria-hidden="true"></i>
	                        <span>{{ $selectedMedia ? 'видео найдено' : 'видео готовится' }}</span>
                    </span>
                </div>

                <div @class([
                    'mt-3 overflow-hidden rounded-lg border',
                    'border-emerald-200 bg-emerald-50' => $selectedMedia,
                    'border-amber-200 bg-amber-50' => ! $selectedMedia,
                ])>
	                    @if ($selectedMedia && $selectedMediaUrl)
	                        <video
                                controls
                                playsinline
                                preload="metadata"
                                poster="{{ $title->poster_url }}"
                                class="js-seasonvar-player aspect-video w-full bg-black"
                                @if ($selectedMediaFormat === 'm3u8') data-hls-src="{{ $selectedMediaUrl }}" @endif
                            >
                                <source src="{{ $selectedMediaUrl }}" @if ($selectedMediaType) type="{{ $selectedMediaType }}" @endif>
                                Ваш браузер не поддерживает воспроизведение видео.
                            </video>
                    @else
                        <div class="grid aspect-video place-items-center p-6 text-center text-amber-700">
                            <div>
                                <div class="mb-3 text-3xl text-amber-600">
                                    <i class="fa-solid fa-circle-play" aria-hidden="true"></i>
                                </div>
                                <div class="text-lg font-bold text-amber-800">
	                                    {{ $selectedEpisode ? 'Видео для выбранной серии готовится' : 'Выберите серию для просмотра' }}
	                                </div>
	                                <p class="mt-1 text-sm">Как только видео будет доступно, оно появится в этом окне автоматически.</p>
	                            </div>
	                        </div>
	                    @endif
	                </div>

	                @if ($selectedEpisodeMediaItems->isNotEmpty())
                        <div class="mt-3">
                            <div class="mb-2 flex items-center gap-2 text-sm font-bold text-slate-700">
                                <i class="fa-solid fa-sliders text-slate-400" aria-hidden="true"></i>
                                <span>Варианты видео</span>
                            </div>
                            <div class="flex flex-wrap gap-2">
                                @foreach ($selectedEpisodeMediaItems as $episodeMedia)
                                    @php
                                        $variantQuery = [
                                            'catalogTitle' => $title,
                                            'episode' => $selectedEpisode?->id,
                                            'media' => $episodeMedia->id,
                                        ];
                                        $variantLabel = collect([
                                            $episodeMedia->quality ? strtoupper($episodeMedia->quality) : null,
                                            $episodeMedia->translation_name,
                                            $episodeMedia->format ? strtoupper($episodeMedia->format) : null,
                                        ])->filter()->implode(' / ') ?: 'Видео';
                                    @endphp
                                    <a href="{{ route('titles.show', $variantQuery) }}#player" @class([
                                        'inline-flex items-center gap-2 rounded-lg px-3 py-2 text-xs font-bold ring-1 transition',
                                        'bg-emerald-600 text-white ring-emerald-600' => $selectedMedia?->id === $episodeMedia->id,
                                        'bg-white text-slate-600 ring-slate-200 hover:bg-emerald-50 hover:text-emerald-700 hover:ring-emerald-200' => $selectedMedia?->id !== $episodeMedia->id,
                                    ])>
                                        <i class="fa-solid fa-file-video" aria-hidden="true"></i>
                                        <span>{{ $variantLabel }}</span>
                                    </a>
                                @endforeach
                            </div>
                        </div>
                    @elseif ($selectedMedia)
                        <div class="mt-3 flex flex-wrap gap-2 text-xs font-bold text-slate-600">
                            <span class="inline-flex items-center gap-1 rounded-full bg-emerald-50 px-3 py-1 text-emerald-700 ring-1 ring-emerald-100">
                                <i class="fa-solid fa-file-video" aria-hidden="true"></i>
                                <span>{{ $selectedMedia->title }}</span>
                            </span>
                        </div>
                    @endif

	                @if ($mediaItems->isNotEmpty())
	                    <div class="mt-3 rounded-lg border border-slate-200 bg-white">
	                        <div class="flex items-center gap-2 border-b border-slate-200 bg-slate-50 px-4 py-2 text-sm font-bold text-slate-700">
	                            <i class="fa-solid fa-folder-open text-slate-400" aria-hidden="true"></i>
	                            <span>Все доступные варианты</span>
                        </div>
                        <div class="max-h-80 divide-y divide-slate-200 overflow-y-auto">
                            @foreach ($mediaItems as $media)
                                @php
	                                    $mediaDetails = collect([
	                                        $media->season ? 'Сезон '.$media->season->number : null,
	                                        $media->episode ? 'Серия '.$media->episode->number : null,
                                            $media->quality ? strtoupper($media->quality) : null,
                                            $media->format ? strtoupper($media->format) : null,
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
                                        <span class="inline-flex items-center gap-2 font-semibold text-slate-700">
                                            <i class="fa-solid fa-file-video text-emerald-700" aria-hidden="true"></i>
                                            <span>{{ $media->title }}</span>
                                        </span>
                                        <span class="inline-flex items-center gap-1 text-xs font-semibold text-slate-500">
                                            <i class="fa-solid fa-circle-info text-slate-400" aria-hidden="true"></i>
	                                            <span>{{ $mediaDetails !== '' ? $mediaDetails : 'Видео сериала' }}</span>
	                                        </span>
                                    </div>
                                </a>
                            @endforeach
                        </div>
                    </div>
                @endif
            </x-ui.panel>

            <x-ui.panel title="Советуем посмотреть" icon="fa-solid fa-thumbs-up" :pad="false">
                <div class="divide-y divide-slate-200">
                    @forelse ($recommendedTitles as $recommendedTitle)
                        <a href="{{ route('titles.show', $recommendedTitle) }}" class="block p-4 hover:bg-emerald-50">
                            <div class="flex gap-3">
                                <x-title-poster :title="$recommendedTitle" class="h-20 w-14 shrink-0" empty-label="" />
                                <div class="min-w-0">
                                    <div class="font-bold text-slate-700">{{ $recommendedTitle->title }}</div>
                                    @if (isset($recommendedTitle->shared_taxonomies_count) && $recommendedTitle->shared_taxonomies_count > 0)
                                        <div class="mt-1 inline-flex items-center gap-1 rounded-full bg-emerald-50 px-2 py-0.5 text-xs font-bold text-emerald-700 ring-1 ring-emerald-100">
                                            <i class="fa-solid fa-diagram-project" aria-hidden="true"></i>
                                            <span>{{ $recommendedTitle->shared_taxonomies_count }} общих связей</span>
                                        </div>
                                    @endif
                                    @if ($recommendedTitle->description)
                                        <p class="mt-1 line-clamp-2 text-sm leading-5 text-slate-500">{{ $recommendedTitle->description }}</p>
                                    @endif
                                </div>
                            </div>
                        </a>
                    @empty
	                        <p class="p-4 text-sm text-slate-500">Рекомендации появятся после обновления каталога.</p>
                    @endforelse
                </div>
            </x-ui.panel>

            @if (! empty($seo['faq']))
                <x-ui.panel title="Вопросы о сериале" icon="fa-solid fa-circle-question" :pad="false">
                    <div class="divide-y divide-slate-200">
                        @foreach ($seo['faq'] as $faqItem)
                            <details class="group px-4 py-3">
                                <summary class="flex cursor-pointer list-none items-center justify-between gap-3 font-bold text-slate-700">
                                    <span>{{ $faqItem['question'] }}</span>
                                    <i class="fa-solid fa-chevron-down text-slate-400 transition group-open:rotate-180" aria-hidden="true"></i>
                                </summary>
                                <p class="mt-2 text-sm leading-6 text-slate-600">{{ $faqItem['answer'] }}</p>
                            </details>
                        @endforeach
                    </div>
                </x-ui.panel>
            @endif

        </main>

        <aside class="space-y-4">
            <x-ui.panel title="Данные страницы" icon="fa-solid fa-chart-simple" :pad="false">
                <div class="grid grid-cols-2 gap-2 p-3 text-center text-xs font-bold">
                    <div class="rounded-lg bg-slate-50 px-2 py-3 text-slate-600 ring-1 ring-slate-200">
                        <div class="flex items-center justify-center gap-2 text-lg text-emerald-700">
                            <i class="fa-solid fa-layer-group" aria-hidden="true"></i>
                            <span>{{ $seasons->count() }}</span>
                        </div>
                        <div>сезонов</div>
                    </div>
                    <div class="rounded-lg bg-slate-50 px-2 py-3 text-slate-600 ring-1 ring-slate-200">
                        <div class="flex items-center justify-center gap-2 text-lg text-emerald-700">
                            <i class="fa-solid fa-circle-play" aria-hidden="true"></i>
                            <span>{{ $episodeCount }}</span>
                        </div>
                        <div>серий</div>
                    </div>
                    <div class="rounded-lg bg-slate-50 px-2 py-3 text-slate-600 ring-1 ring-slate-200">
                        <div class="flex items-center justify-center gap-2 text-lg text-emerald-700">
                            <i class="fa-solid fa-diagram-project" aria-hidden="true"></i>
                            <span>{{ $taxonomyCount }}</span>
                        </div>
                        <div>связей</div>
                    </div>
                    <div class="rounded-lg bg-slate-50 px-2 py-3 text-slate-600 ring-1 ring-slate-200">
                        <div class="flex items-center justify-center gap-2 text-lg text-emerald-700">
                            <i class="fa-solid fa-file-video" aria-hidden="true"></i>
                            <span>{{ $mediaCount }}</span>
                        </div>
                        <div>файлов</div>
                    </div>
                </div>
                <div class="border-t border-slate-200 px-3 py-2 text-xs font-semibold text-slate-500">
	                    Серии доступны в {{ $parsedSeasonCount }} из {{ $seasons->count() }} сезонов
	                </div>
	            </x-ui.panel>

	            <x-ui.panel title="Обновление" icon="fa-solid fa-rotate" :pad="false">
                    <dl class="divide-y divide-slate-200 text-sm">
                        <div class="px-3 py-2">
                            <dt class="text-slate-500">Номер</dt>
                            <dd class="font-bold text-slate-700">{{ $title->external_id ?? 'Неизвестно' }}</dd>
                        </div>
                        <div class="px-3 py-2">
                            <dt class="text-slate-500">Последнее обновление</dt>
                            <dd class="font-bold text-slate-700">{{ $title->indexed_at?->format('d.m.Y H:i') ?? 'Скоро появится' }}</dd>
                        </div>
                    </dl>
                </x-ui.panel>

            <x-ui.panel title="Связи каталога" icon="fa-solid fa-diagram-project">
                <div class="space-y-3">
                    @forelse ($taxonomyGroups as $taxonomyType => $taxonomies)
                        <div>
                            <div class="mb-2 flex items-center justify-between gap-2 text-xs font-bold uppercase tracking-wide text-slate-500">
                                <span class="inline-flex items-center gap-2">
                                    <i class="{{ $taxonomyIcons[$taxonomyType] ?? 'fa-solid fa-tag' }} text-slate-400" aria-hidden="true"></i>
                                    <span>{{ $taxonomyLabels[$taxonomyType] ?? $taxonomyType }}</span>
                                </span>
                                <span class="rounded-full bg-slate-50 px-2 py-0.5 text-slate-500 ring-1 ring-slate-200">{{ $taxonomies->count() }}</span>
                            </div>
                            <div class="flex flex-wrap gap-2">
                                @foreach ($taxonomies as $taxonomy)
                                    <x-ui.taxonomy-chip :taxonomy="$taxonomy" />
                                @endforeach
                            </div>
                        </div>
                    @empty
	                        <span class="text-sm text-slate-500">Связи появятся после обновления страницы.</span>
	                    @endforelse
	                </div>
	            </x-ui.panel>
	        </aside>
    </section>
@endsection
