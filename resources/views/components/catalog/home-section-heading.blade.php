@props([
    'id',
    'title',
    'description' => null,
    'actionUrl' => null,
    'actionLabel' => null,
    'tone' => 'slate',
])

<div @class([
    'mb-5 flex flex-col gap-3 border-b pb-4 sm:flex-row sm:items-end sm:justify-between',
    'border-amber-200' => $tone === 'amber',
    'border-sky-200' => $tone === 'sky',
    'border-emerald-200' => $tone === 'emerald',
    'border-slate-200' => ! in_array($tone, ['amber', 'sky', 'emerald'], true),
])>
    <div class="min-w-0">
        <h2 id="{{ $id }}" class="text-balance text-2xl font-semibold tracking-tight text-slate-900">
            {{ $title }}
        </h2>
        @if ($description)
            <p class="mt-1 max-w-2xl text-pretty text-sm leading-6 text-slate-600">{{ $description }}</p>
        @endif
    </div>

    @if ($actionUrl && $actionLabel)
        <a
            href="{{ $actionUrl }}"
            data-home-section-action
            class="inline-flex min-h-11 shrink-0 items-center justify-center gap-2 self-start rounded-control border border-slate-200 bg-white px-3.5 py-2 text-sm font-bold text-emerald-700 transition hover:border-emerald-700 hover:bg-emerald-50 hover:text-emerald-800 focus-visible:outline-none focus-visible:ring-4 focus-visible:ring-emerald-200 sm:self-auto"
        >
            <span>{{ $actionLabel }}</span>
            <x-ui.icon name="fa-solid fa-arrow-right text-xs" />
        </a>
    @endif
</div>
