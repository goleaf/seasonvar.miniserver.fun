@props([
    'label',
    'activeCount' => 0,
])

<section class="rounded-panel border border-slate-200 bg-white p-4 shadow-panel" aria-label="{{ $label }}">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <h2 class="text-sm font-black text-slate-800">{{ $label }}</h2>
        @if ($activeCount > 0)
            <span class="rounded-full bg-emerald-50 px-2.5 py-1 text-xs font-bold text-emerald-800">
                {{ trans_choice('administration.shared.active_filters', $activeCount, ['count' => $activeCount]) }}
            </span>
        @endif
    </div>
    <div class="mt-4 grid min-w-0 gap-3 sm:grid-cols-2 xl:grid-cols-4 [&>*]:min-w-0">
        {{ $slot }}
    </div>
    @isset($actions)
        <div class="mt-4 flex flex-wrap justify-end gap-2 border-t border-slate-100 pt-4">
            {{ $actions }}
        </div>
    @endisset
</section>
