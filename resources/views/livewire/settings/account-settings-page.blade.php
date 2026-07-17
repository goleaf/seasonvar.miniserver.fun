<div
    class="mx-auto max-w-7xl space-y-5"
    data-account-settings
    data-account-storage-key="{{ $anonymousStorageKey }}"
    data-settings-unsaved-confirm="{{ __('settings.confirm.unsaved_changes') }}"
>
    <header class="rounded-panel border border-slate-200 bg-white p-4 shadow-panel sm:p-6">
        <div class="flex min-w-0 items-start gap-3">
            <span class="grid h-11 w-11 shrink-0 place-items-center rounded-control bg-emerald-50 text-emerald-700">
                <x-ui.icon name="fa-solid fa-gear" />
            </span>
            <div class="min-w-0">
                <h1 class="break-words text-2xl font-black tracking-tight text-slate-900 sm:text-3xl">{{ __('settings.title') }}</h1>
                <p class="mt-2 text-sm leading-6 text-slate-600">{{ __('settings.description') }}</p>
            </div>
        </div>
    </header>

    <div aria-live="polite" aria-atomic="true">
        @if ($statusMessage !== null)
            <div class="rounded-control border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-bold text-emerald-800" role="status">
                <x-ui.icon name="fa-solid fa-circle-check" /> {{ $statusMessage }}
            </div>
        @endif
        @if ($actionError !== null)
            <div class="rounded-control border border-rose-200 bg-rose-50 px-4 py-3 text-sm font-bold text-rose-800" role="alert">
                <x-ui.icon name="fa-solid fa-circle-exclamation" /> {{ $actionError }}
            </div>
        @endif
    </div>

    <div class="grid min-w-0 gap-5 lg:grid-cols-[17rem_minmax(0,1fr)] lg:items-start">
        <nav aria-label="{{ __('settings.navigation.label') }}" class="rounded-panel border border-slate-200 bg-white p-2 shadow-panel lg:sticky lg:top-24">
            <ul class="grid gap-1 sm:grid-cols-2 lg:grid-cols-1">
                @foreach ($navigation as $item)
                    <li>
                        <a
                            href="{{ $item['url'] }}"
                            wire:navigate
                            @if ($item['active']) aria-current="page" @endif
                            @class([
                                'flex min-h-11 min-w-0 items-center gap-3 rounded-control px-3 py-2.5 text-sm font-bold transition',
                                'bg-emerald-50 text-emerald-800' => $item['active'],
                                'text-slate-600 hover:bg-slate-50 hover:text-slate-900' => ! $item['active'],
                            ])
                        >
                            <x-ui.icon :name="$item['icon']" />
                            <span class="break-words">{{ $item['label'] }}</span>
                        </a>
                    </li>
                @endforeach
            </ul>
        </nav>

        <div class="min-w-0">
            @switch($activeSection->value)
                @case('profile')
                    <section aria-labelledby="settings-profile-title" class="rounded-panel border border-slate-200 bg-white p-4 shadow-panel sm:p-6">
                        <div class="flex min-w-0 items-start gap-3">
                            <span class="grid h-11 w-11 shrink-0 place-items-center rounded-control bg-slate-50 text-emerald-700"><x-ui.icon name="fa-solid fa-user" /></span>
                            <div class="min-w-0">
                                <h2 id="settings-profile-title" class="text-xl font-black text-slate-900">{{ __('settings.profile.title') }}</h2>
                                <p class="mt-1 text-sm leading-6 text-slate-600">{{ __('settings.profile.description') }}</p>
                            </div>
                        </div>
                        <dl class="mt-5 grid gap-3 sm:grid-cols-2">
                            <div class="rounded-control bg-slate-50 p-4">
                                <dt class="text-xs font-black uppercase tracking-wide text-slate-500">{{ __('settings.profile.display_name') }}</dt>
                                <dd class="mt-1 break-words font-bold text-slate-800">{{ $profileSummary['name'] }}</dd>
                            </div>
                            <div class="rounded-control bg-slate-50 p-4">
                                <dt class="text-xs font-black uppercase tracking-wide text-slate-500">{{ __('settings.profile.email') }}</dt>
                                <dd class="mt-1 break-all font-bold text-slate-800">{{ $profileSummary['email'] }}</dd>
                                <dd class="mt-1 text-xs font-semibold text-slate-500">{{ $profileSummary['verified'] ? __('settings.profile.email_verified') : __('settings.profile.email_unverified') }}</dd>
                            </div>
                        </dl>
                        <div class="mt-5 rounded-control border border-slate-200 bg-slate-50 p-4 text-sm leading-6 text-slate-600">
                            {{ __('settings.profile.canonical_hint') }}
                        </div>
                        <a href="{{ route('profile.show') }}" wire:navigate class="mt-5 inline-flex min-h-11 w-full items-center justify-center gap-2 rounded-control bg-emerald-700 px-4 py-2.5 text-sm font-bold text-white hover:bg-emerald-600 sm:w-auto">
                            <x-ui.icon name="fa-solid fa-pen-to-square" /> {{ __('settings.profile.open_editor') }}
                        </a>
                    </section>
                    @break

                @case('appearance')
                    <form wire:submit="saveAppearance" class="rounded-panel border border-slate-200 bg-white p-4 shadow-panel sm:p-6" novalidate>
                        <h2 class="text-xl font-black text-slate-900">{{ __('settings.appearance.title') }}</h2>
                        <p class="mt-1 text-sm leading-6 text-slate-600">{{ __('settings.appearance.description') }}</p>

                        <div class="mt-5 grid gap-5">
                            <label for="settings-locale" class="block text-sm font-bold text-slate-700">
                                {{ __('settings.appearance.locale') }}
                                <select id="settings-locale" wire:model="locale" class="mt-2 min-h-11 w-full rounded-control border border-slate-300 bg-white px-3 py-2.5 text-sm">
                                    @foreach ($localeOptions as $option)
                                        <option value="{{ $option['value'] }}">{{ $option['label'] }}</option>
                                    @endforeach
                                </select>
                            </label>
                            @error('locale') <p class="text-sm font-semibold text-rose-700" role="alert">{{ $message }}</p> @enderror
                            <p class="-mt-3 text-sm leading-6 text-slate-500">{{ __('settings.appearance.locale_hint') }}</p>

                            <label for="settings-timezone" class="block text-sm font-bold text-slate-700">
                                {{ __('settings.appearance.timezone') }}
                                <input id="settings-timezone" type="text" list="settings-timezones" wire:model="timezone" autocomplete="off" class="mt-2 min-h-11 w-full rounded-control border border-slate-300 bg-white px-3 py-2.5 text-sm" />
                            </label>
                            <datalist id="settings-timezones">
                                @foreach ($timezoneOptions as $timezoneOption)
                                    <option value="{{ $timezoneOption }}"></option>
                                @endforeach
                            </datalist>
                            @error('timezone') <p class="text-sm font-semibold text-rose-700" role="alert">{{ $message }}</p> @enderror
                            <p class="-mt-3 text-sm leading-6 text-slate-500">{{ __('settings.appearance.timezone_hint') }}</p>
                            <div class="-mt-3 flex flex-col items-start gap-2 sm:flex-row sm:items-center">
                                <button type="button" data-settings-use-browser-timezone data-settings-timezone-detected="{{ __('settings.appearance.timezone_detected') }}" data-settings-timezone-unavailable="{{ __('settings.appearance.timezone_unavailable') }}" class="inline-flex min-h-11 items-center gap-2 rounded-control bg-slate-100 px-3 py-2 text-sm font-bold text-slate-700 hover:bg-slate-200"><x-ui.icon name="fa-solid fa-location-dot" />{{ __('settings.appearance.use_browser_timezone') }}</button>
                                <span data-settings-browser-timezone-status class="text-sm font-semibold text-slate-500" aria-live="polite"></span>
                            </div>

                            <div class="rounded-control bg-slate-50 p-4">
                                <span class="text-xs font-black uppercase tracking-wide text-slate-500">{{ __('settings.appearance.time_preview') }}</span>
                                <p class="mt-1 font-bold text-slate-800">{{ $currentTimePreview }}</p>
                            </div>

                            <label class="flex min-h-12 items-start gap-3 rounded-control bg-slate-50 p-4 text-sm font-bold text-slate-700">
                                <input type="checkbox" wire:model="reducedMotion" class="mt-0.5 h-5 w-5 rounded border-slate-300 text-emerald-700 focus:ring-emerald-600" />
                                <span><span class="block">{{ __('settings.appearance.reduced_motion') }}</span><span class="mt-1 block font-normal leading-5 text-slate-500">{{ __('settings.appearance.reduced_motion_hint') }}</span></span>
                            </label>
                        </div>

                        <div class="mt-6 flex flex-col gap-2 sm:flex-row">
                            <button type="submit" wire:loading.attr="disabled" wire:target="saveAppearance" class="inline-flex min-h-11 items-center justify-center gap-2 rounded-control bg-emerald-700 px-4 py-2.5 text-sm font-bold text-white hover:bg-emerald-600 disabled:opacity-60">
                                <x-ui.icon name="fa-solid fa-floppy-disk" /><span wire:loading.remove wire:target="saveAppearance">{{ __('settings.actions.save') }}</span><span wire:loading wire:target="saveAppearance">{{ __('settings.actions.saving') }}</span>
                            </button>
                            <button type="button" wire:click="cancelChanges" wire:loading.attr="disabled" class="inline-flex min-h-11 items-center justify-center rounded-control bg-slate-100 px-4 py-2.5 text-sm font-bold text-slate-700 hover:bg-slate-200">{{ __('settings.actions.cancel') }}</button>
                        </div>
                    </form>
                    @break

                @case('playback')
                    <form wire:submit="savePlayback" class="rounded-panel border border-slate-200 bg-white p-4 shadow-panel sm:p-6" novalidate>
                        <h2 class="text-xl font-black text-slate-900">{{ __('settings.playback.title') }}</h2>
                        <p class="mt-1 text-sm leading-6 text-slate-600">{{ __('settings.playback.description') }}</p>

                        <fieldset class="mt-5 grid gap-3 sm:grid-cols-2">
                            <legend class="sr-only">{{ __('settings.playback.behaviour') }}</legend>
                            @foreach ([
                                'autoplay' => ['autoplay', 'autoplay_hint'],
                                'rememberVolume' => ['remember_volume', 'remember_volume_hint'],
                                'muted' => ['muted', 'muted_hint'],
                                'subtitlesEnabled' => ['subtitles_enabled', 'subtitles_enabled_hint'],
                                'keyboardShortcutsEnabled' => ['keyboard_shortcuts', 'keyboard_shortcuts_hint'],
                            ] as $property => $copy)
                                <label class="flex min-h-12 items-start gap-3 rounded-control bg-slate-50 p-4 text-sm font-bold text-slate-700">
                                    <input type="checkbox" wire:model="{{ $property }}" class="mt-0.5 h-5 w-5 rounded border-slate-300 text-emerald-700 focus:ring-emerald-600" />
                                    <span><span class="block">{{ __('settings.playback.'.$copy[0]) }}</span><span class="mt-1 block font-normal leading-5 text-slate-500">{{ __('settings.playback.'.$copy[1]) }}</span></span>
                                </label>
                            @endforeach
                        </fieldset>

                        <div class="mt-5 grid gap-5 sm:grid-cols-2">
                            <label for="settings-volume" class="block text-sm font-bold text-slate-700 sm:col-span-2">
                                <span class="flex items-center justify-between gap-3"><span>{{ __('settings.playback.volume') }}</span><output data-settings-volume-output class="tabular-nums">{{ $volume }}%</output></span>
                                <input id="settings-volume" data-settings-volume-input type="range" min="0" max="100" step="1" wire:model="volume" class="mt-3 h-11 w-full accent-emerald-700" />
                            </label>
                            @error('volume') <p class="text-sm font-semibold text-rose-700 sm:col-span-2" role="alert">{{ $message }}</p> @enderror

                            <label for="settings-playback-speed" class="block text-sm font-bold text-slate-700">
                                {{ __('settings.playback.speed') }}
                                <select id="settings-playback-speed" wire:model="playbackSpeed" class="mt-2 min-h-11 w-full rounded-control border border-slate-300 bg-white px-3 py-2.5 text-sm">
                                    @foreach ($speedOptions as $speedOption)<option value="{{ $speedOption }}">{{ rtrim(rtrim($speedOption, '0'), '.') }}×</option>@endforeach
                                </select>
                            </label>

                            <label for="settings-quality" class="block text-sm font-bold text-slate-700">
                                {{ __('settings.playback.quality') }}
                                <select id="settings-quality" wire:model="preferredQuality" class="mt-2 min-h-11 w-full rounded-control border border-slate-300 bg-white px-3 py-2.5 text-sm">
                                    <option value="">{{ __('settings.playback.quality_auto') }}</option>
                                    @foreach ($qualityOptions as $qualityOption)<option value="{{ $qualityOption['value'] }}">{{ $qualityOption['label'] }}</option>@endforeach
                                </select>
                            </label>

                            @if ($variantOptions !== [])
                                <label for="settings-variant" class="block text-sm font-bold text-slate-700 sm:col-span-2">
                                    {{ __('settings.playback.translation') }}
                                    <select id="settings-variant" wire:model="preferredVariant" class="mt-2 min-h-11 w-full rounded-control border border-slate-300 bg-white px-3 py-2.5 text-sm">
                                        <option value="">{{ __('settings.playback.translation_auto') }}</option>
                                        @foreach ($variantOptions as $variantOption)<option value="{{ $variantOption['value'] }}">{{ $variantOption['label'] }}</option>@endforeach
                                    </select>
                                </label>
                            @endif
                        </div>

                        <p class="mt-5 rounded-control border border-slate-200 bg-slate-50 p-4 text-sm leading-6 text-slate-600">{{ __('settings.playback.precedence_hint') }}</p>
                        <div class="mt-6 flex flex-col gap-2 sm:flex-row sm:flex-wrap">
                            <button type="submit" wire:loading.attr="disabled" wire:target="savePlayback" class="inline-flex min-h-11 items-center justify-center gap-2 rounded-control bg-emerald-700 px-4 py-2.5 text-sm font-bold text-white hover:bg-emerald-600 disabled:opacity-60"><x-ui.icon name="fa-solid fa-floppy-disk" /><span wire:loading.remove wire:target="savePlayback">{{ __('settings.actions.save') }}</span><span wire:loading wire:target="savePlayback">{{ __('settings.actions.saving') }}</span></button>
                            <button type="button" wire:click="cancelChanges" wire:loading.attr="disabled" class="inline-flex min-h-11 items-center justify-center rounded-control bg-slate-100 px-4 py-2.5 text-sm font-bold text-slate-700 hover:bg-slate-200">{{ __('settings.actions.cancel') }}</button>
                            <button type="button" wire:click="resetPlayback" wire:confirm="{{ __('settings.confirm.reset_playback') }}" wire:loading.attr="disabled" wire:target="resetPlayback" class="inline-flex min-h-11 items-center justify-center gap-2 rounded-control bg-amber-50 px-4 py-2.5 text-sm font-bold text-amber-800 hover:bg-amber-100"><x-ui.icon name="fa-solid fa-rotate-left" />{{ __('settings.actions.reset_defaults') }}</button>
                        </div>
                    </form>
                    @break

                @case('privacy')
                    <section aria-labelledby="settings-privacy-title" class="rounded-panel border border-slate-200 bg-white p-4 shadow-panel sm:p-6">
                        <h2 id="settings-privacy-title" class="text-xl font-black text-slate-900">{{ __('settings.privacy.title') }}</h2>
                        <p class="mt-1 text-sm leading-6 text-slate-600">{{ __('settings.privacy.description') }}</p>
                        <div class="mt-5 grid gap-3">
                            @foreach (['history', 'progress', 'library', 'personal_tags'] as $privacyItem)
                                <div class="flex min-w-0 items-start gap-3 rounded-control bg-slate-50 p-4">
                                    <x-ui.icon name="fa-solid fa-lock text-emerald-700" />
                                    <div class="min-w-0"><h3 class="font-black text-slate-800">{{ __('settings.privacy.'.$privacyItem) }}</h3><p class="mt-1 text-sm leading-6 text-slate-600">{{ __('settings.privacy.'.$privacyItem.'_hint') }}</p></div>
                                </div>
                            @endforeach
                        </div>
                        <div class="mt-5 flex flex-col gap-2 sm:flex-row sm:flex-wrap">
                            <a href="{{ route('library.section', ['section' => 'history']) }}" wire:navigate class="inline-flex min-h-11 items-center justify-center gap-2 rounded-control bg-slate-100 px-4 py-2.5 text-sm font-bold text-slate-700 hover:bg-slate-200"><x-ui.icon name="fa-solid fa-clock-rotate-left" />{{ __('settings.privacy.open_history') }}</a>
                            <a href="{{ route('profile.discussions') }}" wire:navigate class="inline-flex min-h-11 items-center justify-center gap-2 rounded-control bg-slate-100 px-4 py-2.5 text-sm font-bold text-slate-700 hover:bg-slate-200"><x-ui.icon name="fa-solid fa-user-slash" />{{ __('settings.privacy.manage_relationships') }}</a>
                        </div>
                    </section>
                    @break

                @case('notifications')
                    <form wire:submit="saveNotifications" class="rounded-panel border border-slate-200 bg-white p-4 shadow-panel sm:p-6" novalidate>
                        <h2 class="text-xl font-black text-slate-900">{{ __('settings.notifications.title') }}</h2>
                        <p class="mt-1 text-sm leading-6 text-slate-600">{{ __('settings.notifications.description') }}</p>

                        @if (! $commentNotificationsAvailable && ! $reviewNotificationsAvailable && ! $contentRequestNotificationsAvailable && ! $releaseCalendarNotificationsAvailable)
                            <div class="mt-5 rounded-control bg-slate-50 p-5 text-sm leading-6 text-slate-600">{{ __('settings.notifications.unavailable') }}</div>
                        @else
                            <div class="mt-5 overflow-hidden rounded-control border border-slate-200">
                                <div class="grid grid-cols-[minmax(0,1fr)_6rem] bg-slate-50 px-3 py-3 text-xs font-black uppercase tracking-wide text-slate-500">
                                    <span>{{ __('settings.notifications.category') }}</span><span class="text-center">{{ __('settings.notifications.in_portal') }}</span>
                                </div>
                                <div class="divide-y divide-slate-200">
                                    @if ($commentNotificationsAvailable)
                                        @foreach ([
                                            'replyNotifications' => 'comment_replies',
                                            'reactionNotifications' => 'comment_reactions',
                                            'commentModerationNotifications' => 'comment_moderation',
                                            'commentReportNotifications' => 'comment_reports',
                                        ] as $property => $label)
                                            <label class="grid min-h-14 grid-cols-[minmax(0,1fr)_6rem] items-center gap-3 px-3 py-2 text-sm font-bold text-slate-700"><span class="break-words">{{ __('settings.notifications.'.$label) }}</span><span class="flex justify-center"><input type="checkbox" wire:model="{{ $property }}" class="h-5 w-5 rounded border-slate-300 text-emerald-700 focus:ring-emerald-600" /></span></label>
                                        @endforeach
                                    @endif
                                    @if ($reviewNotificationsAvailable)
                                        @foreach ([
                                            'reviewHelpfulNotifications' => 'review_helpful',
                                            'reviewModerationNotifications' => 'review_moderation',
                                            'reviewReportNotifications' => 'review_reports',
                                        ] as $property => $label)
                                            <label class="grid min-h-14 grid-cols-[minmax(0,1fr)_6rem] items-center gap-3 px-3 py-2 text-sm font-bold text-slate-700"><span class="break-words">{{ __('settings.notifications.'.$label) }}</span><span class="flex justify-center"><input type="checkbox" wire:model="{{ $property }}" class="h-5 w-5 rounded border-slate-300 text-emerald-700 focus:ring-emerald-600" /></span></label>
                                        @endforeach
                                    @endif
                                    @if ($contentRequestNotificationsAvailable)
                                        @foreach ([
                                            'requesterRequestNotifications' => 'request_requester_updates',
                                            'votedRequestNotifications' => 'request_voted_updates',
                                            'followedRequestNotifications' => 'request_followed_updates',
                                        ] as $property => $label)
                                            <label class="grid min-h-14 grid-cols-[minmax(0,1fr)_6rem] items-center gap-3 px-3 py-2 text-sm font-bold text-slate-700"><span class="break-words">{{ __('settings.notifications.'.$label) }}</span><span class="flex justify-center"><input type="checkbox" wire:model="{{ $property }}" class="h-5 w-5 rounded border-slate-300 text-emerald-700 focus:ring-emerald-600" /></span></label>
                                        @endforeach
                                    @endif
                                    @if ($releaseCalendarNotificationsAvailable)
                                        @foreach ([
                                            'releasePremiereNotifications' => 'release_premieres',
                                            'releaseSeasonNotifications' => 'release_seasons',
                                            'releaseEpisodeNotifications' => 'release_episodes',
                                            'releaseTranslationNotifications' => 'release_translations',
                                            'releaseSubtitleNotifications' => 'release_subtitles',
                                            'releaseDateChangeNotifications' => 'release_date_changes',
                                            'releasePostponedNotifications' => 'release_postponed',
                                            'releaseCancelledNotifications' => 'release_cancelled',
                                            'releasePortalPublicationNotifications' => 'release_portal_publications',
                                        ] as $property => $label)
                                            <label class="grid min-h-14 grid-cols-[minmax(0,1fr)_6rem] items-center gap-3 px-3 py-2 text-sm font-bold text-slate-700"><span class="break-words">{{ __('settings.notifications.'.$label) }}</span><span class="flex justify-center"><input type="checkbox" wire:model="{{ $property }}" class="h-5 w-5 rounded border-slate-300 text-emerald-700 focus:ring-emerald-600" /></span></label>
                                        @endforeach
                                    @endif
                                </div>
                            </div>
                            <p class="mt-4 rounded-control bg-slate-50 p-4 text-sm leading-6 text-slate-600">{{ __('settings.notifications.mandatory_security_hint') }}</p>
                            <div class="mt-6 flex flex-col gap-2 sm:flex-row sm:flex-wrap">
                                <button type="submit" wire:loading.attr="disabled" wire:target="saveNotifications" class="inline-flex min-h-11 items-center justify-center gap-2 rounded-control bg-emerald-700 px-4 py-2.5 text-sm font-bold text-white hover:bg-emerald-600 disabled:opacity-60"><x-ui.icon name="fa-solid fa-floppy-disk" /><span wire:loading.remove wire:target="saveNotifications">{{ __('settings.actions.save') }}</span><span wire:loading wire:target="saveNotifications">{{ __('settings.actions.saving') }}</span></button>
                                <button type="button" wire:click="cancelChanges" wire:loading.attr="disabled" class="inline-flex min-h-11 items-center justify-center rounded-control bg-slate-100 px-4 py-2.5 text-sm font-bold text-slate-700 hover:bg-slate-200">{{ __('settings.actions.cancel') }}</button>
                                <button type="button" wire:click="resetNotifications" wire:confirm="{{ __('settings.confirm.reset_notifications') }}" wire:loading.attr="disabled" class="inline-flex min-h-11 items-center justify-center gap-2 rounded-control bg-amber-50 px-4 py-2.5 text-sm font-bold text-amber-800 hover:bg-amber-100"><x-ui.icon name="fa-solid fa-rotate-left" />{{ __('settings.actions.reset_defaults') }}</button>
                            </div>
                        @endif
                    </form>
                    @break

                @case('collections')
                    <form wire:submit="saveCollections" class="rounded-panel border border-slate-200 bg-white p-4 shadow-panel sm:p-6" novalidate>
                        <h2 class="text-xl font-black text-slate-900">{{ __('settings.collections.title') }}</h2>
                        <p class="mt-1 text-sm leading-6 text-slate-600">{{ __('settings.collections.description') }}</p>
                        <fieldset class="mt-5 grid gap-3">
                            <legend class="text-sm font-black text-slate-700">{{ __('settings.collections.default_visibility') }}</legend>
                            @foreach ($visibilityOptions as $option)
                                <label class="flex min-h-12 items-start gap-3 rounded-control border border-slate-200 p-4 text-sm text-slate-700">
                                    <input type="radio" wire:model="collectionDefaultVisibility" value="{{ $option['value'] }}" class="mt-0.5 h-5 w-5 border-slate-300 text-emerald-700 focus:ring-emerald-600" />
                                    <span><span class="block font-black">{{ $option['label'] }}</span><span class="mt-1 block leading-5 text-slate-500">{{ $option['hint'] }}</span></span>
                                </label>
                            @endforeach
                        </fieldset>
                        <p class="mt-4 rounded-control bg-slate-50 p-4 text-sm leading-6 text-slate-600">{{ __('settings.collections.non_retroactive_hint') }}</p>
                        <div class="mt-6 flex flex-col gap-2 sm:flex-row">
                            <button type="submit" wire:loading.attr="disabled" wire:target="saveCollections" class="inline-flex min-h-11 items-center justify-center gap-2 rounded-control bg-emerald-700 px-4 py-2.5 text-sm font-bold text-white hover:bg-emerald-600 disabled:opacity-60"><x-ui.icon name="fa-solid fa-floppy-disk" /><span wire:loading.remove wire:target="saveCollections">{{ __('settings.actions.save') }}</span><span wire:loading wire:target="saveCollections">{{ __('settings.actions.saving') }}</span></button>
                            <button type="button" wire:click="cancelChanges" wire:loading.attr="disabled" class="inline-flex min-h-11 items-center justify-center rounded-control bg-slate-100 px-4 py-2.5 text-sm font-bold text-slate-700 hover:bg-slate-200">{{ __('settings.actions.cancel') }}</button>
                            <a href="{{ route('collections.mine') }}" wire:navigate class="inline-flex min-h-11 items-center justify-center gap-2 rounded-control bg-slate-100 px-4 py-2.5 text-sm font-bold text-slate-700 hover:bg-slate-200"><x-ui.icon name="fa-solid fa-folder-open" />{{ __('settings.collections.manage') }}</a>
                        </div>
                    </form>
                    @break

                @case('security')
                    <section aria-labelledby="settings-security-title" class="rounded-panel border border-slate-200 bg-white p-4 shadow-panel sm:p-6">
                        <h2 id="settings-security-title" class="text-xl font-black text-slate-900">{{ __('settings.security.title') }}</h2>
                        <p class="mt-1 text-sm leading-6 text-slate-600">{{ __('settings.security.description') }}</p>
                        <div class="mt-5 grid gap-3 sm:grid-cols-2">
                            @foreach ([
                                ['fa-solid fa-key', 'password_email', 'password_email_hint'],
                                ['fa-solid fa-desktop', 'sessions', $databaseSessionsAvailable ? 'sessions_hint' : 'sessions_limited_hint'],
                                ['fa-solid fa-mobile-screen', 'api_devices', 'api_devices_hint'],
                                ['fa-solid fa-link', 'login_methods', 'login_methods_hint'],
                            ] as $card)
                                <div class="rounded-control bg-slate-50 p-4"><h3 class="flex items-center gap-2 font-black text-slate-800"><x-ui.icon :name="$card[0].' text-emerald-700'" />{{ __('settings.security.'.$card[1]) }}</h3><p class="mt-2 text-sm leading-6 text-slate-600">{{ __('settings.security.'.$card[2]) }}</p></div>
                            @endforeach
                        </div>
                        <a href="{{ route('profile.security') }}" wire:navigate class="mt-5 inline-flex min-h-11 w-full items-center justify-center gap-2 rounded-control bg-emerald-700 px-4 py-2.5 text-sm font-bold text-white hover:bg-emerald-600 sm:w-auto"><x-ui.icon name="fa-solid fa-shield-halved" />{{ __('settings.security.open') }}</a>
                    </section>
                    @break

                @case('data')
                    <section aria-labelledby="settings-data-title" class="space-y-4">
                        <div class="rounded-panel border border-slate-200 bg-white p-4 shadow-panel sm:p-6">
                            <h2 id="settings-data-title" class="text-xl font-black text-slate-900">{{ __('settings.data.title') }}</h2>
                            <p class="mt-1 text-sm leading-6 text-slate-600">{{ __('settings.data.description') }}</p>
                        </div>
                        <div class="rounded-panel border border-slate-200 bg-white p-4 shadow-panel sm:p-6">
                            <h3 class="text-lg font-black text-slate-900">{{ __('settings.data.export_title') }}</h3>
                            <p class="mt-2 text-sm leading-6 text-slate-600">{{ __('settings.data.export_hint') }}</p>
                            <a href="{{ route('profile.export') }}" class="mt-4 inline-flex min-h-11 w-full items-center justify-center gap-2 rounded-control bg-slate-100 px-4 py-2.5 text-sm font-bold text-slate-700 hover:bg-slate-200 sm:w-auto"><x-ui.icon name="fa-solid fa-file-arrow-down" />{{ __('settings.data.export') }}</a>
                        </div>
                        <div class="rounded-panel border border-rose-200 bg-white p-4 shadow-panel sm:p-6">
                            <h3 class="text-lg font-black text-rose-800">{{ __('settings.data.delete_title') }}</h3>
                            <p class="mt-2 text-sm leading-6 text-slate-600">{{ __('settings.data.delete_hint') }}</p>
                            <a href="{{ route('profile.security') }}#delete-account-title" wire:navigate class="mt-4 inline-flex min-h-11 w-full items-center justify-center gap-2 rounded-control bg-rose-50 px-4 py-2.5 text-sm font-bold text-rose-700 hover:bg-rose-100 sm:w-auto"><x-ui.icon name="fa-solid fa-trash-can" />{{ __('settings.data.open_deletion') }}</a>
                        </div>
                    </section>
                    @break
            @endswitch
        </div>
    </div>

    <p wire:loading.delay wire:target="saveAppearance,savePlayback,resetPlayback,saveCollections,saveNotifications,resetNotifications,cancelChanges" class="rounded-control bg-slate-50 px-4 py-3 text-sm font-bold text-slate-600" role="status">{{ __('settings.actions.loading') }}</p>
</div>
