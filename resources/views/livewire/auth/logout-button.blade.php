<button
    type="button"
    wire:click="logout"
    wire:loading.attr="disabled"
    wire:target="logout"
    class="inline-flex min-h-11 min-w-11 shrink-0 items-center justify-center gap-2 rounded-control px-3 py-2 text-slate-600 hover:bg-slate-50 hover:text-rose-700 disabled:cursor-wait disabled:opacity-60"
    aria-label="{{ __('auth.actions.logout') }}"
    aria-live="polite"
>
    <x-ui.icon name="fa-solid fa-arrow-right-from-bracket" />
    <span class="sr-only sm:not-sr-only">{{ __('auth.actions.logout') }}</span>
</button>
