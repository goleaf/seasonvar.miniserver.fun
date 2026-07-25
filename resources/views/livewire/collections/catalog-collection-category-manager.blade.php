<section class="space-y-5" aria-labelledby="collection-category-admin-title">
    @if ($notice)
        <div role="status" aria-live="polite"><x-form.status-message :message="$notice" /></div>
    @endif

    @if ($canManage && $classificationSummary !== null && $classificationPage !== null)
        <section
            data-collection-classification
            class="overflow-hidden rounded-panel border border-slate-200 bg-white shadow-panel"
            aria-labelledby="collection-classification-title"
        >
            <header class="border-b border-slate-200 bg-slate-50 px-4 py-4 sm:px-5">
                <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                    <div class="min-w-0">
                        <div class="flex items-start gap-3">
                            <span class="mt-0.5 inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-control bg-emerald-100 text-emerald-800">
                                <x-ui.icon name="fa-solid fa-list-check" />
                            </span>
                            <div class="min-w-0">
                                <h2 id="collection-classification-title" class="text-lg font-black text-slate-900">
                                    {{ __('collections.classification.title') }}
                                </h2>
                                <p class="mt-1 max-w-3xl text-sm leading-6 text-slate-600">
                                    {{ __('collections.classification.description') }}
                                </p>
                            </div>
                        </div>
                    </div>
                    <div class="shrink-0 rounded-control border border-emerald-200 bg-white px-4 py-3 text-left lg:text-right">
                        <p class="text-xs font-bold text-slate-500">{{ __('collections.classification.completion') }}</p>
                        <p class="mt-1 text-2xl font-black tabular-nums text-emerald-800">
                            {{ $classificationSummary->completionPercentage }}%
                        </p>
                    </div>
                </div>
            </header>

            <div class="space-y-5 p-4 sm:p-5">
                <dl data-classification-summary class="grid grid-cols-2 gap-px overflow-hidden rounded-control border border-slate-200 bg-slate-200 lg:grid-cols-4">
                    <div class="min-w-0 bg-white p-3 sm:p-4">
                        <dt class="text-xs font-bold leading-5 text-slate-500">{{ __('collections.classification.total') }}</dt>
                        <dd class="mt-1 text-xl font-black tabular-nums text-slate-900">{{ $classificationSummary->total }}</dd>
                    </div>
                    <div class="min-w-0 bg-white p-3 sm:p-4">
                        <dt class="text-xs font-bold leading-5 text-slate-500">{{ __('collections.classification.categorized') }}</dt>
                        <dd class="mt-1 text-xl font-black tabular-nums text-emerald-800">{{ $classificationSummary->categorized }}</dd>
                    </div>
                    <div class="min-w-0 bg-white p-3 sm:p-4">
                        <dt class="text-xs font-bold leading-5 text-slate-500">{{ __('collections.classification.uncategorized') }}</dt>
                        <dd class="mt-1 text-xl font-black tabular-nums text-amber-800">{{ $classificationSummary->uncategorized }}</dd>
                    </div>
                    <div class="min-w-0 bg-white p-3 sm:p-4">
                        <dt class="text-xs font-bold leading-5 text-slate-500">{{ __('collections.classification.public_uncategorized') }}</dt>
                        <dd class="mt-1 text-xl font-black tabular-nums text-slate-900">{{ $classificationSummary->publicUncategorized }}</dd>
                    </div>
                </dl>

                <div class="grid gap-4 rounded-control border border-slate-200 bg-slate-50 p-4 md:grid-cols-2 xl:grid-cols-4">
                    <x-form.field
                        :label="__('collections.classification.search')"
                        for="classification-search"
                        :placeholder="__('collections.classification.search_placeholder')"
                        wire:model.live.debounce.400ms="classificationSearch"
                    />
                    <div>
                        <label for="classification-visibility" class="block text-sm font-bold text-slate-700">
                            {{ __('collections.classification.visibility_filter') }}
                        </label>
                        <select id="classification-visibility" wire:model.live="classificationVisibility" class="mt-2 min-h-11 w-full rounded-control border border-slate-300 bg-white px-3 py-2.5 text-sm font-semibold text-slate-800 focus:border-emerald-600 focus:ring-2 focus:ring-emerald-100">
                            <option value="">{{ __('collections.classification.all_values') }}</option>
                            @foreach ($classificationVisibilityOptions as $option)
                                <option value="{{ $option['value'] }}">{{ $option['label'] }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label for="classification-type" class="block text-sm font-bold text-slate-700">
                            {{ __('collections.classification.type_filter') }}
                        </label>
                        <select id="classification-type" wire:model.live="classificationType" class="mt-2 min-h-11 w-full rounded-control border border-slate-300 bg-white px-3 py-2.5 text-sm font-semibold text-slate-800 focus:border-emerald-600 focus:ring-2 focus:ring-emerald-100">
                            <option value="">{{ __('collections.classification.all_values') }}</option>
                            @foreach ($classificationTypeOptions as $option)
                                <option value="{{ $option['value'] }}">{{ $option['label'] }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label for="classification-per-page" class="block text-sm font-bold text-slate-700">
                            {{ __('collections.classification.per_page') }}
                        </label>
                        <select id="classification-per-page" wire:model.live="classificationPerPage" class="mt-2 min-h-11 w-full rounded-control border border-slate-300 bg-white px-3 py-2.5 text-sm font-semibold text-slate-800 focus:border-emerald-600 focus:ring-2 focus:ring-emerald-100">
                            @foreach ([10, 20, 30, 50] as $perPage)
                                <option value="{{ $perPage }}">{{ $perPage }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>

                <div class="flex flex-col gap-3 rounded-control border border-slate-200 bg-white p-3 sm:flex-row sm:items-center sm:justify-between">
                    <div class="min-w-0">
                        <p class="text-sm font-black text-slate-800">
                            {{ __('collections.classification.selected_count', ['count' => count($selectedClassificationPublicIds)]) }}
                        </p>
                        <p class="mt-1 text-xs leading-5 text-slate-500">
                            {{ __('collections.classification.selection_hint') }}
                        </p>
                    </div>
                    <div class="flex flex-col gap-2 sm:flex-row">
                        <button
                            type="button"
                            wire:click="selectHighConfidence"
                            wire:loading.attr="disabled"
                            wire:target="selectHighConfidence"
                            class="inline-flex min-h-11 items-center justify-center gap-2 rounded-control border border-slate-300 bg-white px-4 py-2.5 text-sm font-bold text-slate-700 hover:bg-slate-100 disabled:cursor-wait disabled:opacity-60"
                        >
                            <x-ui.icon name="fa-solid fa-wand-magic-sparkles" />
                            <span>{{ __('collections.classification.select_high_confidence') }}</span>
                        </button>
                        <button
                            type="button"
                            wire:click="prepareClassificationPreview"
                            wire:loading.attr="disabled"
                            wire:target="prepareClassificationPreview"
                            @disabled($selectedClassificationPublicIds === [])
                            class="inline-flex min-h-11 items-center justify-center gap-2 rounded-control bg-slate-800 px-4 py-2.5 text-sm font-bold text-white hover:bg-slate-700 disabled:cursor-not-allowed disabled:opacity-50"
                        >
                            <x-ui.icon name="fa-solid fa-arrow-right" />
                            <span>{{ __('collections.classification.review_selected') }}</span>
                        </button>
                    </div>
                </div>
                <x-form.input-error for="selectedClassificationPublicIds" />

                <div wire:loading.delay wire:target="classificationSearch,classificationVisibility,classificationType,classificationPerPage,selectHighConfidence,prepareClassificationPreview,confirmClassificationAssignments" role="status" aria-live="polite">
                    <div class="flex items-center gap-2 rounded-control bg-sky-50 px-4 py-3 text-sm font-bold text-sky-800">
                        <x-ui.icon name="fa-solid fa-spinner fa-spin" />
                        <span>{{ __('collections.classification.loading') }}</span>
                    </div>
                </div>

                @if ($classificationPreviewOpen)
                    <section
                        data-classification-preview
                        role="region"
                        aria-labelledby="classification-preview-title"
                        class="rounded-control border-2 border-emerald-300 bg-emerald-50 p-4 sm:p-5"
                    >
                        <div class="flex items-start gap-3">
                            <span class="inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-control bg-white text-emerald-800">
                                <x-ui.icon name="fa-solid fa-clipboard-check" />
                            </span>
                            <div class="min-w-0">
                                <h3 id="classification-preview-title" class="text-base font-black text-slate-900">
                                    {{ __('collections.classification.preview_title') }}
                                </h3>
                                <p class="mt-1 text-sm leading-6 text-slate-700">
                                    {{ __('collections.classification.preview_description', ['count' => count($classificationPreviewRows)]) }}
                                </p>
                            </div>
                        </div>
                        <ol class="mt-4 divide-y divide-emerald-200 border-y border-emerald-200">
                            @foreach ($classificationPreviewRows as $row)
                                <li wire:key="classification-preview-{{ $row['collectionPublicId'] }}" class="grid gap-1 py-3 sm:grid-cols-[minmax(0,1fr)_minmax(12rem,0.7fr)] sm:items-center sm:gap-4">
                                    <span class="break-words text-sm font-black text-slate-900">{{ $row['collectionName'] }}</span>
                                    <span class="break-words text-sm font-bold text-emerald-900">{{ $row['categoryLabel'] }}</span>
                                </li>
                            @endforeach
                        </ol>
                        <div class="mt-4 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
                            <button type="button" wire:click="cancelClassificationPreview" class="inline-flex min-h-11 items-center justify-center rounded-control border border-slate-300 bg-white px-4 py-2.5 text-sm font-bold text-slate-700 hover:bg-slate-100">
                                {{ __('collections.classification.cancel_preview') }}
                            </button>
                            <button
                                data-classification-confirm
                                type="button"
                                wire:click="confirmClassificationAssignments"
                                wire:loading.attr="disabled"
                                wire:target="confirmClassificationAssignments"
                                class="inline-flex min-h-11 items-center justify-center gap-2 rounded-control bg-emerald-700 px-4 py-2.5 text-sm font-black text-white hover:bg-emerald-600 disabled:cursor-wait disabled:opacity-60"
                            >
                                <x-ui.icon name="fa-solid fa-check" />
                                <span>{{ __('collections.classification.confirm_assignments') }}</span>
                            </button>
                        </div>
                    </section>
                @endif

                @island(name: 'collection-classification-pagination', always: true, with: $this->paginationIslandPage)
                <x-ui.pagination-region name="collection-classification-results">
                    <div class="divide-y divide-slate-200 border-y border-slate-200">
                        @forelse ($classificationPage as $collection)
                            <article
                                data-classification-row
                                wire:key="classification-row-{{ $collection->public_id }}"
                                class="grid min-w-0 gap-4 py-4 lg:grid-cols-[minmax(0,1.25fr)_minmax(17rem,0.75fr)] lg:items-start"
                            >
                                <div class="flex min-w-0 items-start gap-3">
                                    <label class="grid min-h-11 min-w-11 shrink-0 cursor-pointer place-items-center rounded-control border border-slate-200 bg-slate-50" aria-label="{{ __('collections.classification.select_collection', ['name' => $collection->display_name]) }}">
                                        <input
                                            type="checkbox"
                                            wire:model.live="selectedClassificationPublicIds"
                                            value="{{ $collection->public_id }}"
                                            class="h-5 w-5 rounded border-slate-300 text-emerald-700 focus:ring-emerald-600"
                                        >
                                    </label>
                                    <div class="min-w-0 flex-1">
                                        <div class="flex flex-wrap gap-2">
                                            <x-ui.status-pill variant="muted">{{ $collection->type_label }}</x-ui.status-pill>
                                            <x-ui.status-pill variant="muted">{{ $collection->visibility_label }}</x-ui.status-pill>
                                            <x-ui.status-pill variant="neutral">{{ $collection->moderation_status_label }}</x-ui.status-pill>
                                        </div>
                                        <h3 class="mt-2 break-words text-base font-black text-slate-900">{{ $collection->display_name }}</h3>
                                        @if ($collection->display_description)
                                            <p class="mt-1 break-words text-sm leading-6 text-slate-600">{{ $collection->display_description }}</p>
                                        @endif
                                        <p class="mt-2 flex flex-wrap gap-x-4 gap-y-1 text-xs font-semibold text-slate-500">
                                            <span>{{ __('collections.page.owner') }}: {{ $collection->owner_label }}</span>
                                            <span>{{ $collection->items_label }}</span>
                                        </p>
                                    </div>
                                </div>

                                <div class="min-w-0 rounded-control border border-slate-200 bg-slate-50 p-3">
                                    <div class="flex flex-wrap items-center justify-between gap-2">
                                        <p class="text-xs font-black uppercase tracking-wide text-slate-500">
                                            {{ __('collections.classification.suggestion') }}
                                        </p>
                                        <x-ui.status-pill :variant="$collection->classification_presentation['confidenceVariant']">
                                            {{ $collection->classification_presentation['confidenceLabel'] }}
                                        </x-ui.status-pill>
                                    </div>
                                    @if ($collection->classification_presentation['categoryPath'])
                                        <p class="mt-2 break-words text-sm font-black text-slate-900">{{ $collection->classification_presentation['categoryPath'] }}</p>
                                    @else
                                        <p class="mt-2 text-sm font-bold text-slate-600">{{ __('collections.classification.suggestion_none') }}</p>
                                    @endif
                                    <p class="mt-1 text-xs font-semibold text-slate-500">
                                        {{ __('collections.classification.score_and_sample', [
                                            'score' => $collection->classification_presentation['score'],
                                            'sample' => $collection->classification_presentation['sampleSize'],
                                            'total' => $collection->classification_presentation['totalItems'],
                                        ]) }}
                                    </p>
                                    <ul class="mt-2 space-y-1 text-xs font-semibold leading-5 text-slate-600">
                                        @foreach ($collection->classification_presentation['reasonLabels'] as $reason)
                                            <li class="flex items-start gap-2">
                                                <x-ui.icon name="fa-solid fa-circle-check text-emerald-700" align="start" />
                                                <span class="break-words">{{ $reason }}</span>
                                            </li>
                                        @endforeach
                                    </ul>
                                    <div class="mt-3">
                                        <label for="classification-category-{{ $collection->public_id }}" class="block text-xs font-black text-slate-700">
                                            {{ __('collections.classification.target_category') }}
                                        </label>
                                        <select
                                            id="classification-category-{{ $collection->public_id }}"
                                            wire:model.live="classificationCategoryByCollection.{{ $collection->public_id }}"
                                            class="mt-2 min-h-11 w-full rounded-control border border-slate-300 bg-white px-3 py-2.5 text-sm font-semibold text-slate-800 focus:border-emerald-600 focus:ring-2 focus:ring-emerald-100"
                                        >
                                            <option value="">{{ __('collections.categories.select_root') }}</option>
                                            @foreach ($assignmentOptions as $option)
                                                <option value="{{ $option['value'] }}">{{ $option['label'] }}</option>
                                            @endforeach
                                        </select>
                                        <x-form.input-error :for="'classificationCategoryByCollection.'.$collection->public_id" />
                                    </div>
                                </div>
                            </article>
                        @empty
                            <div class="py-10 text-center">
                                <span class="mx-auto inline-flex h-11 w-11 items-center justify-center rounded-control bg-slate-100 text-slate-500">
                                    <x-ui.icon name="fa-solid fa-circle-check" />
                                </span>
                                <p class="mt-3 text-sm font-black text-slate-800">{{ __('collections.classification.queue_empty') }}</p>
                            </div>
                        @endforelse
                    </div>
                    @if ($classificationPage->hasPages())
                        <nav class="mt-4" aria-label="{{ __('collections.classification.queue_pagination') }}">
                            {{ $classificationPage->links(data: ['region' => 'collection-classification-results']) }}
                        </nav>
                    @endif
                </x-ui.pagination-region>
                @endisland
            </div>
        </section>
    @endif

    <x-ui.panel
        :title="__('collections.categories.admin_title')"
        :subtitle="__('collections.categories.admin_description')"
        icon="fa-solid fa-sitemap"
    >
        @if ($canManage)
            <form data-category-create-form wire:submit="createCategory" class="grid gap-4 rounded-control border border-slate-200 bg-slate-50 p-4 lg:grid-cols-2" novalidate>
                <div>
                    <label for="category-parent" class="block text-sm font-bold text-slate-700">{{ __('collections.categories.parent') }}</label>
                    <select id="category-parent" wire:model="parentPublicId" class="mt-2 min-h-11 w-full rounded-control border border-slate-300 bg-white px-3 py-2.5 text-sm font-semibold text-slate-700 focus:border-emerald-600 focus:ring-2 focus:ring-emerald-100">
                        <option value="">{{ __('collections.categories.create_root') }}</option>
                        @foreach ($rootOptions as $option)
                            <option value="{{ $option['value'] }}">{{ $option['label'] }}</option>
                        @endforeach
                    </select>
                    <x-form.input-error for="parentPublicId" />
                </div>
                <x-form.field :label="__('collections.categories.slug')" for="category-slug" wire:model="slug" required />
                <x-form.field :label="__('collections.categories.name_ru')" for="category-name-ru" wire:model="nameRu" required />
                <x-form.field :label="__('collections.categories.name_en')" for="category-name-en" wire:model="nameEn" required />
                <div class="lg:col-span-2">
                    <button type="submit" wire:loading.attr="disabled" wire:target="createCategory" class="inline-flex min-h-11 w-full items-center justify-center gap-2 rounded-control bg-emerald-700 px-4 py-2.5 text-sm font-bold text-white hover:bg-emerald-600 disabled:opacity-60 sm:w-auto">
                        <x-ui.icon name="fa-solid fa-plus" />
                        <span>{{ __('collections.categories.create') }}</span>
                    </button>
                </div>
            </form>
        @endif

        <div class="mt-5 divide-y divide-slate-200">
            @foreach ($categoryTree as $root)
                <article wire:key="category-root-{{ $root->public_id }}" class="py-4 first:pt-0 last:pb-0">
                    <div class="flex flex-wrap items-center justify-between gap-3">
                        <div class="min-w-0">
                            <div class="flex flex-wrap items-center gap-2">
                                <h3 class="break-words font-black text-slate-800">{{ $root->display_name }}</h3>
                                <x-ui.status-pill :variant="$root->is_active ? 'success' : 'muted'">
                                    {{ $root->is_active ? __('collections.categories.active') : __('collections.categories.archived') }}
                                </x-ui.status-pill>
                            </div>
                            <p class="mt-1 text-xs font-semibold text-slate-500">{{ $root->slug }} · {{ __('collections.categories.assigned_count', ['count' => $root->collections_count]) }}</p>
                        </div>
                        @if ($canManage)
                            <div class="flex gap-2">
                                <button type="button" wire:click="moveCategory('{{ $root->public_id }}', -1)" class="grid h-11 w-11 place-items-center rounded-control bg-slate-100 text-slate-700 hover:bg-slate-200" aria-label="{{ __('collections.categories.move_up') }}"><x-ui.icon name="fa-solid fa-arrow-up" /></button>
                                <button type="button" wire:click="moveCategory('{{ $root->public_id }}', 1)" class="grid h-11 w-11 place-items-center rounded-control bg-slate-100 text-slate-700 hover:bg-slate-200" aria-label="{{ __('collections.categories.move_down') }}"><x-ui.icon name="fa-solid fa-arrow-down" /></button>
                                <button type="button" wire:click="selectCategory('{{ $root->public_id }}')" class="inline-flex min-h-11 items-center gap-2 rounded-control bg-slate-100 px-3 text-sm font-bold text-slate-700 hover:bg-slate-200"><x-ui.icon name="fa-solid fa-pen" />{{ __('collections.actions.edit') }}</button>
                            </div>
                        @endif
                    </div>
                    @if ($root->children->isNotEmpty())
                        <div class="mt-3 grid gap-2 lg:grid-cols-2">
                            @foreach ($root->children as $child)
                                <div wire:key="category-child-{{ $child->public_id }}" class="flex flex-wrap items-center justify-between gap-3 rounded-control border border-slate-200 bg-white p-3">
                                    <div class="min-w-0">
                                        <p class="break-words text-sm font-black text-slate-800">{{ $child->display_name }}</p>
                                        <p class="mt-1 text-xs font-semibold text-slate-500">{{ $child->slug }} · {{ __('collections.categories.assigned_count', ['count' => $child->collections_count]) }}</p>
                                    </div>
                                    <div class="flex items-center gap-2">
                                        <x-ui.status-pill :variant="$child->is_active ? 'success' : 'muted'">
                                            {{ $child->is_active ? __('collections.categories.active') : __('collections.categories.archived') }}
                                        </x-ui.status-pill>
                                        @if ($canManage)
                                            <button type="button" wire:click="moveCategory('{{ $child->public_id }}', -1)" class="grid h-11 w-11 place-items-center rounded-control bg-slate-100 text-slate-700 hover:bg-slate-200" aria-label="{{ __('collections.categories.move_up') }}"><x-ui.icon name="fa-solid fa-arrow-up" /></button>
                                            <button type="button" wire:click="moveCategory('{{ $child->public_id }}', 1)" class="grid h-11 w-11 place-items-center rounded-control bg-slate-100 text-slate-700 hover:bg-slate-200" aria-label="{{ __('collections.categories.move_down') }}"><x-ui.icon name="fa-solid fa-arrow-down" /></button>
                                            <button type="button" wire:click="selectCategory('{{ $child->public_id }}')" class="grid h-11 w-11 place-items-center rounded-control bg-slate-100 text-slate-700 hover:bg-slate-200" aria-label="{{ __('collections.actions.edit') }}"><x-ui.icon name="fa-solid fa-pen" /></button>
                                        @endif
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    @endif
                </article>
            @endforeach
        </div>
    </x-ui.panel>

    @if ($canManage && $selectedPublicId !== '')
        <x-ui.panel :title="__('collections.categories.edit_title')" icon="fa-solid fa-pen-to-square">
            <form wire:submit="saveCategory" class="grid gap-4 sm:grid-cols-2" novalidate>
                <x-form.field :label="__('collections.categories.name_ru')" for="selected-category-name-ru" wire:model="selectedNameRu" required />
                <x-form.field :label="__('collections.categories.name_en')" for="selected-category-name-en" wire:model="selectedNameEn" required />
                <label class="flex min-h-11 items-center gap-3 rounded-control border border-slate-200 p-3 sm:col-span-2">
                    <input type="checkbox" wire:model="selectedIsActive" class="h-5 w-5 rounded border-slate-300 text-emerald-700 focus:ring-emerald-600">
                    <span class="text-sm font-bold text-slate-700">{{ __('collections.categories.active') }}</span>
                </label>
                <div class="sm:col-span-2">
                    <button type="submit" class="inline-flex min-h-11 w-full items-center justify-center gap-2 rounded-control bg-emerald-700 px-4 text-sm font-bold text-white hover:bg-emerald-600 sm:w-auto"><x-ui.icon name="fa-solid fa-floppy-disk" />{{ __('collections.actions.save') }}</button>
                </div>
            </form>
        </x-ui.panel>
    @endif

</section>
