<button
    type="button"
    data-pwa-logout
    wire:click="logout"
    wire:loading.attr="disabled"
    wire:target="logout"
    class="flex min-h-11 w-full items-center justify-start gap-2 rounded-control px-3 py-2 text-sm font-semibold text-slate-700 hover:bg-red-50 hover:text-red-700 disabled:cursor-wait disabled:opacity-60"
    aria-label="{{ __('auth.actions.logout') }}"
    aria-live="polite"
>
    <x-ui.icon name="fa-solid fa-arrow-right-from-bracket" />
    <span>{{ __('auth.actions.logout') }}</span>
</button>
