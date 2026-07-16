@props(['request'])

<article class="min-w-0 rounded-panel border border-slate-200 bg-white p-4 shadow-panel sm:p-5" wire:key="content-request-{{ $request->publicId }}">
    <div class="flex min-w-0 flex-wrap items-start justify-between gap-3">
        <div class="min-w-0 flex-1">
            <div class="flex flex-wrap items-center gap-2 text-xs font-bold">
                <span class="rounded-full bg-emerald-50 px-2.5 py-1 text-emerald-800">{{ $request->typeLabel }}</span>
                <span class="rounded-full bg-slate-100 px-2.5 py-1 text-slate-700">{{ $request->statusLabel }}</span>
            </div>
            <h2 class="mt-3 break-words text-lg font-black text-slate-800">
                <a href="{{ $request->url }}" class="rounded focus:outline-none focus-visible:ring-2 focus-visible:ring-emerald-600 focus-visible:ring-offset-2">{{ $request->title }}</a>
            </h2>
            @if ($request->originalTitle)
                <p class="mt-1 break-words text-sm text-slate-500">{{ $request->originalTitle }}</p>
            @endif
            @if ($request->targetLabel && $request->targetUrl)
                <a href="{{ $request->targetUrl }}" class="mt-2 inline-flex min-h-11 max-w-full items-center gap-2 break-words text-sm font-bold text-emerald-700 hover:text-emerald-600">
                    <x-ui.icon name="fa-solid fa-film" />
                    <span>{{ $request->targetLabel }}</span>
                </a>
            @endif
        </div>
        @if ($request->year)
            <span class="shrink-0 text-sm font-bold text-slate-500">{{ $request->year }}</span>
        @endif
    </div>
    <div class="mt-4 flex flex-wrap items-center justify-between gap-3 border-t border-slate-100 pt-4 text-sm">
        <div class="flex flex-wrap items-center gap-4 text-slate-600">
            <span class="inline-flex items-center gap-2" aria-label="{{ __('requests.card.votes_label', ['count' => $request->votes]) }}"><x-ui.icon name="fa-solid fa-arrow-up" /> {{ $request->votes }}</span>
            <span class="inline-flex items-center gap-2" aria-label="{{ __('requests.card.followers_label', ['count' => $request->followers]) }}"><x-ui.icon name="fa-solid fa-bell" /> {{ $request->followers }}</span>
            <span>{{ $request->updatedLabel }}</span>
        </div>
        <a href="{{ $request->url }}" class="inline-flex min-h-11 items-center justify-center gap-2 rounded-control bg-slate-100 px-4 py-2 text-sm font-bold text-slate-700 hover:bg-slate-200">
            <span>{{ __('requests.actions.view') }}</span>
            <x-ui.icon name="fa-solid fa-arrow-right" />
        </a>
    </div>
</article>
