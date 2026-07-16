<div class="mx-auto max-w-5xl space-y-5">
    <header class="rounded-panel border border-slate-200 bg-white p-5 shadow-panel sm:p-6">
        <h1 class="text-2xl font-black text-slate-800 sm:text-3xl">{{ __('requests.form.title') }}</h1>
        <p class="mt-2 max-w-3xl text-sm leading-6 text-slate-600">{{ __('requests.form.description') }}</p>
    </header>

    @if (! $schemaReady)
        <x-ui.panel><p role="alert" class="text-sm text-rose-700">{{ __('requests.states.unavailable') }}</p></x-ui.panel>
    @else
        <form wire:submit="submit" class="space-y-5">
            <x-ui.panel :title="__('requests.form.search_first')" icon="fa-solid fa-magnifying-glass">
                <div role="combobox" aria-expanded="{{ count($suggestions) > 0 ? 'true' : 'false' }}" aria-controls="content-request-suggestions" aria-haspopup="listbox">
                    <x-form.field :label="__('requests.fields.search')" for="content-request-search" :placeholder="__('requests.form.search_placeholder')" wire:model.live.debounce.300ms="search" autocomplete="off" />
                </div>
                <div wire:loading.delay wire:target="search" role="status" aria-live="polite" class="mt-3 text-sm font-bold text-sky-700">{{ __('requests.states.searching') }}</div>
                @if ($searchFailed)<p role="alert" class="mt-3 text-sm text-amber-700">{{ __('requests.states.search_fallback') }}</p>@endif
                @if (count($suggestions) > 0)
                    <ul id="content-request-suggestions" role="listbox" class="mt-3 divide-y divide-slate-100 overflow-hidden rounded-control border border-slate-200 bg-white">
                        @foreach ($suggestions as $suggestion)
                            <li role="option" wire:key="request-suggestion-{{ $suggestion['kind'] }}-{{ $suggestion['id'] }}" class="flex min-w-0 flex-wrap items-center justify-between gap-3 p-3">
                                <div class="min-w-0"><p class="break-words text-sm font-bold text-slate-800">{{ $suggestion['label'] }}</p><p class="text-xs text-slate-500">{{ $suggestion['meta'] }}</p></div>
                                @if ($suggestion['kind'] === 'catalog')
                                    <div class="flex flex-wrap gap-2"><a href="{{ $suggestion['url'] }}" class="inline-flex min-h-11 items-center rounded-control bg-slate-100 px-3 py-2 text-sm font-bold text-slate-700">{{ __('requests.actions.open_content') }}</a><button type="button" wire:click="selectCatalogTitle({{ $suggestion['id'] }})" class="min-h-11 rounded-control bg-emerald-700 px-3 py-2 text-sm font-bold text-white">{{ __('requests.actions.select_target') }}</button></div>
                                @else
                                    <a href="{{ $suggestion['url'] }}" class="inline-flex min-h-11 items-center rounded-control bg-amber-50 px-3 py-2 text-sm font-bold text-amber-800">{{ __('requests.actions.view_existing_request') }}</a>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                @elseif ($searchPerformed && ! $searchFailed)
                    <p role="status" class="mt-3 text-sm text-slate-600">{{ __('requests.states.no_matches') }}</p>
                @endif
                @if ($catalogTitleId !== '')
                    <div class="mt-4 flex flex-wrap items-center justify-between gap-3 rounded-control bg-emerald-50 p-3 text-sm"><span class="font-bold text-emerald-900">{{ __('requests.form.selected_target', ['title' => $title]) }}</span><button type="button" wire:click="clearCatalogTitle" class="min-h-11 rounded-control bg-white px-3 py-2 font-bold text-slate-700">{{ __('requests.actions.change_target') }}</button></div>
                @endif
            </x-ui.panel>

            <x-ui.panel :title="__('requests.fields.type')" icon="fa-solid fa-list-check">
                <fieldset class="grid gap-3 sm:grid-cols-2"><legend class="sr-only">{{ __('requests.fields.type') }}</legend>@foreach ($typeOptions as $option)<label class="flex min-h-11 cursor-pointer items-start gap-3 rounded-control border border-slate-200 p-3 focus-within:ring-2 focus-within:ring-emerald-600"><input type="radio" wire:model.live="type" value="{{ $option['value'] }}" class="mt-1"><span><span class="block font-bold text-slate-800">{{ $option['label'] }}</span><span class="mt-1 block text-xs leading-5 text-slate-500">{{ $option['description'] }}</span></span></label>@endforeach</fieldset>
            </x-ui.panel>

            <x-ui.panel :title="__('requests.form.identification_title')" icon="fa-solid fa-fingerprint">
                <div class="grid gap-4 md:grid-cols-2">
                    <x-form.field :label="__('requests.fields.request_title')" for="request-title" wire:model="title" required />
                    <x-form.field :label="__('requests.fields.original_title')" for="request-original-title" wire:model="originalTitle" />
                    <x-form.field :label="__('requests.fields.alternative_title')" for="request-alternative-title" wire:model="alternativeTitle" />
                    <x-form.field :label="__('requests.fields.release_year')" for="request-year" type="number" wire:model="releaseYear" />
                    <x-form.field :label="__('requests.fields.country')" for="request-country" wire:model="country" />
                    <div><label for="content-locale" class="block text-sm font-bold text-slate-700">{{ __('requests.fields.content_locale') }}</label><select id="content-locale" wire:model="contentLocale" class="mt-2 min-h-11 w-full rounded-control border border-slate-300 px-3 py-2"><option value="">{{ __('requests.fields.not_specified') }}</option>@foreach ($languageOptions as $option)<option value="{{ $option['value'] }}">{{ $option['label'] }}</option>@endforeach</select></div>
                    <div><label for="original-language" class="block text-sm font-bold text-slate-700">{{ __('requests.fields.original_language') }}</label><select id="original-language" wire:model="originalLanguage" class="mt-2 min-h-11 w-full rounded-control border border-slate-300 px-3 py-2"><option value="">{{ __('requests.fields.not_specified') }}</option>@foreach ($languageOptions as $option)<option value="{{ $option['value'] }}">{{ $option['label'] }}</option>@endforeach</select></div>
                </div>
            </x-ui.panel>

            @if ($type !== 'serial' && $type !== 'other_content_request')
                <x-ui.panel :title="__('requests.form.target_details')" icon="fa-solid fa-layer-group">
                    @if ($catalogTitleId === '')<p role="alert" class="text-sm font-bold text-amber-800">{{ __('requests.errors.target_required') }}</p>@endif
                    @if ($catalogTitleId !== '' && count($seasons) > 0)
                        <div class="grid gap-4 md:grid-cols-2">@if ($type !== 'season')<div><label for="request-season" class="block text-sm font-bold text-slate-700">{{ __('requests.fields.season') }}</label><select id="request-season" wire:model.live="seasonId" class="mt-2 min-h-11 w-full rounded-control border border-slate-300 px-3 py-2"><option value="">{{ __('requests.fields.whole_serial') }}</option>@foreach ($seasons as $season)<option value="{{ $season->id }}">{{ __('requests.fields.season_number_value', ['number' => $season->number]) }}</option>@endforeach</select></div>@endif
                        @if ($type === 'season')<x-form.field :label="__('requests.fields.season_number')" for="request-season-number" type="number" wire:model="seasonNumber" required /><div><label for="request-season-kind" class="block text-sm font-bold text-slate-700">{{ __('requests.fields.season_kind') }}</label><select id="request-season-kind" wire:model="seasonKind" class="mt-2 min-h-11 w-full rounded-control border border-slate-300 px-3 py-2"><option value="regular">{{ __('requests.season_kinds.regular') }}</option><option value="special">{{ __('requests.season_kinds.special') }}</option></select></div>@endif
                        @if ($type === 'episode')<x-form.field :label="__('requests.fields.episode_number')" for="request-episode-number" type="number" wire:model="episodeNumber" required /><x-form.field :label="__('requests.fields.episode_release_date')" for="request-episode-date" type="date" wire:model="episodeReleaseDate" />@endif
                        @if ($seasonId !== '' && $type !== 'season' && $type !== 'episode' && count($episodes) > 0)<div><label for="request-episode" class="block text-sm font-bold text-slate-700">{{ __('requests.fields.target_episode') }}</label><select id="request-episode" wire:model="episodeId" class="mt-2 min-h-11 w-full rounded-control border border-slate-300 px-3 py-2"><option value="">{{ __('requests.fields.whole_season') }}</option>@foreach ($episodes as $episode)<option value="{{ $episode->id }}">{{ __('requests.fields.episode_number_value', ['number' => $episode->number]) }}</option>@endforeach</select></div>@endif</div>
                    @elseif ($catalogTitleId !== '' && $type === 'season')
                        <div class="grid gap-4 md:grid-cols-2"><x-form.field :label="__('requests.fields.season_number')" for="request-season-number-empty" type="number" wire:model="seasonNumber" required /><div><label for="request-season-kind-empty" class="block text-sm font-bold text-slate-700">{{ __('requests.fields.season_kind') }}</label><select id="request-season-kind-empty" wire:model="seasonKind" class="mt-2 min-h-11 w-full rounded-control border border-slate-300 px-3 py-2"><option value="regular">{{ __('requests.season_kinds.regular') }}</option><option value="special">{{ __('requests.season_kinds.special') }}</option></select></div></div>
                    @endif
                </x-ui.panel>
            @endif

            @if (in_array($type, ['serial', 'season', 'episode', 'translation', 'subtitles'], true))
                <x-ui.panel :title="__('requests.form.language_details')" icon="fa-solid fa-language"><div class="grid gap-4 md:grid-cols-2">
                    @if ($type === 'translation')<div><label for="audio-language" class="block text-sm font-bold text-slate-700">{{ __('requests.fields.audio_language') }}</label><select id="audio-language" wire:model="audioLanguage" class="mt-2 min-h-11 w-full rounded-control border border-slate-300 px-3 py-2"><option value="">{{ __('requests.fields.not_specified') }}</option>@foreach ($languageOptions as $option)<option value="{{ $option['value'] }}">{{ $option['label'] }}</option>@endforeach</select></div><div><label for="translation-type" class="block text-sm font-bold text-slate-700">{{ __('requests.fields.translation_type') }}</label><select id="translation-type" wire:model="translationType" class="mt-2 min-h-11 w-full rounded-control border border-slate-300 px-3 py-2"><option value="">{{ __('requests.fields.not_specified') }}</option>@foreach ($translationTypeOptions as $option)<option value="{{ $option['value'] }}">{{ $option['label'] }}</option>@endforeach</select></div><x-form.field :label="__('requests.fields.translation_studio')" for="translation-studio" wire:model="translationStudio" />@endif
                    @if ($type === 'subtitles')<div><label for="subtitle-language" class="block text-sm font-bold text-slate-700">{{ __('requests.fields.subtitle_language') }}</label><select id="subtitle-language" wire:model="subtitleLanguage" class="mt-2 min-h-11 w-full rounded-control border border-slate-300 px-3 py-2"><option value="">{{ __('requests.fields.not_specified') }}</option>@foreach ($languageOptions as $option)<option value="{{ $option['value'] }}">{{ $option['label'] }}</option>@endforeach</select></div>@endif
                    @if (in_array($type, ['serial', 'season', 'episode'], true))<div><label for="preferred-audio-language" class="block text-sm font-bold text-slate-700">{{ __('requests.fields.audio_language') }}</label><select id="preferred-audio-language" wire:model="audioLanguage" class="mt-2 min-h-11 w-full rounded-control border border-slate-300 px-3 py-2"><option value="">{{ __('requests.fields.not_specified') }}</option>@foreach ($languageOptions as $option)<option value="{{ $option['value'] }}">{{ $option['label'] }}</option>@endforeach</select></div><div><label for="preferred-subtitle-language" class="block text-sm font-bold text-slate-700">{{ __('requests.fields.subtitle_language') }}</label><select id="preferred-subtitle-language" wire:model="subtitleLanguage" class="mt-2 min-h-11 w-full rounded-control border border-slate-300 px-3 py-2"><option value="">{{ __('requests.fields.not_specified') }}</option>@foreach ($languageOptions as $option)<option value="{{ $option['value'] }}">{{ $option['label'] }}</option>@endforeach</select></div><x-form.field :label="__('requests.fields.translation_studio')" for="preferred-translation-studio" wire:model="translationStudio" />@endif
                </div></x-ui.panel>
            @endif

            @if ($type === 'quality_upgrade')
                <x-ui.panel :title="__('requests.form.quality_details')" icon="fa-solid fa-display"><div class="grid gap-4 md:grid-cols-2"><div><label for="current-quality" class="block text-sm font-bold text-slate-700">{{ __('requests.fields.current_quality') }}</label><select id="current-quality" wire:model="currentQuality" class="mt-2 min-h-11 w-full rounded-control border border-slate-300 px-3 py-2"><option value="">{{ __('requests.fields.not_specified') }}</option>@foreach ($qualityOptions as $quality)<option value="{{ $quality }}">{{ $quality }}</option>@endforeach</select></div><div><label for="requested-quality" class="block text-sm font-bold text-slate-700">{{ __('requests.fields.requested_quality') }}</label><select id="requested-quality" wire:model="requestedQuality" class="mt-2 min-h-11 w-full rounded-control border border-slate-300 px-3 py-2"><option value="">{{ __('requests.fields.not_specified') }}</option>@foreach ($qualityOptions as $quality)<option value="{{ $quality }}">{{ $quality }}</option>@endforeach</select></div></div><p class="mt-3 text-xs text-slate-500">{{ __('requests.form.quality_notice') }}</p></x-ui.panel>
            @endif

            @if ($type === 'metadata_correction' || $type === 'episode_list_correction')
                <x-ui.panel :title="__('requests.form.correction_details')" icon="fa-solid fa-pen-to-square">@if ($type === 'metadata_correction')<div><label for="correction-field" class="block text-sm font-bold text-slate-700">{{ __('requests.fields.correction_field') }}</label><select id="correction-field" wire:model="correctionField" class="mt-2 min-h-11 w-full rounded-control border border-slate-300 px-3 py-2"><option value="">{{ __('requests.fields.not_specified') }}</option>@foreach ($correctionOptions as $option)<option value="{{ $option['value'] }}">{{ $option['label'] }}</option>@endforeach</select></div>@endif<div class="mt-4 grid gap-4 md:grid-cols-2"><div><label for="current-value" class="block text-sm font-bold text-slate-700">{{ __('requests.fields.current_value') }}</label><textarea id="current-value" wire:model="currentValue" rows="4" class="mt-2 w-full rounded-control border border-slate-300 p-3"></textarea></div><div><label for="proposed-value" class="block text-sm font-bold text-slate-700">{{ __('requests.fields.proposed_value') }}</label><textarea id="proposed-value" wire:model="proposedValue" rows="4" class="mt-2 w-full rounded-control border border-slate-300 p-3"></textarea></div></div><p class="mt-3 text-xs text-slate-500">{{ __('requests.form.correction_notice') }}</p></x-ui.panel>
            @endif

            <x-ui.panel :title="__('requests.form.evidence_title')" icon="fa-solid fa-link">
                <div class="space-y-3">@foreach ($sourceLinks as $index => $sourceLink)<div wire:key="source-link-{{ $index }}" class="grid gap-2 sm:grid-cols-[minmax(0,1fr)_auto]"><x-form.field :label="__('requests.fields.source_link_number', ['number' => $index + 1])" for="source-link-{{ $index }}" type="url" wire:model="sourceLinks.{{ $index }}" /><button type="button" wire:click="removeSourceLink({{ $index }})" class="min-h-11 self-end rounded-control bg-slate-100 px-3 py-2 text-sm font-bold text-slate-700">{{ __('requests.actions.remove') }}</button></div>@endforeach</div><button type="button" wire:click="addSourceLink" class="mt-3 min-h-11 rounded-control bg-slate-100 px-4 py-2 text-sm font-bold text-slate-700">{{ __('requests.actions.add_source') }}</button>
                <p class="mt-3 text-xs leading-5 text-slate-500">{{ __('requests.form.source_notice') }}</p>
                <div class="mt-5 space-y-3">@foreach ($externalIdentifiers as $index => $identifier)<div wire:key="external-id-{{ $index }}" class="grid gap-2 sm:grid-cols-[12rem_minmax(0,1fr)_auto]"><div><label for="provider-{{ $index }}" class="block text-sm font-bold text-slate-700">{{ __('requests.fields.provider') }}</label><select id="provider-{{ $index }}" wire:model="externalIdentifiers.{{ $index }}.provider" class="mt-2 min-h-11 w-full rounded-control border border-slate-300 px-3 py-2">@foreach ($providerOptions as $option)<option value="{{ $option['value'] }}">{{ $option['label'] }}</option>@endforeach</select></div><x-form.field :label="__('requests.fields.external_id')" for="external-id-{{ $index }}" wire:model="externalIdentifiers.{{ $index }}.identifier" /><button type="button" wire:click="removeExternalIdentifier({{ $index }})" class="min-h-11 self-end rounded-control bg-slate-100 px-3 py-2 text-sm font-bold text-slate-700">{{ __('requests.actions.remove') }}</button></div>@endforeach</div><button type="button" wire:click="addExternalIdentifier" class="mt-3 min-h-11 rounded-control bg-slate-100 px-4 py-2 text-sm font-bold text-slate-700">{{ __('requests.actions.add_external_id') }}</button>
            </x-ui.panel>

            <x-ui.panel :title="__('requests.fields.explanation')" icon="fa-solid fa-message"><label for="request-explanation" class="sr-only">{{ __('requests.fields.explanation') }}</label><textarea id="request-explanation" wire:model="explanation" rows="5" class="w-full rounded-control border border-slate-300 p-3" placeholder="{{ __('requests.form.explanation_placeholder') }}"></textarea><x-form.input-error for="explanation" id="request-explanation-error" /><div class="mt-4"><label for="different-explanation" class="block text-sm font-bold text-slate-700">{{ __('requests.fields.different_explanation') }}</label><textarea id="different-explanation" wire:model="differentExplanation" rows="3" class="mt-2 w-full rounded-control border border-slate-300 p-3"></textarea><p class="mt-2 text-xs text-slate-500">{{ __('requests.form.different_notice') }}</p></div></x-ui.panel>

            @if ($actionError)<div role="alert" aria-live="assertive" class="rounded-control bg-rose-50 p-4 text-sm font-bold text-rose-800">{{ $actionError }} @if ($canonicalUrl)<a href="{{ $canonicalUrl }}" class="ml-2 underline">{{ __('requests.actions.open_match') }}</a>@endif</div>@endif
            <div wire:loading.delay wire:target="submit" role="status" aria-live="polite" class="rounded-control bg-sky-50 p-3 text-sm font-bold text-sky-700">{{ __('requests.states.submitting') }}</div>
            @if ($searchPerformed)
                <button type="submit" wire:loading.attr="disabled" wire:target="submit" class="inline-flex min-h-12 w-full items-center justify-center gap-2 rounded-control bg-emerald-700 px-5 py-3 font-bold text-white hover:bg-emerald-600 disabled:cursor-wait disabled:opacity-60 sm:w-auto"><x-ui.icon name="fa-solid fa-paper-plane" />{{ __('requests.actions.submit') }}</button>
            @else
                <p role="status" class="rounded-control bg-amber-50 p-4 text-sm font-bold text-amber-800">{{ __('requests.form.search_required') }}</p>
            @endif
        </form>
    @endif
</div>
