@extends('layouts.app', ['title' => $seo['title'] ?? $title->title, 'seo' => $seo ?? []])

@section('content')
    <section class="grid min-w-0 gap-5 lg:grid-cols-[minmax(0,1fr)_280px] xl:grid-cols-[minmax(0,1fr)_300px]">
        <main class="min-w-0 space-y-5">
            <x-ui.panel :pad="false">
                <div class="flex flex-col gap-2 border-b border-slate-200 bg-slate-50 px-4 py-3 sm:flex-row sm:items-center sm:justify-between">
                    <a href="{{ route('home') }}" class="inline-flex items-center gap-2 rounded-lg bg-white px-3 py-2 text-sm font-bold text-slate-600 ring-1 ring-slate-200 hover:bg-emerald-50 hover:text-emerald-700">
                        <i class="fa-solid fa-arrow-left" aria-hidden="true"></i>
                        <span>Вернуться</span>
                    </a>
                    <div class="flex flex-wrap gap-2 text-xs font-bold">
                        <a href="#player" class="inline-flex items-center gap-1 rounded-full bg-emerald-50 px-2.5 py-1 text-emerald-700 ring-1 ring-emerald-100 hover:bg-emerald-100">
                            <i class="fa-solid fa-circle-play" aria-hidden="true"></i>
                            <span>Смотреть</span>
                        </a>
                        <a href="#seasons" class="inline-flex items-center gap-1 rounded-full bg-white px-2.5 py-1 text-slate-600 ring-1 ring-slate-200 hover:bg-emerald-50 hover:text-emerald-700">
                            <i class="fa-solid fa-layer-group" aria-hidden="true"></i>
                            <span>Сезоны</span>
                        </a>
                    </div>
                </div>

                <article class="grid gap-4 p-3 sm:p-4 md:grid-cols-[minmax(150px,230px)_minmax(0,1fr)] md:gap-5">
                    <div>
                        <x-title-poster :title="$title" class="mx-auto aspect-[2/3] w-44 max-w-full border border-slate-200 sm:w-52 md:w-full" empty-class="grid h-full place-items-center px-6 text-center text-sm text-slate-400" />
                    </div>

                    <div class="min-w-0">
                        <h1 class="inline-flex items-start gap-2 text-xl font-black leading-tight text-slate-700 sm:text-2xl">
                            <i class="fa-solid fa-clapperboard mt-1 text-emerald-700" aria-hidden="true"></i>
                            <span>{{ $title->title }}</span>
                        </h1>
                        @if ($title->original_title)
                            <div class="mt-1 text-sm font-semibold text-slate-500">{{ $title->original_title }}</div>
                        @endif
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
                            <x-ui.taxonomy-chip icon="fa-solid fa-list-ol">{{ $episodeCount }} серий</x-ui.taxonomy-chip>
                            <x-ui.taxonomy-chip icon="fa-solid fa-file-video">{{ $mediaCount }} видео</x-ui.taxonomy-chip>
                        </div>

                        <div class="mt-4 grid gap-2 text-sm sm:grid-cols-3">
                            <div class="rounded-lg border border-slate-200 bg-slate-50 p-3">
                                <div class="text-xs font-bold uppercase tracking-wide text-slate-400">Сезоны</div>
                                <div class="mt-1 inline-flex items-center gap-2 font-black text-slate-700">
                                    <i class="fa-solid fa-layer-group text-emerald-700" aria-hidden="true"></i>
                                    <span>{{ $seasons->count() }}</span>
                                </div>
                            </div>
                            <div class="rounded-lg border border-slate-200 bg-slate-50 p-3">
                                <div class="text-xs font-bold uppercase tracking-wide text-slate-400">Серии</div>
                                <div class="mt-1 inline-flex items-center gap-2 font-black text-slate-700">
                                    <i class="fa-solid fa-list-ol text-sky-700" aria-hidden="true"></i>
                                    <span>{{ $episodeCount }}</span>
                                </div>
                            </div>
                            <div class="rounded-lg border border-slate-200 bg-slate-50 p-3">
                                <div class="text-xs font-bold uppercase tracking-wide text-slate-400">Видео</div>
                                <div class="mt-1 inline-flex items-center gap-2 font-black text-slate-700">
                                    <i class="fa-solid fa-file-video text-amber-700" aria-hidden="true"></i>
                                    <span>{{ $mediaCount }}</span>
                                </div>
                            </div>
                        </div>

                        <div class="mt-4 rounded-lg border border-slate-200 bg-slate-50">
                            <div class="flex items-center gap-2 border-b border-slate-200 px-3 py-2 text-sm font-bold text-slate-700">
                                <i class="fa-solid fa-book-open text-slate-400" aria-hidden="true"></i>
                                <span>Описание</span>
                            </div>
                            @if ($title->description)
                                <p class="px-3 py-3 text-sm leading-6 text-slate-600">{{ $title->description }}</p>
                            @else
                                <p class="px-3 py-3 text-sm leading-6 text-slate-500">Описание пока отсутствует.</p>
                            @endif
                        </div>

                        @if ($seasons->isNotEmpty())
                            <div class="mt-5">
                                <div class="inline-flex items-center gap-2 text-sm font-bold text-slate-700">
                                    <i class="fa-solid fa-layer-group text-slate-400" aria-hidden="true"></i>
                                    <span>Сезоны сериала</span>
                                </div>
                                <div class="mt-2 flex flex-wrap gap-2">
                                    @foreach ($seasons as $season)
                                        <a href="#season-{{ $season->number }}" @class([
                                            'inline-flex items-center gap-1 rounded-full px-2.5 py-1 text-xs font-bold ring-1',
                                            'bg-emerald-50 text-emerald-700 ring-emerald-100' => $showView->isSelectedSeason($season, $loop->first),
                                            'bg-white text-slate-600 ring-slate-200 hover:bg-emerald-50 hover:text-emerald-700' => ! $showView->isSelectedSeason($season, $loop->first),
                                        ])>
                                            <i class="fa-solid fa-layer-group" aria-hidden="true"></i>
                                            <span>{{ $season->number }} сезон</span>
                                        </a>
                                    @endforeach
                                </div>
                            </div>
                        @endif

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

            <x-ui.panel id="player" title="Просмотр" icon="fa-solid fa-circle-play" class="scroll-mt-40 sm:scroll-mt-44 lg:scroll-mt-48">
                <div class="flex flex-wrap items-center gap-2 text-xs font-bold text-slate-600">
                    @if ($selectedEpisode)
                        <x-ui.status-pill icon="fa-solid fa-circle-play" variant="success">
                            Выбрана {{ $selectedEpisode->number }} серия
                        </x-ui.status-pill>
                        @if ($selectedEpisode->title)
                            <x-ui.status-pill icon="fa-solid fa-file-lines">
                                {{ $selectedEpisode->title }}
                            </x-ui.status-pill>
                        @endif
                    @else
                        <x-ui.status-pill icon="fa-solid fa-circle-info">
                            Серия не выбрана
                        </x-ui.status-pill>
                    @endif
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
                                class="js-catalog-player aspect-video w-full bg-black"
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
                                    {{ $selectedEpisode ? 'Видео для этой серии пока нет' : 'Выберите серию для просмотра' }}
                                </div>
                                <p class="mt-1 text-sm">Можно выбрать другую серию в списке ниже.</p>
                            </div>
                        </div>
                    @endif
                </div>

                @if ($selectedEpisodeMediaItems->isNotEmpty())
                    <div class="mt-4 rounded-lg border border-slate-200 bg-white">
                        <div class="flex flex-col gap-2 border-b border-slate-200 bg-slate-50 px-4 py-3 sm:flex-row sm:items-center sm:justify-between">
                            <div class="inline-flex items-center gap-2 text-sm font-bold text-slate-700">
                                <i class="fa-solid fa-sliders text-slate-400" aria-hidden="true"></i>
                                <span>Настройки просмотра</span>
                            </div>
                            @if ($showView->selectedMediaBadges->isNotEmpty())
                                <div class="flex flex-wrap gap-1">
                                    @foreach ($showView->selectedMediaBadges as $badge)
                                        <x-ui.status-pill size="xs" variant="success">{{ $badge }}</x-ui.status-pill>
                                    @endforeach
                                </div>
                            @endif
                        </div>

                        @if ($showView->playbackOptionGroups !== [])
                            <div class="space-y-4 p-4">
                                @foreach ($showView->playbackOptionGroups as $group)
                                    <section class="space-y-2">
                                        <div class="inline-flex items-center gap-2 text-xs font-black uppercase tracking-wide text-slate-500">
                                            <i class="{{ $group['icon'] }} text-slate-400" aria-hidden="true"></i>
                                            <span>{{ $group['label'] }}</span>
                                        </div>
                                        <div class="grid gap-2 sm:grid-cols-2 xl:grid-cols-3">
                                            @foreach ($group['options'] as $option)
                                                <a href="{{ $option['url'] }}" @class([
                                                    'min-h-14 rounded-lg px-3 py-2 text-sm ring-1 transition',
                                                    'bg-emerald-50 text-emerald-800 ring-emerald-200' => $option['active'],
                                                    'bg-white text-slate-600 ring-slate-200 hover:bg-emerald-50 hover:text-emerald-700 hover:ring-emerald-200' => ! $option['active'],
                                                ])>
                                                    <span class="flex items-start gap-2 font-bold">
                                                        <i class="{{ $option['icon'] }} mt-0.5 shrink-0" aria-hidden="true"></i>
                                                        <span>{{ $option['label'] }}</span>
                                                    </span>
                                                    @if ($option['detail'])
                                                        <span class="mt-1 block text-xs font-semibold text-slate-500">{{ $option['detail'] }}</span>
                                                    @endif
                                                </a>
                                            @endforeach
                                        </div>
                                    </section>
                                @endforeach
                            </div>
                        @else
                            <div class="flex flex-wrap gap-2 p-4 text-xs font-bold text-slate-600">
                                <x-ui.status-pill icon="fa-solid fa-file-video" variant="success" size="md">
                                    {{ $showView->selectedPlaybackLabel }}
                                </x-ui.status-pill>
                            </div>
                        @endif
                    </div>
                @elseif ($selectedMedia)
                    <div class="mt-3 flex flex-wrap gap-2 text-xs font-bold text-slate-600">
                        <x-ui.status-pill icon="fa-solid fa-file-video" variant="success" size="md">
                            {{ $selectedMedia->title }}
                        </x-ui.status-pill>
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
                                <a href="{{ route('titles.show', $showView->mediaQuery($media)) }}#player" @class([
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
                                            <span>{{ $showView->mediaDetailsLabel($media) }}</span>
                                        </span>
                                    </div>
                                </a>
                            @endforeach
                        </div>
                    </div>
                @endif
            </x-ui.panel>

            <x-ui.panel id="seasons" title="Сезоны и серии" icon="fa-solid fa-layer-group" :pad="false" class="scroll-mt-40 sm:scroll-mt-44 lg:scroll-mt-48">
                <div class="divide-y divide-slate-200">
                    @forelse ($seasons as $season)
                        <details id="season-{{ $season->number }}" class="group scroll-mt-40 sm:scroll-mt-44 lg:scroll-mt-48" @if ($showView->isSelectedSeason($season, $loop->first)) open @endif>
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
                                    <x-ui.status-pill variant="muted">
                                        {{ $showView->seasonEpisodeCount($season) }} серий
                                    </x-ui.status-pill>
                                    @foreach ($showView->seasonStatusBadges($season) as $badge)
                                        <x-ui.status-pill variant="success">{{ $badge }}</x-ui.status-pill>
                                    @endforeach
                                </span>
                            </summary>

                            <div class="px-4 pb-4">
                                @if ($showView->seasonEpisodeCount($season) > 0)
                                    <div class="grid gap-2 sm:grid-cols-2 xl:grid-cols-3 2xl:grid-cols-4">
                                        @foreach ($season->episodes->sortBy('number')->values() as $episode)
                                            <x-catalog.episode-link :title="$title" :episode="$episode" :show-view="$showView" />
                                        @endforeach
                                    </div>
                                @else
                                    <div class="rounded-lg bg-slate-50 p-4 text-sm text-slate-500 ring-1 ring-slate-200">
                                        Для этого сезона пока нет серий.
                                    </div>
                                @endif
                            </div>
                        </details>
                    @empty
                        <p class="p-4 text-sm text-slate-500">Сезоны не указаны.</p>
                    @endforelse
                </div>
            </x-ui.panel>

            <x-ui.panel title="Советуем посмотреть" icon="fa-solid fa-thumbs-up" :pad="false">
                @if ($recommendedTitleRecommendations->first()?->recommendedTitle)
                    <div class="space-y-3 p-3">
                        <div class="grid gap-3 lg:grid-cols-[minmax(0,0.95fr)_minmax(0,1.05fr)]">
                            <div class="min-w-0">
                                <x-title-card :title="$recommendedTitleRecommendations->first()->recommendedTitle" />

                                @if ($recommendedTitleRecommendations->first()->reasonLabels() !== [])
                                    <div class="mt-2 flex flex-wrap gap-1 text-xs font-bold">
                                        @foreach ($recommendedTitleRecommendations->first()->reasonLabels() as $reasonLabel)
                                            <span class="inline-flex items-center gap-1 rounded-full bg-emerald-50 px-2 py-1 text-emerald-700 ring-1 ring-emerald-100">
                                                <i class="fa-solid fa-check text-[0.8em]" aria-hidden="true"></i>
                                                <span>{{ $reasonLabel }}</span>
                                            </span>
                                        @endforeach
                                    </div>
                                @endif
                            </div>

                            @if ($recommendedTitleRecommendations->skip(1)->take(4)->isNotEmpty())
                                <div class="min-w-0 overflow-hidden rounded-lg border border-slate-200 bg-white">
                                    <div class="flex items-center gap-2 border-b border-slate-200 bg-slate-50 px-4 py-3 text-sm font-bold text-slate-700">
                                        <i class="fa-solid fa-ranking-star text-emerald-700" aria-hidden="true"></i>
                                        <span>Ближайшие совпадения</span>
                                    </div>
                                    <div class="divide-y divide-slate-200">
                                        @foreach ($recommendedTitleRecommendations->skip(1)->take(4) as $recommendation)
                                            <div>
                                                <x-title-list-row :title="$recommendation->recommendedTitle" compact :show-description="false" />

                                                @if ($recommendation->reasonLabels() !== [])
                                                    <div class="flex flex-wrap gap-1 px-3 pb-3 text-xs font-bold">
                                                        @foreach ($recommendation->reasonLabels() as $reasonLabel)
                                                            <span class="inline-flex items-center gap-1 rounded-full bg-slate-50 px-2 py-1 text-slate-600 ring-1 ring-slate-200">
                                                                <i class="fa-solid fa-check text-[0.8em] text-emerald-700" aria-hidden="true"></i>
                                                                <span>{{ $reasonLabel }}</span>
                                                            </span>
                                                        @endforeach
                                                    </div>
                                                @endif
                                            </div>
                                        @endforeach
                                    </div>
                                </div>
                            @endif
                        </div>

                        @if ($recommendedTitleRecommendations->skip(5)->isNotEmpty())
                            <div class="grid auto-rows-fr gap-3 sm:grid-cols-2 xl:grid-cols-3">
                                @foreach ($recommendedTitleRecommendations->skip(5) as $recommendation)
                                    <div class="min-w-0">
                                        <x-title-card :title="$recommendation->recommendedTitle" />

                                        @if ($recommendation->reasonLabels() !== [])
                                            <div class="mt-2 flex flex-wrap gap-1 text-xs font-bold">
                                                @foreach ($recommendation->reasonLabels() as $reasonLabel)
                                                    <span class="inline-flex items-center gap-1 rounded-full bg-slate-50 px-2 py-1 text-slate-600 ring-1 ring-slate-200">
                                                        <i class="fa-solid fa-check text-[0.8em] text-emerald-700" aria-hidden="true"></i>
                                                        <span>{{ $reasonLabel }}</span>
                                                    </span>
                                                @endforeach
                                            </div>
                                        @endif
                                    </div>
                                @endforeach
                            </div>
                        @endif
                    </div>
                @else
                    @if ($genreRecommendations->isNotEmpty() || $yearRecommendations->isNotEmpty())
                        <div class="grid min-w-0 lg:grid-cols-[minmax(0,1fr)_minmax(0,1fr)]">
                            @if ($genreRecommendations->isNotEmpty())
                                <section @class([
                                    'min-w-0',
                                    'lg:border-r lg:border-slate-200' => $yearRecommendations->isNotEmpty(),
                                ])>
                                    <div class="border-b border-slate-200 bg-slate-50 px-4 py-3">
                                        <div class="inline-flex items-center gap-2 text-sm font-bold text-slate-700">
                                            <i class="fa-solid fa-tags text-emerald-700" aria-hidden="true"></i>
                                            <span>По похожим жанрам</span>
                                        </div>
                                    </div>
                                    <div class="divide-y divide-slate-200">
                                        @foreach ($genreRecommendations->take(6) as $recommendedTitle)
                                            <x-title-list-row :title="$recommendedTitle" readable :show-description="false" />
                                        @endforeach
                                    </div>
                                </section>
                            @endif

                            @if ($yearRecommendations->isNotEmpty())
                                <section class="min-w-0">
                                    <div class="border-b border-slate-200 bg-slate-50 px-4 py-3">
                                        <div class="inline-flex items-center gap-2 text-sm font-bold text-slate-700">
                                            <i class="fa-solid fa-calendar-days text-emerald-700" aria-hidden="true"></i>
                                            <span>За {{ $title->year }} год</span>
                                        </div>
                                    </div>
                                    <div class="divide-y divide-slate-200">
                                        @foreach ($yearRecommendations->take(6) as $recommendedTitle)
                                            <x-title-list-row :title="$recommendedTitle" readable :show-description="false" />
                                        @endforeach
                                    </div>
                                </section>
                            @endif
                        </div>
                    @else
                        <div class="p-3">
                            <div class="rounded-lg border border-dashed border-slate-200 bg-slate-50 p-4 text-sm text-slate-500">
                                <div class="inline-flex items-center gap-2">
                                    <i class="fa-solid fa-circle-info text-slate-400" aria-hidden="true"></i>
                                    <span>Похожие сериалы пока не подобраны.</span>
                                </div>
                            </div>
                        </div>
                    @endif
                @endif
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
                        <span class="text-sm text-slate-500">Связи не указаны.</span>
                    @endforelse
                </div>
            </x-ui.panel>
        </aside>
    </section>
@endsection
