<div class="space-y-5">
    <header class="rounded-panel border border-slate-200 bg-white p-5 shadow-panel sm:p-6">
        <div class="flex items-start gap-4">
            <span class="grid h-14 w-14 shrink-0 place-items-center rounded-full bg-emerald-50 text-xl font-black text-emerald-700" aria-hidden="true">{{ mb_strtoupper(mb_substr($owner->name, 0, 1)) }}</span>
            <div class="min-w-0">
                <h1 class="break-words text-2xl font-black tracking-tight text-slate-800 sm:text-3xl">{{ __('collections.profile.title', ['name' => $owner->name]) }}</h1>
                <p class="mt-2 text-sm leading-6 text-slate-600">{{ __('collections.profile.description', ['name' => $owner->name]) }}</p>
                <nav aria-label="{{ __('collections.locale.switch') }}" class="mt-3 flex w-fit rounded-control bg-slate-100 p-1">
                    @foreach ($localeUrls as $locale => $localeUrl)
                        <a href="{{ $localeUrl }}" hreflang="{{ $locale }}" @class(['inline-flex min-h-9 items-center rounded-lg px-3 text-xs font-black', 'bg-white text-emerald-700 shadow-sm' => $interfaceLocale === $locale, 'text-slate-600 hover:text-emerald-700' => $interfaceLocale !== $locale])>{{ __('collections.locale.'.$locale) }}</a>
                    @endforeach
                </nav>
            </div>
        </div>
    </header>

    @if ($collections->isEmpty())
        <section class="rounded-panel border border-dashed border-slate-300 bg-white p-8 text-center shadow-panel">
            <p class="text-sm font-semibold text-slate-600">{{ __('collections.profile.empty') }}</p>
            <a href="{{ route('collections.index') }}" class="mt-4 inline-flex min-h-11 items-center justify-center gap-2 rounded-control bg-slate-100 px-4 py-2.5 text-sm font-bold text-slate-700 hover:bg-slate-200"><x-ui.icon name="fa-solid fa-layer-group" />{{ __('collections.navigation.public_collections') }}</a>
        </section>
    @else
        <section class="grid min-w-0 gap-4 sm:grid-cols-2 xl:grid-cols-3" aria-label="{{ __('collections.navigation.public_collections') }}">
            @foreach ($collections as $collection)
                <x-collections.collection-card wire:key="profile-collection-{{ $collection->public_id }}" :collection="$collection" />
            @endforeach
        </section>
        <nav aria-label="{{ __('collections.page.pagination') }}">{{ $collections->links() }}</nav>
    @endif
</div>
