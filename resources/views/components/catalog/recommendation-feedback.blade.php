<details class="relative z-20 border-t border-slate-100 bg-slate-50 px-3 py-2" data-recommendation-feedback>
    <summary class="flex min-h-11 cursor-pointer list-none items-center gap-2 rounded-control text-sm font-bold text-slate-600 hover:text-emerald-700 focus-visible:outline-none focus-visible:ring-4 focus-visible:ring-emerald-200">
        <x-ui.icon name="fa-solid fa-sliders" />
        <span>{{ __('recommendations.feedback.menu') }}</span>
    </summary>

    <div class="pb-2 pt-1">
        <p class="text-xs leading-5 text-slate-500">{{ __('recommendations.feedback.hint') }}</p>
        <div
            class="mt-2 grid gap-2 sm:grid-cols-3"
            role="group"
            aria-label="{{ __('recommendations.feedback.group') }}"
        >
            <button
                type="button"
                wire:click="{{ $action }}({{ $titleId }}, 'more_like_this')"
                wire:loading.attr="disabled"
                wire:target="{{ $action }}({{ $titleId }}, 'more_like_this')"
                class="min-h-11 rounded-control border border-emerald-200 bg-white px-3 py-2 text-left hover:bg-emerald-50 disabled:cursor-wait disabled:opacity-60"
            >
                <span class="flex items-center gap-2 text-sm font-bold text-emerald-800">
                    <x-ui.icon name="fa-solid fa-thumbs-up" />
                    <span>{{ __('recommendations.feedback.more_like_this') }}</span>
                </span>
                <span class="mt-1 block text-xs leading-4 text-slate-500">{{ __('recommendations.feedback.more_like_this_description') }}</span>
            </button>

            <button
                type="button"
                wire:click="{{ $action }}({{ $titleId }}, 'not_interested')"
                wire:loading.attr="disabled"
                wire:target="{{ $action }}({{ $titleId }}, 'not_interested')"
                class="min-h-11 rounded-control border border-amber-200 bg-white px-3 py-2 text-left hover:bg-amber-50 disabled:cursor-wait disabled:opacity-60"
            >
                <span class="flex items-center gap-2 text-sm font-bold text-amber-800">
                    <x-ui.icon name="fa-solid fa-eye-slash" />
                    <span>{{ __('recommendations.feedback.not_interested') }}</span>
                </span>
                <span class="mt-1 block text-xs leading-4 text-slate-500">{{ __('recommendations.feedback.not_interested_description') }}</span>
            </button>

            <button
                type="button"
                wire:click="{{ $action }}({{ $titleId }}, 'blacklisted')"
                wire:loading.attr="disabled"
                wire:target="{{ $action }}({{ $titleId }}, 'blacklisted')"
                class="min-h-11 rounded-control border border-rose-200 bg-white px-3 py-2 text-left hover:bg-rose-50 disabled:cursor-wait disabled:opacity-60"
            >
                <span class="flex items-center gap-2 text-sm font-bold text-rose-800">
                    <x-ui.icon name="fa-solid fa-ban" />
                    <span>{{ __('recommendations.feedback.blacklist') }}</span>
                </span>
                <span class="mt-1 block text-xs leading-4 text-slate-500">{{ __('recommendations.feedback.blacklist_description') }}</span>
            </button>
        </div>

        <p
            wire:loading
            wire:target="{{ $action }}({{ $titleId }}, 'more_like_this'), {{ $action }}({{ $titleId }}, 'not_interested'), {{ $action }}({{ $titleId }}, 'blacklisted')"
            class="mt-2 text-xs font-semibold text-slate-500"
            role="status"
            aria-live="polite"
        >
            {{ __('recommendations.feedback.saving') }}
        </p>
    </div>
</details>
