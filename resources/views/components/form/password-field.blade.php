@props([
    'label',
    'for',
    'autocomplete' => null,
    'required' => false,
    'hint' => null,
])

<div>
    <label for="{{ $for }}" class="block text-sm font-bold text-slate-700">{{ $label }}</label>
    @if ($hint)
        <p id="{{ $for }}-hint" class="mt-1 text-xs font-semibold leading-5 text-slate-500">{{ $hint }}</p>
    @endif
    <div data-password-control class="relative mt-2">
        <input
            id="{{ $for }}"
            type="password"
            @if ($autocomplete) autocomplete="{{ $autocomplete }}" @endif
            @if ($required) required @endif
            @if ($hint) aria-describedby="{{ $for }}-hint" @endif
            @if (isset($errors) && $errors->has($attributes->get('wire:model'))) aria-invalid="true" aria-describedby="{{ $for }}-error" @endif
            {{ $attributes->class(['min-h-11 w-full rounded-control border border-slate-300 bg-white py-2.5 pl-3 pr-14 text-base text-slate-800 outline-none focus:border-emerald-600 focus:ring-2 focus:ring-emerald-100 disabled:cursor-wait disabled:bg-slate-100']) }}
        >
        <button
            type="button"
            data-password-toggle
            data-show-label="{{ __('auth.actions.show_password') }}"
            data-hide-label="{{ __('auth.actions.hide_password') }}"
            aria-controls="{{ $for }}"
            aria-pressed="false"
            aria-label="{{ __('auth.actions.show_password') }}"
            class="absolute inset-y-0 right-0 inline-flex min-h-11 min-w-11 items-center justify-center rounded-control text-slate-500 hover:text-emerald-700 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-emerald-600"
        >
            <x-ui.icon data-password-show-icon name="fa-regular fa-eye" />
            <x-ui.icon data-password-hide-icon name="fa-regular fa-eye-slash hidden" />
        </button>
    </div>
    <x-form.input-error :for="$attributes->get('wire:model')" :id="$for.'-error'" />
</div>
