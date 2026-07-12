@props([
    'name' => 'q',
    'id' => null,
    'value' => '',
    'label' => '',
    'placeholder' => '',
    'icon' => 'fa-solid fa-magnifying-glass',
    'containerClass' => 'relative min-w-0 flex-1',
    'frameClass' => 'flex min-w-0 items-center overflow-hidden rounded-control border bg-white',
    'iconClass' => 'flex shrink-0 items-center pl-3 text-slate-400',
    'inputClass' => 'min-h-11 min-w-0 flex-1 border-0 bg-transparent px-3 py-2 text-sm text-slate-700 outline-none placeholder:text-slate-500',
])

<div class="{{ $containerClass }}">
    @if ($label !== '')
        <label for="{{ $id ?? $name }}" class="sr-only">{{ $label }}</label>
    @endif

    <div @class([
        $frameClass,
        'border-rose-300 ring-1 ring-rose-100' => $errors->has($name),
        'border-slate-200 focus-within:border-emerald-300' => ! $errors->has($name),
    ])>
        @if ($icon !== '')
            <span class="{{ $iconClass }}">
                <i class="{{ $icon }}" aria-hidden="true"></i>
            </span>
        @endif

        <input
            id="{{ $id ?? $name }}"
            type="search"
            name="{{ $name }}"
            value="{{ old($name, $value) }}"
            placeholder="{{ $placeholder }}"
            @if ($label !== '') aria-label="{{ $label }}" @endif
            @error($name) aria-invalid="true" aria-describedby="{{ ($id ?? $name).'-error' }}" @enderror
            {{ $attributes->merge(['class' => $inputClass]) }}
        >
    </div>

    <x-form.input-error :for="$name" :id="($id ?? $name).'-error'" />
</div>
