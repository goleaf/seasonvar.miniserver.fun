<div class="mx-auto max-w-6xl space-y-6">
    <header class="rounded-panel border border-slate-200 bg-white p-5 shadow-panel sm:p-7">
        <p class="text-sm font-bold uppercase tracking-wide text-emerald-700">{{ __('onboarding.eyebrow') }}</p>
        <h1 class="mt-2 text-3xl font-black tracking-tight text-slate-900 sm:text-4xl">{{ __('onboarding.title') }}</h1>
        <p class="mt-3 max-w-3xl text-sm leading-6 text-slate-600 sm:text-base">{{ __('onboarding.intro') }}</p>
        <div class="mt-5 flex items-center gap-3" role="status" aria-live="polite">
            <span class="grid h-11 w-11 place-items-center rounded-full bg-emerald-50 text-sm font-black text-emerald-800">
                {{ count($likedTitleIds) }}
            </span>
            <p class="text-sm font-semibold text-slate-700">{{ __('onboarding.progress', ['count' => count($likedTitleIds)]) }}</p>
        </div>
    </header>

    <form wire:submit="save" class="space-y-6">
        <section class="rounded-panel border border-slate-200 bg-white p-5 shadow-panel sm:p-7" aria-labelledby="liked-title-heading">
            <h2 id="liked-title-heading" class="text-xl font-black text-slate-900">{{ __('onboarding.sections.liked') }}</h2>
            <p class="mt-2 text-sm leading-6 text-slate-600">{{ __('onboarding.hints.liked') }}</p>
            <label for="liked-title-search" class="mt-5 block text-sm font-bold text-slate-800">{{ __('onboarding.actions.search_titles') }}</label>
            <input
                id="liked-title-search"
                type="search"
                wire:model.live.debounce.350ms="likedSearch"
                autocomplete="off"
                class="mt-2 min-h-11 w-full rounded-control border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 focus:border-emerald-600 focus:outline-none focus:ring-2 focus:ring-emerald-100"
                placeholder="{{ __('onboarding.placeholders.search') }}"
            >
            <x-form.input-error for="likedTitleIds" class="mt-2" />
            <p wire:loading wire:target="likedSearch" class="mt-3 text-sm font-semibold text-slate-500" role="status">{{ __('onboarding.states.searching') }}</p>

            @if ($likedSuggestions !== [])
                <ul class="mt-3 grid gap-2 sm:grid-cols-2" aria-label="{{ __('onboarding.sections.search_results') }}">
                    @foreach ($likedSuggestions as $title)
                        <li>
                            <button type="button" wire:click="addLikedTitle({{ $title['id'] }})" wire:loading.attr="disabled" wire:target="likedSearch" class="flex min-h-11 w-full items-center justify-between rounded-control border border-slate-200 px-3 py-2 text-left text-sm font-semibold text-slate-800 hover:border-emerald-400 hover:bg-emerald-50 disabled:cursor-wait disabled:opacity-60">
                                <span>{{ $title['title'] }}</span>
                                <span class="ml-3 text-xs text-slate-500">{{ $title['year'] }}</span>
                            </button>
                        </li>
                    @endforeach
                </ul>
            @elseif ($likedSearchReady)
                <p class="mt-3 rounded-control bg-slate-50 px-3 py-3 text-sm text-slate-600" role="status">{{ __('onboarding.states.no_results') }}</p>
            @endif

            <ul class="mt-4 grid gap-2 sm:grid-cols-2" aria-label="{{ __('onboarding.sections.selected_liked') }}">
                @foreach ($likedTitles as $title)
                    <li class="flex min-h-11 items-center justify-between rounded-control bg-slate-50 px-3 py-2">
                        <span class="min-w-0 break-words text-sm font-semibold text-slate-800">{{ $title['title'] }}</span>
                        <button type="button" wire:click="removeLikedTitle({{ $title['id'] }})" class="ml-3 inline-flex min-h-11 min-w-11 items-center justify-center rounded-control text-slate-500 hover:bg-slate-200" aria-label="{{ __('onboarding.actions.remove_title', ['title' => $title['title']]) }}">
                            <x-ui.icon name="fa-solid fa-xmark" />
                        </button>
                    </li>
                @endforeach
            </ul>
            @if ($likedTitles === [])
                <p class="mt-4 text-sm text-slate-500" role="status">{{ __('onboarding.states.no_liked_titles') }}</p>
            @endif
        </section>

        <div class="grid gap-6 lg:grid-cols-2">
            <fieldset class="rounded-panel border border-slate-200 bg-white p-5 shadow-panel sm:p-7">
                <legend class="px-1 text-xl font-black text-slate-900">{{ __('onboarding.sections.genres') }}</legend>
                <p class="mt-2 text-sm text-slate-600">{{ __('onboarding.hints.genres') }}</p>
                <div class="mt-4 grid gap-2 sm:grid-cols-2">
                    @foreach ($genres as $genre)
                        <label class="flex min-h-11 cursor-pointer items-center gap-3 rounded-control border border-slate-200 px-3 py-2 text-sm font-semibold text-slate-800 hover:bg-slate-50">
                            <input type="checkbox" value="{{ $genre['id'] }}" wire:model="genreIds" class="h-5 w-5 rounded border-slate-300 text-emerald-700 focus:ring-emerald-600">
                            <span>{{ $genre['name'] }}</span>
                        </label>
                    @endforeach
                </div>
                <x-form.input-error for="genreIds" class="mt-2" />
            </fieldset>

            <fieldset class="rounded-panel border border-slate-200 bg-white p-5 shadow-panel sm:p-7">
                <legend class="px-1 text-xl font-black text-slate-900">{{ __('onboarding.sections.countries') }}</legend>
                <p class="mt-2 text-sm text-slate-600">{{ __('onboarding.hints.countries') }}</p>
                <div class="mt-4 max-h-96 space-y-2 overflow-y-auto pr-1">
                    @foreach ($countries as $country)
                        <label class="flex min-h-11 cursor-pointer items-center gap-3 rounded-control border border-slate-200 px-3 py-2 text-sm font-semibold text-slate-800 hover:bg-slate-50">
                            <input type="checkbox" value="{{ $country['id'] }}" wire:model="countryIds" class="h-5 w-5 rounded border-slate-300 text-emerald-700 focus:ring-emerald-600">
                            <span>{{ $country['name'] }}</span>
                        </label>
                    @endforeach
                </div>
                <x-form.input-error for="countryIds" class="mt-2" />
            </fieldset>
        </div>

        <section class="rounded-panel border border-slate-200 bg-white p-5 shadow-panel sm:p-7">
            <h2 class="text-xl font-black text-slate-900">{{ __('onboarding.sections.preferences') }}</h2>
            <div class="mt-5 grid gap-5 md:grid-cols-2">
                <label class="block text-sm font-bold text-slate-800">
                    {{ __('onboarding.fields.locale') }}
                    <select wire:model="locale" class="mt-2 min-h-11 w-full rounded-control border border-slate-300 bg-white px-3 py-2 font-normal">
                        <option value="ru">{{ __('onboarding.options.locale.ru') }}</option>
                        <option value="en">{{ __('onboarding.options.locale.en') }}</option>
                    </select>
                    <span class="mt-2 block text-xs font-normal leading-5 text-slate-500">{{ __('onboarding.hints.locale') }}</span>
                </label>

                @foreach (['playbackPreference' => 'playback', 'completionPreference' => 'completion', 'episodeLengthPreference' => 'episode_length'] as $property => $field)
                    <label class="block text-sm font-bold text-slate-800">
                        {{ __('onboarding.fields.'.$field) }}
                        <select wire:model="{{ $property }}" class="mt-2 min-h-11 w-full rounded-control border border-slate-300 bg-white px-3 py-2 font-normal">
                            @foreach (__('onboarding.options.'.$field) as $value => $label)
                                <option value="{{ $value }}">{{ $label }}</option>
                            @endforeach
                        </select>
                    </label>
                @endforeach
            </div>
        </section>

        <section class="rounded-panel border border-slate-200 bg-white p-5 shadow-panel sm:p-7" aria-labelledby="excluded-title-heading">
            <h2 id="excluded-title-heading" class="text-xl font-black text-slate-900">{{ __('onboarding.sections.excluded') }}</h2>
            <p class="mt-2 text-sm leading-6 text-slate-600">{{ __('onboarding.hints.excluded') }}</p>
            <label for="excluded-title-search" class="mt-5 block text-sm font-bold text-slate-800">{{ __('onboarding.actions.search_titles') }}</label>
            <input id="excluded-title-search" type="search" wire:model.live.debounce.350ms="excludedSearch" autocomplete="off" class="mt-2 min-h-11 w-full rounded-control border border-slate-300 bg-white px-3 py-2 text-sm" placeholder="{{ __('onboarding.placeholders.search') }}">
            <x-form.input-error for="excludedTitleIds" class="mt-2" />
            <p wire:loading wire:target="excludedSearch" class="mt-3 text-sm font-semibold text-slate-500" role="status">{{ __('onboarding.states.searching') }}</p>

            @if ($excludedSuggestions !== [])
                <ul class="mt-3 grid gap-2 sm:grid-cols-2">
                    @foreach ($excludedSuggestions as $title)
                        <li>
                            <button type="button" wire:click="addExcludedTitle({{ $title['id'] }})" wire:loading.attr="disabled" wire:target="excludedSearch" class="flex min-h-11 w-full items-center justify-between rounded-control border border-slate-200 px-3 py-2 text-left text-sm font-semibold hover:border-rose-300 hover:bg-rose-50 disabled:cursor-wait disabled:opacity-60">
                                <span>{{ $title['title'] }}</span>
                                <span class="ml-3 text-xs text-slate-500">{{ $title['year'] }}</span>
                            </button>
                        </li>
                    @endforeach
                </ul>
            @elseif ($excludedSearchReady)
                <p class="mt-3 rounded-control bg-slate-50 px-3 py-3 text-sm text-slate-600" role="status">{{ __('onboarding.states.no_results') }}</p>
            @endif

            <ul class="mt-4 grid gap-2 sm:grid-cols-2">
                @foreach ($excludedTitles as $title)
                    <li class="flex min-h-11 items-center justify-between rounded-control bg-rose-50 px-3 py-2">
                        <span class="min-w-0 break-words text-sm font-semibold text-slate-800">{{ $title['title'] }}</span>
                        <button type="button" wire:click="removeExcludedTitle({{ $title['id'] }})" class="ml-3 inline-flex min-h-11 min-w-11 items-center justify-center rounded-control text-slate-500 hover:bg-rose-100" aria-label="{{ __('onboarding.actions.remove_title', ['title' => $title['title']]) }}">
                            <x-ui.icon name="fa-solid fa-xmark" />
                        </button>
                    </li>
                @endforeach
            </ul>
            @if ($excludedTitles === [])
                <p class="mt-4 text-sm text-slate-500" role="status">{{ __('onboarding.states.no_excluded_titles') }}</p>
            @endif
        </section>

        <x-form.input-error for="onboarding" />
        <div class="sticky bottom-3 z-10 flex flex-col gap-3 rounded-panel border border-slate-200 bg-white/95 p-3 shadow-panel backdrop-blur sm:flex-row sm:justify-end">
            <button type="button" wire:click="skip" wire:loading.attr="disabled" class="inline-flex min-h-11 items-center justify-center rounded-control bg-slate-100 px-5 py-2.5 text-sm font-bold text-slate-700 hover:bg-slate-200 disabled:opacity-60">
                {{ __('onboarding.actions.later') }}
            </button>
            <button type="submit" wire:loading.attr="disabled" wire:target="save" class="inline-flex min-h-11 items-center justify-center gap-2 rounded-control bg-emerald-700 px-5 py-2.5 text-sm font-bold text-white hover:bg-emerald-800 disabled:cursor-wait disabled:opacity-60">
                <span wire:loading.remove wire:target="save">{{ __('onboarding.actions.save') }}</span>
                <span wire:loading wire:target="save">{{ __('onboarding.actions.saving') }}</span>
            </button>
        </div>
    </form>
</div>
