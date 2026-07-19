@props(['groups'])

<nav
    data-administration-navigation
    aria-label="{{ __('administration.navigation.label') }}"
    class="mb-5 rounded-panel border border-slate-200 bg-white p-3 shadow-panel sm:p-4"
>
    <div class="flex items-center justify-between gap-3 border-b border-slate-200 pb-3">
        <div class="flex min-w-0 items-center gap-3">
            <span class="grid h-11 w-11 shrink-0 place-items-center rounded-control bg-emerald-50 text-emerald-700">
                <x-ui.icon name="fa-solid fa-user-shield" />
            </span>
            <div class="min-w-0">
                <p class="break-words text-sm font-black text-slate-800">{{ __('administration.navigation.title') }}</p>
                <p class="break-words text-xs leading-5 text-slate-500">{{ __('administration.navigation.description') }}</p>
            </div>
        </div>
        <a href="{{ route('home') }}" class="inline-flex min-h-11 shrink-0 items-center gap-2 rounded-control px-3 py-2 text-sm font-bold text-slate-600 hover:bg-slate-100 hover:text-emerald-700">
            <x-ui.icon name="fa-solid fa-arrow-left" />
            <span class="hidden sm:inline">{{ __('administration.navigation.portal') }}</span>
        </a>
    </div>

    <div class="mt-3 grid gap-4 md:grid-cols-2 xl:grid-cols-4">
        @foreach ($groups as $group => $items)
            <section aria-labelledby="admin-nav-{{ $group }}" class="min-w-0">
                <h2 id="admin-nav-{{ $group }}" class="px-2 text-xs font-black uppercase tracking-[0.12em] text-slate-500">
                    {{ __('administration.navigation.groups.'.$group) }}
                </h2>
                <ul class="mt-1 space-y-1">
                    @foreach ($items as $item)
                        <li wire:key="admin-navigation-{{ $item->code }}">
                            <a
                                href="{{ $item->url }}"
                                @if ($item->active) aria-current="page" @endif
                                @class([
                                    'flex min-h-11 min-w-0 items-center gap-3 rounded-control px-3 py-2 text-sm font-bold transition focus-visible:outline-none focus-visible:ring-4 focus-visible:ring-emerald-200',
                                    'bg-emerald-50 text-emerald-800' => $item->active,
                                    'text-slate-600 hover:bg-slate-100 hover:text-emerald-700' => ! $item->active,
                                ])
                            >
                                <x-ui.icon :name="$item->icon.' shrink-0'" />
                                <span class="min-w-0 break-words">{{ $item->label }}</span>
                            </a>
                        </li>
                    @endforeach
                </ul>
            </section>
        @endforeach
    </div>
</nav>
