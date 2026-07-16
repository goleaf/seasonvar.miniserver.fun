<div class="mx-auto max-w-6xl space-y-5">
    <header class="rounded-panel border border-slate-200 bg-white p-5 shadow-panel sm:p-6">
        <h1 class="text-2xl font-black tracking-tight text-slate-900 sm:text-3xl">{{ __('profiles.admin.title') }}</h1>
        <p class="mt-2 text-sm leading-6 text-slate-600">{{ __('profiles.admin.description') }}</p>
    </header>

    <div aria-live="polite" class="space-y-2">
        @if ($notice)<x-form.status-message :message="$notice" />@endif
        @if ($actionError)<div role="alert" class="rounded-control border border-rose-200 bg-rose-50 px-4 py-3 text-sm font-semibold text-rose-800">{{ $actionError }}</div>@endif
    </div>

    <label for="profile-moderation-private-note" class="block rounded-panel border border-slate-200 bg-white p-4 text-sm font-bold text-slate-700 shadow-panel">
        {{ __('profiles.admin.private_note') }}
        <textarea id="profile-moderation-private-note" wire:model="privateNote" rows="3" maxlength="2000" class="mt-2 w-full rounded-control border border-slate-300 px-3 py-2.5 text-sm leading-6 text-slate-800"></textarea>
    </label>

    @if ($reports === null)
        <div role="alert" class="rounded-panel border border-rose-200 bg-rose-50 p-8 text-center shadow-panel">
            <p class="text-sm font-semibold text-rose-800">{{ __('profiles.errors.load_failed') }}</p>
        </div>
    @elseif ($reports->isEmpty())
        <div class="rounded-panel border border-dashed border-slate-300 bg-white p-8 text-center shadow-panel"><p class="text-sm font-semibold text-slate-600">{{ __('profiles.admin.empty') }}</p></div>
    @else
        <div class="space-y-4">
            @foreach ($reports as $report)
                <article wire:key="profile-report-{{ $report['public_id'] }}" class="rounded-panel border border-slate-200 bg-white p-4 shadow-panel sm:p-5">
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div class="min-w-0">
                            <h2 class="break-words text-lg font-black text-slate-900">{{ $report['display_name'] }} @if ($report['username'])<span class="text-slate-500">{{ '@'.$report['username'] }}</span>@endif</h2>
                            <p class="mt-1 text-sm font-bold text-amber-800">{{ $report['category'] }}</p>
                        </div>
                        <span class="text-xs font-semibold text-slate-500">{{ $report['created_at'] }}</span>
                    </div>
                    @if ($report['details'])<p class="mt-4 whitespace-pre-line break-words text-sm leading-7 text-slate-700">{{ $report['details'] }}</p>@endif
                    @if ($report['biography'])<p class="mt-4 rounded-control bg-slate-50 px-3 py-3 text-sm leading-6 text-slate-700">{{ $report['biography'] }}</p>@endif
                    <div class="mt-4 flex flex-wrap gap-2">
                        @if ($report['target_available'])
                            <button type="button" wire:click="moderate('{{ $report['public_id'] }}', 'activate')" wire:loading.attr="disabled" class="min-h-11 rounded-control bg-emerald-50 px-3 py-2 text-sm font-bold text-emerald-800">{{ __('profiles.admin.activate') }}</button>
                            <button type="button" wire:click="moderate('{{ $report['public_id'] }}', 'hide')" wire:confirm="{{ __('profiles.admin.confirm') }}" wire:loading.attr="disabled" class="min-h-11 rounded-control bg-amber-50 px-3 py-2 text-sm font-bold text-amber-800">{{ __('profiles.admin.hide') }}</button>
                            <button type="button" wire:click="moderate('{{ $report['public_id'] }}', 'suspend')" wire:confirm="{{ __('profiles.admin.confirm') }}" wire:loading.attr="disabled" class="min-h-11 rounded-control bg-rose-50 px-3 py-2 text-sm font-bold text-rose-800">{{ __('profiles.admin.suspend') }}</button>
                            @if ($report['biography'])<button type="button" wire:click="moderate('{{ $report['public_id'] }}', 'hide_biography')" wire:confirm="{{ __('profiles.admin.confirm') }}" wire:loading.attr="disabled" class="min-h-11 rounded-control bg-slate-100 px-3 py-2 text-sm font-bold text-slate-700">{{ __('profiles.admin.hide_biography') }}</button>@endif
                            @if ($report['has_avatar'])<button type="button" wire:click="moderate('{{ $report['public_id'] }}', 'remove_avatar')" wire:confirm="{{ __('profiles.admin.confirm') }}" wire:loading.attr="disabled" class="min-h-11 rounded-control bg-slate-100 px-3 py-2 text-sm font-bold text-slate-700">{{ __('profiles.admin.remove_avatar') }}</button>@endif
                            @if ($report['has_cover'])<button type="button" wire:click="moderate('{{ $report['public_id'] }}', 'remove_cover')" wire:confirm="{{ __('profiles.admin.confirm') }}" wire:loading.attr="disabled" class="min-h-11 rounded-control bg-slate-100 px-3 py-2 text-sm font-bold text-slate-700">{{ __('profiles.admin.remove_cover') }}</button>@endif
                        @endif
                        <button type="button" wire:click="moderate('{{ $report['public_id'] }}', 'dismiss')" wire:loading.attr="disabled" class="min-h-11 rounded-control bg-white px-3 py-2 text-sm font-bold text-slate-600 ring-1 ring-slate-200">{{ __('profiles.admin.dismiss') }}</button>
                    </div>
                </article>
            @endforeach
        </div>
        <nav aria-label="{{ __('profiles.pagination') }}">{{ $reports->links() }}</nav>
    @endif
</div>
