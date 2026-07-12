@props(['siteName'])

<footer data-site-footer {{ $attributes->class(['mt-8 border-t border-slate-200 bg-white']) }}>
    <div class="mx-auto flex max-w-[1760px] flex-col gap-4 px-3 py-6 text-sm text-slate-600 sm:px-6 lg:flex-row lg:items-center lg:justify-between lg:px-8">
        <div class="inline-flex items-center gap-2 font-bold text-slate-700">
            <i class="fa-solid fa-film text-emerald-700" aria-hidden="true"></i>
            <span>{{ $siteName }}</span>
        </div>
        <nav aria-label="Техническая навигация" class="flex flex-wrap gap-2">
            <a href="{{ route('titles.index') }}" class="inline-flex min-h-11 items-center rounded-control px-3 py-2 font-semibold hover:bg-emerald-50 hover:text-emerald-700">Каталог</a>
            <a href="{{ route('sitemap') }}" class="inline-flex min-h-11 items-center rounded-control px-3 py-2 font-semibold hover:bg-emerald-50 hover:text-emerald-700">Карта сайта</a>
            <a href="{{ route('feed') }}" class="inline-flex min-h-11 items-center rounded-control px-3 py-2 font-semibold hover:bg-emerald-50 hover:text-emerald-700">RSS</a>
        </nav>
    </div>
</footer>
