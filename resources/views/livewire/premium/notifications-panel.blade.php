<section aria-labelledby="premium-notifications-title" class="overflow-hidden rounded-panel border border-slate-200 bg-white shadow-panel">
    @island(name: 'premium-notifications-pagination', always: true, with: $this->paginationIslandPage)
    <x-ui.pagination-region name="premium-notifications-results">
    <div class="flex flex-wrap items-center justify-between gap-3 p-4 sm:p-5">
        <div><h2 id="premium-notifications-title" class="flex items-center gap-2 text-lg font-black text-slate-800"><x-ui.icon name="fa-solid fa-crown text-emerald-700" />{{ __('premium.notifications.title') }}</h2>@if ($notice !== '')<p class="mt-1 text-sm font-bold text-emerald-700" role="status">{{ $notice }}</p>@endif</div>
        @if ($notifications !== null && $notifications->isNotEmpty())<button type="button" wire:click="markAllRead" wire:loading.attr="disabled" class="min-h-11 rounded-control bg-slate-100 px-3 text-sm font-bold text-slate-700">{{ __('premium.notifications.mark_all_read') }}</button>@endif
    </div>
    @if ($queryFailed || $notifications === null || $notifications->isEmpty())
        <p class="border-t border-slate-100 p-5 text-sm font-semibold text-slate-600">{{ $queryFailed ? __('premium.errors.query_failed') : __('premium.notifications.empty') }}</p>
    @else
        <ul class="divide-y divide-slate-100">@foreach ($notifications as $notification)<li wire:key="premium-notification-{{ $notification->id }}" @class(['p-4 sm:p-5', 'bg-emerald-50/60' => ! $notification->isRead])><div class="flex min-w-0 flex-wrap items-start justify-between gap-3"><div class="min-w-0"><div class="flex flex-wrap items-center gap-2">@if (! $notification->isRead)<x-ui.status-pill variant="success">{{ __('premium.notifications.unread') }}</x-ui.status-pill>@endif<time datetime="{{ $notification->createdAtIso }}" class="text-xs text-slate-500">{{ $notification->createdAtLabel }}</time></div><p class="mt-2 break-words text-sm font-bold text-slate-800">{{ $notification->label }}</p>@if ($notification->detail !== null)<p class="mt-1 break-words text-sm text-slate-600">{{ $notification->detail }}</p>@endif</div><div class="flex flex-wrap gap-2"><a href="{{ $notification->url }}" wire:navigate class="inline-flex min-h-11 items-center rounded-control bg-emerald-700 px-3 text-sm font-bold text-white">{{ __('premium.notifications.open') }}</a>@if (! $notification->isRead)<button type="button" wire:click="markRead('{{ $notification->id }}')" wire:loading.attr="disabled" class="min-h-11 rounded-control bg-slate-100 px-3 text-sm font-bold text-slate-700">{{ __('premium.notifications.mark_read') }}</button>@endif</div></div></li>@endforeach</ul>
        @if ($notifications->hasPages())<nav class="p-4" aria-label="{{ __('premium.notifications.title') }}">{{ $notifications->links(data: ['region' => 'premium-notifications-results']) }}</nav>@endif
    @endif
    </x-ui.pagination-region>
    @endisland
</section>
