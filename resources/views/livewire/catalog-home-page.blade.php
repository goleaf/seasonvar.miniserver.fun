<div data-home-page class="space-y-10 pb-4 sm:space-y-12 lg:space-y-14 lg:pb-8">
    <h1 class="sr-only">{{ __('home.title') }}</h1>

    @if (! $isPersonalizedHome)
        <section
            data-home-section="statistics"
            data-home-surface="slate"
            aria-label="{{ __('home.accessibility.statistics') }}"
            class="rounded-panel border border-slate-200 bg-slate-100 px-4 py-3 sm:px-5"
        >
            <div data-home-metrics-compact class="grid grid-cols-2 gap-x-4 gap-y-2 text-sm tabular-nums sm:flex sm:flex-wrap sm:items-center sm:justify-center sm:gap-x-3 lg:justify-start">
                @foreach ([
                    'titles' => $stats['titles'],
                    'episodes' => $stats['episodes'],
                    'videos' => $stats['videos'],
                    'genres' => $stats['genres'],
                    'countries' => $stats['countries'],
                ] as $metric => $value)
                    <span
                        @if ($loop->last) data-home-metrics-mobile-last @endif
                        @class([
                            'inline-flex items-baseline justify-center gap-1 text-slate-700 sm:justify-start',
                            'col-span-2' => $loop->last,
                        ])
                    >
                        <x-localized-number :value="$value" class="font-semibold text-slate-900" />
                        <span>{{ __('home.statistics.'.$metric) }}</span>
                    </span>
                    @unless ($loop->last)
                        <span aria-hidden="true" class="hidden text-slate-300 sm:inline">·</span>
                    @endunless
                @endforeach
            </div>
        </section>

        <section
            data-home-section="trending"
            data-home-surface="amber"
            aria-labelledby="home-trending"
            class="rounded-panel border border-amber-200 bg-amber-50/70 p-4 sm:p-5 lg:p-6"
        >
            <x-catalog.home-section-heading
                id="home-trending"
                :title="__('home.sections.trending')"
                :description="__('home.trending.description')"
                :action-url="$trendingUrl"
                :action-label="__('home.actions.view_all')"
                tone="amber"
            />
            <x-catalog.home-trending-grid :spotlight="$trendingSpotlight" :candidates="$trendingCandidates" />
        </section>

        <section
            data-home-section="latest-updates"
            data-home-surface="sky"
            aria-labelledby="home-latest-updates"
            class="rounded-panel border border-sky-200 bg-sky-50/70 p-4 sm:p-5 lg:p-6"
        >
            <x-catalog.home-section-heading
                id="home-latest-updates"
                :title="__('home.sections.latest_updates')"
                :action-url="$recentlyUpdatedUrl"
                :action-label="__('home.actions.view_all')"
                tone="sky"
            />
            <div data-home-latest-updates-list class="grid gap-3 md:grid-cols-2">
                @forelse ($latestReleaseGroups as $releaseGroup)
                    <div @class(['rounded-panel border border-sky-100 bg-white', 'hidden sm:block' => $loop->iteration > 4])>
                        <x-catalog.latest-media-card
                            :title="$releaseGroup['title']"
                            :episodes="$releaseGroup['episodes']"
                            :media="$releaseGroup['media']"
                            :timezone="$accountTimezone"
                            :episode-count="$releaseGroup['episode_count']"
                            :media-count="$releaseGroup['media_count']"
                            :episode-min="$releaseGroup['episode_min']"
                            :episode-max="$releaseGroup['episode_max']"
                        />
                    </div>
                @empty
                    <p class="rounded-control border border-dashed border-sky-200 bg-white px-4 py-6 text-sm text-slate-600">{{ __('home.empty_states.episodes') }}</p>
                @endforelse
            </div>
        </section>

        <section
            data-home-section="new-titles"
            data-home-surface="slate"
            aria-labelledby="home-new-titles"
            class="rounded-panel border border-slate-200 bg-slate-100 p-4 sm:p-5 lg:p-6"
        >
            <x-catalog.home-section-heading
                id="home-new-titles"
                :title="__('home.sections.new_titles')"
                :action-url="$recentlyAddedUrl"
                :action-label="__('home.actions.view_all')"
            />
            <div class="grid grid-cols-2 gap-x-3 gap-y-5 sm:grid-cols-3 sm:gap-x-4 sm:gap-y-6 lg:grid-cols-4 xl:grid-cols-6">
                @forelse ($homeRecommendationItems as $recommendationItem)
                    <div @class(['min-w-0', 'hidden sm:block' => $loop->iteration > 4])>
                        <x-catalog.title-card :title="$recommendationItem->title" layout="home" :show-description="false" />
                    </div>
                @empty
                    <p class="col-span-full rounded-control border border-dashed border-slate-300 bg-white px-4 py-6 text-sm text-slate-600">{{ __('home.empty_states.titles') }}</p>
                @endforelse
            </div>
        </section>

        <section
            data-home-section="watch-now"
            data-home-surface="emerald"
            aria-labelledby="home-watch-now"
            class="rounded-panel border border-emerald-200 bg-emerald-50/70 p-4 sm:p-5 lg:p-6"
        >
            <x-catalog.home-section-heading
                id="home-watch-now"
                :title="__('home.sections.watch_now')"
                :action-url="route('titles.index')"
                :action-label="__('home.actions.view_all')"
                tone="emerald"
            />
            <div class="grid grid-cols-2 gap-x-3 gap-y-5 sm:grid-cols-3 sm:gap-x-4 sm:gap-y-6 lg:grid-cols-4 xl:grid-cols-6">
                @forelse ($videoTitles as $catalogTitle)
                    <div @class(['min-w-0', 'hidden sm:block' => $loop->iteration > 4])>
                        <x-catalog.title-card :title="$catalogTitle" layout="home" :show-description="false" />
                    </div>
                @empty
                    <p class="col-span-full rounded-control border border-dashed border-emerald-200 bg-white px-4 py-6 text-sm text-slate-600">{{ __('home.empty_states.videos') }}</p>
                @endforelse
            </div>
        </section>

        <section
            data-home-section="featured-collections"
            data-home-surface="sky"
            aria-labelledby="home-featured-collections"
            class="rounded-panel border border-sky-200 bg-sky-50/70 p-4 sm:p-5 lg:p-6"
        >
            <x-catalog.home-section-heading
                id="home-featured-collections"
                :title="__('collections.home.featured')"
                :action-url="$collectionsUrl"
                :action-label="__('collections.navigation.public_collections')"
                tone="sky"
            />
            <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                @forelse ($featuredCollections as $featuredCollection)
                    <x-collections.collection-card :collection="$featuredCollection" :timezone="$accountTimezone" />
                @empty
                    <p class="col-span-full rounded-control border border-dashed border-sky-200 bg-white px-4 py-6 text-sm text-slate-600">{{ __('home.empty_states.collections') }}</p>
                @endforelse
            </div>
        </section>

    @else
        <section
            data-home-section="continue-watching"
            data-home-surface="emerald"
            aria-labelledby="home-continue-watching"
            class="rounded-panel border border-emerald-200 bg-emerald-50/70 p-4 sm:p-5 lg:p-6"
        >
            <x-catalog.home-section-heading
                id="home-continue-watching"
                :title="__('home.sections.continue_watching')"
                :action-url="$continueWatchingUrl"
                :action-label="__('home.actions.view_all')"
                tone="emerald"
            />
            <div class="grid grid-cols-2 gap-x-3 gap-y-5 sm:grid-cols-3 sm:gap-x-4 sm:gap-y-6 lg:grid-cols-6">
                @forelse ($continueWatchingItems as $continueItem)
                    <div @class(['min-w-0', 'hidden sm:block' => $loop->iteration > 4])>
                        <x-ui.poster-card
                            :src="$continueItem->title->poster_url"
                            :alt="__('catalog.seo.poster_alt', ['title' => $continueItem->title->display_title])"
                            layout="home"
                            data-home-continue-title="{{ $continueItem->title->id }}"
                        >
                        <h3 class="text-lg font-semibold leading-tight text-slate-900">
                            <a
                                href="{{ route('titles.show', ['catalogTitle' => $continueItem->title, 'episode' => $continueItem->episode->id]) }}#player"
                                data-home-title-link
                                class="cursor-pointer break-words after:absolute after:inset-0 after:rounded-panel hover:text-emerald-800 focus-visible:outline-none focus-visible:after:ring-4 focus-visible:after:ring-emerald-200 focus-visible:after:ring-inset"
                            >{{ $continueItem->title->display_title }}</a>
                        </h3>
                        <p class="mt-2 line-clamp-1 text-sm text-slate-600">
                            {{ __('catalog.release.season', ['number' => $continueItem->episode->season->number]) }} ·
                            {{ __('catalog.release.episode', ['number' => $continueItem->episode->number]) }}
                        </p>
                        @if ($continueItem->progressPercent !== null)
                            <progress class="mt-3 h-1.5 w-full accent-emerald-700" max="100" value="{{ $continueItem->progressPercent }}" aria-label="{{ __('catalog.viewing.watched_percent_label', ['percent' => $continueItem->progressPercent]) }}"></progress>
                        @endif
                        <a href="{{ route('titles.show', ['catalogTitle' => $continueItem->title, 'episode' => $continueItem->episode->id]) }}#player" class="relative z-10 mt-auto inline-flex min-h-11 items-center gap-2 pt-3 text-sm font-bold text-emerald-700 hover:text-emerald-800">
                            <x-ui.icon name="fa-solid fa-play" />
                            <span>{{ $continueItem->actionLabel }}</span>
                        </a>
                        </x-ui.poster-card>
                    </div>
                @empty
                    <p class="col-span-full rounded-control border border-dashed border-emerald-200 bg-white px-4 py-6 text-sm text-slate-600">{{ __('home.empty_states.continue_watching') }}</p>
                @endforelse
            </div>
        </section>

        <section
            data-home-section="library-updates"
            data-home-surface="sky"
            aria-labelledby="home-library-updates"
            class="rounded-panel border border-sky-200 bg-sky-50/70 p-4 sm:p-5 lg:p-6"
        >
            <x-catalog.home-section-heading
                id="home-library-updates"
                :title="__('home.sections.library_updates')"
                :action-url="$libraryUpdatesUrl"
                :action-label="__('home.actions.view_all')"
                tone="sky"
            />
            <div class="grid grid-cols-2 gap-x-3 gap-y-5 sm:grid-cols-3 sm:gap-x-4 sm:gap-y-6 lg:grid-cols-6">
                @forelse ($libraryUpdateStates as $libraryState)
                    <div @class(['min-w-0', 'hidden sm:block' => $loop->iteration > 4])>
                        <x-catalog.title-card
                            :title="$libraryState->catalogTitle"
                            layout="home"
                            :show-description="false"
                            data-home-library-update="{{ $libraryState->catalog_title_id }}"
                        />
                    </div>
                @empty
                    <p class="col-span-full rounded-control border border-dashed border-sky-200 bg-white px-4 py-6 text-sm text-slate-600">{{ __('home.empty_states.library_updates') }}</p>
                @endforelse
            </div>
        </section>

        <section
            data-home-section="personal-recommendations"
            data-home-surface="amber"
            aria-labelledby="home-personal-recommendations"
            class="rounded-panel border border-amber-200 bg-amber-50/70 p-4 sm:p-5 lg:p-6"
        >
            <x-catalog.home-section-heading
                id="home-personal-recommendations"
                :title="__('home.sections.recommended_for_you')"
                :action-url="$discoveryUrl"
                :action-label="__('home.actions.view_all')"
                tone="amber"
            />
            <div class="grid grid-cols-2 gap-x-3 gap-y-5 sm:grid-cols-3 sm:gap-x-4 sm:gap-y-6 lg:grid-cols-4 xl:grid-cols-6">
                @forelse ($homeRecommendationItems as $recommendationItem)
                    <div @class(['min-w-0', 'hidden sm:block' => $loop->iteration > 4])>
                        <x-catalog.title-card :title="$recommendationItem->title" layout="home" :show-description="false" />
                    </div>
                @empty
                    <p class="col-span-full rounded-control border border-dashed border-amber-200 bg-white px-4 py-6 text-sm text-slate-600">{{ __('home.empty_states.recommendations') }}</p>
                @endforelse
            </div>
        </section>

        <section
            data-home-section="trending"
            data-home-surface="amber"
            aria-labelledby="home-trending"
            class="rounded-panel border border-amber-200 bg-amber-50/70 p-4 sm:p-5 lg:p-6"
        >
            <x-catalog.home-section-heading
                id="home-trending"
                :title="__('home.sections.trending')"
                :action-url="$trendingUrl"
                :action-label="__('home.actions.view_all')"
                tone="amber"
            />
            <x-catalog.home-trending-grid :spotlight="$trendingSpotlight" :candidates="$trendingCandidates" />
        </section>

        <section
            data-home-section="latest-updates"
            data-home-surface="sky"
            aria-labelledby="home-latest-updates"
            class="rounded-panel border border-sky-200 bg-sky-50/70 p-4 sm:p-5 lg:p-6"
        >
            <x-catalog.home-section-heading
                id="home-latest-updates"
                :title="__('home.sections.latest_updates')"
                :action-url="$recentlyUpdatedUrl"
                :action-label="__('home.actions.view_all')"
                tone="sky"
            />
            <div data-home-latest-updates-list class="grid gap-3 md:grid-cols-2">
                @forelse ($latestReleaseGroups as $releaseGroup)
                    <div @class(['rounded-panel border border-sky-100 bg-white', 'hidden sm:block' => $loop->iteration > 4])>
                        <x-catalog.latest-media-card
                            :title="$releaseGroup['title']"
                            :episodes="$releaseGroup['episodes']"
                            :media="$releaseGroup['media']"
                            :timezone="$accountTimezone"
                            :episode-count="$releaseGroup['episode_count']"
                            :media-count="$releaseGroup['media_count']"
                            :episode-min="$releaseGroup['episode_min']"
                            :episode-max="$releaseGroup['episode_max']"
                        />
                    </div>
                @empty
                    <p class="rounded-control border border-dashed border-sky-200 bg-white px-4 py-6 text-sm text-slate-600">{{ __('home.empty_states.episodes') }}</p>
                @endforelse
            </div>
        </section>

        <section
            data-home-section="account-tools"
            data-home-surface="slate"
            aria-labelledby="home-account-tools"
            class="rounded-panel border border-slate-200 bg-slate-100 p-4 sm:p-5 lg:p-6"
        >
            <x-catalog.home-section-heading
                id="home-account-tools"
                :title="__('home.sections.account_tools')"
            />
            <div class="grid gap-3 sm:grid-cols-2">
                <a href="{{ $myCollectionsUrl }}" data-home-section-action class="flex min-h-24 items-center gap-4 rounded-panel border border-emerald-200 bg-emerald-50 p-4 text-slate-700 transition hover:border-emerald-700 hover:text-emerald-800 focus-visible:outline-none focus-visible:ring-4 focus-visible:ring-emerald-200">
                    <span class="grid h-11 w-11 shrink-0 place-items-center rounded-control bg-white text-emerald-700">
                        <x-ui.icon name="fa-solid fa-folder-open text-xl" />
                    </span>
                    <span class="font-semibold">{{ __('home.navigation.my_collections') }}</span>
                </a>
                <a href="{{ $myCalendarUrl }}" data-home-section-action class="flex min-h-24 items-center gap-4 rounded-panel border border-sky-200 bg-sky-50 p-4 text-slate-700 transition hover:border-emerald-700 hover:text-emerald-800 focus-visible:outline-none focus-visible:ring-4 focus-visible:ring-emerald-200">
                    <span class="grid h-11 w-11 shrink-0 place-items-center rounded-control bg-white text-sky-700">
                        <x-ui.icon name="fa-solid fa-calendar-days text-xl" />
                    </span>
                    <span class="font-semibold">{{ __('home.navigation.my_calendar') }}</span>
                </a>
            </div>
        </section>
    @endif

    <section
        data-home-section="catalog-facets"
        data-home-surface="slate"
        aria-labelledby="home-catalog-facets"
        class="rounded-panel border border-slate-200 bg-slate-100 p-4 sm:p-5 lg:p-6"
    >
        <x-catalog.home-section-heading
            id="home-catalog-facets"
            :title="__('home.sections.catalog_facets')"
        />
        <div class="grid gap-6 lg:grid-cols-3">
            <div>
                <h3 class="text-lg font-semibold text-emerald-900">{{ __('home.sections.genres') }}</h3>
                <div
                    data-home-facet-list="genres"
                    class="mt-3 flex max-h-72 flex-wrap content-start gap-2 overflow-y-auto overscroll-contain pr-2 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-emerald-700"
                    tabindex="0"
                    aria-label="{{ __('home.sections.genres') }}"
                >
                    @if ($subtitleTag && $subtitleTagUrl)
                        <a href="{{ $subtitleTagUrl }}" class="inline-flex min-h-8 items-center gap-1 rounded-full bg-slate-100 px-2.5 py-1 text-sm font-semibold text-slate-700 hover:bg-emerald-50 hover:text-emerald-800">
                            <span>{{ __('home.navigation.subtitles') }}</span>
                            <x-localized-number :value="$subtitleTag->catalog_titles_count" class="text-xs text-slate-600" />
                        </a>
                    @endif
                    @foreach ($genres as $genre)
                        <x-ui.taxonomy-chip :taxonomy="$genre" :count="$genre->catalog_titles_count" />
                    @endforeach
                </div>
            </div>
            <div class="border-t border-slate-200 pt-5 lg:border-l lg:border-t-0 lg:pl-6 lg:pt-0">
                <h3 class="text-lg font-semibold text-sky-900">{{ __('home.sections.countries') }}</h3>
                <div
                    data-home-facet-list="countries"
                    class="mt-3 flex max-h-72 flex-wrap content-start gap-2 overflow-y-auto overscroll-contain pr-2 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-emerald-700"
                    tabindex="0"
                    aria-label="{{ __('home.sections.countries') }}"
                >
                    @foreach ($countries as $country)
                        <a href="{{ $country->detail_url }}" class="inline-flex min-h-8 items-center gap-1 rounded-full bg-slate-100 px-2.5 py-1 text-sm font-semibold text-slate-700 hover:bg-emerald-50 hover:text-emerald-800">
                            <span>{{ $country->name }}</span>
                            <x-localized-number :value="$country->catalog_titles_count" class="text-xs text-slate-600" />
                        </a>
                    @endforeach
                </div>
            </div>
            <div class="border-t border-slate-200 pt-5 lg:border-l lg:border-t-0 lg:pl-6 lg:pt-0">
                <h3 class="text-lg font-semibold text-amber-900">{{ __('home.sections.years') }}</h3>
                <div
                    data-home-facet-list="years"
                    class="mt-3 flex max-h-72 flex-wrap content-start gap-2 overflow-y-auto overscroll-contain pr-2 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-emerald-700"
                    tabindex="0"
                    aria-label="{{ __('home.sections.years') }}"
                >
                    @foreach ($yearBuckets as $bucket)
                        <a href="{{ route('titles.year', ['year' => $bucket->year]) }}" class="inline-flex min-h-8 items-center gap-1 rounded-full bg-slate-100 px-2.5 py-1 text-sm font-semibold text-slate-700 hover:bg-emerald-50 hover:text-emerald-800">
                            <span>{{ $bucket->year }}</span>
                            <x-localized-number :value="$bucket->titles_count" class="text-xs text-slate-600" />
                        </a>
                    @endforeach
                </div>
            </div>
        </div>
    </section>
</div>
