<div class="space-y-5">
    <x-ui.panel>
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div>
                <h1 class="inline-flex items-center gap-3 text-2xl font-black text-slate-800 sm:text-3xl">
                    <x-ui.icon name="fa-solid fa-tags text-violet-600" />
                    <span>{{ __('tags.personal_page.title') }}</span>
                </h1>
                <p class="mt-2 max-w-3xl text-sm leading-6 text-slate-600">{{ __('tags.personal_page.lead') }}</p>
            </div>
            <a href="{{ route('library.index') }}" class="inline-flex min-h-11 items-center gap-2 rounded-control bg-slate-50 px-3 py-2 text-sm font-bold text-slate-700 hover:bg-violet-50 hover:text-violet-700">
                <x-ui.icon name="fa-solid fa-arrow-left" />
                <span>{{ __('tags.actions.back_library') }}</span>
            </a>
        </div>

        @if ($status !== null)
            <p role="status" aria-live="polite" class="mt-4 rounded-control bg-emerald-50 p-3 text-sm font-bold text-emerald-700">{{ $status }}</p>
        @endif
        @if ($errors->any())
            <div role="alert" aria-live="assertive" class="mt-4 rounded-control bg-rose-50 p-3 text-sm text-rose-800">
                <p class="font-black">{{ __('tags.states.error') }}</p>
                <ul class="mt-2 list-disc space-y-1 pl-5">
                    @foreach ($errors->all() as $message)
                        <li>{{ $message }}</li>
                    @endforeach
                </ul>
            </div>
        @endif
    </x-ui.panel>

    <div class="grid min-w-0 gap-5 lg:grid-cols-[minmax(280px,360px)_minmax(0,1fr)]">
        <div class="min-w-0 space-y-5">
            <x-ui.panel :title="$editingPublicId === null ? __('tags.actions.create') : __('tags.actions.edit')" icon="fa-solid fa-tag">
                @if ($canInteract)
                    <form wire:submit="save" class="space-y-4">
                        <x-form.field for="personal-tag-name" :label="__('tags.fields.name')" wire:model="name" maxlength="80" required />

                        <div>
                            <label for="personal-tag-description" class="block text-sm font-bold text-slate-700">{{ __('tags.fields.description') }}</label>
                            <textarea id="personal-tag-description" wire:model="description" maxlength="1000" rows="4" class="mt-2 min-h-28 w-full rounded-control border border-slate-300 bg-white px-3 py-2 text-base text-slate-800 outline-none focus:border-violet-500 focus:ring-2 focus:ring-violet-100" @error('description') aria-invalid="true" aria-describedby="personal-tag-description-error" @enderror></textarea>
                            @error('description') <p id="personal-tag-description-error" role="alert" class="mt-1 text-sm font-bold text-rose-700">{{ $message }}</p> @enderror
                        </div>

                        <div>
                            <label for="personal-tag-language" class="block text-sm font-bold text-slate-700">{{ __('tags.fields.language') }}</label>
                            <select id="personal-tag-language" wire:model="contentLocale" class="mt-2 min-h-11 w-full rounded-control border border-slate-300 bg-white px-3 py-2 text-base text-slate-800 outline-none focus:border-violet-500 focus:ring-2 focus:ring-violet-100" @error('contentLocale') aria-invalid="true" aria-describedby="personal-tag-language-error" @enderror>
                                <option value="">{{ __('tags.fields.language_unspecified') }}</option>
                                @foreach ($supportedLocales as $locale)
                                    <option value="{{ $locale }}">{{ __('tags.locales.'.$locale) }}</option>
                                @endforeach
                            </select>
                            @error('contentLocale') <p id="personal-tag-language-error" role="alert" class="mt-1 text-sm font-bold text-rose-700">{{ $message }}</p> @enderror
                            <p class="mt-2 text-xs leading-5 text-slate-500">{{ __('tags.personal_page.original_language') }}</p>
                        </div>

                        <div class="flex flex-wrap gap-2">
                            <button type="submit" wire:loading.attr="disabled" wire:target="save" class="inline-flex min-h-11 items-center justify-center gap-2 rounded-control bg-violet-600 px-4 py-2 text-sm font-bold text-white hover:bg-violet-700 disabled:cursor-wait disabled:opacity-60">
                                <x-ui.icon name="fa-solid fa-check" />
                                <span>{{ __('tags.actions.save') }}</span>
                            </button>
                            @if ($editingPublicId !== null)
                                <button type="button" wire:click="cancelEdit" wire:loading.attr="disabled" class="inline-flex min-h-11 items-center justify-center rounded-control bg-slate-50 px-4 py-2 text-sm font-bold text-slate-700 hover:bg-slate-100 disabled:opacity-60">{{ __('tags.actions.cancel') }}</button>
                            @endif
                        </div>
                    </form>
                @else
                    <p class="text-sm font-bold text-amber-700">{{ __('tags.selector.verify_email') }}</p>
                @endif
            </x-ui.panel>

            <x-ui.panel :title="__('tags.personal')" icon="fa-solid fa-lock">
                <x-form.search-field id="personal-tag-manager-search" name="personal_tag_search" :label="__('tags.actions.search')" :placeholder="__('tags.selector.search_placeholder')" wire:model.live.debounce.300ms="search" />
                <div wire:loading.delay wire:target="search" role="status" aria-live="polite" class="mt-2 text-sm font-bold text-violet-700">{{ __('tags.states.loading') }}</div>

                <div class="mt-3 space-y-2">
                    @forelse ($activeTags as $personalTag)
                        <article wire:key="personal-tag-manager-{{ $personalTag->public_id }}" @class([
                            'rounded-control border p-3',
                            'border-violet-200 bg-violet-50' => $selectedPublicId === $personalTag->public_id,
                            'border-slate-200 bg-white' => $selectedPublicId !== $personalTag->public_id,
                        ])>
                            <button type="button" wire:click="selectTag('{{ $personalTag->public_id }}')" class="flex min-h-11 w-full min-w-0 items-center justify-between gap-3 text-left focus-visible:outline-none focus-visible:ring-4 focus-visible:ring-violet-200">
                                <span class="min-w-0 break-words text-sm font-black text-slate-800">{{ $personalTag->name }}</span>
                                <span class="shrink-0 text-xs font-bold tabular-nums text-slate-500">{{ $personalTag->catalog_titles_count }}</span>
                            </button>
                            @if ($canInteract)
                                <div class="mt-2 flex flex-wrap gap-2 border-t border-slate-200 pt-2">
                                    <button type="button" wire:click="startEdit('{{ $personalTag->public_id }}')" aria-label="{{ __('tags.accessibility.edit', ['tag' => $personalTag->name]) }}" class="inline-flex min-h-11 items-center gap-2 rounded-control bg-white px-3 py-2 text-xs font-bold text-slate-700 hover:text-violet-700">
                                        <x-ui.icon name="fa-solid fa-pen" />
                                        <span>{{ __('tags.actions.edit') }}</span>
                                    </button>
                                    <button type="button" wire:click="deleteTag('{{ $personalTag->public_id }}')" wire:confirm="{{ __('tags.personal_page.delete_confirm') }}" wire:loading.attr="disabled" wire:target="deleteTag('{{ $personalTag->public_id }}')" aria-label="{{ __('tags.accessibility.delete', ['tag' => $personalTag->name]) }}" class="inline-flex min-h-11 items-center gap-2 rounded-control bg-rose-50 px-3 py-2 text-xs font-bold text-rose-700 hover:bg-rose-100 disabled:cursor-wait disabled:opacity-60">
                                        <x-ui.icon name="fa-solid fa-trash" />
                                        <span>{{ __('tags.actions.delete') }}</span>
                                    </button>
                                </div>
                            @endif
                        </article>
                    @empty
                        <p class="rounded-control border border-dashed border-slate-200 p-4 text-sm text-slate-500">{{ __('tags.personal_page.empty') }}</p>
                    @endforelse
                </div>
            </x-ui.panel>

            @if ($canInteract && $restorableTags->isNotEmpty())
                <x-ui.panel :title="__('tags.actions.restore')" icon="fa-solid fa-clock-rotate-left">
                    <div class="space-y-2">
                        @foreach ($restorableTags as $deletedTag)
                            <div wire:key="restorable-personal-tag-{{ $deletedTag->public_id }}" class="flex flex-wrap items-center justify-between gap-3 rounded-control border border-slate-200 p-3">
                                <span class="min-w-0 break-words text-sm font-bold text-slate-700">{{ $deletedTag->name }}</span>
                                <button type="button" wire:click="restoreTag('{{ $deletedTag->public_id }}')" wire:loading.attr="disabled" wire:target="restoreTag('{{ $deletedTag->public_id }}')" class="inline-flex min-h-11 items-center gap-2 rounded-control bg-emerald-50 px-3 py-2 text-xs font-bold text-emerald-700 hover:bg-emerald-100 disabled:cursor-wait disabled:opacity-60">
                                    <x-ui.icon name="fa-solid fa-clock-rotate-left" />
                                    <span>{{ __('tags.actions.restore') }}</span>
                                </button>
                            </div>
                        @endforeach
                    </div>
                </x-ui.panel>
            @endif
        </div>

        <section class="min-w-0" aria-labelledby="personal-tag-assignments-title">
            <x-ui.panel :pad="false">
                <div class="border-b border-slate-200 px-4 py-3">
                    <h2 id="personal-tag-assignments-title" class="inline-flex min-w-0 items-center gap-2 text-lg font-black text-slate-800">
                        <x-ui.icon name="fa-solid fa-clapperboard text-violet-600" />
                        <span class="min-w-0 break-words">{{ $selectedTag?->name ?? __('tags.personal_page.select_tag') }}</span>
                    </h2>
                </div>

                @if ($selectedTag !== null && $taggedTitles !== null)
                    <div class="divide-y divide-slate-200">
                        @forelse ($taggedTitles as $catalogTitle)
                            <article wire:key="personal-tag-title-{{ $selectedTag->public_id }}-{{ $catalogTitle->id }}" class="relative">
                                <x-catalog.title-card :title="$catalogTitle" layout="list" readable />
                                @if ($canInteract)
                                    <div class="px-4 pb-3 sm:pl-36">
                                        <button type="button" wire:click="removeAssignment('{{ $selectedTag->public_id }}', {{ $catalogTitle->id }})" wire:loading.attr="disabled" wire:target="removeAssignment('{{ $selectedTag->public_id }}', {{ $catalogTitle->id }})" aria-label="{{ __('tags.accessibility.remove', ['tag' => $selectedTag->name]) }}" class="inline-flex min-h-11 items-center gap-2 rounded-control bg-rose-50 px-3 py-2 text-xs font-bold text-rose-700 hover:bg-rose-100 disabled:cursor-wait disabled:opacity-60">
                                            <x-ui.icon name="fa-solid fa-tag" />
                                            <span>{{ __('tags.actions.remove') }}</span>
                                        </button>
                                    </div>
                                @endif
                            </article>
                        @empty
                            <p class="p-5 text-sm text-slate-500">{{ __('tags.personal_page.empty_assignments') }}</p>
                        @endforelse
                    </div>
                    @if ($taggedTitles->hasPages())
                        <div class="border-t border-slate-200 p-4">{{ $taggedTitles->links() }}</div>
                    @endif
                @else
                    <p class="p-5 text-sm text-slate-500">{{ __('tags.personal_page.select_tag') }}</p>
                @endif
            </x-ui.panel>
        </section>
    </div>
</div>
