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
                    <x-ui.status-pill variant="muted">{{ $collection->type->label() }}</x-ui.status-pill>
                    <x-ui.status-pill variant="muted">{{ $collection->visibility->label() }}</x-ui.status-pill>
                    <x-ui.status-pill variant="muted">{{ $collection->moderation_status->label() }}</x-ui.status-pill>
                </div>
            </div>
            @if ($collection->isPubliclyViewable())
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

    @if ($collection->moderation_status->value === 'pending')
        <x-form.status-message :message="__('collections.moderation.notice_pending')" variant="warning" />
    @endif

    <div class="grid min-w-0 gap-5 lg:grid-cols-[minmax(0,1fr)_20rem]">
        <x-ui.panel :title="__('collections.actions.edit')" icon="fa-solid fa-pen-to-square">
            <form wire:submit="save" class="space-y-5" novalidate>
                @if ($collection->type->value === 'editorial')
                    <fieldset>
                        <legend class="text-sm font-bold text-slate-700">{{ __('collections.editorial.translation_language') }}</legend>
                        <div class="mt-2 flex flex-wrap gap-2">
                            @foreach ($supportedLocales as $locale)
                                <button type="button" wire:click="selectEditorialLocale('{{ $locale }}')" @class(['inline-flex min-h-11 items-center rounded-control px-4 text-sm font-black', 'bg-emerald-700 text-white' => $contentLocale === $locale, 'bg-slate-100 text-slate-700 hover:bg-slate-200' => $contentLocale !== $locale])>{{ __('collections.locale.'.$locale) }}</button>
                            @endforeach
                        </div>
                        <p class="mt-2 text-xs leading-5 text-slate-500">{{ __('collections.editorial.translation_hint') }}</p>
                    </fieldset>
                @endif
                <x-form.field :label="__('collections.form.name')" for="collection-edit-name" wire:model="name" required />
                <div>
                    <label for="collection-edit-description" class="block text-sm font-bold text-slate-700">{{ __('collections.form.description') }}</label>
                    <textarea id="collection-edit-description" wire:model="description" rows="8" maxlength="10000" class="mt-2 w-full rounded-control border border-slate-300 bg-white px-3 py-2.5 text-base text-slate-800 outline-none focus:border-emerald-600 focus:ring-2 focus:ring-emerald-100"></textarea>
                    <x-form.input-error for="description" id="collection-edit-description-error" />
                </div>
                <div class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <label for="collection-edit-visibility" class="block text-sm font-bold text-slate-700">{{ __('collections.form.visibility') }}</label>
                        <select id="collection-edit-visibility" wire:model="visibility" class="mt-2 min-h-11 w-full rounded-control border border-slate-300 bg-white px-3 py-2.5 text-sm font-semibold text-slate-700 focus:border-emerald-600 focus:ring-2 focus:ring-emerald-100">
                            @foreach ($visibilityOptions as $option)
                                <option value="{{ $option->value }}">{{ $option->label() }}</option>
                            @endforeach
                        </select>
                        <x-form.input-error for="visibility" />
                    </div>
                    <div>
                        <label for="collection-edit-sort" class="block text-sm font-bold text-slate-700">{{ __('collections.form.sort_mode') }}</label>
                        <select id="collection-edit-sort" wire:model="sortMode" class="mt-2 min-h-11 w-full rounded-control border border-slate-300 bg-white px-3 py-2.5 text-sm font-semibold text-slate-700 focus:border-emerald-600 focus:ring-2 focus:ring-emerald-100">
                            @foreach ($sortOptions as $option)
                                <option value="{{ $option->value }}">{{ $option->label() }}</option>
                            @endforeach
                        </select>
                        <x-form.input-error for="sortMode" />
                    </div>
                </div>
                @if ($collection->type->value === 'editorial')
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
            <x-ui.panel :title="__('collections.form.cover')" icon="fa-solid fa-image">
                <x-ui.poster-frame
                    :src="$coverUrl"
                    :alt="__('collections.accessibility.collection_cover', ['name' => $collection->display_name])"
                    :empty-label="__('collections.page.cover_missing')"
                    class="aspect-[16/9] w-full rounded-control bg-slate-100"
                />
                <p class="mt-3 text-xs leading-5 text-slate-500">{{ __('collections.form.cover_hint', ['size' => $maximumCoverMegabytes]) }}</p>
                <form wire:submit="uploadCover" class="mt-4 space-y-3">
                    <input type="file" wire:model="cover" accept="image/jpeg,image/png,image/webp" class="block w-full text-sm text-slate-600 file:mr-3 file:min-h-11 file:rounded-control file:border-0 file:bg-slate-100 file:px-3 file:py-2.5 file:font-bold file:text-slate-700 hover:file:bg-slate-200">
                    <x-form.input-error for="cover" />
                    <button type="submit" wire:loading.attr="disabled" wire:target="cover,uploadCover" class="inline-flex min-h-11 w-full items-center justify-center gap-2 rounded-control bg-slate-800 px-3 py-2.5 text-sm font-bold text-white hover:bg-slate-700 disabled:cursor-wait disabled:opacity-60">
                        <x-ui.icon name="fa-solid fa-upload" />
                        <span>{{ __('collections.actions.upload_cover') }}</span>
                    </button>
                </form>
                @if ($collection->cover_path)
                    <button type="button" wire:click="removeCover" wire:confirm="{{ __('collections.confirmations.remove_cover') }}" wire:loading.attr="disabled" class="mt-2 inline-flex min-h-11 w-full items-center justify-center gap-2 rounded-control bg-rose-50 px-3 py-2.5 text-sm font-bold text-rose-700 hover:bg-rose-100">
                        <x-ui.icon name="fa-solid fa-trash-can" />
                        <span>{{ __('collections.actions.remove_cover') }}</span>
                    </button>
                @endif
            </x-ui.panel>

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

    <x-ui.panel :title="trans_choice('collections.page.items', $collection->total_items_count, ['count' => $collection->total_items_count])" :subtitle="__('collections.ordering.hint')" icon="fa-solid fa-list-ol" :pad="false">
        @if ($items->isEmpty())
            <div class="p-8 text-center">
                <p class="text-sm font-semibold text-slate-600">{{ __('collections.page.empty') }}</p>
                <a href="{{ route('titles.index') }}" class="mt-4 inline-flex min-h-11 items-center justify-center gap-2 rounded-control bg-emerald-700 px-4 py-2.5 text-sm font-bold text-white hover:bg-emerald-600">
                    <x-ui.icon name="fa-solid fa-clapperboard" />{{ __('collections.actions.open_catalog') }}
                </a>
            </div>
        @else
            <ol class="divide-y divide-slate-200">
                @foreach ($items as $item)
                    <li wire:key="collection-edit-item-{{ $item->collection_item_id }}" class="relative min-w-0">
                        <x-catalog.title-card :title="$item" layout="list" :show-description="false" readable />
                        <div class="relative z-20 flex flex-wrap gap-2 border-t border-slate-100 px-3 pb-3 pt-2 sm:px-4 md:pl-28">
                            <span class="inline-flex min-h-11 items-center rounded-control bg-slate-50 px-3 text-xs font-bold text-slate-500">{{ __('collections.page.position', ['position' => $item->collection_position]) }}</span>
                            <button type="button" wire:click="moveItem({{ $item->collection_item_id }}, -1)" wire:loading.attr="disabled" aria-label="{{ __('collections.accessibility.reorder_item', ['title' => $item->display_title]) }} — {{ __('collections.actions.move_up') }}" class="inline-flex min-h-11 flex-1 items-center justify-center gap-2 rounded-control bg-slate-100 px-3 text-sm font-bold text-slate-700 hover:bg-slate-200 sm:flex-none">
                                <x-ui.icon name="fa-solid fa-arrow-up" />{{ __('collections.actions.move_up') }}
                            </button>
                            <button type="button" wire:click="moveItem({{ $item->collection_item_id }}, 1)" wire:loading.attr="disabled" aria-label="{{ __('collections.accessibility.reorder_item', ['title' => $item->display_title]) }} — {{ __('collections.actions.move_down') }}" class="inline-flex min-h-11 flex-1 items-center justify-center gap-2 rounded-control bg-slate-100 px-3 text-sm font-bold text-slate-700 hover:bg-slate-200 sm:flex-none">
                                <x-ui.icon name="fa-solid fa-arrow-down" />{{ __('collections.actions.move_down') }}
                            </button>
                            <button type="button" wire:click="removeItem({{ $item->id }})" wire:confirm="{{ __('collections.confirmations.remove_item') }}" wire:loading.attr="disabled" class="inline-flex min-h-11 flex-1 items-center justify-center gap-2 rounded-control bg-rose-50 px-3 text-sm font-bold text-rose-700 hover:bg-rose-100 sm:flex-none">
                                <x-ui.icon name="fa-solid fa-xmark" />{{ __('collections.actions.remove') }}
                            </button>
                        </div>
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
