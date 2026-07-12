@props([
    'title',
    'icon',
    'empty' => 'Нет данных.',
])

<section>
    <div class="mb-2 inline-flex items-center gap-2 text-xs font-bold uppercase tracking-wide text-slate-500">
        <i class="{{ $icon }} text-slate-400" aria-hidden="true"></i>
        <span>{{ $title }}</span>
    </div>

    <div class="space-y-1">
        @if ($slot->isEmpty())
            <p class="text-sm text-slate-500">{{ $empty }}</p>
        @else
            {{ $slot }}
        @endif
    </div>
</section>
