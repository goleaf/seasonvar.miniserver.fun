@props([
    'label',
    'for',
    'type' => 'text',
    'autocomplete' => null,
    'placeholder' => null,
    'required' => false,
])

<div>
    <label for="{{ $for }}" class="block text-sm font-bold text-slate-700">{{ $label }}</label>
    <input
        id="{{ $for }}"
        type="{{ $type }}"
        @if ($autocomplete) autocomplete="{{ $autocomplete }}" @endif
        @if ($placeholder) placeholder="{{ $placeholder }}" @endif
        @if ($required) required @endif
        @if (isset($errors) && $errors->has($attributes->get('wire:model'))) aria-invalid="true" aria-describedby="{{ $for }}-error" @endif
        {{ $attributes->class(['mt-2 min-h-11 w-full rounded-control border border-slate-300 bg-white px-3 py-2.5 text-base text-slate-800 outline-none placeholder:text-slate-400 focus:border-emerald-600 focus:ring-2 focus:ring-emerald-100 disabled:cursor-wait disabled:bg-slate-100']) }}
    >
    <x-form.input-error :for="$attributes->get('wire:model')" :id="$for.'-error'" />
</div>
