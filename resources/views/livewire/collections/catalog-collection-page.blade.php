<div class="space-y-5">
    <article class="overflow-hidden rounded-panel border border-slate-200 bg-white shadow-panel">
        <div class="grid min-w-0 gap-5 p-5 sm:p-6 lg:grid-cols-[20rem_minmax(0,1fr)] lg:items-start">
            <x-ui.poster-frame
                :src="$coverUrl"
                :alt="__('collections.accessibility.collection_cover', ['name' => $collection->display_name])"
                :empty-label="__('collections.page.cover_missing')"
                loading="eager"
                class="aspect-[16/9] w-full rounded-panel bg-slate-100"
            />
            <div class="min-w-0">
                <div class="flex flex-wrap gap-2">
                    @if ($collection->is_featured)
                        <x-ui.status-pill variant="warning">{{ __('collections.page.featured') }}</x-ui.status-pill>
                    @endif
                    @if ($isEditorial)
                        <x-ui.status-pill variant="success">{{ $collectionTypeLabel }}</x-ui.status-pill>
                    @endif
                    @if ($canManage)
                        <x-ui.status-pill variant="muted">{{ $collectionVisibilityLabel }}</x-ui.status-pill>
                        <x-ui.status-pill variant="muted">{{ $collectionModerationLabel }}</x-ui.status-pill>
                    @endif
                </div>
                <h1 class="mt-3 break-words text-2xl font-black tracking-tight text-slate-800 sm:text-4xl">{{ $collection->display_name }}</h1>
                @if ($collection->display_description)
                    <p class="mt-4 whitespace-pre-line break-words text-sm leading-7 text-slate-600">{{ $collection->display_description }}</p>
                @endif
                <dl class="mt-5 flex flex-wrap gap-x-6 gap-y-3 text-sm">
                    @if ($ownerUrl !== null && $collection->owner)
                        <div>
                            <dt class="font-bold text-slate-500">{{ __('collections.page.owner') }}</dt>
                            <dd class="mt-1"><a href="{{ $ownerUrl }}" class="break-words font-black text-emerald-700 hover:text-emerald-600">{{ $collection->owner->name }}</a></dd>
                        </div>
                    @endif
                    <div>
                        <dt class="font-bold text-slate-500">{{ __('collections.form.sort_mode') }}</dt>
                        <dd class="mt-1 font-black text-slate-700">{{ $collectionSortLabel }}</dd>
                    </div>
                    <div>
                        <dt class="font-bold text-slate-500">{{ __('collections.page.updated_label') }}</dt>
                        <dd class="mt-1 font-black text-slate-700"><time datetime="{{ $collectionUpdatedAtIso }}">{{ $collectionUpdatedAtLabel }}</time></dd>
                    </div>
                    <div>
                        <dt class="font-bold text-slate-500">{{ __('collections.page.item_count_label') }}</dt>
                        <dd class="mt-1 font-black text-slate-700">{{ $headerItemCountLabel }}</dd>
                    </div>
                </dl>

                <div class="mt-5 flex flex-wrap gap-2">
                    @if ($canManage)
                        <a href="{{ route('collections.edit', ['collectionPublicId' => $collection->public_id]) }}" class="inline-flex min-h-11 flex-1 items-center justify-center gap-2 rounded-control bg-emerald-700 px-4 py-2.5 text-sm font-bold text-white hover:bg-emerald-600 sm:flex-none">
                            <x-ui.icon name="fa-solid fa-pen-to-square" />{{ __('collections.actions.manage') }}
                        </a>
                    @endif
                    @if ($canShare)
                        <button type="button" data-collection-share data-share-url="{{ $canonicalUrl }}" data-share-title="{{ $collection->display_name }}" data-copy-label="{{ __('collections.actions.copy_link') }}" data-share-success="{{ __('collections.sharing.copied') }}" data-share-error="{{ __('collections.sharing.failed') }}" class="inline-flex min-h-11 flex-1 items-center justify-center gap-2 rounded-control bg-slate-100 px-4 py-2.5 text-sm font-bold text-slate-700 hover:bg-slate-200 sm:flex-none">
                            <x-ui.icon name="fa-solid fa-share-nodes" />{{ __('collections.actions.share') }}
                        </button>
                    @endif
                    @if ($canReport)
                        <button type="button" data-collection-dialog-trigger wire:click="openReport" class="inline-flex min-h-11 flex-1 items-center justify-center gap-2 rounded-control bg-slate-100 px-4 py-2.5 text-sm font-bold text-slate-700 hover:bg-slate-200 sm:flex-none">
                            <x-ui.icon name="fa-solid fa-flag" />{{ __('collections.actions.report') }}
                        </button>
                    @endif
                </div>
                <nav aria-label="{{ __('collections.locale.switch') }}" class="mt-4 flex w-fit rounded-control bg-slate-100 p-1">
                    @foreach ($localeUrls as $locale => $localeUrl)
                        <a href="{{ $localeUrl }}" hreflang="{{ $locale }}" data-preserve-discussion-state @class(['inline-flex min-h-9 items-center rounded-lg px-3 text-xs font-black', 'bg-white text-emerald-700 shadow-sm' => $interfaceLocale === $locale, 'text-slate-600 hover:text-emerald-700' => $interfaceLocale !== $locale])>{{ __('collections.locale.'.$locale) }}</a>
                    @endforeach
                </nav>
                <div data-collection-share-status class="mt-3 text-sm font-bold text-emerald-700" role="status" aria-live="polite"></div>
            </div>
        </div>
        <div class="border-t border-slate-200 bg-slate-50 px-5 py-3 text-sm font-semibold text-slate-600 sm:px-6">
            <span class="inline-flex items-center gap-2"><x-ui.icon :name="$visibilityIcon" />{{ $visibilityNotice }}</span>
        </div>
    </article>

    @if ($notice)
        <x-form.status-message :message="$notice" />
    @endif
    <x-form.input-error for="collection" />

    <div wire:loading.delay wire:target="removeItem,submitReport" role="status" aria-live="polite">
        <div class="flex items-center gap-2 rounded-control bg-sky-50 px-4 py-3 text-sm font-bold text-sky-700">
            <x-ui.icon name="fa-solid fa-spinner fa-spin" />{{ __('collections.page.loading') }}
        </div>
    </div>

    <x-ui.panel :title="__('collections.form.filters_title')" icon="fa-solid fa-sliders">
        <form wire:submit="applyFilters" class="grid gap-4" aria-label="{{ __('collections.accessibility.filters') }}">
            <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                <x-form.field :label="__('collections.form.search_inside')" for="collection-search-inside" :placeholder="__('collections.form.search_inside_placeholder')" wire:model="search" />
                @foreach (['genre' => $filterOptions['genres'], 'country' => $filterOptions['countries'], 'statusFilter' => $filterOptions['statuses']] as $property => $options)
                    <div>
                        <label for="collection-filter-{{ $property }}" class="block text-sm font-bold text-slate-700">{{ __('collections.form.'.($property === 'statusFilter' ? 'status' : $property)) }}</label>
                        <select id="collection-filter-{{ $property }}" wire:model.live="{{ $property }}" class="mt-2 min-h-11 w-full rounded-control border border-slate-300 bg-white px-3 py-2.5 text-sm font-semibold text-slate-700 focus:border-emerald-600 focus:ring-2 focus:ring-emerald-100">
                            <option value="">{{ __('collections.form.all') }}</option>
                            @foreach ($options as $option)
                                <option value="{{ $option->slug }}">{{ $option->name }}</option>
                            @endforeach
                        </select>
                    </div>
                @endforeach
                <div>
                    <label for="collection-filter-year" class="block text-sm font-bold text-slate-700">{{ __('collections.form.year') }}</label>
                    <select id="collection-filter-year" wire:model.live="year" class="mt-2 min-h-11 w-full rounded-control border border-slate-300 bg-white px-3 py-2.5 text-sm font-semibold text-slate-700 focus:border-emerald-600 focus:ring-2 focus:ring-emerald-100">
                        <option value="">{{ __('collections.form.all') }}</option>
                        @foreach ($filterOptions['years'] as $optionYear)
                            <option value="{{ $optionYear }}">{{ $optionYear }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label for="collection-filter-sort" class="block text-sm font-bold text-slate-700">{{ __('collections.form.sort_mode') }}</label>
                    <select id="collection-filter-sort" wire:model.live="sort" class="mt-2 min-h-11 w-full rounded-control border border-slate-300 bg-white px-3 py-2.5 text-sm font-semibold text-slate-700 focus:border-emerald-600 focus:ring-2 focus:ring-emerald-100">
                        @foreach ($sortOptions as $option)
                            <option value="{{ $option['value'] }}">{{ $option['label'] }}</option>
                        @endforeach
                    </select>
                </div>
            </div>
            <div class="flex flex-wrap gap-2">
                <button type="submit" wire:loading.attr="disabled" class="inline-flex min-h-11 flex-1 items-center justify-center gap-2 rounded-control bg-slate-800 px-4 py-2.5 text-sm font-bold text-white hover:bg-slate-700 sm:flex-none"><x-ui.icon name="fa-solid fa-filter" />{{ __('collections.form.filter') }}</button>
                @if ($hasFilters)
                    <button type="button" wire:click="resetFilters" class="inline-flex min-h-11 flex-1 items-center justify-center gap-2 rounded-control bg-slate-100 px-4 py-2.5 text-sm font-bold text-slate-700 hover:bg-slate-200 sm:flex-none"><x-ui.icon name="fa-solid fa-xmark" />{{ __('collections.form.reset_filters') }}</button>
                @endif
            </div>
        </form>
    </x-ui.panel>

    <div wire:loading.delay wire:target="search,genre,country,statusFilter,year,sort,applyFilters,resetFilters" role="status" aria-live="polite">
        <div class="flex items-center gap-2 rounded-control bg-sky-50 px-4 py-3 text-sm font-bold text-sky-700">
            <x-ui.icon name="fa-solid fa-spinner fa-spin" />{{ __('collections.page.loading') }}
        </div>
    </div>

    <x-ui.panel :title="$itemResultTitle" icon="fa-solid fa-clapperboard" :pad="false">
        @if ($items->isEmpty())
            <div class="p-8 text-center">
                <p class="text-sm font-semibold text-slate-600">{{ $hasFilters ? __('collections.page.no_results') : __('collections.page.empty') }}</p>
                @if ($hasFilters)
                    <button type="button" wire:click="resetFilters" class="mt-4 inline-flex min-h-11 items-center justify-center gap-2 rounded-control bg-slate-100 px-4 py-2.5 text-sm font-bold text-slate-700 hover:bg-slate-200"><x-ui.icon name="fa-solid fa-xmark" />{{ __('collections.form.reset_filters') }}</button>
                @else
                    <a href="{{ route('titles.index') }}" class="mt-4 inline-flex min-h-11 items-center justify-center gap-2 rounded-control bg-emerald-700 px-4 py-2.5 text-sm font-bold text-white hover:bg-emerald-600"><x-ui.icon name="fa-solid fa-compass" />{{ __('collections.actions.open_catalog') }}</a>
                @endif
            </div>
        @else
            <ol class="divide-y divide-slate-200">
                @foreach ($items as $item)
                    <li wire:key="collection-item-{{ $item->collection_item_id }}" class="relative min-w-0">
                        <x-catalog.title-card :title="$item" layout="list" :show-description="false" readable />
                        @if ($canManage)
                            <div class="relative z-20 flex justify-end border-t border-slate-100 px-3 pb-3 pt-2 sm:px-4">
                                <button type="button" wire:click="removeItem({{ $item->id }})" wire:confirm="{{ __('collections.confirmations.remove_item') }}" wire:loading.attr="disabled" class="inline-flex min-h-11 w-full items-center justify-center gap-2 rounded-control bg-rose-50 px-3 text-sm font-bold text-rose-700 hover:bg-rose-100 sm:w-auto"><x-ui.icon name="fa-solid fa-xmark" />{{ __('collections.actions.remove') }}</button>
                            </div>
                        @endif
                    </li>
                @endforeach
            </ol>
            <nav class="p-4" aria-label="{{ __('collections.page.pagination') }}">{{ $items->links() }}</nav>
        @endif
    </x-ui.panel>

    @if ($unavailableItems->isNotEmpty())
        <x-ui.panel :title="__('collections.page.unavailable')" :subtitle="__('collections.page.unavailable_hint')" icon="fa-solid fa-eye-slash">
            <ul class="space-y-2">
                @foreach ($unavailableItems as $unavailable)
                    <li wire:key="collection-page-unavailable-{{ $unavailable->id }}" class="flex flex-wrap items-center justify-between gap-3 rounded-control bg-slate-50 p-3">
                        <span class="break-words text-sm font-bold text-slate-600">{{ $unavailable->catalogTitleWithTrashed?->title ?: __('collections.page.unavailable_item') }}</span>
                        <button type="button" wire:click="removeItem({{ $unavailable->catalog_title_id }})" wire:confirm="{{ __('collections.confirmations.remove_item') }}" class="inline-flex min-h-11 items-center gap-2 rounded-control bg-rose-50 px-3 text-sm font-bold text-rose-700 hover:bg-rose-100"><x-ui.icon name="fa-solid fa-xmark" />{{ __('collections.actions.remove') }}</button>
                    </li>
                @endforeach
            </ul>
        </x-ui.panel>
    @endif

    @if ($relatedCollections->isNotEmpty())
        <section aria-labelledby="related-collections-title">
            <h2 id="related-collections-title" class="mb-3 text-lg font-black text-slate-800">{{ __('collections.page.related') }}</h2>
            <div class="grid min-w-0 gap-4 sm:grid-cols-2 xl:grid-cols-3">
                @foreach ($relatedCollections as $related)
                    <x-collections.collection-card wire:key="related-collection-{{ $related->public_id }}" :collection="$related" />
                @endforeach
            </div>
        </section>
    @endif

    <livewire:comments.comment-discussion
        target-type="collection"
        :target-id="$collection->id"
        :interface-locale="$interfaceLocale"
        :wire:key="'collection-discussion-'.$collection->id"
    />

    @if ($showReport && $canReport)
        <dialog data-collection-dialog data-collection-dialog-open class="w-[min(42rem,calc(100%-2rem))] rounded-panel border-0 bg-white p-0 shadow-2xl backdrop:bg-slate-950/60" aria-labelledby="collection-report-title">
            <form wire:submit="submitReport" class="p-5 sm:p-6">
                <div class="flex items-start justify-between gap-4">
                    <div>
                        <h2 id="collection-report-title" class="text-xl font-black text-slate-800">{{ __('collections.reports.title') }}</h2>
                        <p class="mt-2 text-sm text-slate-600">{{ __('collections.reports.details_hint') }}</p>
                    </div>
                    <button type="button" data-collection-dialog-close wire:click="closeReport" class="grid h-11 w-11 shrink-0 place-items-center rounded-control bg-slate-100 text-slate-700 hover:bg-slate-200" aria-label="{{ __('collections.accessibility.close_dialog') }}"><x-ui.icon name="fa-solid fa-xmark" /></button>
                </div>
                <div class="mt-5 space-y-4">
                    <div>
                        <label for="collection-report-reason" class="block text-sm font-bold text-slate-700">{{ __('collections.reports.reason') }}</label>
                        <select id="collection-report-reason" wire:model="reportReason" class="mt-2 min-h-11 w-full rounded-control border border-slate-300 bg-white px-3 py-2.5 text-sm font-semibold text-slate-700 focus:border-emerald-600 focus:ring-2 focus:ring-emerald-100">
                        @foreach ($reportReasons as $reason)
                            <option value="{{ $reason['value'] }}">{{ $reason['label'] }}</option>
                        @endforeach
                        </select>
                    </div>
                    <div>
                        <label for="collection-report-details" class="block text-sm font-bold text-slate-700">{{ __('collections.reports.details') }}</label>
                        <textarea id="collection-report-details" wire:model="reportDetails" rows="5" maxlength="2000" class="mt-2 w-full rounded-control border border-slate-300 px-3 py-2.5 text-base text-slate-800 focus:border-emerald-600 focus:ring-2 focus:ring-emerald-100"></textarea>
                        <x-form.input-error for="reportDetails" />
                    </div>
                    <button type="submit" wire:loading.attr="disabled" wire:target="submitReport" class="inline-flex min-h-11 w-full items-center justify-center gap-2 rounded-control bg-rose-700 px-4 py-2.5 text-sm font-bold text-white hover:bg-rose-600 disabled:cursor-wait disabled:opacity-60"><x-ui.icon name="fa-solid fa-flag" />{{ __('collections.reports.submit') }}</button>
                </div>
            </form>
        </dialog>
    @endif
</div>
