@props(['taxonomy' => null, 'href' => null, 'active' => false, 'count' => null, 'muted' => false, 'icon' => null])

@php
    $name = $taxonomy?->name ?? trim((string) $slot);
    $type = $taxonomy?->type ?? (is_object($taxonomy) && method_exists($taxonomy, 'filterType') ? $taxonomy->filterType() : null);
    $href = $href ?? ($taxonomy && $type ? route('titles.taxonomy', ['type' => $type, 'taxonomy' => $taxonomy->slug]) : null);
    $iconMap = [
        'genre' => 'fa-solid fa-masks-theater',
        'country' => 'fa-solid fa-earth-europe',
        'actor' => 'fa-solid fa-user-group',
        'director' => 'fa-solid fa-video',
        'age_rating' => 'fa-solid fa-shield-halved',
        'translation' => 'fa-solid fa-language',
        'status' => 'fa-solid fa-signal',
        'network' => 'fa-solid fa-tower-broadcast',
        'studio' => 'fa-solid fa-building',
        'tag' => 'fa-solid fa-tag',
    ];
    $icon = $icon ?? ($type ? ($iconMap[$type] ?? null) : null);
    $classes = 'inline-flex items-center gap-1.5 rounded-full border px-2.5 py-1 text-xs font-semibold transition';
    $stateClasses = match (true) {
        $active => 'border-emerald-300 bg-emerald-50 text-emerald-700 hover:bg-emerald-100',
        $muted => 'border-slate-200 bg-slate-50 text-slate-400',
        default => 'border-slate-200 bg-slate-50 text-slate-600 hover:border-emerald-200 hover:bg-emerald-50 hover:text-emerald-700',
    };
@endphp

@if ($href)
    <a href="{{ $href }}" {{ $attributes->merge(['class' => $classes.' '.$stateClasses]) }}>
        @if ($icon)
            <i class="{{ $icon }} text-[0.85em]" aria-hidden="true"></i>
        @endif
        <span>{{ $name }}</span>
        @if ($count !== null)
            <span class="text-slate-400">{{ $count }}</span>
        @endif
    </a>
@else
    <span {{ $attributes->merge(['class' => $classes.' '.$stateClasses]) }}>
        @if ($icon)
            <i class="{{ $icon }} text-[0.85em]" aria-hidden="true"></i>
        @endif
        <span>{{ $name }}</span>
        @if ($count !== null)
            <span class="text-slate-400">{{ $count }}</span>
        @endif
    </span>
@endif
