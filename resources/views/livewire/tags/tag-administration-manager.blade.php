<div class="space-y-5">
    <x-ui.panel>
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div>
                <h1 class="inline-flex items-center gap-3 text-2xl font-black text-slate-800 sm:text-3xl">
                    <x-ui.icon name="fa-solid fa-tags text-emerald-700" />
                    <span>{{ __('tags.admin.title') }}</span>
                </h1>
                <p class="mt-2 max-w-3xl text-sm leading-6 text-slate-600">{{ __('tags.admin.lead') }}</p>
            </div>
            <div class="flex flex-wrap gap-2">
                <a href="{{ route('admin.catalog') }}" class="inline-flex min-h-11 items-center gap-2 rounded-control bg-slate-50 px-3 py-2 text-sm font-bold text-slate-700 hover:bg-emerald-50 hover:text-emerald-700">
                    <x-ui.icon name="fa-solid fa-clapperboard" />
                    <span>{{ __('tags.admin.catalog') }}</span>
                </a>
                <button type="button" wire:click="newTag" wire:loading.attr="disabled" wire:target="newTag" class="inline-flex min-h-11 items-center gap-2 rounded-control bg-emerald-50 px-3 py-2 text-sm font-bold text-emerald-700 hover:bg-emerald-100 disabled:cursor-wait disabled:opacity-50">
                    <x-ui.icon name="fa-solid fa-plus" />
                    <span>{{ __('tags.admin.create') }}</span>
                </button>
            </div>
        </div>
        @if ($notice !== null)
            <p role="status" aria-live="polite" class="mt-4 rounded-control bg-emerald-50 p-3 text-sm font-bold text-emerald-700">{{ $notice }}</p>
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

    <div class="grid min-w-0 gap-5 xl:grid-cols-[340px_minmax(0,1fr)]">
        <x-ui.panel :title="__('tags.title')" icon="fa-solid fa-list" :pad="false">
            <div class="space-y-3 border-b border-slate-200 p-3">
                <x-form.search-field id="admin-tag-search" name="admin_tag_search" :label="__('tags.actions.search')" :placeholder="__('tags.admin.search_placeholder')" wire:model.live.debounce.350ms="search" />
                <label for="admin-tag-moderation" class="sr-only">{{ __('tags.fields.moderation') }}</label>
                <select id="admin-tag-moderation" wire:model.live="moderationFilter" class="min-h-11 w-full rounded-control border border-slate-300 bg-white px-3 py-2 text-sm text-slate-800">
                    <option value="">{{ __('tags.admin.all_statuses') }}</option>
                    @foreach ($moderationStatuses as $moderationStatus)
                        <option value="{{ $moderationStatus->value }}">{{ $moderationStatus->label() }}</option>
                    @endforeach
                </select>
            </div>
            <div wire:loading.delay wire:target="search,moderationFilter" role="status" aria-live="polite" class="p-3 text-sm font-bold text-emerald-700">{{ __('tags.states.loading') }}</div>
            <div class="max-h-[70vh] divide-y divide-slate-200 overflow-y-auto">
                @forelse ($tagsPage as $tagRow)
                    <button type="button" wire:key="admin-tag-row-{{ $tagRow->public_id }}" wire:click="selectTag('{{ $tagRow->public_id }}')" @class([
                        'block min-h-16 w-full min-w-0 px-3 py-3 text-left hover:bg-emerald-50 focus-visible:outline-none focus-visible:ring-4 focus-visible:ring-emerald-200',
                        'bg-emerald-50' => $selectedPublicId === $tagRow->public_id,
                    ])>
                        <span class="flex min-w-0 items-start justify-between gap-3">
                            <span class="min-w-0">
                                <span class="block break-words text-sm font-black text-slate-800">{{ $tagRow->name }}</span>
                                <span class="mt-1 block break-all text-xs text-slate-500">{{ $tagRow->slug }}</span>
                            </span>
                            <span class="shrink-0 rounded-full bg-slate-100 px-2 py-1 text-xs font-bold tabular-nums text-slate-600">{{ $tagRow->catalog_titles_count }}</span>
                        </span>
                        <span class="mt-2 flex flex-wrap gap-1.5 text-[11px] font-bold">
                            <span class="rounded-full bg-slate-100 px-2 py-1 text-slate-600">{{ $tagRow->type->label() }}</span>
                            <span @class([
                                'rounded-full px-2 py-1',
                                'bg-emerald-100 text-emerald-700' => $tagRow->moderation_status->value === 'approved',
                                'bg-amber-100 text-amber-800' => $tagRow->moderation_status->value === 'pending',
                                'bg-rose-100 text-rose-700' => in_array($tagRow->moderation_status->value, ['rejected', 'hidden'], true),
                                'bg-slate-200 text-slate-700' => in_array($tagRow->moderation_status->value, ['merged', 'archived'], true),
                            ])>{{ $tagRow->moderation_status->label() }}</span>
                        </span>
                    </button>
                @empty
                    <p class="p-4 text-sm text-slate-500">{{ __('tags.states.empty') }}</p>
                @endforelse
            </div>
            @if ($tagsPage->hasPages())
                <div class="border-t border-slate-200 p-3">{{ $tagsPage->links() }}</div>
            @endif
        </x-ui.panel>

        <div class="min-w-0 space-y-5">
            <x-ui.panel :title="$selectedTag === null ? __('tags.admin.create') : $selectedTag->name" icon="fa-solid fa-tag">
                @if ($selectedTag?->merged_into_id !== null)
                    <div role="status" class="mb-4 rounded-control bg-amber-50 p-3 text-sm font-bold text-amber-800">
                        {{ __('tags.admin.merged_into', ['tag' => $selectedTag->mergedInto?->name]) }}
                    </div>
                @endif

                <form wire:submit="saveTag" class="space-y-4">
                    <div class="grid gap-4 sm:grid-cols-2">
                        <x-form.field for="admin-tag-name" :label="__('tags.fields.name')" wire:model="tagForm.name" maxlength="80" required />
                        <x-form.field for="admin-tag-code" :label="__('tags.fields.code')" wire:model="tagForm.code" maxlength="120" :disabled="$selectedTag?->code !== null" />
                        <x-form.field for="admin-tag-slug" :label="__('tags.fields.slug')" wire:model="tagForm.slug" maxlength="180" />
                        <label class="text-sm font-bold text-slate-700">{{ __('tags.fields.type') }}
                            <select wire:model="tagForm.type" class="mt-2 min-h-11 w-full rounded-control border border-slate-300 bg-white px-3 py-2 font-normal">
                                @foreach ($tagTypes as $tagType)
                                    <option value="{{ $tagType->value }}">{{ $tagType->label() }}</option>
                                @endforeach
                            </select>
                        </label>
                        <label class="text-sm font-bold text-slate-700">{{ __('tags.fields.visibility') }}
                            <select wire:model="tagForm.visibility" class="mt-2 min-h-11 w-full rounded-control border border-slate-300 bg-white px-3 py-2 font-normal">
                                @foreach ($visibilities as $visibility)
                                    <option value="{{ $visibility->value }}">{{ $visibility->label() }}</option>
                                @endforeach
                            </select>
                        </label>
                        <label class="text-sm font-bold text-slate-700">{{ __('tags.fields.moderation') }}
                            <select wire:model="tagForm.moderation_status" class="mt-2 min-h-11 w-full rounded-control border border-slate-300 bg-white px-3 py-2 font-normal">
                                @foreach ($moderationStatuses as $moderationStatus)
                                    @if (! in_array($moderationStatus->value, ['merged', 'archived'], true) || $selectedTag?->moderation_status === $moderationStatus)
                                        <option value="{{ $moderationStatus->value }}">{{ $moderationStatus->label() }}</option>
                                    @endif
                                @endforeach
                            </select>
                        </label>
                        <label class="text-sm font-bold text-slate-700">{{ __('tags.fields.source') }}
                            <select wire:model="tagForm.source" class="mt-2 min-h-11 w-full rounded-control border border-slate-300 bg-white px-3 py-2 font-normal">
                                @foreach ($tagSources as $tagSource)
                                    <option value="{{ $tagSource->value }}">{{ __('tags.sources.'.$tagSource->value) }}</option>
                                @endforeach
                            </select>
                        </label>
                    </div>

                    @error('tagForm') <p role="alert" class="text-sm font-bold text-rose-700">{{ $message }}</p> @enderror
                    @error('name') <p role="alert" class="text-sm font-bold text-rose-700">{{ $message }}</p> @enderror
                    @error('code') <p role="alert" class="text-sm font-bold text-rose-700">{{ $message }}</p> @enderror
                    @error('slug') <p role="alert" class="text-sm font-bold text-rose-700">{{ $message }}</p> @enderror

                    <div class="flex flex-wrap gap-2">
                        <button type="submit" wire:loading.attr="disabled" wire:target="saveTag" @disabled($selectedTag?->merged_into_id !== null || $selectedTag?->archived_at !== null) class="inline-flex min-h-11 items-center gap-2 rounded-control bg-emerald-700 px-4 py-2 text-sm font-bold text-white hover:bg-emerald-600 disabled:cursor-not-allowed disabled:opacity-50">
                            <x-ui.icon name="fa-solid fa-floppy-disk" />
                            <span>{{ __('tags.actions.save') }}</span>
                        </button>
                        @if ($selectedTag !== null && $selectedTag->merged_into_id === null)
                            @if ($selectedTag->archived_at === null)
                                <button type="button" wire:click="archiveTag" wire:confirm="{{ __('tags.admin.archive_confirm') }}" wire:loading.attr="disabled" wire:target="archiveTag" class="inline-flex min-h-11 items-center gap-2 rounded-control bg-rose-50 px-4 py-2 text-sm font-bold text-rose-700 hover:bg-rose-100 disabled:cursor-wait disabled:opacity-50">
                                    <x-ui.icon name="fa-solid fa-box-archive" />
                                    <span>{{ __('tags.actions.archive') }}</span>
                                </button>
                            @else
                                <button type="button" wire:click="restoreTag" wire:loading.attr="disabled" wire:target="restoreTag" class="inline-flex min-h-11 items-center gap-2 rounded-control bg-emerald-50 px-4 py-2 text-sm font-bold text-emerald-700 hover:bg-emerald-100 disabled:cursor-wait disabled:opacity-50">
                                    <x-ui.icon name="fa-solid fa-clock-rotate-left" />
                                    <span>{{ __('tags.actions.restore') }}</span>
                                </button>
                            @endif
                        @endif
                    </div>
                </form>
            </x-ui.panel>

            @if ($selectedTag !== null)
                <x-ui.panel :title="__('tags.admin.usage')" icon="fa-solid fa-chart-simple">
                    <dl class="grid gap-3 sm:grid-cols-4">
                        <div class="rounded-control bg-slate-50 p-3"><dt class="text-xs font-bold text-slate-500">{{ __('tags.admin.serial_count') }}</dt><dd class="mt-1 text-xl font-black tabular-nums text-slate-800">{{ $selectedTag->catalog_titles_count }}</dd></div>
                        <div class="rounded-control bg-slate-50 p-3"><dt class="text-xs font-bold text-slate-500">{{ __('tags.fields.aliases') }}</dt><dd class="mt-1 text-xl font-black tabular-nums text-slate-800">{{ $selectedTag->aliases_count }}</dd></div>
                        <div class="rounded-control bg-slate-50 p-3"><dt class="text-xs font-bold text-slate-500">{{ __('tags.admin.translations') }}</dt><dd class="mt-1 text-xl font-black tabular-nums text-slate-800">{{ $selectedTag->translations_count }}</dd></div>
                        <div class="rounded-control bg-slate-50 p-3"><dt class="text-xs font-bold text-slate-500">{{ __('tags.admin.provider_mappings') }}</dt><dd class="mt-1 text-xl font-black tabular-nums text-slate-800">{{ $selectedTag->provider_mappings_count }}</dd></div>
                    </dl>
                </x-ui.panel>

                @if ($selectedTag->merged_into_id === null && $selectedTag->archived_at === null)
                    <x-ui.panel :title="__('tags.admin.translations')" icon="fa-solid fa-language">
                        <div class="space-y-5">
                            @foreach ($supportedLocales as $locale)
                                <form wire:key="admin-tag-translation-{{ $locale }}" wire:submit="saveTranslation('{{ $locale }}')" class="space-y-3 rounded-control border border-slate-200 p-3">
                                    <h3 class="text-sm font-black text-slate-800">{{ __('tags.locales.'.$locale) }}</h3>
                                    <x-form.field for="admin-tag-label-{{ $locale }}" :label="__('tags.fields.name')" wire:model="translationForms.{{ $locale }}.label" maxlength="80" required />
                                    <x-form.field for="admin-tag-short-description-{{ $locale }}" :label="__('tags.admin.short_description')" wire:model="translationForms.{{ $locale }}.short_description" maxlength="500" />
                                    <div>
                                        <label for="admin-tag-description-{{ $locale }}" class="block text-sm font-bold text-slate-700">{{ __('tags.fields.description') }}</label>
                                        <textarea id="admin-tag-description-{{ $locale }}" wire:model="translationForms.{{ $locale }}.description" maxlength="10000" rows="5" @if ($errors->has('translationForms.'.$locale.'.description')) aria-invalid="true" aria-describedby="admin-tag-description-{{ $locale }}-error" @endif class="mt-2 min-h-32 w-full rounded-control border border-slate-300 bg-white px-3 py-2 text-sm text-slate-800"></textarea>
                                        <x-form.input-error :for="'translationForms.'.$locale.'.description'" :id="'admin-tag-description-'.$locale.'-error'" />
                                    </div>
                                    <div class="grid gap-3 sm:grid-cols-2">
                                        <x-form.field for="admin-tag-seo-title-{{ $locale }}" :label="__('tags.admin.seo_title')" wire:model="translationForms.{{ $locale }}.seo_title" maxlength="180" />
                                        <x-form.field for="admin-tag-seo-description-{{ $locale }}" :label="__('tags.admin.seo_description')" wire:model="translationForms.{{ $locale }}.seo_description" maxlength="320" />
                                    </div>
                                    <button type="submit" wire:loading.attr="disabled" wire:target="saveTranslation('{{ $locale }}')" class="inline-flex min-h-11 items-center gap-2 rounded-control bg-emerald-50 px-3 py-2 text-sm font-bold text-emerald-700 hover:bg-emerald-100 disabled:cursor-wait disabled:opacity-50">
                                        <x-ui.icon name="fa-solid fa-floppy-disk" />
                                        <span>{{ __('tags.actions.save') }}</span>
                                    </button>
                                </form>
                            @endforeach
                        </div>
                    </x-ui.panel>

                    <div class="grid min-w-0 gap-5 lg:grid-cols-2">
                        <x-ui.panel :title="__('tags.fields.aliases')" icon="fa-solid fa-spell-check">
                            <div class="space-y-2">
                                @foreach ($selectedTag->aliases as $alias)
                                    <div wire:key="admin-tag-alias-{{ $alias->id }}" class="flex flex-wrap items-center justify-between gap-2 rounded-control bg-slate-50 p-2">
                                        <span class="min-w-0 break-words text-sm font-bold text-slate-700">
                                            {{ $alias->name }}
                                            <span class="text-xs text-slate-400">{{ $alias->locale }}</span>
                                            <span class="mt-1 block text-xs font-bold text-slate-500">{{ $alias->moderation_status->label() }}</span>
                                        </span>
                                        <span class="flex flex-wrap items-center gap-1">
                                            @if ($alias->source->value === 'provider' || $alias->moderation_status->value !== 'approved')
                                                <button type="button" wire:click="moderateAlias({{ $alias->id }}, 'approved')" wire:loading.attr="disabled" wire:target="moderateAlias({{ $alias->id }}, 'approved')" aria-label="{{ __('tags.actions.approve') }}" class="inline-flex min-h-11 min-w-11 items-center justify-center rounded-control text-emerald-700 hover:bg-emerald-100 disabled:opacity-50"><x-ui.icon name="fa-solid fa-check" /></button>
                                                <button type="button" wire:click="moderateAlias({{ $alias->id }}, 'rejected')" wire:loading.attr="disabled" wire:target="moderateAlias({{ $alias->id }}, 'rejected')" aria-label="{{ __('tags.actions.reject') }}" class="inline-flex min-h-11 min-w-11 items-center justify-center rounded-control text-amber-800 hover:bg-amber-100 disabled:opacity-50"><x-ui.icon name="fa-solid fa-ban" /></button>
                                            @endif
                                            <button type="button" wire:click="removeAlias({{ $alias->id }})" wire:loading.attr="disabled" wire:target="removeAlias({{ $alias->id }})" aria-label="{{ __('tags.actions.remove') }}" class="inline-flex min-h-11 min-w-11 items-center justify-center rounded-control text-rose-700 hover:bg-rose-50 disabled:opacity-50"><x-ui.icon name="fa-solid fa-xmark" /></button>
                                        </span>
                                    </div>
                                @endforeach
                            </div>
                            <form wire:submit="addAlias" class="mt-3 grid gap-2 sm:grid-cols-[minmax(0,1fr)_110px_auto] sm:items-end">
                                <x-form.field for="admin-tag-alias-name" :label="__('tags.fields.aliases')" wire:model="aliasName" maxlength="80" required />
                                <label class="text-sm font-bold text-slate-700">{{ __('tags.fields.language') }}
                                    <select wire:model="aliasLocale" @if ($errors->has('aliasLocale')) aria-invalid="true" aria-describedby="admin-tag-alias-locale-error" @endif class="mt-2 min-h-11 w-full rounded-control border border-slate-300 bg-white px-2 py-2 font-normal">
                                        <option value="und">—</option>
                                        @foreach ($supportedLocales as $locale)<option value="{{ $locale }}">{{ $locale }}</option>@endforeach
                                    </select>
                                    <x-form.input-error for="aliasLocale" id="admin-tag-alias-locale-error" />
                                </label>
                                <button type="submit" wire:loading.attr="disabled" wire:target="addAlias" class="inline-flex min-h-11 items-center justify-center rounded-control bg-emerald-50 px-3 py-2 text-sm font-bold text-emerald-700 hover:bg-emerald-100 disabled:cursor-wait disabled:opacity-50">{{ __('tags.actions.add') }}</button>
                            </form>
                            @error('alias') <p role="alert" class="mt-2 text-sm font-bold text-rose-700">{{ $message }}</p> @enderror
                        </x-ui.panel>

                        <x-ui.panel :title="__('tags.fields.synonyms')" icon="fa-solid fa-diagram-project">
                            <div class="space-y-2">
                                @foreach ($selectedTag->displaySynonyms as $synonym)
                                    <div wire:key="admin-tag-synonym-{{ $synonym->id }}" class="flex flex-wrap items-center justify-between gap-2 rounded-control bg-slate-50 p-2">
                                        <span class="min-w-0 break-words text-sm font-bold text-slate-700">{{ $synonym->display_related_name }}</span>
                                        <button type="button" wire:click="removeRelationship({{ $synonym->id }})" wire:loading.attr="disabled" wire:target="removeRelationship({{ $synonym->id }})" aria-label="{{ __('tags.actions.remove') }}" class="inline-flex min-h-11 min-w-11 items-center justify-center rounded-control text-rose-700 hover:bg-rose-50 disabled:opacity-50"><x-ui.icon name="fa-solid fa-xmark" /></button>
                                    </div>
                                @endforeach
                            </div>
                            <x-form.search-field id="admin-tag-relationship-search" name="admin_tag_relationship_search" :label="__('tags.actions.search')" :placeholder="__('tags.admin.relationship_search')" wire:model.live.debounce.350ms="relationshipSearch" container-class="mt-3" />
                            <div class="mt-2 grid gap-1">
                                @foreach ($relationshipCandidates as $candidate)
                                    <button type="button" wire:key="admin-tag-relationship-candidate-{{ $candidate->public_id }}" wire:click="$set('relationshipTarget', '{{ $candidate->public_id }}')" @class(['min-h-11 rounded-control px-3 py-2 text-left text-sm font-bold', 'bg-emerald-50 text-emerald-700' => $relationshipTarget === $candidate->public_id, 'bg-slate-50 text-slate-700' => $relationshipTarget !== $candidate->public_id])>{{ $candidate->name }}</button>
                                @endforeach
                            </div>
                            <button type="button" wire:click="addRelationship" wire:loading.attr="disabled" wire:target="addRelationship" @disabled($relationshipTarget === null) class="mt-3 inline-flex min-h-11 items-center rounded-control bg-emerald-50 px-3 py-2 text-sm font-bold text-emerald-700 hover:bg-emerald-100 disabled:cursor-wait disabled:opacity-50">{{ __('tags.actions.add') }}</button>
                        </x-ui.panel>
                    </div>

                    <x-ui.panel :title="__('tags.admin.assignments')" icon="fa-solid fa-clapperboard">
                        <x-form.search-field id="admin-tag-title-search" name="admin_tag_title_search" :label="__('tags.admin.title_search')" :placeholder="__('tags.admin.title_search')" wire:model.live.debounce.350ms="titleSearch" />
                        @if ($titleOptions->isNotEmpty())
                            <div class="mt-2 grid gap-1 sm:grid-cols-2">
                                @foreach ($titleOptions as $titleOption)
                                    <button type="button" wire:key="admin-tag-title-option-{{ $titleOption->id }}" wire:click="assignTitle({{ $titleOption->id }})" wire:loading.attr="disabled" wire:target="assignTitle({{ $titleOption->id }})" class="min-h-11 min-w-0 rounded-control bg-emerald-50 px-3 py-2 text-left text-sm font-bold text-emerald-800 hover:bg-emerald-100 disabled:cursor-wait disabled:opacity-50"><span class="break-words">{{ $titleOption->title }}</span></button>
                                @endforeach
                            </div>
                        @endif
                        <div class="mt-4 grid gap-2 sm:grid-cols-2">
                            @forelse ($assignedTitles as $assignedTitle)
                                <div wire:key="admin-tag-assigned-title-{{ $assignedTitle->id }}" class="flex min-w-0 items-center justify-between gap-3 rounded-control border border-slate-200 p-3">
                                    @if ($assignedTitle->trashed())
                                        <span class="min-w-0 break-words text-sm font-bold text-slate-500">{{ $assignedTitle->title }} · {{ __('tags.admin.deleted_title') }}</span>
                                    @else
                                        <a href="{{ route('titles.show', $assignedTitle) }}" class="min-w-0 break-words text-sm font-bold text-slate-700 hover:text-emerald-700">{{ $assignedTitle->title }}</a>
                                    @endif
                                    <button type="button" wire:click="removeTitle({{ $assignedTitle->id }})" wire:confirm="{{ __('tags.admin.remove_assignment_confirm') }}" wire:loading.attr="disabled" wire:target="removeTitle({{ $assignedTitle->id }})" aria-label="{{ __('tags.actions.remove') }}" class="inline-flex min-h-11 min-w-11 shrink-0 items-center justify-center rounded-control text-rose-700 hover:bg-rose-50 disabled:opacity-50"><x-ui.icon name="fa-solid fa-xmark" /></button>
                                </div>
                            @empty
                                <p class="text-sm text-slate-500">{{ __('tags.admin.no_assignments') }}</p>
                            @endforelse
                        </div>
                    </x-ui.panel>

                    <x-ui.panel :title="__('tags.actions.merge')" icon="fa-solid fa-code-merge">
                        <p class="text-sm leading-6 text-slate-600">{{ __('tags.admin.merge_preview') }}</p>
                        <x-form.search-field id="admin-tag-merge-search" name="admin_tag_merge_search" :label="__('tags.actions.search')" :placeholder="__('tags.admin.merge_search')" wire:model.live.debounce.350ms="mergeSearch" container-class="mt-3" />
                        <div class="mt-2 grid gap-2 sm:grid-cols-2">
                            @foreach ($mergeCandidates as $candidate)
                                <button type="button" wire:key="admin-tag-merge-candidate-{{ $candidate->public_id }}" wire:click="$set('mergeTarget', '{{ $candidate->public_id }}')" @class(['min-h-16 rounded-control border p-3 text-left', 'border-amber-300 bg-amber-50' => $mergeTarget === $candidate->public_id, 'border-slate-200 bg-white' => $mergeTarget !== $candidate->public_id])>
                                    <span class="block break-words text-sm font-black text-slate-800">{{ $candidate->name }}</span>
                                    <span class="mt-1 block break-all text-xs text-slate-500">{{ $candidate->slug }} · {{ $candidate->moderation_status->label() }}</span>
                                    <span class="mt-1 block text-xs text-slate-500">{{ __('tags.admin.merge_counts', ['titles' => $candidate->catalog_titles_count, 'translations' => $candidate->translations_count, 'aliases' => $candidate->aliases_count, 'providers' => $candidate->provider_mappings_count]) }}</span>
                                </button>
                            @endforeach
                        </div>
                        <button type="button" wire:click="mergeTag" wire:confirm="{{ __('tags.admin.merge_confirm') }}" wire:loading.attr="disabled" wire:target="mergeTag" @disabled($mergeTarget === null) class="mt-3 inline-flex min-h-11 items-center gap-2 rounded-control bg-amber-100 px-4 py-2 text-sm font-bold text-amber-900 hover:bg-amber-200 disabled:cursor-wait disabled:opacity-50">
                            <x-ui.icon name="fa-solid fa-code-merge" />
                            <span>{{ __('tags.actions.merge') }}</span>
                        </button>
                    </x-ui.panel>
                @endif

                @if ($selectedTag->providerMappings->isNotEmpty() || $selectedTag->historicalSlugs->isNotEmpty())
                    <x-ui.panel :title="__('tags.admin.compatibility')" icon="fa-solid fa-link">
                        <div class="grid gap-4 lg:grid-cols-2">
                            <div>
                                <h3 class="text-sm font-black text-slate-700">{{ __('tags.admin.provider_mappings') }}</h3>
                                <ul class="mt-2 space-y-2 text-sm text-slate-600">
                                    @foreach ($selectedTag->providerMappings as $mapping)
                                        <li class="rounded-control bg-slate-50 p-2">
                                            <span class="block break-words font-bold text-slate-700">{{ $mapping->raw_label }}</span>
                                            <span class="mt-1 block break-all text-xs text-slate-500">{{ $mapping->provider }} · {{ $mapping->provider_key }}</span>
                                            <span class="mt-1 block text-xs font-bold text-slate-600">{{ $mapping->status->label() }}</span>
                                            <span class="mt-2 flex flex-wrap gap-2">
                                                <button type="button" wire:click="moderateProviderMapping({{ $mapping->id }}, 'approved')" wire:loading.attr="disabled" wire:target="moderateProviderMapping({{ $mapping->id }}, 'approved')" class="inline-flex min-h-11 items-center rounded-control bg-emerald-50 px-3 py-2 text-xs font-bold text-emerald-700 hover:bg-emerald-100 disabled:opacity-50">{{ __('tags.actions.approve') }}</button>
                                                <button type="button" wire:click="moderateProviderMapping({{ $mapping->id }}, 'rejected')" wire:confirm="{{ __('tags.admin.provider_mapping_reject_confirm') }}" wire:loading.attr="disabled" wire:target="moderateProviderMapping({{ $mapping->id }}, 'rejected')" class="inline-flex min-h-11 items-center rounded-control bg-rose-50 px-3 py-2 text-xs font-bold text-rose-700 hover:bg-rose-100 disabled:opacity-50">{{ __('tags.actions.reject') }}</button>
                                            </span>
                                        </li>
                                    @endforeach
                                </ul>
                            </div>
                            <div>
                                <h3 class="text-sm font-black text-slate-700">{{ __('tags.admin.legacy_urls') }}</h3>
                                <ul class="mt-2 space-y-2 text-sm text-slate-600">
                                    @foreach ($selectedTag->historicalSlugs as $historicalSlug)
                                        <li class="break-all rounded-control bg-slate-50 p-2"><a class="hover:text-emerald-700" href="{{ route('titles.taxonomy', ['type' => 'tag', 'taxonomy' => $historicalSlug->slug]) }}">{{ route('titles.taxonomy', ['type' => 'tag', 'taxonomy' => $historicalSlug->slug]) }}</a></li>
                                    @endforeach
                                </ul>
                            </div>
                        </div>
                    </x-ui.panel>
                @endif
            @endif
        </div>
    </div>
</div>
