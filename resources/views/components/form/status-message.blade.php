@props(['message', 'variant' => 'success'])

@if ($message)
    <div @class([
        'flex items-start gap-2 rounded-control border px-3 py-2.5 text-sm font-semibold leading-6',
        'border-emerald-200 bg-emerald-50 text-emerald-800' => $variant === 'success',
        'border-amber-200 bg-amber-50 text-amber-900' => $variant === 'warning',
        'border-rose-200 bg-rose-50 text-rose-800' => $variant === 'error',
    ]) role="status">
        <x-ui.icon :name="$variant === 'success' ? 'fa-solid fa-circle-check' : 'fa-solid fa-circle-info'" align="start" />
        <span>{{ $message }}</span>
    </div>
@endif
