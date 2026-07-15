<section aria-labelledby="personal-tag-selector-title-{{ $catalogTitleId }}" class="rounded-control border border-slate-200 bg-white p-3">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div class="min-w-0">
            <h2 id="personal-tag-selector-title-{{ $catalogTitleId }}" class="inline-flex items-center gap-2 text-sm font-black text-slate-700">
                <x-ui.icon name="fa-solid fa-tags text-violet-600" />
                <span>{{ __('tags.selector.title') }}</span>
            </h2>
            <p class="mt-1 text-xs text-slate-500">{{ __('tags.personal_page.lead') }}</p>
        </div>

        @if ($canInteract)
            <button type="button" wire:click="open" wire:loading.attr="disabled" wire:target="open" class="inline-flex min-h-11 items-center gap-2 rounded-control bg-violet-50 px-3 py-2 text-sm font-bold text-violet-700 hover:bg-violet-100 disabled:cursor-wait disabled:opacity-60">
                <x-ui.icon name="fa-solid fa-pen-to-square" />
                <span>{{ __('tags.selector.open') }}</span>
            </button>
        @elseif (! $isAuthenticated)
            <a href="{{ route('login') }}" class="inline-flex min-h-11 items-center gap-2 rounded-control bg-slate-50 px-3 py-2 text-sm font-bold text-slate-700 hover:bg-violet-50 hover:text-violet-700">
                <x-ui.icon name="fa-solid fa-right-to-bracket" />
                <span>{{ __('tags.actions.login') }}</span>
            </a>
        @else
            <span class="text-xs font-bold text-amber-700">{{ __('tags.selector.verify_email') }}</span>
        @endif
    </div>

    @if ($assignedTags->isNotEmpty())
        <div class="mt-3 flex flex-wrap gap-2">
            @foreach ($assignedTags as $personalTag)
                <span wire:key="assigned-personal-tag-{{ $personalTag->public_id }}" aria-label="{{ __('tags.accessibility.personal_badge', ['tag' => $personalTag->name]) }}" class="inline-flex min-h-9 max-w-full items-center gap-2 rounded-full border border-violet-100 bg-violet-50 px-3 py-1.5 text-xs font-bold text-violet-800">
                    <x-ui.icon name="fa-solid fa-lock" />
                    <span class="min-w-0 break-words">{{ $personalTag->name }}</span>
                    <span class="sr-only">{{ __('tags.private') }}</span>
                </span>
            @endforeach
        </div>
    @endif

    @if ($status !== null)
        <p role="status" aria-live="polite" class="mt-3 text-sm font-bold text-emerald-700">{{ $status }}</p>
    @endif

    @if ($isOpen && $canInteract)
        <div class="mt-4 border-t border-slate-200 pt-4">
            <div class="grid gap-3 sm:grid-cols-[minmax(0,1fr)_auto]">
                <x-form.search-field
                    id="personal-tag-search-{{ $catalogTitleId }}"
                    name="personal_tag_search"
                    :label="__('tags.actions.search')"
                    :placeholder="__('tags.selector.search_placeholder')"
                    wire:model.live.debounce.300ms="search"
                    role="combobox"
                    aria-autocomplete="list"
                    aria-controls="personal-tag-options-{{ $catalogTitleId }}"
                    aria-expanded="true"
                />
                <a href="{{ route('personal-tags.index') }}" class="inline-flex min-h-11 items-center justify-center gap-2 rounded-control bg-slate-50 px-3 py-2 text-sm font-bold text-slate-700 hover:bg-violet-50 hover:text-violet-700">
                    <x-ui.icon name="fa-solid fa-gear" />
                    <span>{{ __('tags.actions.edit') }}</span>
                </a>
            </div>

            <div wire:loading.delay wire:target="search" role="status" aria-live="polite" class="mt-2 text-sm font-bold text-violet-700">
                <x-ui.icon name="fa-solid fa-spinner fa-spin" />
                <span>{{ __('tags.selector.loading') }}</span>
            </div>

            <div id="personal-tag-options-{{ $catalogTitleId }}" role="listbox" aria-multiselectable="true" class="mt-3 grid max-h-64 gap-2 overflow-y-auto sm:grid-cols-2">
                @forelse ($allTags as $personalTag)
                    <button
                        type="button"
                        role="option"
                        aria-selected="{{ in_array($personalTag->public_id, $draftPublicIds, true) ? 'true' : 'false' }}"
                        wire:key="personal-tag-option-{{ $personalTag->public_id }}"
                        wire:click="toggle('{{ $personalTag->public_id }}')"
                        @class([
                            'flex min-h-11 min-w-0 items-center justify-between gap-3 rounded-control border px-3 py-2 text-left text-sm font-bold focus-visible:outline-none focus-visible:ring-4 focus-visible:ring-violet-200',
                            'border-violet-200 bg-violet-50 text-violet-800' => in_array($personalTag->public_id, $draftPublicIds, true),
                            'border-slate-200 bg-white text-slate-700 hover:bg-slate-50' => ! in_array($personalTag->public_id, $draftPublicIds, true),
                        ])
                    >
                        <span class="min-w-0 break-words">{{ $personalTag->name }}</span>
                        <x-ui.icon :name="in_array($personalTag->public_id, $draftPublicIds, true) ? 'fa-solid fa-circle-check text-violet-700' : 'fa-regular fa-circle text-slate-400'" />
                    </button>
                @empty
                    <p class="text-sm text-slate-500">{{ __('tags.selector.no_results') }}</p>
                @endforelse
            </div>
            <p class="mt-2 text-xs font-bold text-slate-500" role="status" aria-live="polite">{{ __('tags.selector.selected', ['count' => count($draftPublicIds)]) }}</p>
            @error('tags') <p role="alert" class="mt-2 text-sm font-bold text-rose-700">{{ $message }}</p> @enderror

            <form wire:submit="createAndSelect" class="mt-4 grid gap-2 sm:grid-cols-[minmax(0,1fr)_auto]">
                <x-form.field
                    for="new-personal-tag-{{ $catalogTitleId }}"
                    :label="__('tags.actions.create')"
                    :placeholder="__('tags.selector.create_placeholder')"
                    wire:model="newName"
                    maxlength="80"
                />
                <button type="submit" wire:loading.attr="disabled" wire:target="createAndSelect" class="inline-flex min-h-11 items-center justify-center gap-2 self-end rounded-control bg-violet-50 px-4 py-2 text-sm font-bold text-violet-700 hover:bg-violet-100 disabled:cursor-wait disabled:opacity-60">
                    <x-ui.icon name="fa-solid fa-plus" />
                    <span>{{ __('tags.actions.create') }}</span>
                </button>
            </form>
            <div class="mt-4 flex flex-wrap justify-end gap-2 border-t border-slate-200 pt-4">
                <button type="button" wire:click="cancel" wire:loading.attr="disabled" class="inline-flex min-h-11 items-center justify-center rounded-control bg-slate-50 px-4 py-2 text-sm font-bold text-slate-700 hover:bg-slate-100 disabled:opacity-60">{{ __('tags.actions.cancel') }}</button>
                <button type="button" wire:click="apply" wire:loading.attr="disabled" wire:target="apply" class="inline-flex min-h-11 items-center justify-center gap-2 rounded-control bg-violet-600 px-4 py-2 text-sm font-bold text-white hover:bg-violet-700 disabled:cursor-wait disabled:opacity-60">
                    <x-ui.icon name="fa-solid fa-check" />
                    <span wire:loading.remove wire:target="apply">{{ __('tags.actions.apply') }}</span>
                    <span wire:loading wire:target="apply">{{ __('tags.selector.saving') }}</span>
                </button>
            </div>
        </div>
    @endif
</section>
