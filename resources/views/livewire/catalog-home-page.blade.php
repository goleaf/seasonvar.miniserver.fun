<div class="space-y-8 lg:space-y-10">
    <h1 class="sr-only">{{ __('home.title') }}</h1>

    @if (! $isPersonalizedHome)
        <section data-home-section="statistics" aria-label="{{ __('home.accessibility.statistics') }}">
            <div data-home-metrics-compact class="grid grid-cols-2 gap-x-4 gap-y-2 border-y border-slate-200 py-3 text-sm sm:flex sm:flex-wrap sm:items-center sm:justify-center sm:gap-x-3 lg:justify-start">
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

        <section data-home-section="trending" aria-labelledby="home-trending">
            <div class="mb-4 flex items-end justify-between gap-3 border-b border-slate-200 pb-3">
                <div>
                    <h2 id="home-trending" class="text-2xl font-semibold text-slate-900">{{ __('home.sections.trending') }}</h2>
                    <p class="mt-1 text-sm text-slate-600">{{ __('home.trending.description') }}</p>
                </div>
                <a href="{{ $trendingUrl }}" class="shrink-0 text-sm font-bold text-emerald-700 hover:text-emerald-800">{{ __('home.actions.view_all') }}</a>
            </div>
            <x-catalog.home-trending-grid :spotlight="$trendingSpotlight" :candidates="$trendingCandidates" />
        </section>

        <section data-home-section="latest-updates" aria-labelledby="home-latest-updates">
            <div class="mb-4 flex items-end justify-between gap-3 border-b border-slate-200 pb-3">
                <h2 id="home-latest-updates" class="text-2xl font-semibold text-slate-900">{{ __('home.sections.latest_updates') }}</h2>
                <a href="{{ $recentlyUpdatedUrl }}" class="shrink-0 text-sm font-bold text-emerald-700 hover:text-emerald-800">{{ __('home.actions.view_all') }}</a>
            </div>
            <div data-home-latest-updates-list class="grid gap-3 md:grid-cols-2">
                @forelse ($latestReleaseGroups as $releaseGroup)
                    <div @class(['border-b border-slate-200 pb-3', 'hidden sm:block' => $loop->iteration > 4])>
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
                    <p class="py-6 text-sm text-slate-600">{{ __('home.empty_states.episodes') }}</p>
                @endforelse
            </div>
        </section>

        <section data-home-section="new-titles" aria-labelledby="home-new-titles">
            <div class="mb-4 flex items-end justify-between gap-3 border-b border-slate-200 pb-3">
                <h2 id="home-new-titles" class="text-2xl font-semibold text-slate-900">{{ __('home.sections.new_titles') }}</h2>
                <a href="{{ $recentlyAddedUrl }}" class="shrink-0 text-sm font-bold text-emerald-700 hover:text-emerald-800">{{ __('home.actions.view_all') }}</a>
            </div>
            <div class="grid grid-cols-2 gap-x-3 gap-y-6 sm:grid-cols-3 lg:grid-cols-4 xl:grid-cols-6">
                @forelse ($homeRecommendationItems as $recommendationItem)
                    <div @class(['min-w-0', 'hidden sm:block' => $loop->iteration > 4])>
                        <x-catalog.title-card :title="$recommendationItem->title" layout="home" :show-description="false" />
                    </div>
                @empty
                    <p class="col-span-full py-6 text-sm text-slate-600">{{ __('home.empty_states.titles') }}</p>
                @endforelse
            </div>
        </section>

        <section data-home-section="watch-now" aria-labelledby="home-watch-now">
            <div class="mb-4 flex items-end justify-between gap-3 border-b border-slate-200 pb-3">
                <h2 id="home-watch-now" class="text-2xl font-semibold text-slate-900">{{ __('home.sections.watch_now') }}</h2>
                <a href="{{ route('titles.index') }}" class="shrink-0 text-sm font-bold text-emerald-700 hover:text-emerald-800">{{ __('home.actions.view_all') }}</a>
            </div>
            <div class="grid grid-cols-2 gap-x-3 gap-y-6 sm:grid-cols-3 lg:grid-cols-4 xl:grid-cols-6">
                @forelse ($videoTitles as $catalogTitle)
                    <div @class(['min-w-0', 'hidden sm:block' => $loop->iteration > 4])>
                        <x-catalog.title-card :title="$catalogTitle" layout="home" :show-description="false" />
                    </div>
                @empty
                    <p class="col-span-full py-6 text-sm text-slate-600">{{ __('home.empty_states.videos') }}</p>
                @endforelse
            </div>
        </section>

        <section data-home-section="featured-collections" aria-labelledby="home-featured-collections">
            <div class="mb-4 flex items-end justify-between gap-3 border-b border-slate-200 pb-3">
                <h2 id="home-featured-collections" class="text-2xl font-semibold text-slate-900">{{ __('collections.home.featured') }}</h2>
                <a href="{{ $collectionsUrl }}" class="shrink-0 text-sm font-bold text-emerald-700 hover:text-emerald-800">{{ __('collections.navigation.public_collections') }}</a>
            </div>
            <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                @forelse ($featuredCollections as $featuredCollection)
                    <x-collections.collection-card :collection="$featuredCollection" :timezone="$accountTimezone" />
                @empty
                    <p class="py-6 text-sm text-slate-600">{{ __('home.empty_states.collections') }}</p>
                @endforelse
            </div>
        </section>

    @else
        <section data-home-section="continue-watching" aria-labelledby="home-continue-watching">
            <div class="mb-4 flex items-end justify-between gap-3 border-b border-slate-200 pb-3">
                <h2 id="home-continue-watching" class="text-2xl font-semibold text-slate-900">{{ __('home.sections.continue_watching') }}</h2>
                <a href="{{ $continueWatchingUrl }}" class="shrink-0 text-sm font-bold text-emerald-700 hover:text-emerald-800">{{ __('home.actions.view_all') }}</a>
            </div>
            <div class="grid grid-cols-2 gap-x-3 gap-y-6 sm:grid-cols-3 lg:grid-cols-6">
                @forelse ($continueWatchingItems as $continueItem)
                    <div @class(['min-w-0', 'hidden sm:block' => $loop->iteration > 4])>
                        <x-ui.poster-card
                            :src="$continueItem->title->poster_url"
                            :alt="__('catalog.seo.poster_alt', ['title' => $continueItem->title->display_title])"
                            layout="home"
                            data-home-continue-title="{{ $continueItem->title->id }}"
                        >
                        <h3 class="text-lg font-semibold leading-tight text-slate-900">
                            <a href="{{ route('titles.show', ['catalogTitle' => $continueItem->title, 'episode' => $continueItem->episode->id]) }}#player" class="break-words hover:text-emerald-800">{{ $continueItem->title->display_title }}</a>
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
                    <p class="col-span-full py-6 text-sm text-slate-600">{{ __('home.empty_states.continue_watching') }}</p>
                @endforelse
            </div>
        </section>

        <section data-home-section="library-updates" aria-labelledby="home-library-updates">
            <div class="mb-4 flex items-end justify-between gap-3 border-b border-slate-200 pb-3">
                <h2 id="home-library-updates" class="text-2xl font-semibold text-slate-900">{{ __('home.sections.library_updates') }}</h2>
                <a href="{{ $libraryUpdatesUrl }}" class="shrink-0 text-sm font-bold text-emerald-700 hover:text-emerald-800">{{ __('home.actions.view_all') }}</a>
            </div>
            <div class="grid grid-cols-2 gap-x-3 gap-y-6 sm:grid-cols-3 lg:grid-cols-6">
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
                    <p class="col-span-full py-6 text-sm text-slate-600">{{ __('home.empty_states.library_updates') }}</p>
                @endforelse
            </div>
        </section>

        <section data-home-section="personal-recommendations" aria-labelledby="home-personal-recommendations">
            <div class="mb-4 flex items-end justify-between gap-3 border-b border-slate-200 pb-3">
                <h2 id="home-personal-recommendations" class="text-2xl font-semibold text-slate-900">{{ __('home.sections.recommended_for_you') }}</h2>
                <a href="{{ $discoveryUrl }}" class="shrink-0 text-sm font-bold text-emerald-700 hover:text-emerald-800">{{ __('home.actions.view_all') }}</a>
            </div>
            <div class="grid grid-cols-2 gap-x-3 gap-y-6 sm:grid-cols-3 lg:grid-cols-4 xl:grid-cols-6">
                @forelse ($homeRecommendationItems as $recommendationItem)
                    <div @class(['min-w-0', 'hidden sm:block' => $loop->iteration > 4])>
                        <x-catalog.title-card :title="$recommendationItem->title" layout="home" :show-description="false" />
                    </div>
                @empty
                    <p class="col-span-full py-6 text-sm text-slate-600">{{ __('home.empty_states.recommendations') }}</p>
                @endforelse
            </div>
        </section>

        <section data-home-section="trending" aria-labelledby="home-trending">
            <div class="mb-4 flex items-end justify-between gap-3 border-b border-slate-200 pb-3">
                <h2 id="home-trending" class="text-2xl font-semibold text-slate-900">{{ __('home.sections.trending') }}</h2>
                <a href="{{ $trendingUrl }}" class="shrink-0 text-sm font-bold text-emerald-700 hover:text-emerald-800">{{ __('home.actions.view_all') }}</a>
            </div>
            <x-catalog.home-trending-grid :spotlight="$trendingSpotlight" :candidates="$trendingCandidates" />
        </section>

        <section data-home-section="latest-updates" aria-labelledby="home-latest-updates">
            <div class="mb-4 flex items-end justify-between gap-3 border-b border-slate-200 pb-3">
                <h2 id="home-latest-updates" class="text-2xl font-semibold text-slate-900">{{ __('home.sections.latest_updates') }}</h2>
                <a href="{{ $recentlyUpdatedUrl }}" class="shrink-0 text-sm font-bold text-emerald-700 hover:text-emerald-800">{{ __('home.actions.view_all') }}</a>
            </div>
            <div data-home-latest-updates-list class="grid gap-3 md:grid-cols-2">
                @forelse ($latestReleaseGroups as $releaseGroup)
                    <div @class(['border-b border-slate-200 pb-3', 'hidden sm:block' => $loop->iteration > 4])>
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
                    <p class="py-6 text-sm text-slate-600">{{ __('home.empty_states.episodes') }}</p>
                @endforelse
            </div>
        </section>

        <section data-home-section="account-tools" aria-labelledby="home-account-tools">
            <div class="mb-4 border-b border-slate-200 pb-3">
                <h2 id="home-account-tools" class="text-2xl font-semibold text-slate-900">{{ __('home.sections.account_tools') }}</h2>
            </div>
            <div class="grid gap-3 sm:grid-cols-2">
                <a href="{{ $myCollectionsUrl }}" class="flex min-h-20 items-center gap-4 rounded-panel border border-slate-200 bg-white p-4 text-slate-700 hover:border-emerald-700 hover:text-emerald-800">
                    <x-ui.icon name="fa-solid fa-folder-open text-xl" />
                    <span class="font-semibold">{{ __('home.navigation.my_collections') }}</span>
                </a>
                <a href="{{ $myCalendarUrl }}" class="flex min-h-20 items-center gap-4 rounded-panel border border-slate-200 bg-white p-4 text-slate-700 hover:border-emerald-700 hover:text-emerald-800">
                    <x-ui.icon name="fa-solid fa-calendar-days text-xl" />
                    <span class="font-semibold">{{ __('home.navigation.my_calendar') }}</span>
                </a>
            </div>
        </section>
    @endif

    <section data-home-section="catalog-facets" aria-labelledby="home-catalog-facets">
        <div class="mb-4 border-b border-slate-200 pb-3">
            <h2 id="home-catalog-facets" class="text-2xl font-semibold text-slate-900">{{ __('home.sections.catalog_facets') }}</h2>
        </div>
        <div class="grid gap-6 lg:grid-cols-3">
            <div>
                <h3 class="text-lg font-semibold text-slate-900">{{ __('home.sections.genres') }}</h3>
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
                <h3 class="text-lg font-semibold text-slate-900">{{ __('home.sections.countries') }}</h3>
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
                <h3 class="text-lg font-semibold text-slate-900">{{ __('home.sections.years') }}</h3>
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
