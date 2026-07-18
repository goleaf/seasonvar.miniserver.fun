<div class="mx-auto max-w-7xl space-y-5">
    <header class="rounded-panel border border-slate-200 bg-white p-4 shadow-panel sm:p-6">
        <p class="text-xs font-black uppercase tracking-[0.16em] text-emerald-700">{{ __('premium.eyebrow') }}</p>
        <h1 class="mt-2 break-words text-2xl font-black text-slate-900 sm:text-3xl">{{ __('premium.admin.title') }}</h1>
        <p class="mt-2 max-w-3xl text-sm leading-6 text-slate-600">{{ __('premium.admin.description') }}</p>
    </header>

    <div aria-live="polite" aria-atomic="true">
        @if ($statusMessage !== '')<div class="rounded-control border border-emerald-200 bg-emerald-50 p-4 text-sm font-bold text-emerald-800" role="status">{{ $statusMessage }}</div>@endif
        @if ($actionError !== '')<div class="rounded-control border border-rose-200 bg-rose-50 p-4 text-sm font-bold text-rose-800" role="alert">{{ $actionError }}</div>@endif
    </div>

    @if (! $schemaReady)
        <p class="rounded-panel border border-amber-200 bg-amber-50 p-5 font-semibold text-amber-900">{{ __('premium.settings.unavailable') }}</p>
    @else
        @if ($canManageGrants)<section class="grid gap-5 xl:grid-cols-[minmax(0,1fr)_minmax(22rem,0.8fr)]">
            <div class="rounded-panel border border-slate-200 bg-white p-4 shadow-panel sm:p-6">
                <form wire:submit="findUser" class="flex flex-col gap-3 sm:flex-row sm:items-end" novalidate>
                    <label for="premium-admin-user" class="min-w-0 flex-1 text-sm font-bold text-slate-700">{{ __('premium.admin.user_search') }}<input id="premium-admin-user" type="search" wire:model="userSearch" maxlength="191" autocomplete="off" class="mt-2 min-h-11 w-full rounded-control border border-slate-300 px-3 py-2.5 text-sm" /></label>
                    <button type="submit" wire:loading.attr="disabled" wire:target="findUser" class="inline-flex min-h-11 items-center justify-center gap-2 rounded-control bg-slate-900 px-4 py-2.5 text-sm font-bold text-white hover:bg-slate-700"><x-ui.icon name="fa-solid fa-magnifying-glass" />{{ __('premium.admin.find_user') }}</button>
                </form>
                @error('userSearch')<p class="mt-2 text-sm font-semibold text-rose-700" role="alert">{{ $message }}</p>@enderror

                @if ($selectedUser !== null)
                    <div class="mt-5 rounded-control bg-slate-50 p-4"><span class="text-xs font-black uppercase tracking-wide text-slate-500">{{ __('premium.admin.selected_user') }}</span><p class="mt-1 break-words font-black text-slate-900">{{ $selectedUser['name'] }}</p><p class="break-all text-sm text-slate-600">{{ $selectedUser['email'] }}</p></div>
                    <form wire:submit="grant" class="mt-5 space-y-4" novalidate>
                        <h2 class="text-lg font-black text-slate-900">{{ __('premium.admin.grant_title') }}</h2>
                        <div class="grid gap-4 sm:grid-cols-2">
                            <label class="text-sm font-bold text-slate-700">{{ __('premium.admin.duration_days') }}<input type="number" wire:model="durationDays" min="1" max="3650" class="mt-2 min-h-11 w-full rounded-control border border-slate-300 px-3 py-2.5" /></label>
                            <label class="flex min-h-12 items-center gap-3 rounded-control bg-slate-50 p-4 text-sm font-bold text-slate-700"><input type="checkbox" wire:model="lifetime" class="h-5 w-5 rounded border-slate-300 text-emerald-700 focus:ring-emerald-600" />{{ __('premium.admin.lifetime') }}</label>
                            <label class="text-sm font-bold text-slate-700 sm:col-span-2">{{ __('premium.admin.reason') }}<select wire:model="reason" class="mt-2 min-h-11 w-full rounded-control border border-slate-300 px-3 py-2.5">@foreach ($reasonOptions as $option)<option value="{{ $option['value'] }}">{{ $option['label'] }}</option>@endforeach</select></label>
                            <label class="text-sm font-bold text-slate-700 sm:col-span-2">{{ __('premium.admin.private_note') }}<textarea wire:model="privateNote" maxlength="1000" rows="3" class="mt-2 w-full rounded-control border border-slate-300 px-3 py-2.5"></textarea></label>
                        </div>
                        @error('durationDays')<p class="text-sm font-semibold text-rose-700" role="alert">{{ $message }}</p>@enderror
                        @error('reason')<p class="text-sm font-semibold text-rose-700" role="alert">{{ $message }}</p>@enderror
                        <button type="submit" wire:confirm="{{ __('premium.admin.grant_confirm') }}" wire:loading.attr="disabled" wire:target="grant" class="inline-flex min-h-11 w-full items-center justify-center gap-2 rounded-control bg-emerald-700 px-4 py-2.5 text-sm font-bold text-white hover:bg-emerald-600 sm:w-auto"><x-ui.icon name="fa-solid fa-crown" />{{ __('premium.admin.grant') }}</button>
                    </form>
                @endif
            </div>

            <div class="rounded-panel border border-slate-200 bg-white p-4 shadow-panel sm:p-6">
                <h2 class="text-lg font-black text-slate-900">{{ __('premium.settings.entitlements') }}</h2>
                @if ($entitlements->isEmpty())<p class="mt-3 text-sm text-slate-600">{{ __('premium.settings.no_entitlements') }}</p>@else
                    <ul class="mt-4 space-y-3">@foreach ($entitlements as $entitlement)<li class="rounded-control bg-slate-50 p-4"><div class="flex min-w-0 flex-wrap justify-between gap-2"><span class="font-black text-slate-800">{{ $entitlement['feature'] }}</span><span class="text-xs font-bold text-slate-500">{{ $entitlement['source'] }}</span></div><p class="mt-1 text-sm text-slate-600">{{ $entitlement['period'] }}</p>@if ($entitlement['can_revoke'])<button type="button" wire:click="revoke('{{ $entitlement['public_id'] }}')" wire:confirm="{{ __('premium.admin.revoke_confirm') }}" class="mt-3 inline-flex min-h-11 items-center rounded-control bg-rose-50 px-3 py-2 text-sm font-bold text-rose-700 hover:bg-rose-100">{{ __('premium.admin.revoke') }}</button>@endif</li>@endforeach</ul>
                @endif
            </div>
        </section>@endif

        @if ($canManagePromotions)<section class="rounded-panel border border-slate-200 bg-white p-4 shadow-panel sm:p-6">
            <h2 class="text-lg font-black text-slate-900">{{ __('premium.admin.promotion_title') }}</h2>
            <form wire:submit="createPromotion" class="mt-4 grid gap-4 sm:grid-cols-2 lg:grid-cols-3" novalidate>
                <label class="text-sm font-bold text-slate-700">{{ __('premium.admin.promotion_code') }}<input type="text" wire:model="promotionCode" maxlength="64" class="mt-2 min-h-11 w-full rounded-control border border-slate-300 px-3 py-2.5" /></label>
                <label class="text-sm font-bold text-slate-700">{{ __('premium.admin.duration_days') }}<input type="number" wire:model="promotionDurationDays" min="1" max="3650" class="mt-2 min-h-11 w-full rounded-control border border-slate-300 px-3 py-2.5" /></label>
                <label class="text-sm font-bold text-slate-700">{{ __('premium.admin.per_user_limit') }}<input type="number" wire:model="promotionPerUserLimit" min="1" max="20" class="mt-2 min-h-11 w-full rounded-control border border-slate-300 px-3 py-2.5" /></label>
                <label class="text-sm font-bold text-slate-700">{{ __('premium.settings.started_at') }}<input type="datetime-local" wire:model="promotionStartsAt" class="mt-2 min-h-11 w-full rounded-control border border-slate-300 px-3 py-2.5" /></label>
                <label class="text-sm font-bold text-slate-700">{{ __('premium.settings.expires_at') }}<input type="datetime-local" wire:model="promotionEndsAt" class="mt-2 min-h-11 w-full rounded-control border border-slate-300 px-3 py-2.5" /></label>
                <label class="text-sm font-bold text-slate-700">{{ __('premium.admin.total_limit') }}<input type="number" wire:model="promotionTotalLimit" min="1" class="mt-2 min-h-11 w-full rounded-control border border-slate-300 px-3 py-2.5" /></label>
                <div class="sm:col-span-2 lg:col-span-3"><button type="submit" wire:loading.attr="disabled" wire:target="createPromotion" class="inline-flex min-h-11 w-full items-center justify-center gap-2 rounded-control bg-emerald-700 px-4 py-2.5 text-sm font-bold text-white hover:bg-emerald-600 sm:w-auto">{{ __('premium.admin.create_promotion') }}</button></div>
            </form>
            <ul class="mt-5 grid gap-3 sm:grid-cols-2">@foreach ($promotions as $promotion)<li class="rounded-control bg-slate-50 p-4"><div class="flex min-w-0 flex-wrap justify-between gap-2"><span class="break-words font-mono font-black text-slate-800">{{ $promotion['code'] }}</span><span class="text-xs font-bold text-slate-500">{{ $promotion['redemptions'] }} / {{ $promotion['limit'] }}</span></div><p class="mt-1 text-sm text-slate-600">{{ $promotion['duration'] }}</p><button type="button" wire:click="createCoupon('{{ $promotion['public_id'] }}')" wire:loading.attr="disabled" class="mt-3 inline-flex min-h-11 items-center rounded-control bg-slate-100 px-3 py-2 text-sm font-bold text-slate-700 hover:bg-slate-200">{{ __('premium.admin.create_coupon') }}</button></li>@endforeach</ul>
        </section>@endif

        <section class="grid gap-5 lg:grid-cols-[minmax(0,1fr)_20rem]">
            @if ($canViewAudit)<div class="rounded-panel border border-slate-200 bg-white p-4 shadow-panel sm:p-6"><h2 class="text-lg font-black text-slate-900">{{ __('premium.admin.audit_title') }}</h2>@if ($audits->isEmpty())<p class="mt-3 text-sm text-slate-600">{{ __('premium.admin.no_audit') }}</p>@else<ul class="mt-4 divide-y divide-slate-200">@foreach ($audits as $event)<li class="py-3"><div class="flex min-w-0 flex-wrap justify-between gap-2"><span class="break-words font-bold text-slate-800">{{ $event['action'] }}</span><time class="text-xs font-semibold text-slate-500">{{ $event['occurred_at'] }}</time></div><p class="mt-1 text-sm text-slate-600">{{ $event['actor'] }} · {{ $event['resource_type'] }}</p></li>@endforeach</ul>{{ $audits->links() }}@endif</div>@endif
            <aside class="rounded-panel border border-slate-200 bg-white p-4 shadow-panel sm:p-6"><h2 class="text-lg font-black text-slate-900">{{ __('premium.admin.providers_title') }}</h2>@if ($providerCodes === [])<p class="mt-3 text-sm leading-6 text-slate-600">{{ __('premium.admin.providers_empty') }}</p>@else<ul class="mt-3 space-y-2">@foreach ($providerCodes as $providerCode)<li class="rounded-control bg-slate-50 p-3 font-mono text-sm font-bold text-slate-700">{{ $providerCode }}</li>@endforeach</ul>@endif</aside>
        </section>
    @endif
</div>
