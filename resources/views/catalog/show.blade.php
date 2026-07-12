@extends('layouts.app', ['title' => $seo['title'] ?? $title->title, 'seo' => $seo ?? []])

@section('content')
    <section class="grid min-w-0 gap-5 lg:grid-cols-[280px_minmax(0,1fr)] xl:grid-cols-[300px_minmax(0,1fr)]">
        <aside class="space-y-4">
            <section class="h-full overflow-hidden rounded-panel bg-white shadow-panel">
                <div class="bg-slate-50 px-4 py-3">
                    <div class="flex items-center gap-2">
                        <span class="inline-flex h-7 w-7 shrink-0 items-center justify-center rounded-control bg-emerald-50 text-emerald-700">
                            <i class="fa-solid fa-compass" aria-hidden="true"></i>
                        </span>
                        <h2 class="text-sm font-bold text-slate-700">Быстрый доступ</h2>
                    </div>
                </div>
                <div class="space-y-4 p-4">
                    <nav aria-label="Быстрые переходы по сериалу" class="grid gap-2">
                        <a href="#player" class="inline-flex min-h-11 items-center gap-2 rounded-control bg-emerald-700 px-3 py-2 text-sm font-bold text-white hover:bg-emerald-600">
                            <i class="fa-solid fa-circle-play" aria-hidden="true"></i>
                            <span>Смотреть</span>
                        </a>

                        <a href="#seasons" class="inline-flex min-h-11 items-center gap-2 rounded-control bg-slate-50 px-3 py-2 text-sm font-bold text-slate-600 hover:bg-emerald-50 hover:text-emerald-700">
                            <i class="fa-solid fa-layer-group" aria-hidden="true"></i>
                            <span>Сезоны</span>
                        </a>

                        <a href="#data-title-reference" class="inline-flex min-h-11 items-center gap-2 rounded-control bg-slate-50 px-3 py-2 text-sm font-bold text-slate-600 hover:bg-emerald-50 hover:text-emerald-700">
                            <i class="fa-solid fa-circle-info" aria-hidden="true"></i>
                            <span>О сериале</span>
                        </a>
                    </nav>

                    <div class="grid gap-2 sm:grid-cols-3 lg:grid-cols-1">
                        <div class="rounded-lg bg-slate-50 px-3 py-2">
                            <div class="flex items-center justify-between gap-2">
                                <div class="text-xs font-bold uppercase tracking-wide text-slate-500">Сезонов</div>
                                <i class="fa-solid fa-layer-group text-slate-400" aria-hidden="true"></i>
                            </div>
                            <div class="mt-1 text-lg font-black text-slate-800">{{ $showView->parsedSeasonCount }}</div>
                        </div>

                        <div class="rounded-lg bg-slate-50 px-3 py-2">
                            <div class="flex items-center justify-between gap-2">
                                <div class="text-xs font-bold uppercase tracking-wide text-slate-500">Серий</div>
                                <i class="fa-solid fa-list-ol text-slate-400" aria-hidden="true"></i>
                            </div>
                            <div class="mt-1 text-lg font-black text-slate-800">{{ $showView->episodeCount }}</div>
                        </div>

                        <div class="rounded-lg bg-slate-50 px-3 py-2">
                            <div class="flex items-center justify-between gap-2">
                                <div class="text-xs font-bold uppercase tracking-wide text-slate-500">Видео</div>
                                <i class="fa-solid fa-file-video text-slate-400" aria-hidden="true"></i>
                            </div>
                            <div class="mt-1 text-lg font-black text-slate-800">{{ $showView->mediaCount }}</div>
                        </div>
                    </div>

                </div>
            </section>
        </aside>

        <div class="min-w-0 space-y-5">
            <x-ui.panel data-title-hero :pad="false" class="overflow-hidden border-emerald-100">
                <div class="flex flex-wrap items-center justify-between gap-2 border-b border-slate-200 bg-slate-50 px-4 py-3">
                    <a href="{{ route('titles.index') }}" class="inline-flex min-h-11 items-center gap-2 rounded-control bg-slate-50 px-3 py-2 text-sm font-bold text-slate-600 hover:bg-emerald-50 hover:text-emerald-700">
                        <i class="fa-solid fa-arrow-left" aria-hidden="true"></i>
                        <span>К каталогу</span>
                    </a>
                </div>

                <article class="grid gap-5 bg-gradient-to-br from-white via-white to-emerald-50 p-4 md:grid-cols-[minmax(150px,220px)_minmax(0,1fr)] md:p-5">
                    <x-title-poster :title="$title" class="mx-auto aspect-[2/3] w-44 max-w-full border border-slate-200 shadow-panel sm:w-52 md:w-full" empty-class="grid h-full place-items-center px-6 text-center text-sm text-slate-500" />

                    <div class="min-w-0">
                        <h1 class="flex items-start gap-3 text-2xl font-black tracking-tight text-slate-800 sm:text-3xl">
                            <i class="fa-solid fa-clapperboard mt-1 text-emerald-700" aria-hidden="true"></i>
                            <span>{{ $title->title }}</span>
                        </h1>
                        @if ($title->original_title)
                            <div class="mt-2 break-words text-sm font-semibold text-slate-500">{{ $title->original_title }}</div>
                        @endif

                        <div class="mt-4 flex flex-wrap gap-2 text-xs font-bold">
                            @if ($title->year)
                                <x-ui.taxonomy-chip :href="route('titles.year', ['year' => $title->year])" active icon="fa-solid fa-calendar-days">{{ $title->year }}</x-ui.taxonomy-chip>
                            @endif
                            @foreach ($ageRatings as $ageRating)
                                <x-ui.taxonomy-chip :taxonomy="$ageRating" active />
                            @endforeach
                            <x-ui.taxonomy-chip icon="fa-solid fa-layer-group">{{ $seasons->count() }} сезонов</x-ui.taxonomy-chip>
                            <x-ui.taxonomy-chip icon="fa-solid fa-list-ol">{{ $episodeCount }} серий</x-ui.taxonomy-chip>
                            <x-ui.taxonomy-chip icon="fa-solid fa-file-video">{{ $mediaCount }} видео</x-ui.taxonomy-chip>
                        </div>

                        <section class="mt-5 rounded-control border border-slate-200 bg-white p-4">
                            <h2 class="flex items-center gap-2 text-sm font-bold text-slate-700">
                                <i class="fa-solid fa-book-open text-slate-400" aria-hidden="true"></i>
                                <span>Описание</span>
                            </h2>
                            <p class="mt-2 text-sm leading-6 text-slate-600">{{ $title->description ?: 'Описание пока отсутствует.' }}</p>
                        </section>

                    </div>
                </article>
            </x-ui.panel>

            <livewire:catalog-title-player :catalog-title-id="$title->id" />

            <x-ui.panel data-title-reference title="О сериале" icon="fa-solid fa-circle-info">
                @if ($actors->isNotEmpty())
                    <div>
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

                <dl class="mt-4 divide-y divide-slate-200 text-sm">
                    @foreach ($taxonomyRows as $row)
                        @if ($row['items']->isNotEmpty())
                            <div class="grid gap-2 py-3 sm:grid-cols-[120px_minmax(0,1fr)]">
                                <dt class="inline-flex items-center gap-2 font-bold text-slate-500">
                                    <i class="{{ $row['icon'] ?? 'fa-solid fa-tag' }} text-slate-400" aria-hidden="true"></i>
                                    <span>{{ $row['label'] }}</span>
                                </dt>
                                <dd class="flex flex-wrap gap-1.5">
                                    @foreach ($row['items'] as $taxonomy)
                                        <x-ui.taxonomy-chip :taxonomy="$taxonomy" />
                                    @endforeach
                                </dd>
                            </div>
                        @endif
                    @endforeach
                    @if ($aliases->isNotEmpty())
                        <div class="grid gap-2 py-3 sm:grid-cols-[120px_minmax(0,1fr)]">
                            <dt class="inline-flex items-center gap-2 font-bold text-slate-500">
                                <i class="fa-solid fa-signature text-slate-400" aria-hidden="true"></i>
                                <span>Другие названия</span>
                            </dt>
                            <dd class="flex flex-wrap gap-1.5">
                                @foreach ($aliases as $alias)
                                    <x-ui.status-pill variant="muted">{{ $alias->name }}</x-ui.status-pill>
                                @endforeach
                            </dd>
                        </div>
                    @endif
                    @if ($ratings->isNotEmpty())
                        <div class="grid gap-2 py-3 sm:grid-cols-[120px_minmax(0,1fr)]">
                            <dt class="inline-flex items-center gap-2 font-bold text-slate-500">
                                <i class="fa-solid fa-star text-slate-400" aria-hidden="true"></i>
                                <span>Рейтинги</span>
                            </dt>
                            <dd class="flex flex-wrap gap-1.5">
                                @foreach ($ratings as $rating)
                                    <x-ui.status-pill variant="success">
                                        {{ mb_strtoupper($rating->provider) }}: {{ $rating->rating }}@if ($rating->votes) · {{ $rating->votes }} голосов @endif
                                    </x-ui.status-pill>
                                @endforeach
                            </dd>
                        </div>
                    @endif
                    @if ($title->year)
                        <div class="grid gap-2 py-3 sm:grid-cols-[120px_minmax(0,1fr)]">
                            <dt class="font-bold text-slate-500">Вышел</dt>
                            <dd><a href="{{ route('titles.year', ['year' => $title->year]) }}" class="font-bold text-emerald-700">{{ $title->year }}</a></dd>
                        </div>
                    @endif
                </dl>

                @if ($topTaxonomies->isNotEmpty())
                    <div class="mt-4 flex flex-wrap gap-2">
                        @foreach ($topTaxonomies as $taxonomy)
                            <x-ui.taxonomy-chip :taxonomy="$taxonomy" />
                        @endforeach
                    </div>
                @endif
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
                                            <span class="inline-flex items-center gap-1 rounded-full bg-emerald-50 px-2 py-1 text-emerald-700">
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
                                                            <span class="inline-flex items-center gap-1 rounded-full bg-slate-50 px-2 py-1 text-slate-600">
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
                                                    <span class="inline-flex items-center gap-1 rounded-full bg-slate-50 px-2 py-1 text-slate-600">
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

            <x-ui.panel title="Связи каталога" icon="fa-solid fa-diagram-project">
                <div class="space-y-3">
                    @forelse ($taxonomyGroups as $taxonomyType => $taxonomies)
                        <div>
                            <div class="mb-2 flex items-center justify-between gap-2 text-xs font-bold uppercase tracking-wide text-slate-500">
                                <span class="inline-flex items-center gap-2">
                                    <i class="{{ $taxonomyIcons[$taxonomyType] ?? 'fa-solid fa-tag' }} text-slate-400" aria-hidden="true"></i>
                                    <span>{{ $taxonomyLabels[$taxonomyType] ?? $taxonomyType }}</span>
                                </span>
                                <span class="rounded-full bg-slate-50 px-2 py-0.5 text-slate-500">{{ $taxonomies->count() }}</span>
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
        </div>
    </section>
@endsection
