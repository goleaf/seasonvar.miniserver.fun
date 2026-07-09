<a href="{{ $url }}" {{ $attributes->merge($linkAttributes()) }}>
    <div class="flex items-start justify-between gap-2">
        <div class="inline-flex min-w-0 items-center gap-2 font-bold text-slate-700">
            <i class="fa-solid fa-circle-play text-emerald-700" aria-hidden="true"></i>
            <span>{{ $episode->number }} серия</span>
        </div>

        <x-ui.status-pill :icon="$statusIcon" :variant="$statusVariant" size="xs" class="shrink-0">
            {{ $statusLabel }}
        </x-ui.status-pill>
    </div>

    @if ($episode->title)
        <div class="mt-0.5 line-clamp-2 text-xs text-slate-500">{{ $episode->title }}</div>
    @endif

    @if ($releasedAtLabel)
        <div class="mt-1 text-xs font-semibold text-emerald-700">{{ $releasedAtLabel }}</div>
    @endif
</a>
