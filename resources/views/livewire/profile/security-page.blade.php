<div class="mx-auto max-w-5xl space-y-5">
    <header class="rounded-panel border border-slate-200 bg-white p-4 shadow-panel sm:p-6">
        <div class="flex items-start gap-3">
            <span class="grid h-11 w-11 shrink-0 place-items-center rounded-control bg-emerald-50 text-emerald-700"><x-ui.icon name="fa-solid fa-shield-halved" /></span>
            <div class="min-w-0">
                <h1 class="break-words text-2xl font-black tracking-tight text-slate-800 sm:text-3xl">{{ __('settings.security_page.title') }}</h1>
                <p class="mt-2 text-sm leading-6 text-slate-600">{{ __('settings.security_page.description') }}</p>
            </div>
        </div>
        <a href="{{ route('settings.index', ['section' => 'security']) }}" wire:navigate class="mt-4 inline-flex min-h-11 items-center gap-2 rounded-control bg-slate-100 px-3 py-2 text-sm font-bold text-slate-700 hover:bg-slate-200"><x-ui.icon name="fa-solid fa-arrow-left" />{{ __('settings.security_page.back_to_settings') }}</a>
    </header>

    @if ($securityError !== null)
        <div class="rounded-control border border-rose-200 bg-rose-50 px-4 py-3 text-sm font-semibold text-rose-800" role="alert">{{ $securityError }}</div>
    @endif

    <x-ui.panel :title="__('settings.security_page.current_password')" :subtitle="__('settings.security_page.current_password_hint')" icon="fa-solid fa-key">
        <x-form.password-field :label="__('settings.security_page.current_password')" for="security-current-password" wire:model="currentPassword" autocomplete="current-password" required />
    </x-ui.panel>

    <x-ui.panel :title="__('settings.security_page.change_password')" :subtitle="__('settings.security_page.change_password_hint')" icon="fa-solid fa-lock">
        @if ($passwordStatus)<div class="mb-5"><x-form.status-message :message="$passwordStatus" /></div>@endif
        <form wire:submit="updatePassword" class="space-y-5" novalidate>
            <x-form.password-field :label="__('settings.security_page.new_password')" for="security-new-password" wire:model="password" autocomplete="new-password" :hint="__('settings.security_page.password_hint')" required />
            <x-form.password-field :label="__('settings.security_page.confirm_password')" for="security-new-password-confirmation" wire:model="passwordConfirmation" autocomplete="new-password" required />
            <button type="submit" wire:loading.attr="disabled" wire:target="updatePassword" class="inline-flex min-h-11 w-full items-center justify-center gap-2 rounded-control bg-emerald-700 px-4 py-2.5 text-sm font-bold text-white hover:bg-emerald-600 disabled:cursor-wait disabled:opacity-60 sm:w-auto">
                <x-ui.icon name="fa-solid fa-floppy-disk" /><span wire:loading.remove wire:target="updatePassword">{{ __('settings.security_page.change_password_action') }}</span><span wire:loading wire:target="updatePassword">{{ __('settings.security_page.changing_password') }}</span>
            </button>
        </form>
    </x-ui.panel>

    <x-ui.panel :title="__('settings.security_page.browser_sessions')" :subtitle="__('settings.security_page.browser_sessions_hint')" icon="fa-solid fa-desktop">
        @if ($sessionStatus)<div class="mb-5"><x-form.status-message :message="$sessionStatus" /></div>@endif
        @error('session')<div class="mb-5 rounded-control border border-rose-200 bg-rose-50 px-4 py-3 text-sm font-semibold text-rose-800" role="alert">{{ $message }}</div>@enderror

        @if ($sessionsFailed)
            <p class="rounded-control border border-rose-200 bg-rose-50 p-4 text-sm leading-6 text-rose-800" role="alert">{{ __('settings.security_page.sessions_failed') }}</p>
        @elseif (! $databaseSessionsAvailable)
            <p class="rounded-control bg-slate-50 p-4 text-sm leading-6 text-slate-600">{{ __('settings.security_page.session_driver_limited') }}</p>
        @elseif ($browserSessions->isEmpty())
            <p class="rounded-control bg-slate-50 p-4 text-sm leading-6 text-slate-600">{{ __('settings.security_page.sessions_empty') }}</p>
        @else
            <div class="space-y-3">
                @foreach ($browserSessions as $browserSession)
                    <article wire:key="browser-session-{{ $browserSession->wireKey }}" class="grid min-w-0 gap-3 rounded-control border border-slate-200 p-4 sm:grid-cols-[minmax(0,1fr)_auto] sm:items-center">
                        <div class="min-w-0">
                            <div class="flex flex-wrap items-center gap-2">
                                <h3 class="break-words font-black text-slate-800">{{ $browserSession->deviceLabel }}</h3>
                                @if ($browserSession->current)<x-ui.status-pill variant="success">{{ __('settings.security_page.current_session') }}</x-ui.status-pill>@endif
                            </div>
                            <p class="mt-1 text-xs font-semibold text-slate-500"><time datetime="{{ $browserSession->lastActivityIso }}">{{ __('settings.security_page.last_activity', ['time' => $browserSession->lastActivityLabel]) }}</time></p>
                        </div>
                        @if (! $browserSession->current && $browserSession->opaqueToken !== null)
                            <button type="button" wire:click="revokeBrowserSession('{{ $browserSession->opaqueToken }}')" wire:confirm="{{ __('settings.security_page.revoke_session_confirm') }}" wire:loading.attr="disabled" wire:target="revokeBrowserSession('{{ $browserSession->opaqueToken }}')" class="inline-flex min-h-11 w-full items-center justify-center gap-2 rounded-control bg-rose-50 px-3 py-2 text-sm font-bold text-rose-700 hover:bg-rose-100 disabled:opacity-60 sm:w-auto"><x-ui.icon name="fa-solid fa-link-slash" />{{ __('settings.security_page.revoke_session') }}</button>
                        @endif
                    </article>
                @endforeach
            </div>
        @endif

        <button type="button" wire:click="logoutOtherDevices" wire:confirm="{{ __('settings.security_page.logout_others_confirm') }}" wire:loading.attr="disabled" wire:target="logoutOtherDevices" class="mt-4 inline-flex min-h-11 w-full items-center justify-center gap-2 rounded-control bg-slate-100 px-4 py-2.5 text-sm font-bold text-slate-700 hover:bg-slate-200 disabled:cursor-wait disabled:opacity-60 sm:w-auto"><x-ui.icon name="fa-solid fa-right-from-bracket" /><span wire:loading.remove wire:target="logoutOtherDevices">{{ __('settings.security_page.logout_others') }}</span><span wire:loading wire:target="logoutOtherDevices">{{ __('settings.security_page.revoking_sessions') }}</span></button>
    </x-ui.panel>

    <x-ui.panel :title="__('settings.security_page.api_devices')" :subtitle="__('settings.security_page.api_devices_hint')" icon="fa-solid fa-mobile-screen">
        @if ($deviceStatus)<div class="mb-5"><x-form.status-message :message="$deviceStatus" /></div>@endif
        @if ($devicesFailed)
            <div class="rounded-control border border-rose-200 bg-rose-50 px-4 py-5 text-sm leading-6 text-rose-800" role="alert">{{ __('settings.security_page.devices_failed') }}</div>
        @elseif ($devices->isEmpty())
            <div class="rounded-control bg-slate-50 px-4 py-5 text-sm leading-6 text-slate-600">{{ __('settings.security_page.devices_empty') }}</div>
        @else
            <div class="space-y-3">
                @foreach ($devices as $device)
                    <article wire:key="api-device-{{ $device['id'] }}" class="grid gap-3 rounded-control border border-slate-200 p-4 sm:grid-cols-[minmax(0,1fr)_auto] sm:items-center">
                        <div class="min-w-0"><h3 class="break-words font-black text-slate-800">{{ $device['name'] }}</h3><div class="mt-1 flex flex-wrap gap-x-4 gap-y-1 text-xs font-semibold text-slate-500"><span>{{ __('settings.security_page.last_used', ['time' => $device['last_used_at'] ?? __('settings.security_page.no_data')]) }}</span><span>{{ __('settings.security_page.expires', ['time' => $device['expires_at'] ?? __('settings.security_page.no_expiry')]) }}</span></div></div>
                        <button type="button" wire:click="revokeDevice({{ $device['id'] }})" wire:confirm="{{ __('settings.security_page.revoke_device_confirm') }}" wire:loading.attr="disabled" wire:target="revokeDevice({{ $device['id'] }})" class="inline-flex min-h-11 w-full items-center justify-center gap-2 rounded-control bg-slate-100 px-4 py-2.5 text-sm font-bold text-slate-700 hover:bg-rose-50 hover:text-rose-700 disabled:opacity-60 sm:w-auto"><x-ui.icon name="fa-solid fa-link-slash" />{{ __('settings.security_page.revoke_device') }}</button>
                    </article>
                @endforeach
            </div>
            <button type="button" wire:click="revokeAllDevices" wire:confirm="{{ __('settings.security_page.revoke_devices_confirm') }}" wire:loading.attr="disabled" wire:target="revokeAllDevices" class="mt-4 inline-flex min-h-11 w-full items-center justify-center gap-2 rounded-control bg-rose-50 px-4 py-2.5 text-sm font-bold text-rose-700 hover:bg-rose-100 disabled:opacity-60 sm:w-auto"><x-ui.icon name="fa-solid fa-mobile-screen-button" />{{ __('settings.security_page.revoke_devices') }}</button>
        @endif
    </x-ui.panel>

    <x-ui.panel :title="__('settings.data.export_title')" :subtitle="__('settings.data.export_hint')" icon="fa-solid fa-file-arrow-down">
        <a href="{{ route('profile.export') }}" class="inline-flex min-h-11 w-full items-center justify-center gap-2 rounded-control bg-slate-100 px-4 py-2.5 text-sm font-bold text-slate-700 hover:bg-slate-200 sm:w-auto"><x-ui.icon name="fa-solid fa-download" />{{ __('settings.data.export') }}</a>
    </x-ui.panel>

    <section class="rounded-panel border border-rose-200 bg-white p-4 shadow-panel sm:p-6" aria-labelledby="delete-account-title">
        <h2 id="delete-account-title" class="flex items-center gap-2 text-lg font-black text-rose-800"><x-ui.icon name="fa-solid fa-triangle-exclamation" />{{ __('settings.data.delete_title') }}</h2>
        <p class="mt-2 text-sm leading-6 text-slate-600">{{ __('settings.data.delete_hint') }}</p>
        <p class="mt-2 text-sm leading-6 text-slate-600">{{ __('collections.account.deletion_scope') }}</p>
        <p class="mt-2 text-sm leading-6 text-slate-600">{{ __('comments.profile.deletion_hint') }}</p>
        <button type="button" wire:click="deleteAccount" wire:confirm.prompt="{{ __('settings.security_page.delete_confirm') }}|{{ __('settings.security_page.delete_word') }}" wire:loading.attr="disabled" wire:target="deleteAccount" class="mt-5 inline-flex min-h-11 w-full items-center justify-center gap-2 rounded-control bg-rose-700 px-4 py-2.5 text-sm font-bold text-white hover:bg-rose-600 disabled:opacity-60 sm:w-auto"><x-ui.icon name="fa-solid fa-trash-can" /><span wire:loading.remove wire:target="deleteAccount">{{ __('settings.security_page.delete_account') }}</span><span wire:loading wire:target="deleteAccount">{{ __('settings.security_page.deleting_account') }}</span></button>
    </section>
</div>
