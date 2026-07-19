@props([
    'type',
    'title',
    'description',
])

<div class="rounded-panel border border-slate-200 bg-slate-50 p-5" role="status" aria-live="polite" data-administration-state="{{ $type }}">
    <h2 class="text-base font-black text-slate-800">{{ $title }}</h2>
    <p class="mt-1 text-sm leading-6 text-slate-600">{{ $description }}</p>
    {{ $slot }}
</div>
