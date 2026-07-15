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
    <input
        id="{{ $for }}"
        type="password"
        @if ($autocomplete) autocomplete="{{ $autocomplete }}" @endif
        @if ($required) required @endif
        @if ($hint) aria-describedby="{{ $for }}-hint" @endif
        @if (isset($errors) && $errors->has($attributes->get('wire:model'))) aria-invalid="true" aria-describedby="{{ $for }}-error" @endif
        {{ $attributes->class(['mt-2 min-h-11 w-full rounded-control border border-slate-300 bg-white px-3 py-2.5 text-base text-slate-800 outline-none focus:border-emerald-600 focus:ring-2 focus:ring-emerald-100 disabled:cursor-wait disabled:bg-slate-100']) }}
    >
    <x-form.input-error :for="$attributes->get('wire:model')" :id="$for.'-error'" />
</div>
