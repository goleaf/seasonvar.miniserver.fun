<div class="mx-auto max-w-6xl space-y-5">
    <header class="rounded-panel border border-slate-200 bg-white p-5 shadow-panel sm:p-6">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div class="min-w-0">
                <a href="{{ route('collections.mine') }}" class="inline-flex min-h-11 items-center gap-2 text-sm font-bold text-emerald-700 hover:text-emerald-600">
                    <x-ui.icon name="fa-solid fa-arrow-left" />
                    <span>{{ __('collections.navigation.my_collections') }}</span>
                </a>
                <h1 class="mt-2 break-words text-2xl font-black tracking-tight text-slate-800 sm:text-3xl">{{ __('collections.actions.edit') }}: {{ $collection->display_name }}</h1>
                <div class="mt-3 flex flex-wrap gap-2">
                    <x-ui.status-pill variant="muted">{{ $collectionTypeLabel }}</x-ui.status-pill>
                    <x-ui.status-pill variant="muted">{{ $collectionVisibilityLabel }}</x-ui.status-pill>
                    <x-ui.status-pill variant="muted">{{ $collectionModerationLabel }}</x-ui.status-pill>
                    @if ($isSmart)
                        <x-ui.status-pill variant="success" icon="fa-solid fa-wand-magic-sparkles">{{ __('collections.smart.badge') }}</x-ui.status-pill>
                    @endif
                </div>
            </div>
            @if ($canOpenPublicPage)
                <a href="{{ route('collections.show', ['collectionSlug' => $collection->slug]) }}" class="inline-flex min-h-11 w-full items-center justify-center gap-2 rounded-control bg-slate-100 px-4 py-2.5 text-sm font-bold text-slate-700 hover:bg-slate-200 sm:w-auto">
                    <x-ui.icon name="fa-solid fa-arrow-up-right-from-square" />
                    <span>{{ __('collections.actions.open_public_page') }}</span>
                </a>
            @endif
        </div>
    </header>

    @if ($status)
        <x-form.status-message :message="$status" />
    @endif
    <x-form.input-error for="collection" />
    <x-form.input-error for="order" />
    <x-form.input-error for="rules" />
    <x-form.input-error for="form" />

    @if ($isPendingModeration)
        <x-form.status-message :message="__('collections.moderation.notice_pending')" variant="warning" />
    @endif

    <div class="grid min-w-0 gap-5 lg:grid-cols-[minmax(0,1fr)_20rem]">
        <x-ui.panel :title="__('collections.actions.edit')" icon="fa-solid fa-pen-to-square">
            <form wire:submit="save" class="space-y-5" novalidate>
                <x-form.field :label="__('collections.form.name')" for="collection-edit-name" wire:model="name" required />
                <div>
                    <label for="collection-edit-description" class="block text-sm font-bold text-slate-700">{{ __('collections.form.description') }}</label>
                    <textarea id="collection-edit-description" wire:model="description" rows="8" maxlength="10000" class="mt-2 w-full rounded-control border border-slate-300 bg-white px-3 py-2.5 text-base text-slate-800 outline-none focus:border-emerald-600 focus:ring-2 focus:ring-emerald-100"></textarea>
                    <x-form.input-error for="description" id="collection-edit-description-error" />
                </div>
                <div class="grid gap-4 sm:grid-cols-2">
                    @unless ($isSmart)
                    <div>
                        <label for="collection-edit-visibility" class="block text-sm font-bold text-slate-700">{{ __('collections.form.visibility') }}</label>
                        <select id="collection-edit-visibility" wire:model="visibility" class="mt-2 min-h-11 w-full rounded-control border border-slate-300 bg-white px-3 py-2.5 text-sm font-semibold text-slate-700 focus:border-emerald-600 focus:ring-2 focus:ring-emerald-100">
                            @foreach ($visibilityOptions as $option)
                                <option value="{{ $option['value'] }}">{{ $option['label'] }}</option>
                            @endforeach
                        </select>
                        <x-form.input-error for="visibility" />
                    </div>
                    @endunless
                    <div>
                        <label for="collection-edit-sort" class="block text-sm font-bold text-slate-700">{{ __('collections.form.sort_mode') }}</label>
                        <select id="collection-edit-sort" wire:model="sortMode" class="mt-2 min-h-11 w-full rounded-control border border-slate-300 bg-white px-3 py-2.5 text-sm font-semibold text-slate-700 focus:border-emerald-600 focus:ring-2 focus:ring-emerald-100">
                            @foreach ($sortOptions as $option)
                                <option value="{{ $option['value'] }}">{{ $option['label'] }}</option>
                            @endforeach
                        </select>
                        <x-form.input-error for="sortMode" />
                    </div>
                </div>
                @if ($isSmart)
                    <section class="space-y-5 rounded-control border border-sky-200 bg-sky-50 p-4" aria-labelledby="smart-rules-title">
                        <div>
                            <h2 id="smart-rules-title" class="font-black text-sky-950">{{ __('collections.smart.editor.title') }}</h2>
                            <p class="mt-1 text-xs leading-5 text-sky-800">{{ __('collections.smart.editor.description') }}</p>
                        </div>
                        <div>
                            <span class="block text-sm font-bold text-sky-950">{{ __('collections.smart.preset_label') }}</span>
                            <div class="mt-2 grid gap-2 sm:grid-cols-2">
                                @foreach ($smartPresetOptions as $preset)
                                    <button type="button" wire:click="applySmartPreset('{{ $preset['value'] }}')" wire:loading.attr="disabled" wire:target="applySmartPreset" class="min-h-11 rounded-control border border-sky-200 bg-white px-3 py-2 text-left text-sm font-bold text-sky-900 hover:border-emerald-600 hover:bg-emerald-50">
                                        <span class="block">{{ $preset['label'] }}</span>
                                        <span class="mt-1 block text-xs font-medium leading-5 text-slate-500">{{ $preset['description'] }}</span>
                                    </button>
                                @endforeach
                            </div>
                            <x-form.input-error for="smartPreset" />
                        </div>
                        <div class="grid gap-4 md:grid-cols-2">
                            <div>
                                <label for="smart-country" class="block text-sm font-bold text-slate-700">{{ __('collections.smart.fields.country_slug') }}</label>
                                <select id="smart-country" wire:model="countrySlug" class="mt-2 min-h-11 w-full rounded-control border border-slate-300 bg-white px-3 py-2.5 text-sm font-semibold text-slate-700 focus:border-emerald-600 focus:ring-2 focus:ring-emerald-100">
                                    <option value="">{{ __('collections.form.all') }}</option>
                                    @foreach ($smartCountryOptions as $option)
                                        <option value="{{ $option->slug }}">{{ $option->name }}</option>
                                    @endforeach
                                </select>
                                <x-form.input-error for="country_slug" />
                            </div>
                            <div>
                                <label for="smart-genre" class="block text-sm font-bold text-slate-700">{{ __('collections.smart.fields.genre_slug') }}</label>
                                <select id="smart-genre" wire:model="genreSlug" class="mt-2 min-h-11 w-full rounded-control border border-slate-300 bg-white px-3 py-2.5 text-sm font-semibold text-slate-700 focus:border-emerald-600 focus:ring-2 focus:ring-emerald-100">
                                    <option value="">{{ __('collections.form.all') }}</option>
                                    @foreach ($smartGenreOptions as $option)
                                        <option value="{{ $option->slug }}">{{ $option->name }}</option>
                                    @endforeach
                                </select>
                                <x-form.input-error for="genre_slug" />
                            </div>
                            <div class="md:col-span-2">
                                <label for="smart-actor-search" class="block text-sm font-bold text-slate-700">{{ __('collections.smart.actor_search') }}</label>
                                <input id="smart-actor-search" type="search" wire:model.live.debounce.300ms="actorSearch" maxlength="80" autocomplete="off" class="mt-2 min-h-11 w-full rounded-control border border-slate-300 bg-white px-3 py-2.5 text-base text-slate-800 focus:border-emerald-600 focus:ring-2 focus:ring-emerald-100">
                                @if ($showSmartActorResults)
                                    <div class="mt-2 grid gap-2 sm:grid-cols-2">
                                        @forelse ($smartActorOptions as $option)
                                            <label class="flex min-h-11 cursor-pointer items-center gap-2 rounded-control border border-sky-200 bg-white px-3 py-2 text-sm font-bold text-slate-700 has-[:checked]:border-emerald-600 has-[:checked]:bg-emerald-50">
                                                <input type="radio" wire:model="actorSlug" value="{{ $option->slug }}" class="h-5 w-5 border-slate-300 text-emerald-700 focus:ring-emerald-600">
                                                <span>{{ $option->name }}</span>
                                            </label>
                                        @empty
                                            <p class="text-xs font-semibold text-slate-500">{{ __('collections.smart.actor_empty') }}</p>
                                        @endforelse
                                    </div>
                                @endif
                                @if ($actorSlug !== '')
                                    <button type="button" wire:click="$set('actorSlug', '')" class="mt-2 min-h-11 rounded-control bg-white px-3 text-sm font-bold text-slate-700 hover:bg-slate-100">{{ __('collections.smart.clear_actor') }}</button>
                                @endif
                                <x-form.input-error for="actor_slug" />
                            </div>
                            <div>
                                <label for="smart-imdb" class="block text-sm font-bold text-slate-700">{{ __('collections.smart.fields.imdb_min') }}</label>
                                <input id="smart-imdb" type="text" inputmode="decimal" wire:model="imdbMin" maxlength="8" placeholder="8,0" class="mt-2 min-h-11 w-full rounded-control border border-slate-300 bg-white px-3 py-2.5 text-base text-slate-800 focus:border-emerald-600 focus:ring-2 focus:ring-emerald-100">
                                <x-form.input-error for="imdb_min" />
                            </div>
                            <div>
                                <label for="smart-completion" class="block text-sm font-bold text-slate-700">{{ __('collections.smart.fields.completion') }}</label>
                                <select id="smart-completion" wire:model="completion" class="mt-2 min-h-11 w-full rounded-control border border-slate-300 bg-white px-3 py-2.5 text-sm font-semibold text-slate-700 focus:border-emerald-600 focus:ring-2 focus:ring-emerald-100">
                                    <option value="">{{ __('collections.form.all') }}</option>
                                    @foreach ($smartCompletionOptions as $option)
                                        <option value="{{ $option['value'] }}">{{ $option['label'] }}</option>
                                    @endforeach
                                </select>
                                <x-form.input-error for="completion" />
                            </div>
                            <div class="grid grid-cols-2 gap-3">
                                <div>
                                    <label for="smart-year-from" class="block text-sm font-bold text-slate-700">{{ __('collections.smart.fields.year_from') }}</label>
                                    <input id="smart-year-from" type="text" inputmode="numeric" wire:model="yearFrom" maxlength="4" class="mt-2 min-h-11 w-full rounded-control border border-slate-300 bg-white px-3 py-2.5 text-base text-slate-800 focus:border-emerald-600 focus:ring-2 focus:ring-emerald-100">
                                </div>
                                <div>
                                    <label for="smart-year-to" class="block text-sm font-bold text-slate-700">{{ __('collections.smart.fields.year_to') }}</label>
                                    <input id="smart-year-to" type="text" inputmode="numeric" wire:model="yearTo" maxlength="4" class="mt-2 min-h-11 w-full rounded-control border border-slate-300 bg-white px-3 py-2.5 text-base text-slate-800 focus:border-emerald-600 focus:ring-2 focus:ring-emerald-100">
                                </div>
                                <x-form.input-error for="year_from" />
                                <x-form.input-error for="year_to" />
                            </div>
                            <div class="grid grid-cols-2 gap-3">
                                <div>
                                    <label for="smart-episodes-max" class="block text-sm font-bold text-slate-700">{{ __('collections.smart.fields.episodes_max') }}</label>
                                    <input id="smart-episodes-max" type="text" inputmode="numeric" wire:model="episodesMax" maxlength="5" class="mt-2 min-h-11 w-full rounded-control border border-slate-300 bg-white px-3 py-2.5 text-base text-slate-800 focus:border-emerald-600 focus:ring-2 focus:ring-emerald-100">
                                </div>
                                <div>
                                    <label for="smart-duration-max" class="block text-sm font-bold text-slate-700">{{ __('collections.smart.fields.max_episode_minutes') }}</label>
                                    <input id="smart-duration-max" type="text" inputmode="numeric" wire:model="maxEpisodeMinutes" maxlength="4" class="mt-2 min-h-11 w-full rounded-control border border-slate-300 bg-white px-3 py-2.5 text-base text-slate-800 focus:border-emerald-600 focus:ring-2 focus:ring-emerald-100">
                                </div>
                                <x-form.input-error for="episodes_max" />
                                <x-form.input-error for="max_episode_minutes" />
                            </div>
                            <div>
                                <label for="smart-watch-status" class="block text-sm font-bold text-slate-700">{{ __('collections.smart.fields.watch_status') }}</label>
                                <select id="smart-watch-status" wire:model="watchStatus" class="mt-2 min-h-11 w-full rounded-control border border-slate-300 bg-white px-3 py-2.5 text-sm font-semibold text-slate-700 focus:border-emerald-600 focus:ring-2 focus:ring-emerald-100">
                                    <option value="">{{ __('collections.form.all') }}</option>
                                    @foreach ($smartWatchStatusOptions as $option)
                                        <option value="{{ $option['value'] }}">{{ $option['label'] }}</option>
                                    @endforeach
                                </select>
                                <x-form.input-error for="watch_status" />
                            </div>
                            <div>
                                <label for="smart-watch-age" class="block text-sm font-bold text-slate-700">{{ __('collections.smart.fields.watch_status_older_days') }}</label>
                                <input id="smart-watch-age" type="text" inputmode="numeric" wire:model="watchStatusOlderDays" maxlength="4" class="mt-2 min-h-11 w-full rounded-control border border-slate-300 bg-white px-3 py-2.5 text-base text-slate-800 focus:border-emerald-600 focus:ring-2 focus:ring-emerald-100">
                                <x-form.input-error for="watch_status_older_days" />
                            </div>
                        </div>
                        <fieldset>
                            <legend class="text-sm font-bold text-slate-700">{{ __('collections.smart.boolean_rules') }}</legend>
                            <div class="mt-2 grid gap-2 sm:grid-cols-2">
                                @foreach ($smartBooleanOptions as $option)
                                    <label class="flex min-h-11 cursor-pointer items-center gap-3 rounded-control border border-sky-200 bg-white px-3 py-2 text-sm font-bold text-slate-700 has-[:checked]:border-emerald-600 has-[:checked]:bg-emerald-50">
                                        <input type="checkbox" wire:model="{{ $option['property'] }}" class="h-5 w-5 rounded border-slate-300 text-emerald-700 focus:ring-emerald-600">
                                        <span>{{ __('collections.smart.fields.'.$option['label']) }}</span>
                                    </label>
                                @endforeach
                            </div>
                        </fieldset>
                        @if ($smartRuleSummary !== [])
                            <div>
                                <h3 class="text-sm font-black text-sky-950">{{ __('collections.smart.active_rules') }}</h3>
                                <ul class="mt-2 flex flex-wrap gap-2">
                                    @foreach ($smartRuleSummary as $rule)
                                        <li class="rounded-full bg-white px-3 py-1.5 text-xs font-bold text-sky-900">{{ $rule }}</li>
                                    @endforeach
                                </ul>
                            </div>
                        @endif
                        <button type="button" wire:click="resetSmartRules" wire:loading.attr="disabled" wire:target="resetSmartRules" class="min-h-11 rounded-control bg-white px-4 text-sm font-bold text-slate-700 hover:bg-slate-100">{{ __('collections.smart.reset_rules') }}</button>
                    </section>
                @else
                <x-collections.category-fields
                    :root-options="$categoryRootOptions"
                    :child-options="$categoryChildOptions"
                    :assignment-archived="$categoryAssignmentArchived"
                    id-prefix="collection-edit-category"
                />
                @endif
                @if ($isEditorial)
                    <div class="grid gap-4 rounded-control bg-slate-50 p-4">
                        <h3 class="font-black text-slate-800">{{ __('collections.editorial.seo_fields') }}</h3>
                        <x-form.field :label="__('collections.editorial.seo_title')" for="collection-editorial-seo-title" wire:model="seoTitle" />
                        <div>
                            <label for="collection-editorial-seo-description" class="block text-sm font-bold text-slate-700">{{ __('collections.editorial.seo_description') }}</label>
                            <textarea id="collection-editorial-seo-description" wire:model="seoDescription" rows="4" maxlength="500" class="mt-2 w-full rounded-control border border-slate-300 bg-white px-3 py-2.5 text-base text-slate-800 focus:border-emerald-600 focus:ring-2 focus:ring-emerald-100"></textarea>
                            <x-form.input-error for="seoDescription" />
                        </div>
                    </div>
                @endif
                <button type="submit" wire:loading.attr="disabled" wire:target="save" class="inline-flex min-h-11 w-full items-center justify-center gap-2 rounded-control bg-emerald-700 px-4 py-2.5 text-sm font-bold text-white hover:bg-emerald-600 disabled:cursor-wait disabled:opacity-60 sm:w-auto">
                    <x-ui.icon name="fa-solid fa-floppy-disk" />
                    <span wire:loading.remove wire:target="save">{{ __('collections.actions.save') }}</span>
                    <span wire:loading wire:target="save">{{ __('collections.actions.saving') }}</span>
                </button>
            </form>
        </x-ui.panel>

        <div class="space-y-5">
            <section class="rounded-panel border border-rose-200 bg-white p-4 shadow-panel">
                <h2 class="font-black text-rose-800">{{ __('collections.actions.delete') }}</h2>
                <p class="mt-2 text-xs leading-5 text-slate-600">{{ __('collections.confirmations.delete') }}</p>
                <button type="button" wire:click="delete" wire:confirm="{{ __('collections.confirmations.delete') }}" wire:loading.attr="disabled" wire:target="delete" class="mt-4 inline-flex min-h-11 w-full items-center justify-center gap-2 rounded-control bg-rose-700 px-3 py-2.5 text-sm font-bold text-white hover:bg-rose-600 disabled:cursor-wait disabled:opacity-60">
                    <x-ui.icon name="fa-solid fa-trash-can" />
                    <span wire:loading.remove wire:target="delete">{{ __('collections.actions.delete') }}</span>
                    <span wire:loading wire:target="delete">{{ __('collections.actions.deleting') }}</span>
                </button>
            </section>
        </div>
    </div>

    @island(name: 'collection-editor-pagination', always: true, with: $this->paginationIslandPage)
    <x-ui.pagination-region name="collection-editor-results">
    <x-ui.panel :title="$itemsTitle" :subtitle="$isSmart ? __('collections.smart.editor.result_hint') : __('collections.ordering.hint')" icon="fa-solid fa-list-ol" :pad="false">
        @if ($items->isEmpty())
            <div class="p-8 text-center">
                <p class="text-sm font-semibold text-slate-600">{{ __('collections.page.empty') }}</p>
                <a href="{{ route('titles.index') }}" class="mt-4 inline-flex min-h-11 items-center justify-center gap-2 rounded-control bg-emerald-700 px-4 py-2.5 text-sm font-bold text-white hover:bg-emerald-600">
                    <x-ui.icon name="fa-solid fa-clapperboard" />{{ __('collections.actions.open_catalog') }}
                </a>
            </div>
        @else
            <ol wire:sort="sortItem" class="divide-y divide-slate-200">
                @foreach ($items as $item)
                    <li wire:key="collection-edit-item-{{ $item->collection_item_id }}" @if (! $isSmart) wire:sort:item="{{ $item->collection_item_id }}" @endif class="relative min-w-0">
                        <x-catalog.title-card :title="$item" layout="list" :show-description="false" readable />
                        @unless ($isSmart)
                        <div class="relative z-20 flex flex-wrap gap-2 border-t border-slate-100 px-3 pb-3 pt-2 sm:px-4 md:pl-28">
                            <span wire:sort:handle aria-hidden="true" class="inline-flex min-h-11 min-w-11 cursor-grab items-center justify-center rounded-control bg-slate-100 text-slate-500 active:cursor-grabbing">
                                <x-ui.icon name="fa-solid fa-grip-vertical" />
                            </span>
                            <span class="inline-flex min-h-11 items-center rounded-control bg-slate-50 px-3 text-xs font-bold text-slate-500">{{ $item->collection_position_label }}</span>
                            <div wire:sort:ignore class="contents">
                                <button type="button" wire:click="moveItem({{ $item->collection_item_id }}, -1)" wire:loading.attr="disabled" @disabled(! $item->collection_can_move_up) aria-label="{{ $item->collection_move_up_label }}" class="inline-flex min-h-11 flex-1 items-center justify-center gap-2 rounded-control bg-slate-100 px-3 text-sm font-bold text-slate-700 hover:bg-slate-200 disabled:cursor-not-allowed disabled:opacity-50 sm:flex-none">
                                    <x-ui.icon name="fa-solid fa-arrow-up" />{{ __('collections.actions.move_up') }}
                                </button>
                                <button type="button" wire:click="moveItem({{ $item->collection_item_id }}, 1)" wire:loading.attr="disabled" @disabled(! $item->collection_can_move_down) aria-label="{{ $item->collection_move_down_label }}" class="inline-flex min-h-11 flex-1 items-center justify-center gap-2 rounded-control bg-slate-100 px-3 text-sm font-bold text-slate-700 hover:bg-slate-200 disabled:cursor-not-allowed disabled:opacity-50 sm:flex-none">
                                    <x-ui.icon name="fa-solid fa-arrow-down" />{{ __('collections.actions.move_down') }}
                                </button>
                                <button type="button" wire:click="removeItem({{ $item->id }})" wire:confirm="{{ __('collections.confirmations.remove_item') }}" wire:loading.attr="disabled" class="inline-flex min-h-11 flex-1 items-center justify-center gap-2 rounded-control bg-rose-50 px-3 text-sm font-bold text-rose-700 hover:bg-rose-100 sm:flex-none">
                                    <x-ui.icon name="fa-solid fa-xmark" />{{ __('collections.actions.remove') }}
                                </button>
                            </div>
                        </div>
                        @endunless
                    </li>
                @endforeach
            </ol>
            <nav class="p-4" aria-label="{{ __('collections.page.pagination') }}">{{ $items->links(data: ['region' => 'collection-editor-results']) }}</nav>
        @endif
    </x-ui.panel>
    </x-ui.pagination-region>
    @endisland

    @if ($unavailableItems->isNotEmpty())
        <x-ui.panel :title="__('collections.page.unavailable')" :subtitle="__('collections.page.unavailable_hint')" icon="fa-solid fa-eye-slash">
            <ul class="space-y-2">
                @foreach ($unavailableItems as $unavailable)
                    <li wire:key="unavailable-collection-item-{{ $unavailable->id }}" class="flex flex-wrap items-center justify-between gap-3 rounded-control bg-slate-50 p-3">
                        <span class="min-w-0 break-words text-sm font-bold text-slate-600">{{ $unavailable->catalogTitleWithTrashed?->title ?: __('collections.page.unavailable_item') }}</span>
                        <button type="button" wire:click="removeItem({{ $unavailable->catalog_title_id }})" wire:confirm="{{ __('collections.confirmations.remove_item') }}" class="inline-flex min-h-11 items-center gap-2 rounded-control bg-rose-50 px-3 text-sm font-bold text-rose-700 hover:bg-rose-100">
                            <x-ui.icon name="fa-solid fa-xmark" />{{ __('collections.actions.remove') }}
                        </button>
                    </li>
                @endforeach
            </ul>
        </x-ui.panel>
    @endif
</div>
