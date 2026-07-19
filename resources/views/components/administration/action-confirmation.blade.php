@props([
    'action',
    'title',
    'impact',
    'destructive' => true,
])

<section class="rounded-panel border border-slate-200 bg-white p-4 shadow-panel" role="alertdialog" aria-labelledby="admin-confirmation-{{ str_replace('.', '-', $action) }}" aria-describedby="admin-confirmation-impact-{{ str_replace('.', '-', $action) }}" data-impact-preview data-admin-action="{{ $action }}">
    <h2 id="admin-confirmation-{{ str_replace('.', '-', $action) }}" class="text-base font-black text-slate-800">{{ $title }}</h2>
    <p id="admin-confirmation-impact-{{ str_replace('.', '-', $action) }}" class="mt-2 text-sm leading-6 text-slate-600">{{ $impact }}</p>
    <div class="mt-4 flex flex-wrap justify-end gap-2">
        <button type="button" wire:click="$dispatch('admin-confirmation-cancelled', { action: '{{ $action }}' })" class="min-h-11 rounded-control border border-slate-300 px-4 py-2 text-sm font-bold text-slate-700 focus-visible:outline-none focus-visible:ring-4 focus-visible:ring-slate-200">
            {{ __('administration.shared.cancel') }}
        </button>
        <button type="button" wire:click="$dispatch('admin-confirmed', { action: '{{ $action }}' })" class="min-h-11 rounded-control px-4 py-2 text-sm font-bold text-white focus-visible:outline-none focus-visible:ring-4 {{ $destructive ? 'bg-rose-700 focus-visible:ring-rose-200' : 'bg-emerald-700 focus-visible:ring-emerald-200' }}">
            {{ __('administration.shared.confirm') }}
        </button>
    </div>
</section>
