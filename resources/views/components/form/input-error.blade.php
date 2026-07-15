@props(['for', 'id' => null])

@if (isset($errors) && $errors->has($for))
    <p id="{{ $id ?? $for.'-error' }}" {{ $attributes->merge(['class' => 'mt-2 flex items-start gap-1.5 text-xs font-semibold leading-5 text-rose-700']) }}>
        <x-ui.icon name="fa-solid fa-circle-exclamation" align="start" />
        <span>{{ $errors->first($for) }}</span>
    </p>
@endif
