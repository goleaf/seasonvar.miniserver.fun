<span class="contents">
    @if ($helpLink !== null)
        <a href="{{ $helpLink->url }}" class="inline-flex min-h-11 w-full items-center justify-center gap-2 rounded-control border border-sky-200 bg-sky-50 px-4 py-2.5 text-sm font-bold text-sky-900 hover:bg-sky-100 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-emerald-700 sm:w-auto">
            <x-ui.icon name="fa-regular fa-circle-question" />
            <span>{{ $helpLink->title }}</span>
        </a>
    @endif
</span>
