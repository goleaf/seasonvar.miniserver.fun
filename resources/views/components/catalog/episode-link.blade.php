<a href="{{ $url }}" {{ $attributes->merge($linkAttributes()) }}>
    <div class="flex items-start justify-between gap-2">
        <div class="inline-flex min-w-0 items-center gap-2 font-bold text-slate-700">
            <i class="fa-solid fa-circle-play text-emerald-700" aria-hidden="true"></i>
            <span>{{ $episode->number }} серия</span>
        </div>

        @if ($hasMedia)
            <x-ui.status-pill :icon="$statusIcon" :variant="$statusVariant" size="xs" class="shrink-0">
                {{ $statusLabel }}
            </x-ui.status-pill>
        @endif
    </div>

    @if ($episode->title)
        <div class="mt-0.5 text-xs text-slate-500">{{ $episode->title }}</div>
    @endif

    @if ($variantBadges->isNotEmpty())
        <div class="mt-2 flex flex-wrap gap-1">
            @foreach ($variantBadges as $badge)
                <span class="inline-flex items-center rounded-full bg-slate-50 px-2 py-0.5 text-[11px] font-bold text-slate-500">
                    {{ $badge }}
                </span>
            @endforeach
        </div>
    @endif

    @if ($releasedAtLabel)
        <div class="mt-1 text-xs font-semibold text-emerald-700">{{ $releasedAtLabel }}</div>
    @endif
</a>
