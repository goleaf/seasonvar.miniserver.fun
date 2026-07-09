@props(['for', 'id' => null])

@error($for)
    <p id="{{ $id ?? $for.'-error' }}" {{ $attributes->merge(['class' => 'mt-2 flex items-start gap-1.5 text-xs font-semibold leading-5 text-rose-700']) }}>
        <i class="fa-solid fa-circle-exclamation mt-0.5 shrink-0" aria-hidden="true"></i>
        <span>{{ $message }}</span>
    </p>
@enderror
