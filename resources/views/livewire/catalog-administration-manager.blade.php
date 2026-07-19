<div class="space-y-5" data-livewire-catalog-administration-manager>
    @if ($notice)
        <div role="status" class="rounded-control bg-emerald-50 px-4 py-3 text-sm font-semibold text-emerald-800">{{ $notice }}</div>
    @endif

    @if ($errors->isNotEmpty())
        <div role="alert" class="rounded-control border border-rose-200 bg-rose-50 px-4 py-3 text-sm font-semibold text-rose-800">
            <div class="flex items-start gap-2">
                <x-ui.icon name="fa-solid fa-circle-exclamation" align="start" />
                <div class="space-y-1">
                    @foreach ($errors->all() as $message)
                        <p>{{ $message }}</p>
                    @endforeach
                </div>
            </div>
        </div>
    @endif

    <x-ui.panel data-admin-catalog-results :title="__('administration.catalog.titles')" :subtitle="__('administration.catalog.titles_description')" icon="fa-solid fa-film" :pad="false">
        <div class="border-b border-slate-200 p-4">
            <label class="block text-sm font-bold text-slate-700" for="catalog-admin-search">{{ __('administration.catalog.search_label') }}</label>
            <div class="relative mt-2">
                <x-ui.icon name="fa-solid fa-magnifying-glass pointer-events-none absolute left-3 top-3.5 text-slate-400" />
                <input id="catalog-admin-search" type="search" wire:model.live.debounce.500ms="search" maxlength="80" class="min-h-11 w-full rounded-control border border-slate-300 bg-white py-2 pl-10 pr-3 text-sm text-slate-700 focus:border-emerald-600 focus:outline-none" placeholder="{{ __('administration.catalog.search_placeholder') }}">
            </div>
        </div>

        @island(name: 'admin-catalog-pagination', always: true, with: $this->paginationIslandPage)
        <x-ui.pagination-region name="admin-catalog-results">
        <div wire:loading.flex wire:target="search,selectTitle" class="min-h-24 items-center justify-center gap-2 p-6 text-sm font-semibold text-slate-500">
            <x-ui.icon name="fa-solid fa-spinner fa-spin text-emerald-700" />
            <span>{{ __('administration.catalog.loading') }}</span>
        </div>

        <div wire:loading.remove wire:target="search,selectTitle">
            @forelse ($titles as $catalogTitle)
                <button type="button" wire:key="admin-title-{{ $catalogTitle->id }}" wire:click="selectTitle({{ $catalogTitle->id }})" @class([
                    'grid w-full gap-2 border-b border-slate-200 px-4 py-3 text-left last:border-b-0 sm:grid-cols-[minmax(0,1fr)_auto] sm:items-center',
                    'bg-emerald-50' => $selectedTitle?->id === $catalogTitle->id,
                    'bg-white hover:bg-slate-50' => $selectedTitle?->id !== $catalogTitle->id,
                ])>
                    <span class="min-w-0">
                        <span class="block text-sm font-black text-slate-800">{{ $catalogTitle->title }}</span>
                        <span class="mt-1 block text-xs font-semibold text-slate-500">#{{ $catalogTitle->id }} · {{ $catalogTitle->slug }} · {{ __('administration.catalog.external_id', ['id' => $catalogTitle->external_id ?: '—']) }}</span>
                    </span>
                    <span class="text-xs font-bold text-slate-500">{{ __('administration.catalog.counts', ['seasons' => $catalogTitle->seasons_count, 'episodes' => $catalogTitle->episodes_count, 'media' => $catalogTitle->licensed_media_count]) }}</span>
                </button>
            @empty
                <div class="p-8 text-center text-sm text-slate-500">{{ __('administration.catalog.titles_empty') }}</div>
            @endforelse
        </div>
        {{ $titles->links(data: ['region' => 'admin-catalog-results']) }}
        </x-ui.pagination-region>
        @endisland
    </x-ui.panel>

    @if ($selectedTitle)
        @if ($canManageContent)
        <x-ui.panel :title="__('administration.catalog.title_panel')" :subtitle="__('administration.catalog.title_panel_description')" icon="fa-solid fa-pen-to-square">
            <form wire:submit="saveTitle" class="space-y-4">
                <div class="grid gap-4 lg:grid-cols-2">
                    <label class="text-sm font-bold text-slate-700">{{ __('administration.catalog.title') }}
                        <input type="text" wire:model.blur="titleForm.title" maxlength="255" class="mt-2 min-h-11 w-full rounded-control border border-slate-300 px-3 py-2 font-normal focus:border-emerald-600 focus:outline-none">
                        <x-form.input-error for="titleForm.title" />
                    </label>
                    <label class="text-sm font-bold text-slate-700">{{ __('administration.catalog.original_title') }}
                        <input type="text" wire:model.blur="titleForm.original_title" maxlength="255" class="mt-2 min-h-11 w-full rounded-control border border-slate-300 px-3 py-2 font-normal focus:border-emerald-600 focus:outline-none">
                    </label>
                    <label class="text-sm font-bold text-slate-700">{{ __('administration.catalog.slug') }}
                        <input type="text" wire:model.blur="titleForm.slug" maxlength="255" class="mt-2 min-h-11 w-full rounded-control border border-slate-300 px-3 py-2 font-normal focus:border-emerald-600 focus:outline-none">
                        <x-form.input-error for="titleForm.slug" />
                    </label>
                    <label class="text-sm font-bold text-slate-700">{{ __('administration.catalog.source_external_id') }}
                        <input type="text" wire:model.blur="titleForm.external_id" maxlength="120" class="mt-2 min-h-11 w-full rounded-control border border-slate-300 px-3 py-2 font-normal focus:border-emerald-600 focus:outline-none">
                        <x-form.input-error for="titleForm.external_id" />
                    </label>
                    <label class="text-sm font-bold text-slate-700">{{ __('administration.catalog.year') }}
                        <input type="number" wire:model.blur="titleForm.year" min="1900" max="{{ $maxCatalogYear }}" class="mt-2 min-h-11 w-full rounded-control border border-slate-300 px-3 py-2 font-normal focus:border-emerald-600 focus:outline-none">
                    </label>
                    <label class="text-sm font-bold text-slate-700">{{ __('administration.catalog.poster') }}
                        <input type="url" wire:model.blur="titleForm.poster_url" maxlength="2048" class="mt-2 min-h-11 w-full rounded-control border border-slate-300 px-3 py-2 font-normal focus:border-emerald-600 focus:outline-none">
                    </label>
                </div>

                <label class="block text-sm font-bold text-slate-700">{{ __('administration.catalog.description') }}
                    <textarea wire:model.blur="titleForm.description" rows="6" maxlength="20000" class="mt-2 w-full rounded-control border border-slate-300 px-3 py-2 font-normal leading-6 focus:border-emerald-600 focus:outline-none"></textarea>
                </label>

                <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                    <label class="text-sm font-bold text-slate-700">{{ __('administration.catalog.publication') }}
                        <select wire:model="titleForm.publication_status" @disabled(! $canPublishContent) class="mt-2 min-h-11 w-full rounded-control border border-slate-300 bg-white px-3 py-2 font-normal disabled:bg-slate-100">
                            @foreach ($publicationStatuses as $status)
                                <option value="{{ $status->value }}">{{ $publicationLabels[$status->value] }}</option>
                            @endforeach
                        </select>
                    </label>
                    <label class="text-sm font-bold text-slate-700">{{ __('administration.catalog.audience') }}
                        <select wire:model="titleForm.audience" class="mt-2 min-h-11 w-full rounded-control border border-slate-300 bg-white px-3 py-2 font-normal">
                            @foreach ($audiences as $audience)
                                <option value="{{ $audience->value }}">{{ $audienceLabels[$audience->value] }}</option>
                            @endforeach
                        </select>
                    </label>
                    <label class="text-sm font-bold text-slate-700">{{ __('administration.catalog.available_from') }}
                        <input type="text" wire:model.blur="titleForm.available_from" placeholder="2026-08-01 10:00" class="mt-2 min-h-11 w-full rounded-control border border-slate-300 px-3 py-2 font-normal">
                    </label>
                    <label class="text-sm font-bold text-slate-700">{{ __('administration.catalog.available_until') }}
                        <input type="text" wire:model.blur="titleForm.available_until" placeholder="2026-09-01 10:00" class="mt-2 min-h-11 w-full rounded-control border border-slate-300 px-3 py-2 font-normal">
                    </label>
                </div>

                <div class="flex flex-wrap gap-2">
                    <button type="submit" wire:loading.attr="disabled" wire:target="saveTitle" class="inline-flex min-h-11 items-center gap-2 rounded-control bg-emerald-700 px-4 py-2 text-sm font-bold text-white hover:bg-emerald-600 disabled:opacity-60">
                        <x-ui.icon name="fa-solid fa-floppy-disk" /><span>{{ __('administration.catalog.save_title') }}</span>
                    </button>
                    @if ($canDeleteContent)
                        <button type="button" wire:click="archiveTitle" wire:confirm="{{ __('administration.catalog.archive_title_confirm') }}" wire:loading.attr="disabled" class="inline-flex min-h-11 items-center gap-2 rounded-control bg-rose-50 px-4 py-2 text-sm font-bold text-rose-700 hover:bg-rose-100 disabled:opacity-60">
                            <x-ui.icon name="fa-solid fa-eye-slash" /><span>{{ __('administration.catalog.archive_title') }}</span>
                        </button>
                    @endif
                </div>
            </form>
        </x-ui.panel>
        @endif

        @if ($canManageContent || $canCreateContent)
        <x-ui.panel :title="__('administration.catalog.relations_title')" :subtitle="__('administration.catalog.relations_description')" icon="fa-solid fa-tags">
            <div class="grid gap-4 xl:grid-cols-2">
                @foreach ($relationGroups as $type => $group)
                    <section wire:key="admin-relation-group-{{ $type }}" class="rounded-control border border-slate-200 bg-slate-50 p-3">
                        <div class="flex items-center justify-between gap-3">
                            <h3 class="text-sm font-black text-slate-700">{{ $group['label'] }}</h3>
                            @if ($canCreateContent)
                                <button type="button" wire:click="newLookup('{{ $type }}')" class="min-h-11 px-2 text-xs font-bold text-emerald-700 hover:text-emerald-600">{{ __('administration.catalog.create') }}</button>
                            @endif
                        </div>
                        <div class="mt-2 flex flex-wrap gap-2">
                            @forelse ($group['selected'] as $relation)
                                @if ($canManageContent)
                                    <button type="button" wire:key="admin-relation-{{ $type }}-{{ $relation->id }}" wire:click="detachRelation('{{ $type }}', {{ $relation->id }})" wire:confirm="{{ __('administration.catalog.detach_confirm', ['name' => $relation->name]) }}" class="inline-flex min-h-11 items-center gap-2 rounded-control bg-white px-3 py-2 text-xs font-bold text-slate-700 hover:bg-rose-50 hover:text-rose-700">
                                        <span>{{ $relation->name }}</span><x-ui.icon name="fa-solid fa-xmark" />
                                    </button>
                                @else
                                    <span class="inline-flex min-h-11 items-center rounded-control bg-white px-3 py-2 text-xs font-bold text-slate-700">{{ $relation->name }}</span>
                                @endif
                            @empty
                                <span class="text-xs font-semibold text-slate-500">{{ __('administration.catalog.relations_empty') }}</span>
                            @endforelse
                        </div>
                        @if ($canManageContent)
                        <input type="search" wire:model.live.debounce.350ms="relationSearch.{{ $type }}" maxlength="80" class="mt-3 min-h-11 w-full rounded-control border border-slate-300 bg-white px-3 py-2 text-sm" placeholder="{{ __('administration.catalog.relation_search') }}">
                        @if (mb_strlen($relationSearch[$type]) >= 2)
                            <div class="mt-2 grid gap-1">
                                @forelse ($group['options'] as $option)
                                    <button type="button" wire:key="admin-relation-option-{{ $type }}-{{ $option->id }}" wire:click="attachRelation('{{ $type }}', {{ $option->id }})" class="min-h-11 rounded-control bg-white px-3 py-2 text-left text-xs font-bold text-slate-700 hover:bg-emerald-50 hover:text-emerald-700">{{ $option->name }}</button>
                                @empty
                                    <span class="px-2 py-2 text-xs text-slate-500">{{ __('administration.catalog.no_matches') }}</span>
                                @endforelse
                            </div>
                        @endif
                        @endif
                    </section>
                @endforeach
            </div>

            @if ($lookupType && $canCreateContent)
                <form wire:submit="saveLookup" class="mt-4 grid gap-3 rounded-control border border-emerald-200 bg-emerald-50 p-3 lg:grid-cols-[minmax(0,1fr)_minmax(0,1fr)_auto] lg:items-end">
                    <label class="text-sm font-bold text-slate-700">{{ __('administration.catalog.title') }}
                        <input type="text" wire:model="lookupForm.name" maxlength="255" class="mt-2 min-h-11 w-full rounded-control border border-slate-300 bg-white px-3 py-2 font-normal">
                    </label>
                    <label class="text-sm font-bold text-slate-700">{{ __('administration.catalog.slug') }}
                        <input type="text" wire:model="lookupForm.slug" maxlength="255" class="mt-2 min-h-11 w-full rounded-control border border-slate-300 bg-white px-3 py-2 font-normal">
                    </label>
                    <button type="submit" class="inline-flex min-h-11 items-center justify-center gap-2 rounded-control bg-emerald-700 px-4 py-2 text-sm font-bold text-white hover:bg-emerald-600"><x-ui.icon name="fa-solid fa-plus" /><span>{{ __('administration.catalog.create') }}</span></button>
                </form>
            @endif
        </x-ui.panel>
        @endif

        @if ($canManageRecommendations)
        <x-ui.panel
            :title="__('recommendations.admin.section_title')"
            :subtitle="__('recommendations.admin.section_description')"
            icon="fa-solid fa-code-branch"
        >
            <div class="grid gap-4 lg:grid-cols-[minmax(0,1fr)_10rem_12rem]">
                <label class="text-sm font-bold text-slate-700">
                    {{ __('recommendations.admin.relation_type') }}
                    <select wire:model="recommendationRelationForm.type" class="mt-2 min-h-11 w-full rounded-control border border-slate-300 bg-white px-3 py-2 font-normal">
                        @foreach ($recommendationRelationTypes as $relationType)
                            <option value="{{ $relationType->value }}">{{ __('recommendations.relations.'.$relationType->value) }}</option>
                        @endforeach
                    </select>
                </label>
                <label class="text-sm font-bold text-slate-700">
                    {{ __('recommendations.admin.priority') }}
                    <input type="number" min="0" max="65535" wire:model="recommendationRelationForm.priority" class="mt-2 min-h-11 w-full rounded-control border border-slate-300 bg-white px-3 py-2 font-normal">
                </label>
                <label class="mt-7 flex min-h-11 items-center gap-3 rounded-control bg-slate-50 px-3 py-2 text-sm font-bold text-slate-700">
                    <input type="checkbox" wire:model="recommendationRelationForm.locked" class="h-4 w-4 rounded border-slate-300 text-emerald-700">
                    <span>{{ __('recommendations.admin.locked') }}</span>
                </label>
            </div>

            <label class="mt-4 block text-sm font-bold text-slate-700" for="recommendation-relation-search">
                {{ __('recommendations.admin.target_search') }}
            </label>
            <input
                id="recommendation-relation-search"
                type="search"
                wire:model.live.debounce.350ms="recommendationRelationSearch"
                maxlength="80"
                class="mt-2 min-h-11 w-full rounded-control border border-slate-300 bg-white px-3 py-2 text-sm"
                placeholder="{{ __('recommendations.admin.target_placeholder') }}"
            >

            @if (mb_strlen($recommendationRelationSearch) >= 2)
                <div class="mt-2 grid gap-1" aria-live="polite">
                    @forelse ($recommendationRelationCandidates as $candidate)
                        <button
                            type="button"
                            wire:key="recommendation-relation-candidate-{{ $candidate->id }}"
                            wire:click="addRecommendationRelation({{ $candidate->id }})"
                            wire:loading.attr="disabled"
                            wire:target="addRecommendationRelation({{ $candidate->id }})"
                            class="flex min-h-11 items-center justify-between gap-3 rounded-control bg-slate-50 px-3 py-2 text-left text-sm font-bold text-slate-700 hover:bg-emerald-50 hover:text-emerald-700 disabled:opacity-60"
                        >
                            <span>{{ $candidate->title }} @if ($candidate->year)· {{ $candidate->year }}@endif</span>
                            <span class="text-xs">{{ __('recommendations.admin.add') }}</span>
                        </button>
                    @empty
                        <p class="rounded-control bg-slate-50 px-3 py-2 text-sm text-slate-500">{{ __('recommendations.admin.no_matches') }}</p>
                    @endforelse
                </div>
            @endif

            <div class="mt-4 grid gap-2">
                @forelse ($recommendationRelations as $relation)
                    <div wire:key="recommendation-relation-{{ $relation->id }}" class="flex flex-col gap-3 rounded-control border border-slate-200 bg-white p-3 sm:flex-row sm:items-center sm:justify-between">
                        <div class="min-w-0">
                            <p class="break-words text-sm font-black text-slate-800">{{ $relation->targetTitle->title }}</p>
                            <p class="mt-1 text-xs font-semibold text-slate-500">
                                {{ __('recommendations.relations.'.$relation->relation_type->value) }}
                                · {{ __('recommendations.admin.source_'.$relation->source->value) }}
                                · {{ __('recommendations.admin.priority') }} {{ $relation->priority }}
                            </p>
                        </div>
                        @if ($relation->source->value === 'editorial')
                            <button
                                type="button"
                                wire:click="removeRecommendationRelation({{ $relation->id }})"
                                wire:confirm="{{ __('recommendations.admin.remove') }}?"
                                wire:loading.attr="disabled"
                                class="min-h-11 rounded-control bg-rose-50 px-3 py-2 text-sm font-bold text-rose-700 hover:bg-rose-100 disabled:opacity-60"
                            >{{ __('recommendations.admin.remove') }}</button>
                        @endif
                    </div>
                @empty
                    <p class="rounded-control bg-slate-50 px-3 py-3 text-sm text-slate-500">{{ __('recommendations.admin.empty') }}</p>
                @endforelse
            </div>
        </x-ui.panel>
        @endif

        <div class="grid gap-5 xl:grid-cols-[minmax(0,0.8fr)_minmax(0,1.2fr)]">
            <x-ui.panel :title="__('administration.catalog.seasons')" :subtitle="__('administration.catalog.seasons_description')" icon="fa-solid fa-layer-group" :pad="false">
                <div class="border-b border-slate-200 p-3">
                    @if ($canCreateContent)
                        <button type="button" wire:click="newSeason" class="inline-flex min-h-11 items-center gap-2 rounded-control bg-emerald-50 px-3 py-2 text-sm font-bold text-emerald-700 hover:bg-emerald-100"><x-ui.icon name="fa-solid fa-plus" /><span>{{ __('administration.catalog.new_season') }}</span></button>
                    @endif
                </div>
                @forelse ($seasons as $season)
                    <button type="button" wire:key="admin-season-{{ $season->id }}" wire:click="editSeason({{ $season->id }})" @class([
                        'grid w-full gap-1 border-b border-slate-200 px-4 py-3 text-left last:border-b-0',
                        'bg-emerald-50' => $activeSeason?->id === $season->id,
                        'hover:bg-slate-50' => $activeSeason?->id !== $season->id,
                    ])>
                        <span class="text-sm font-black text-slate-700">{{ $releaseKindLabels[$season->kind->value] }} {{ $season->number }} · {{ $season->title ?: __('administration.catalog.unnamed') }}</span>
                        <span class="text-xs font-semibold text-slate-500">{{ $publicationLabels[$season->publication_status->value] }} · {{ __('administration.catalog.episodes_count', ['count' => $season->episodes_count]) }}</span>
                    </button>
                @empty
                    <div class="p-6 text-sm text-slate-500">{{ __('administration.catalog.seasons_empty') }}</div>
                @endforelse
            </x-ui.panel>

            @if ($seasonForm && ($editingSeasonId ? $canManageContent : $canCreateContent))
                <x-ui.panel :title="__('administration.catalog.season_form')" icon="fa-solid fa-pen">
                    <form wire:submit="saveSeason" class="space-y-4">
                        <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
                            <label class="text-sm font-bold text-slate-700">{{ __('administration.catalog.number') }}<input type="number" min="0" wire:model="seasonForm.number" class="mt-2 min-h-11 w-full rounded-control border border-slate-300 px-3 py-2 font-normal"><x-form.input-error for="seasonForm.number" /></label>
                            <label class="text-sm font-bold text-slate-700">{{ __('administration.catalog.type') }}<select wire:model="seasonForm.kind" class="mt-2 min-h-11 w-full rounded-control border border-slate-300 bg-white px-3 py-2 font-normal">@foreach ($releaseKinds as $kind)<option value="{{ $kind->value }}">{{ $releaseKindLabels[$kind->value] }}</option>@endforeach</select></label>
                            <label class="text-sm font-bold text-slate-700">{{ __('administration.catalog.sort_order') }}<input type="number" min="0" wire:model="seasonForm.sort_order" class="mt-2 min-h-11 w-full rounded-control border border-slate-300 px-3 py-2 font-normal"></label>
                        </div>
                        <label class="block text-sm font-bold text-slate-700">{{ __('administration.catalog.title') }}<input type="text" wire:model="seasonForm.title" maxlength="255" class="mt-2 min-h-11 w-full rounded-control border border-slate-300 px-3 py-2 font-normal"></label>
                        <div class="grid gap-3 sm:grid-cols-2">
                            <label class="text-sm font-bold text-slate-700">{{ __('administration.catalog.publication') }}<select wire:model="seasonForm.publication_status" @disabled(! $canPublishContent) class="mt-2 min-h-11 w-full rounded-control border border-slate-300 bg-white px-3 py-2 font-normal disabled:bg-slate-100">@foreach ($publicationStatuses as $status)<option value="{{ $status->value }}">{{ $publicationLabels[$status->value] }}</option>@endforeach</select></label>
                            <label class="text-sm font-bold text-slate-700">{{ __('administration.catalog.audience') }}<select wire:model="seasonForm.audience" class="mt-2 min-h-11 w-full rounded-control border border-slate-300 bg-white px-3 py-2 font-normal">@foreach ($audiences as $audience)<option value="{{ $audience->value }}">{{ $audienceLabels[$audience->value] }}</option>@endforeach</select></label>
                            <label class="text-sm font-bold text-slate-700">{{ __('administration.catalog.available_from') }}<input type="text" wire:model="seasonForm.available_from" class="mt-2 min-h-11 w-full rounded-control border border-slate-300 px-3 py-2 font-normal"></label>
                            <label class="text-sm font-bold text-slate-700">{{ __('administration.catalog.available_until') }}<input type="text" wire:model="seasonForm.available_until" class="mt-2 min-h-11 w-full rounded-control border border-slate-300 px-3 py-2 font-normal"></label>
                        </div>
                        <div class="flex flex-wrap gap-2">
                            <button type="submit" class="min-h-11 rounded-control bg-emerald-700 px-4 py-2 text-sm font-bold text-white hover:bg-emerald-600">{{ __('administration.catalog.save_season') }}</button>
                            @if ($editingSeasonId && $canDeleteContent)<button type="button" wire:click="archiveSeason" wire:confirm="{{ __('administration.catalog.archive_season_confirm') }}" class="min-h-11 rounded-control bg-rose-50 px-4 py-2 text-sm font-bold text-rose-700 hover:bg-rose-100">{{ __('administration.catalog.archive_season') }}</button>@endif
                        </div>
                    </form>
                </x-ui.panel>
            @endif
        </div>

        @if ($activeSeason)
            <div class="grid gap-5 xl:grid-cols-[minmax(0,0.8fr)_minmax(0,1.2fr)]">
                <x-ui.panel :title="__('administration.catalog.episodes')" icon="fa-solid fa-list-ol" :pad="false">
                    @if ($canCreateContent)
                        <div class="border-b border-slate-200 p-3"><button type="button" wire:click="newEpisode" class="inline-flex min-h-11 items-center gap-2 rounded-control bg-emerald-50 px-3 py-2 text-sm font-bold text-emerald-700 hover:bg-emerald-100"><x-ui.icon name="fa-solid fa-plus" /><span>{{ __('administration.catalog.new_episode') }}</span></button></div>
                    @endif
                    @forelse ($episodes as $episode)
                        <button type="button" wire:key="admin-episode-{{ $episode->id }}" wire:click="editEpisode({{ $episode->id }})" @class(['grid w-full gap-1 border-b border-slate-200 px-4 py-3 text-left last:border-b-0', 'bg-emerald-50' => $activeEpisode?->id === $episode->id, 'hover:bg-slate-50' => $activeEpisode?->id !== $episode->id])>
                            <span class="text-sm font-black text-slate-700">{{ $releaseKindLabels[$episode->kind->value] }} {{ $episode->number }} · {{ $episode->title ?: __('administration.catalog.unnamed') }}</span>
                            <span class="text-xs font-semibold text-slate-500">{{ $publicationLabels[$episode->publication_status->value] }} · {{ __('administration.catalog.media_count', ['count' => $episode->licensed_media_count]) }}</span>
                        </button>
                    @empty
                        <div class="p-6 text-sm text-slate-500">{{ __('administration.catalog.episodes_empty') }}</div>
                    @endforelse
                </x-ui.panel>

                @if ($episodeForm && ($editingEpisodeId ? $canManageContent : $canCreateContent))
                    <x-ui.panel :title="__('administration.catalog.episode_form')" icon="fa-solid fa-pen">
                        <form wire:submit="saveEpisode" class="space-y-4">
                            <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
                                <label class="text-sm font-bold text-slate-700">{{ __('administration.catalog.number') }}<input type="number" min="0" wire:model="episodeForm.number" class="mt-2 min-h-11 w-full rounded-control border border-slate-300 px-3 py-2 font-normal"><x-form.input-error for="episodeForm.number" /></label>
                                <label class="text-sm font-bold text-slate-700">{{ __('administration.catalog.type') }}<select wire:model="episodeForm.kind" class="mt-2 min-h-11 w-full rounded-control border border-slate-300 bg-white px-3 py-2 font-normal">@foreach ($releaseKinds as $kind)<option value="{{ $kind->value }}">{{ $releaseKindLabels[$kind->value] }}</option>@endforeach</select></label>
                                <label class="text-sm font-bold text-slate-700">{{ __('administration.catalog.sort_order') }}<input type="number" min="0" wire:model="episodeForm.sort_order" class="mt-2 min-h-11 w-full rounded-control border border-slate-300 px-3 py-2 font-normal"></label>
                            </div>
                            <div class="grid gap-3 sm:grid-cols-2"><label class="text-sm font-bold text-slate-700">{{ __('administration.catalog.title') }}<input type="text" wire:model="episodeForm.title" maxlength="255" class="mt-2 min-h-11 w-full rounded-control border border-slate-300 px-3 py-2 font-normal"></label><label class="text-sm font-bold text-slate-700">{{ __('administration.catalog.released_at') }}<input type="date" wire:model="episodeForm.released_at" class="mt-2 min-h-11 w-full rounded-control border border-slate-300 px-3 py-2 font-normal"></label></div>
                            <label class="block text-sm font-bold text-slate-700">{{ __('administration.catalog.summary') }}<textarea rows="4" wire:model="episodeForm.summary" maxlength="20000" class="mt-2 w-full rounded-control border border-slate-300 px-3 py-2 font-normal leading-6"></textarea></label>
                            <div class="grid gap-3 sm:grid-cols-2">
                                <label class="text-sm font-bold text-slate-700">{{ __('administration.catalog.publication') }}<select wire:model="episodeForm.publication_status" @disabled(! $canPublishContent) class="mt-2 min-h-11 w-full rounded-control border border-slate-300 bg-white px-3 py-2 font-normal disabled:bg-slate-100">@foreach ($publicationStatuses as $status)<option value="{{ $status->value }}">{{ $publicationLabels[$status->value] }}</option>@endforeach</select></label>
                                <label class="text-sm font-bold text-slate-700">{{ __('administration.catalog.audience') }}<select wire:model="episodeForm.audience" class="mt-2 min-h-11 w-full rounded-control border border-slate-300 bg-white px-3 py-2 font-normal">@foreach ($audiences as $audience)<option value="{{ $audience->value }}">{{ $audienceLabels[$audience->value] }}</option>@endforeach</select></label>
                                <label class="text-sm font-bold text-slate-700">{{ __('administration.catalog.available_from') }}<input type="text" wire:model="episodeForm.available_from" class="mt-2 min-h-11 w-full rounded-control border border-slate-300 px-3 py-2 font-normal"></label>
                                <label class="text-sm font-bold text-slate-700">{{ __('administration.catalog.available_until') }}<input type="text" wire:model="episodeForm.available_until" class="mt-2 min-h-11 w-full rounded-control border border-slate-300 px-3 py-2 font-normal"></label>
                            </div>
                            <div class="flex flex-wrap gap-2"><button type="submit" class="min-h-11 rounded-control bg-emerald-700 px-4 py-2 text-sm font-bold text-white hover:bg-emerald-600">{{ __('administration.catalog.save_episode') }}</button>@if ($editingEpisodeId && $canDeleteContent)<button type="button" wire:click="archiveEpisode" wire:confirm="{{ __('administration.catalog.archive_episode_confirm') }}" class="min-h-11 rounded-control bg-rose-50 px-4 py-2 text-sm font-bold text-rose-700 hover:bg-rose-100">{{ __('administration.catalog.archive_episode') }}</button>@endif</div>
                        </form>
                    </x-ui.panel>
                @endif
            </div>
        @endif

        @if ($activeEpisode && $canViewSources)
            <div class="grid gap-5 xl:grid-cols-[minmax(0,0.8fr)_minmax(0,1.2fr)]">
                <x-ui.panel :title="__('administration.catalog.media')" :subtitle="__('administration.catalog.media_description')" icon="fa-solid fa-circle-play" :pad="false">
                    @if ($canManageSources)
                        <div class="border-b border-slate-200 p-3"><button type="button" wire:click="newMedia" class="inline-flex min-h-11 items-center gap-2 rounded-control bg-emerald-50 px-3 py-2 text-sm font-bold text-emerald-700 hover:bg-emerald-100"><x-ui.icon name="fa-solid fa-plus" /><span>{{ __('administration.catalog.new_media') }}</span></button></div>
                    @endif
                    @forelse ($mediaItems as $media)
                        <button type="button" wire:key="admin-media-{{ $media->id }}" wire:click="editMedia({{ $media->id }})" @class(['grid w-full gap-1 border-b border-slate-200 px-4 py-3 text-left last:border-b-0', 'bg-emerald-50' => $editingMediaId === $media->id, 'hover:bg-slate-50' => $editingMediaId !== $media->id])>
                            <span class="text-sm font-black text-slate-700">{{ $media->title }}</span>
                            <span class="text-xs font-semibold text-slate-500">{{ $mediaStatuses[$media->status] ?? $media->status }} · {{ $media->quality ?: __('administration.catalog.without_quality') }} · {{ $media->format ?: __('administration.catalog.without_format') }} · {{ $media->storage_disk }}</span>
                        </button>
                    @empty
                        <div class="p-6 text-sm text-slate-500">{{ __('administration.catalog.media_empty') }}</div>
                    @endforelse
                </x-ui.panel>

                @if ($mediaForm && $canManageSources)
                    <x-ui.panel :title="__('administration.catalog.media_form')" icon="fa-solid fa-pen">
                        <form wire:submit="saveMedia" class="space-y-4">
                            <label class="block text-sm font-bold text-slate-700">{{ __('administration.catalog.title') }}<input type="text" wire:model="mediaForm.title" maxlength="255" class="mt-2 min-h-11 w-full rounded-control border border-slate-300 px-3 py-2 font-normal"></label>
                            @if (! $editingMediaId)
                                <label class="block text-sm font-bold text-slate-700">{{ __('administration.catalog.https_url') }}<input type="url" wire:model="mediaForm.playback_url" maxlength="2048" class="mt-2 min-h-11 w-full rounded-control border border-slate-300 px-3 py-2 font-normal"><x-form.input-error for="mediaForm.playback_url" /></label>
                            @else
                                <p class="rounded-control bg-slate-50 px-3 py-2 text-xs font-semibold text-slate-500">{{ __('administration.catalog.protected_url') }}</p>
                            @endif
                            <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
                                <label class="text-sm font-bold text-slate-700">{{ __('administration.catalog.quality') }}<select wire:model="mediaForm.quality" class="mt-2 min-h-11 w-full rounded-control border border-slate-300 bg-white px-3 py-2 font-normal"><option value="">{{ __('administration.catalog.not_specified') }}</option>@foreach ($supportedQualities as $quality)<option value="{{ $quality }}">{{ $quality }}</option>@endforeach</select></label>
                                <label class="text-sm font-bold text-slate-700">{{ __('administration.catalog.format') }}<select wire:model="mediaForm.format" class="mt-2 min-h-11 w-full rounded-control border border-slate-300 bg-white px-3 py-2 font-normal"><option value="">{{ __('administration.catalog.choose') }}</option>@foreach ($allowedFormats as $format)<option value="{{ $format }}">{{ $format }}</option>@endforeach</select></label>
                                <label class="text-sm font-bold text-slate-700">{{ __('administration.catalog.duration_seconds') }}<input type="number" min="1" wire:model="mediaForm.duration_seconds" class="mt-2 min-h-11 w-full rounded-control border border-slate-300 px-3 py-2 font-normal"></label>
                                <label class="text-sm font-bold text-slate-700">{{ __('administration.catalog.translation') }}<input type="text" wire:model="mediaForm.translation_name" maxlength="120" class="mt-2 min-h-11 w-full rounded-control border border-slate-300 px-3 py-2 font-normal"></label>
                                <label class="text-sm font-bold text-slate-700">{{ __('administration.catalog.status') }}<select wire:model="mediaForm.status" class="mt-2 min-h-11 w-full rounded-control border border-slate-300 bg-white px-3 py-2 font-normal">@foreach ($mediaStatuses as $value => $label)<option value="{{ $value }}" @disabled($value === 'published' && ! $canPublishContent)>{{ $label }}</option>@endforeach</select></label>
                                <label class="text-sm font-bold text-slate-700">{{ __('administration.catalog.audience') }}<select wire:model="mediaForm.audience" class="mt-2 min-h-11 w-full rounded-control border border-slate-300 bg-white px-3 py-2 font-normal">@foreach ($audiences as $audience)<option value="{{ $audience->value }}">{{ $audienceLabels[$audience->value] }}</option>@endforeach</select></label>
                            </div>
                            <label class="flex min-h-11 items-center gap-3 rounded-control bg-slate-50 px-3 py-2 text-sm font-bold text-slate-700"><input type="checkbox" wire:model="mediaForm.has_subtitles" class="h-4 w-4 rounded border-slate-300 text-emerald-700"><span>{{ __('administration.catalog.has_subtitles') }}</span></label>
                            <div class="grid gap-3 sm:grid-cols-2"><label class="text-sm font-bold text-slate-700">{{ __('administration.catalog.available_from') }}<input type="text" wire:model="mediaForm.available_from" class="mt-2 min-h-11 w-full rounded-control border border-slate-300 px-3 py-2 font-normal"></label><label class="text-sm font-bold text-slate-700">{{ __('administration.catalog.available_until') }}<input type="text" wire:model="mediaForm.available_until" class="mt-2 min-h-11 w-full rounded-control border border-slate-300 px-3 py-2 font-normal"></label></div>
                            <div class="flex flex-wrap gap-2"><button type="submit" class="min-h-11 rounded-control bg-emerald-700 px-4 py-2 text-sm font-bold text-white hover:bg-emerald-600">{{ __('administration.catalog.save_media') }}</button>@if ($editingMediaId && $canDisableSources)<button type="button" wire:click="archiveMedia" wire:confirm="{{ __('administration.catalog.disable_media_confirm') }}" class="min-h-11 rounded-control bg-rose-50 px-4 py-2 text-sm font-bold text-rose-700 hover:bg-rose-100">{{ __('administration.catalog.disable_media') }}</button>@endif</div>
                        </form>
                    </x-ui.panel>
                @endif
            </div>
        @endif
    @endif
</div>
