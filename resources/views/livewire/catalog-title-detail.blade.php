<div
    @if ($refreshShouldInitialize)
        wire:init="startRefresh"
    @endif
    @if ($refreshIsActive)
        wire:poll.3s.visible="refreshCatalog"
    @endif
    class="space-y-5"
    data-livewire-catalog-title-detail
    data-title-detail-workspace
>
    <section data-title-detail-layout class="grid min-w-0 gap-5 lg:grid-cols-[280px_minmax(0,1fr)] xl:grid-cols-[300px_minmax(0,1fr)]">
        <aside data-title-detail-sidebar class="order-2 space-y-4 lg:order-1">
            <section class="h-full overflow-hidden rounded-panel border border-slate-200 bg-white">
                <div class="border-b border-slate-200 bg-white px-4 py-3">
                    <div class="flex items-center gap-2">
                        <span class="inline-flex h-7 w-7 shrink-0 items-center justify-center rounded-control bg-emerald-50 text-emerald-700">
                            <x-ui.icon name="fa-solid fa-compass" />
                        </span>
                        <h2 class="text-2xl font-semibold text-slate-900">{{ __('catalog.title.quick_access') }}</h2>
                    </div>
                </div>
                <div class="space-y-4 p-4">
                    <nav aria-label="{{ __('catalog.title.quick_navigation') }}" data-title-quick-navigation class="-mx-2 grid gap-1">
                        <a data-title-quick-link href="#player" class="relative inline-flex min-h-11 items-center gap-3 rounded-lg px-3 py-2 text-sm font-semibold text-slate-600 transition before:absolute before:inset-y-2 before:left-0 before:w-1 before:rounded-full before:bg-transparent hover:bg-slate-50 hover:text-emerald-800 aria-[current=location]:bg-emerald-50 aria-[current=location]:font-semibold aria-[current=location]:text-emerald-700 aria-[current=location]:before:bg-emerald-800">
                            <x-ui.icon name="fa-solid fa-circle-play" />
                            <span>{{ __('catalog.title.watch') }}</span>
                        </a>

                        <a data-title-quick-link href="#seasons" class="relative inline-flex min-h-11 items-center gap-3 rounded-lg px-3 py-2 text-sm font-semibold text-slate-600 transition before:absolute before:inset-y-2 before:left-0 before:w-1 before:rounded-full before:bg-transparent hover:bg-slate-50 hover:text-emerald-800 aria-[current=location]:bg-emerald-50 aria-[current=location]:font-semibold aria-[current=location]:text-emerald-700 aria-[current=location]:before:bg-emerald-800">
                            <x-ui.icon name="fa-solid fa-layer-group text-slate-400" />
                            <span>{{ __('catalog.title.seasons') }}</span>
                        </a>

                        <a data-title-quick-link href="#data-title-reference" class="relative inline-flex min-h-11 items-center gap-3 rounded-lg px-3 py-2 text-sm font-semibold text-slate-600 transition before:absolute before:inset-y-2 before:left-0 before:w-1 before:rounded-full before:bg-transparent hover:bg-slate-50 hover:text-emerald-800 aria-[current=location]:bg-emerald-50 aria-[current=location]:font-semibold aria-[current=location]:text-emerald-700 aria-[current=location]:before:bg-emerald-800">
                            <x-ui.icon name="fa-solid fa-circle-info text-slate-400" />
                            <span>{{ __('catalog.title.about') }}</span>
                        </a>

                        <a data-title-quick-link href="#reviews" class="relative inline-flex min-h-11 items-center gap-3 rounded-lg px-3 py-2 text-sm font-semibold text-slate-600 transition before:absolute before:inset-y-2 before:left-0 before:w-1 before:rounded-full before:bg-transparent hover:bg-slate-50 hover:text-emerald-800 aria-[current=location]:bg-emerald-50 aria-[current=location]:font-semibold aria-[current=location]:text-emerald-700 aria-[current=location]:before:bg-emerald-800">
                            <x-ui.icon name="fa-solid fa-star-half-stroke text-slate-400" />
                            <span>{{ __('reviews.section.label') }}</span>
                        </a>
                    </nav>

                    <a href="{{ $contentRequestUrl }}" class="inline-flex min-h-11 w-full items-center justify-center gap-2 rounded-control bg-amber-50 px-3 py-2 text-sm font-bold text-amber-900 hover:bg-amber-100">
                        <x-ui.icon name="fa-solid fa-circle-exclamation" />
                        <span>{{ __('requests.actions.request_for_title') }}</span>
                    </a>

                    <a href="{{ $releaseCalendarUrl }}" class="inline-flex min-h-11 w-full items-center justify-center gap-2 rounded-control bg-emerald-50 px-3 py-2 text-sm font-bold text-emerald-800 hover:bg-emerald-100">
                        <x-ui.icon name="fa-regular fa-calendar-days" />
                        <span>{{ __('calendar.open_title_schedule') }}</span>
                    </a>

                    <div class="grid gap-2 sm:grid-cols-3 lg:grid-cols-1">
                        <div class="grid min-h-16 content-center gap-1 border-b border-slate-200 py-3 last:border-b-0">
                            <div class="flex items-center justify-between gap-2">
                                <div class="text-xs font-semibold leading-none text-slate-600">{{ __('catalog.title.seasons') }}</div>
                                <x-ui.icon name="fa-solid fa-layer-group text-slate-400" />
                            </div>
                            <div class="text-lg font-semibold leading-none tabular-nums text-slate-900">{{ $showView->parsedSeasonCount }}</div>
                        </div>

                        <div class="grid min-h-16 content-center gap-1 border-b border-slate-200 py-3 last:border-b-0">
                            <div class="flex items-center justify-between gap-2">
                                <div class="text-xs font-semibold leading-none text-slate-600">{{ __('catalog.title.episodes') }}</div>
                                <x-ui.icon name="fa-solid fa-list-ol text-slate-400" />
                            </div>
                            <div class="text-lg font-semibold leading-none tabular-nums text-slate-900">{{ $showView->episodeCount }}</div>
                        </div>

                        <div class="grid min-h-16 content-center gap-1 border-b border-slate-200 py-3 last:border-b-0">
                            <div class="flex items-center justify-between gap-2">
                                <div class="text-xs font-semibold leading-none text-slate-600">{{ __('catalog.title.video') }}</div>
                                <x-ui.icon name="fa-solid fa-file-video text-slate-400" />
                            </div>
                            <div class="text-lg font-semibold leading-none tabular-nums text-slate-900">{{ $showView->mediaCount }}</div>
                        </div>
                    </div>

                </div>
            </section>
        </aside>

        <div data-title-detail-primary class="order-1 min-w-0 space-y-5 lg:order-2">
            <x-ui.panel data-title-hero :pad="false" class="overflow-hidden">
                <div class="flex flex-wrap items-center justify-between gap-2 border-b border-slate-200 bg-white px-4 py-3">
                    <a href="{{ route('titles.index') }}" class="inline-flex min-h-11 items-center gap-2 rounded-control bg-slate-100 px-3 py-2 text-sm font-semibold text-slate-700 hover:bg-emerald-50 hover:text-emerald-800">
                        <x-ui.icon name="fa-solid fa-arrow-left" />
                        <span>{{ __('catalog.title.back_to_catalog') }}</span>
                    </a>
                    <div class="flex flex-wrap items-center justify-end gap-2">
                        <button
                            type="button"
                            data-public-share
                            data-share-url="{{ $shareData['url'] }}"
                            data-share-title="{{ $shareData['title'] }}"
                            data-share-success="{{ __('mobile.share.success') }}"
                            data-share-error="{{ __('mobile.share.error') }}"
                            aria-describedby="catalog-title-share-status"
                            class="inline-flex min-h-11 items-center gap-2 rounded-control border border-slate-200 bg-white px-3 py-2 text-sm font-semibold text-slate-700 hover:border-emerald-200 hover:bg-emerald-50 hover:text-emerald-800 disabled:opacity-60"
                        >
                            <x-ui.icon name="fa-solid fa-share-nodes" />
                            <span>{{ __('mobile.share.action') }}</span>
                        </button>
                        @if ($refreshStatus !== null)
                            <span @class([
                                'inline-flex min-h-9 items-center gap-2 rounded-full px-3 py-2 text-xs font-semibold',
                                'bg-sky-50 text-sky-700' => $refreshStatus['tone'] === 'active',
                                'bg-emerald-50 text-emerald-700' => $refreshStatus['tone'] === 'completed',
                                'bg-red-50 text-red-700' => $refreshStatus['tone'] === 'failed',
                            ]) data-title-refresh-status>
                                <x-ui.icon :name="$refreshStatus['icon']" />
                                <span>{{ $refreshStatus['label'] }}</span>
                            </span>
                        @endif
                    </div>
                    <p id="catalog-title-share-status" class="sr-only" role="status" aria-live="polite"></p>
                </div>

                <article class="grid gap-5 bg-white p-4 md:grid-cols-[minmax(150px,220px)_minmax(0,1fr)] md:p-5">
                    <div class="grid content-start justify-items-center gap-2 md:justify-items-stretch">
                        <x-ui.poster-frame
                            :src="$title->poster_url"
                            :alt="__('catalog.seo.poster_alt', ['title' => $title->display_title])"
                            loading="eager"
                            class="mx-auto aspect-[2/3] w-44 max-w-full rounded-panel sm:w-52 md:w-full"
                        />
                        <x-content-requests.correction-link
                            :url="$correctionUrls['poster']"
                            field="poster"
                            class="w-full justify-center"
                        />
                    </div>

                    <div class="min-w-0">
                        <div class="flex min-w-0 flex-wrap items-start justify-between gap-3">
                            <h1 class="flex min-w-0 flex-1 items-start gap-3 text-3xl font-semibold tracking-tight text-slate-900 sm:text-4xl">
                                <x-ui.icon name="fa-solid fa-clapperboard text-emerald-700" align="start" />
                                <span class="min-w-0 break-words">{{ $showView->displayTitle }}</span>
                            </h1>
                            <x-content-requests.correction-link :url="$correctionUrls['title']" field="title" />
                        </div>
                        @if ($showView->displayOriginalTitle !== '')
                            <div class="mt-2 break-words text-sm font-semibold text-slate-600">{{ $showView->displayOriginalTitle }}</div>
                        @endif

                        <div class="mt-4 flex flex-wrap gap-2 text-xs font-bold">
                            @if ($title->year)
                                <x-ui.taxonomy-chip :href="route('titles.year', ['year' => $title->year])" active icon="fa-solid fa-calendar-days">{{ $title->year }}</x-ui.taxonomy-chip>
                            @endif
                            <x-content-requests.correction-link :url="$correctionUrls['year']" field="year" />
                            @foreach ($ageRatings as $ageRating)
                                <x-ui.taxonomy-chip :taxonomy="$ageRating" active />
                            @endforeach
                            <x-ui.taxonomy-chip icon="fa-solid fa-layer-group">{{ trans_choice('catalog.counts.seasons', $seasons->count()) }}</x-ui.taxonomy-chip>
                            <x-ui.taxonomy-chip icon="fa-solid fa-list-ol">{{ trans_choice('catalog.counts.episodes', $episodeCount) }}</x-ui.taxonomy-chip>
                            <x-ui.taxonomy-chip icon="fa-solid fa-file-video">{{ trans_choice('catalog.counts.videos', $mediaCount) }}</x-ui.taxonomy-chip>
                        </div>

                        <section class="mt-5 border-t border-slate-200 pt-4">
                            <div class="flex flex-wrap items-center justify-between gap-2">
                                <h2 class="flex items-center gap-2 text-2xl font-semibold text-slate-900">
                                    <x-ui.icon name="fa-solid fa-book-open text-slate-400" />
                                    <span>{{ __('catalog.title.description') }}</span>
                                </h2>
                                <x-content-requests.correction-link :url="$correctionUrls['description']" field="description" />
                            </div>
                            <p class="mt-2 text-base leading-7 text-slate-700">{{ $showView->displayDescription !== '' ? $showView->displayDescription : __('catalog.title.description_missing') }}</p>
                        </section>

                        <div class="mt-4">
                            <livewire:collections.catalog-collection-membership-manager
                                :catalog-title-id="$title->id"
                                :wire:key="'catalog-title-collection-membership-'.$title->id"
                            />
                        </div>
                        <div class="mt-3">
                            <livewire:tags.personal-tag-selector
                                :catalog-title-id="$title->id"
                                :wire:key="'catalog-title-personal-tags-'.$title->id"
                            />
                        </div>

                    </div>
                </article>
            </x-ui.panel>

            <div data-player-workspace-region>
                <livewire:catalog-title-player
                    :catalog-title-id="$title->id"
                    wire:ref="player"
                    :wire:key="'catalog-title-player-'.$title->id"
                />
            </div>

            <x-ui.panel id="data-title-reference" data-title-reference :title="__('catalog.title.about')" icon="fa-solid fa-circle-info" class="scroll-mt-40 sm:scroll-mt-44 lg:scroll-mt-48">
                <div>
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <div class="inline-flex items-center gap-2 text-sm font-bold text-slate-700">
                            <x-ui.icon name="fa-solid fa-user-group text-slate-400" />
                            <span>{{ __('catalog.title.cast') }}</span>
                        </div>
                        @if ($actors->isEmpty())
                            <x-content-requests.correction-link :url="$correctionUrls['actor']" field="actor" />
                        @endif
                    </div>
                    @if ($actors->isNotEmpty())
                        <div class="mt-2 flex flex-wrap gap-2">
                            @foreach ($actors->take(12) as $actor)
                                <span class="inline-flex flex-wrap items-center gap-1">
                                    <x-ui.taxonomy-chip :taxonomy="$actor" />
                                    <x-content-requests.correction-link
                                        :url="$taxonomyCorrectionUrls['actor'][$actor->id]"
                                        field="actor"
                                        :label="__('requests.actions.correct_short')"
                                    />
                                </span>
                            @endforeach
                        </div>
                    @endif
                </div>

                <dl class="mt-4 divide-y divide-slate-200 text-sm">
                    @foreach ($taxonomyRows as $row)
                        @if ($row['items']->isNotEmpty() || in_array($row['type'], ['genre', 'country', 'translation', 'tag'], true))
                            <div class="grid gap-2 py-3 sm:grid-cols-[120px_minmax(0,1fr)]">
                                <dt class="inline-flex items-center gap-2 font-semibold text-slate-600">
                                    <x-ui.icon name="{{ $row['icon'] ?? 'fa-solid fa-tag' }} text-slate-400" />
                                    <span>{{ $row['label'] }}</span>
                                </dt>
                                <dd class="flex flex-wrap gap-1.5">
                                    @forelse ($row['items'] as $taxonomy)
                                        <span class="inline-flex flex-wrap items-center gap-1">
                                            <x-ui.taxonomy-chip :taxonomy="$taxonomy" />
                                            @if (isset($taxonomyCorrectionUrls[$row['type']][$taxonomy->id]))
                                                <x-content-requests.correction-link
                                                    :url="$taxonomyCorrectionUrls[$row['type']][$taxonomy->id]"
                                                    :field="$row['type']"
                                                    :label="__('requests.actions.correct_short')"
                                                />
                                            @endif
                                        </span>
                                    @empty
                                        <span class="inline-flex min-h-11 items-center text-slate-500">{{ __('requests.corrections.value_missing') }}</span>
                                        <x-content-requests.correction-link
                                            :url="$correctionUrls[$row['type']]"
                                            :field="$row['type']"
                                        />
                                    @endforelse
                                </dd>
                            </div>
                        @endif
                    @endforeach
                    @if ($aliases->isNotEmpty())
                        <div class="grid gap-2 py-3 sm:grid-cols-[120px_minmax(0,1fr)]">
                            <dt class="inline-flex items-center gap-2 font-semibold text-slate-600">
                                <x-ui.icon name="fa-solid fa-signature text-slate-400" />
                                <span>{{ __('catalog.title.other_names') }}</span>
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
                            <dt class="inline-flex items-center gap-2 font-semibold text-slate-600">
                                <x-ui.icon name="fa-solid fa-star text-slate-400" />
                                <span>{{ __('catalog.title.ratings') }}</span>
                            </dt>
                            <dd class="flex flex-wrap gap-1.5">
                                @foreach ($ratings as $rating)
                                    <x-ui.status-pill variant="success">
                                        {{ mb_strtoupper($rating->provider) }}: {{ $rating->rating }}@if ($rating->votes) · {{ trans_choice('catalog.counts.votes', $rating->votes) }} @endif
                                    </x-ui.status-pill>
                                @endforeach
                            </dd>
                        </div>
                    @endif
                    @if ($title->year)
                        <div class="grid gap-2 py-3 sm:grid-cols-[120px_minmax(0,1fr)]">
                            <dt class="inline-flex items-center gap-2 font-semibold text-slate-600">
                                <x-ui.icon name="fa-solid fa-calendar-days" class="text-slate-400" />
                                <span>{{ __('catalog.title.released') }}</span>
                            </dt>
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

            @if ($recommendationNotice)
                <div class="flex flex-wrap items-center justify-between gap-3 rounded-control bg-emerald-50 px-4 py-3 text-sm font-semibold text-emerald-800" role="status" aria-live="polite">
                    <span>{{ $recommendationNotice }}</span>
                    @if ($lastRecommendationFeedbackTitleId)
                        <button type="button" wire:click="undoRecommendationFeedback" class="min-h-11 rounded-control px-3 py-2 font-bold underline hover:bg-emerald-100">{{ __('recommendations.feedback.undo') }}</button>
                    @endif
                </div>
            @endif

            @if ($errors->has('recommendationFeedback'))
                <div role="alert" aria-live="assertive" class="rounded-control border border-red-200 bg-red-50 px-4 py-3 text-sm font-semibold text-red-800">{{ $errors->first('recommendationFeedback') }}</div>
            @endif

            @if ($relatedRecommendationItems->isNotEmpty())
                <x-ui.panel :title="__('recommendations.types.related.title')" icon="fa-solid fa-code-branch" :pad="false">
                    <ol class="divide-y divide-slate-200" aria-label="{{ __('recommendations.types.related.accessibility') }}" data-related-list>
                        @foreach ($relatedRecommendationItems as $recommendationItem)
                            <li wire:key="title-related-{{ $title->id }}-{{ $recommendationItem->title->id }}" data-recommendation-row>
                                <x-catalog.title-card :title="$recommendationItem->title" layout="recommendation" :rank="$recommendationItem->rank" :reason-labels="$recommendationItem->reasonLabels" />
                                @if ($recommendationItem->canDismiss)
                                    <x-catalog.recommendation-feedback :title-id="$recommendationItem->title->id" action="setRecommendationFeedback" :feedback-options="$recommendationItem->feedbackOptions" />
                                @endif
                            </li>
                        @endforeach
                    </ol>
                </x-ui.panel>
            @endif

            <x-ui.panel :title="__('recommendations.types.similar.title')" icon="fa-solid fa-thumbs-up" :pad="false">
                @if ($recommendationItems->isNotEmpty())
                    <ol class="divide-y divide-slate-200" aria-label="{{ __('recommendations.types.similar.accessibility') }}" data-recommendation-list>
                        @foreach ($recommendationItems as $recommendationItem)
                            <li
                                wire:key="title-recommendation-{{ $title->id }}-{{ $recommendationItem->title->id }}"
                                data-recommendation-row
                            >
                                <x-catalog.title-card
                                    :title="$recommendationItem->title"
                                    layout="recommendation"
                                    :rank="$recommendationItem->rank"
                                    :reason-labels="$recommendationItem->reasonLabels"
                                />
                                @if ($recommendationItem->canDismiss)
                                    <x-catalog.recommendation-feedback :title-id="$recommendationItem->title->id" action="setRecommendationFeedback" :feedback-options="$recommendationItem->feedbackOptions" />
                                @endif
                            </li>
                        @endforeach
                    </ol>
                @else
                    <div class="border-t border-slate-200 p-4 text-sm text-slate-600">
                        <div class="inline-flex items-center gap-2">
                            <x-ui.icon name="fa-solid fa-circle-info text-slate-400" />
                            <span>{{ __('recommendations.page.empty') }}</span>
                        </div>
                    </div>
                @endif
            </x-ui.panel>

            @if ($publicCollections->isNotEmpty())
                <section aria-labelledby="title-public-collections">
                    <h2 id="title-public-collections" class="mb-3 flex items-center gap-2 text-2xl font-semibold text-slate-900">
                        <x-ui.icon name="fa-solid fa-layer-group text-emerald-700" />
                        <span>{{ __('collections.page.contains_title') }}</span>
                    </h2>
                    <div class="grid min-w-0 gap-4 sm:grid-cols-2 xl:grid-cols-3">
                        @foreach ($publicCollections as $publicCollection)
                            <x-collections.collection-card wire:key="title-public-collection-{{ $publicCollection->public_id }}" :collection="$publicCollection" />
                        @endforeach
                    </div>
                </section>
            @endif

            <livewire:reviews.catalog-title-reviews
                :catalog-title-id="$title->id"
                :locale="$reviewLocale"
                :highlighted-review-id="$highlightedReviewId"
                :wire:key="'title-reviews-'.$title->id"
                :lazy="$highlightedReviewId === null"
            />

            <livewire:comments.comment-discussion
                target-type="title"
                :target-id="$title->id"
                :wire:key="'title-discussion-'.$title->id"
                :lazy="$highlightedCommentId === null"
            />

            @if (! empty($seo['faq']))
                <x-ui.panel :title="__('catalog.title.questions')" icon="fa-solid fa-circle-question" :pad="false">
                    <div class="divide-y divide-slate-200">
                        @foreach ($seo['faq'] as $faqItem)
                            <details class="group px-4 py-3">
                                <summary class="flex min-h-11 cursor-pointer list-none items-center justify-between gap-3 font-bold text-slate-700">
                                    <span>{{ $faqItem['question'] }}</span>
                                    <x-ui.icon name="fa-solid fa-chevron-down text-slate-400 transition group-open:rotate-180" />
                                </summary>
                                <p class="mt-2 text-sm leading-6 text-slate-600">{{ $faqItem['answer'] }}</p>
                            </details>
                        @endforeach
                    </div>
                </x-ui.panel>
            @endif

        </div>
    </section>
</div>
