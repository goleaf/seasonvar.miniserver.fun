<div class="mx-auto max-w-4xl space-y-5">
    <header class="rounded-panel border border-slate-200 bg-white p-5 shadow-panel sm:p-6">
        <p class="text-xs font-black uppercase tracking-widest text-emerald-700">{{ __('issues.title') }}</p>
        <h1 class="mt-2 text-2xl font-black text-slate-900 sm:text-3xl">{{ __('issues.create.title') }}</h1>
        <p class="mt-3 max-w-3xl text-sm leading-6 text-slate-600">{{ __('issues.create.description') }}</p>
    </header>

    @if ($statusMessage)
        <p role="status" aria-live="polite" class="rounded-control bg-emerald-50 p-4 text-sm font-bold text-emerald-800">{{ $statusMessage }}</p>
    @endif
    @if ($actionError)
        <p role="alert" class="rounded-control bg-rose-50 p-4 text-sm font-bold text-rose-800">{{ $actionError }}</p>
    @endif
    @if ($errors->any())
        <div role="alert" class="rounded-control bg-rose-50 p-4 text-sm text-rose-800">
            <p class="font-bold">{{ __('issues.errors.invalid_input') }}</p>
            <ul class="mt-2 list-disc space-y-1 pl-5">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif
    <div wire:loading.delay role="status" aria-live="polite" class="rounded-control bg-sky-50 p-3 text-sm font-bold text-sky-800">{{ __('issues.states.loading') }}</div>

    @if (! $schemaReady || $target === null)
        <x-ui.panel :title="__('issues.states.schema_unavailable')" icon="fa-solid fa-wrench">
            <p class="text-sm text-slate-600">{{ __('issues.create.unavailable') }}</p>
        </x-ui.panel>
    @else
        <form wire:submit="submit" class="space-y-5" data-technical-issue-form>
            <x-ui.panel :title="__('issues.fields.target')" icon="fa-solid fa-location-dot">
                <p class="break-words font-bold text-slate-800">{{ $target->label }}</p>
                <p class="mt-2 text-sm text-slate-600">{{ __('issues.hints.private') }}</p>
            </x-ui.panel>

            <x-ui.panel :title="__('issues.fields.issue_type')" icon="fa-solid fa-triangle-exclamation">
                <label for="technical-issue-type" class="block text-sm font-bold text-slate-700">{{ __('issues.fields.issue_type') }}</label>
                <select id="technical-issue-type" wire:model.live="type" required class="mt-2 min-h-11 w-full rounded-control border border-slate-300 bg-white px-3 text-slate-800 focus:border-emerald-600 focus:outline-none focus:ring-2 focus:ring-emerald-600/20">
                    <option value="">{{ __('issues.create.choose_type') }}</option>
                    @foreach ($availableTypes as $option)
                        <option value="{{ $option['value'] }}">{{ $option['label'] }}</option>
                    @endforeach
                </select>
                @if ($selectedType)
                    <p class="mt-3 rounded-control bg-slate-50 p-3 text-sm leading-6 text-slate-600">{{ $selectedType->help() }}</p>
                @endif
            </x-ui.panel>

            @if ($selectedType)
                <x-ui.panel :title="__('issues.create.details_title')" icon="fa-solid fa-list-check">
                    <div class="space-y-4">
                        <div>
                            <label for="technical-summary" class="block text-sm font-bold text-slate-700">{{ __('issues.fields.summary') }}</label>
                            <input id="technical-summary" wire:model.blur="summary" type="text" maxlength="240" autocomplete="off" class="mt-2 min-h-11 w-full rounded-control border border-slate-300 px-3 text-slate-800">
                            <p class="mt-1 text-xs text-slate-500">{{ __('issues.hints.summary') }}</p>
                        </div>
                        <div class="grid gap-4 lg:grid-cols-2">
                            <div>
                                <label for="technical-expected" class="block text-sm font-bold text-slate-700">{{ __('issues.fields.expected_behavior') }}</label>
                                <textarea id="technical-expected" wire:model.blur="expectedBehavior" rows="5" maxlength="4000" class="mt-2 w-full rounded-control border border-slate-300 p-3 text-slate-800"></textarea>
                            </div>
                            <div>
                                <label for="technical-actual" class="block text-sm font-bold text-slate-700">{{ __('issues.fields.actual_behavior') }} @if ($requiresActual)<span aria-hidden="true">*</span>@endif</label>
                                <textarea id="technical-actual" wire:model.blur="actualBehavior" rows="5" maxlength="4000" @required($requiresActual) class="mt-2 w-full rounded-control border border-slate-300 p-3 text-slate-800"></textarea>
                            </div>
                        </div>
                        <div>
                            <label for="technical-steps" class="block text-sm font-bold text-slate-700">{{ __('issues.fields.reproduction_steps') }} @if ($requiresSteps)<span aria-hidden="true">*</span>@endif</label>
                            <textarea id="technical-steps" wire:model.blur="reproductionSteps" rows="6" maxlength="6000" @required($requiresSteps) class="mt-2 w-full rounded-control border border-slate-300 p-3 text-slate-800"></textarea>
                            <p class="mt-1 text-xs text-slate-500">{{ __('issues.hints.steps') }}</p>
                        </div>

                        @if ($supportsTimestamp || $showAudio || $showSubtitles || $showQuality)
                            <div class="grid gap-4 sm:grid-cols-2">
                                @if ($supportsTimestamp)
                                    <div>
                                        <label for="technical-timestamp" class="block text-sm font-bold text-slate-700">{{ __('issues.fields.playback_timestamp') }} @if ($requiresTimestamp)<span aria-hidden="true">*</span>@endif</label>
                                        <input id="technical-timestamp" data-technical-issue-position wire:model="playbackPositionSeconds" type="number" min="0" max="86400" inputmode="numeric" @required($requiresTimestamp) class="mt-2 min-h-11 w-full rounded-control border border-slate-300 px-3">
                                        <p class="mt-1 text-xs text-slate-500">{{ __('issues.hints.timestamp') }}</p>
                                    </div>
                                @endif
                                @if ($showAudio)
                                    <div><label for="technical-audio" class="block text-sm font-bold text-slate-700">{{ __('issues.fields.audio_language') }} @if ($requiresAudio)<span aria-hidden="true">*</span>@endif</label><input id="technical-audio" wire:model="audioLanguage" type="text" maxlength="16" placeholder="{{ __('issues.fields.language_placeholder') }}" @required($requiresAudio) class="mt-2 min-h-11 w-full rounded-control border border-slate-300 px-3"></div>
                                @endif
                                @if ($showSubtitles)
                                    <div><label for="technical-subtitle" class="block text-sm font-bold text-slate-700">{{ __('issues.fields.subtitle_language') }} @if ($requiresSubtitles)<span aria-hidden="true">*</span>@endif</label><input id="technical-subtitle" wire:model="subtitleLanguage" type="text" maxlength="16" placeholder="{{ __('issues.fields.language_placeholder') }}" @required($requiresSubtitles) class="mt-2 min-h-11 w-full rounded-control border border-slate-300 px-3"></div>
                                @endif
                                @if ($showQuality)
                                    <div><label for="technical-quality" class="block text-sm font-bold text-slate-700">{{ __('issues.fields.quality') }} @if ($requiresQuality)<span aria-hidden="true">*</span>@endif</label><input id="technical-quality" wire:model="qualityCode" type="text" maxlength="24" autocomplete="off" @required($requiresQuality) @readonly($qualityLocked) class="mt-2 min-h-11 w-full rounded-control border border-slate-300 px-3 read-only:bg-slate-100"></div>
                                @endif
                            </div>
                        @endif
                    </div>
                </x-ui.panel>

                <x-ui.panel :title="__('issues.diagnostics.title')" icon="fa-solid fa-shield-halved">
                    <label class="flex min-h-11 cursor-pointer items-start gap-3 rounded-control border border-slate-200 p-3">
                        <input data-technical-issue-consent wire:model.live="diagnosticsConsent" type="checkbox" class="mt-1 size-5 rounded border-slate-300 text-emerald-700 focus:ring-emerald-600">
                        <span><span class="block font-bold text-slate-800">{{ __('issues.fields.diagnostics_consent') }}</span><span class="mt-1 block text-sm leading-6 text-slate-600">{{ __('issues.hints.diagnostics') }}</span></span>
                    </label>
                    <div data-technical-issue-diagnostics @if (! $diagnosticsConsent) hidden @endif class="mt-4 grid gap-3 rounded-control bg-slate-50 p-4 text-sm sm:grid-cols-2" aria-live="polite">
                        <input data-diagnostic="browserFamily" wire:model="browserFamily" type="hidden">
                        <input data-diagnostic="browserMajor" wire:model="browserMajor" type="hidden">
                        <input data-diagnostic="operatingSystem" wire:model="operatingSystem" type="hidden">
                        <input data-diagnostic="deviceCategory" wire:model="deviceCategory" type="hidden">
                        <input data-diagnostic="viewportWidth" wire:model="viewportWidth" type="hidden">
                        <input data-diagnostic="viewportHeight" wire:model="viewportHeight" type="hidden">
                        <input data-diagnostic="timezone" wire:model="timezone" type="hidden">
                        <input data-diagnostic="networkOnline" wire:model="networkOnline" type="hidden">
                        <p><strong>{{ __('issues.diagnostics.browser') }}:</strong> <span data-diagnostic-label="browser">{{ $browserFamily ?: __('issues.create.not_detected') }}</span></p>
                        <p><strong>{{ __('issues.diagnostics.operating_system') }}:</strong> <span data-diagnostic-label="os">{{ $operatingSystem ?: __('issues.create.not_detected') }}</span></p>
                        <p><strong>{{ __('issues.diagnostics.device') }}:</strong> <span data-diagnostic-label="device">{{ $deviceCategory ?: __('issues.create.not_detected') }}</span></p>
                        <p><strong>{{ __('issues.diagnostics.viewport') }}:</strong> <span data-diagnostic-label="viewport">{{ $viewportWidth && $viewportHeight ? $viewportWidth.' × '.$viewportHeight : __('issues.create.not_detected') }}</span></p>
                    </div>
                </x-ui.panel>

                <x-ui.panel :title="__('issues.fields.screenshots')" icon="fa-solid fa-image">
                    <label for="technical-screenshots" class="block text-sm font-bold text-slate-700">{{ __('issues.fields.screenshots') }}</label>
                    <input id="technical-screenshots" wire:model="screenshots" type="file" accept="image/jpeg,image/png,image/webp" multiple class="mt-2 block w-full text-sm text-slate-600 file:mr-3 file:min-h-11 file:rounded-control file:border-0 file:bg-slate-100 file:px-3 file:py-2.5 file:font-bold file:text-slate-700 hover:file:bg-slate-200">
                    <p class="mt-2 text-xs leading-5 text-slate-500">{{ __('issues.hints.attachments', ['count' => $maximumAttachments]) }}</p>
                    @if (count($screenshots) > 0)
                        <ul class="mt-4 grid gap-3 sm:grid-cols-2">
                            @foreach ($screenshots as $index => $screenshot)
                                <li wire:key="technical-screenshot-{{ $index }}-{{ $screenshot->getFilename() }}" class="flex min-w-0 items-center gap-3 rounded-control border border-slate-200 p-3">
                                    <img src="{{ $screenshot->temporaryUrl() }}" alt="{{ __('issues.attachments.preview_alt', ['number' => $index + 1]) }}" class="size-16 shrink-0 rounded-control bg-slate-100 object-cover">
                                    <span class="min-w-0 flex-1 truncate text-sm text-slate-700">{{ __('issues.attachments.screenshot_name', ['number' => $index + 1]) }}</span>
                                    <button type="button" wire:click="removeScreenshot({{ $index }})" wire:loading.attr="disabled" class="min-h-11 shrink-0 rounded-control bg-slate-100 px-3 text-sm font-bold text-slate-700">{{ __('issues.actions.remove_attachment') }}</button>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </x-ui.panel>

                <x-ui.panel :title="__('issues.create.similar_title')" icon="fa-solid fa-code-compare">
                    <button type="button" wire:click="findSimilar" wire:loading.attr="disabled" wire:target="findSimilar" class="min-h-11 rounded-control bg-slate-100 px-4 py-2 text-sm font-bold text-slate-700 disabled:opacity-50">{{ __('issues.create.check_similar') }}</button>
                    <span wire:loading wire:target="findSimilar" role="status" class="ml-3 text-sm text-slate-500">{{ __('issues.states.loading') }}</span>
                    @if (count($duplicateCandidates) > 0)
                        <ul class="mt-4 space-y-2">
                            @foreach ($duplicateCandidates as $candidate)
                                <li wire:key="duplicate-candidate-{{ $candidate['public_id'] }}"><a href="{{ $candidate['url'] }}" class="flex min-h-11 flex-wrap items-center justify-between gap-2 rounded-control border border-amber-200 bg-amber-50 px-3 py-2 font-bold text-amber-900"><span>{{ $candidate['number'] }}</span><span>{{ __('issues.statuses.'.$candidate['status']) }}</span></a></li>
                            @endforeach
                        </ul>
                    @endif
                </x-ui.panel>

                <div class="flex flex-wrap items-center justify-between gap-3 rounded-panel border border-slate-200 bg-white p-5 shadow-panel">
                    <p class="max-w-2xl text-sm leading-6 text-slate-600">{{ __('issues.create.boundary_notice') }}</p>
                    <button type="submit" wire:loading.attr="disabled" wire:target="submit,screenshots" class="min-h-11 rounded-control bg-emerald-700 px-5 py-2.5 font-bold text-white disabled:opacity-50">
                        <span wire:loading.remove wire:target="submit">{{ __('issues.actions.submit') }}</span>
                        <span wire:loading wire:target="submit">{{ __('issues.actions.submitting') }}</span>
                    </button>
                </div>
            @endif
        </form>
    @endif
</div>
