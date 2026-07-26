<details class="relative z-20 border-t border-slate-100 bg-slate-50 px-3 py-2" data-recommendation-feedback>
    <summary class="flex min-h-11 cursor-pointer list-none items-center gap-2 rounded-control text-sm font-bold text-slate-600 hover:text-emerald-700 focus-visible:outline-none focus-visible:ring-4 focus-visible:ring-emerald-200">
        <x-ui.icon name="fa-solid fa-sliders" />
        <span>{{ __('recommendations.feedback.menu') }}</span>
    </summary>

    <div class="pb-2 pt-1">
        <p class="text-xs leading-5 text-slate-500">{{ __('recommendations.feedback.hint') }}</p>
        <button
            type="button"
            wire:click="{{ $action }}({{ $titleId }}, 'more_like_this')"
            wire:loading.attr="disabled"
            wire:target="{{ $action }}({{ $titleId }}, 'more_like_this')"
            class="mt-2 min-h-11 w-full rounded-control border border-emerald-200 bg-white px-3 py-2 text-left hover:bg-emerald-50 disabled:cursor-wait disabled:opacity-60"
        >
            <span class="flex items-center gap-2 text-sm font-bold text-emerald-800">
                <x-ui.icon name="fa-solid fa-thumbs-up" />
                <span>{{ __('recommendations.feedback.more_like_this') }}</span>
            </span>
            <span class="mt-1 block text-xs leading-4 text-slate-500">{{ __('recommendations.feedback.more_like_this_description') }}</span>
        </button>

        <details class="group mt-2 rounded-control border border-amber-200 bg-white">
            <summary class="flex min-h-11 cursor-pointer list-none items-center justify-between gap-3 px-3 py-2 text-sm font-bold text-amber-800">
                <span class="flex items-center gap-2">
                    <x-ui.icon name="fa-solid fa-eye-slash" />
                    <span>{{ __('recommendations.feedback.not_interested') }}</span>
                </span>
                <x-ui.icon name="fa-solid fa-chevron-down text-xs transition group-open:rotate-180" />
            </summary>
            <div class="border-t border-amber-100 p-3">
                <p class="text-sm font-black text-slate-800">{{ __('recommendations.feedback.reason_question') }}</p>
                <p class="mt-1 text-xs leading-5 text-slate-500">{{ __('recommendations.feedback.reason_hint') }}</p>

                <div class="mt-3 grid gap-2 sm:grid-cols-2">
                    @foreach (['watched_elsewhere', 'too_many_episodes', 'unfinished', 'too_old', 'low_rating', 'wrong_mood', 'not_this_title', 'not_similar'] as $reason)
                        <button
                            type="button"
                            wire:click="{{ $reasonAction() }}({{ $titleId }}, '{{ $reason }}')"
                            wire:loading.attr="disabled"
                            wire:target="{{ $reasonAction() }}({{ $titleId }}, '{{ $reason }}')"
                            class="min-h-11 rounded-control border border-slate-200 px-3 py-2 text-left text-sm font-semibold text-slate-700 hover:border-amber-300 hover:bg-amber-50 disabled:cursor-wait disabled:opacity-60"
                        >{{ __('recommendations.feedback.reasons.'.$reason) }}</button>
                    @endforeach
                </div>

                @foreach ([
                    ['key' => 'genres', 'reason' => 'dislike_genre', 'icon' => 'fa-solid fa-masks-theater'],
                    ['key' => 'countries', 'reason' => 'dislike_country', 'icon' => 'fa-solid fa-flag'],
                    ['key' => 'actors', 'reason' => 'dislike_actor', 'icon' => 'fa-solid fa-user-group'],
                ] as $subjectGroup)
                    @if (($feedbackOptions[$subjectGroup['key']] ?? []) !== [])
                        <div class="mt-3">
                            <p class="flex items-center gap-2 text-xs font-black uppercase tracking-wide text-slate-500">
                                <x-ui.icon :name="$subjectGroup['icon']" />
                                <span>{{ __('recommendations.feedback.reasons.'.$subjectGroup['reason']) }}</span>
                            </p>
                            <div class="mt-2 flex flex-wrap gap-2">
                                @foreach ($feedbackOptions[$subjectGroup['key']] as $subject)
                                    <button
                                        type="button"
                                        wire:click="{{ $reasonAction() }}({{ $titleId }}, '{{ $subjectGroup['reason'] }}', {{ $subject['id'] }})"
                                        wire:loading.attr="disabled"
                                        wire:target="{{ $reasonAction() }}({{ $titleId }}, '{{ $subjectGroup['reason'] }}', {{ $subject['id'] }})"
                                        class="min-h-11 rounded-control border border-slate-200 px-3 py-2 text-sm font-semibold text-slate-700 hover:border-amber-300 hover:bg-amber-50 disabled:cursor-wait disabled:opacity-60"
                                    >{{ $subject['name'] }}</button>
                                @endforeach
                            </div>
                            @if ($subjectGroup['key'] === 'genres')
                                <div class="mt-2 flex flex-wrap gap-2">
                                    @foreach ($feedbackOptions['genres'] as $genre)
                                        <button
                                            type="button"
                                            wire:click="hideRecommendationGenre({{ $genre['id'] }})"
                                            wire:loading.attr="disabled"
                                            wire:target="hideRecommendationGenre({{ $genre['id'] }})"
                                            class="min-h-11 rounded-control px-3 py-2 text-xs font-bold text-slate-500 underline hover:bg-slate-100 hover:text-slate-800 disabled:cursor-wait disabled:opacity-60"
                                        >{{ __('recommendations.preferences.hide_genre', ['genre' => $genre['name']]) }}</button>
                                    @endforeach
                                </div>
                            @endif
                        </div>
                    @endif
                @endforeach
            </div>
        </details>

        <p
            wire:loading
            wire:target="{{ $action }}({{ $titleId }}, 'more_like_this'), {{ $reasonAction() }}, hideRecommendationGenre"
            class="mt-2 text-xs font-semibold text-slate-500"
            role="status"
            aria-live="polite"
        >
            {{ __('recommendations.feedback.saving') }}
        </p>
    </div>
</details>
