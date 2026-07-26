<article @class([
    'group flex h-full min-w-0 flex-col rounded-panel border border-slate-200 bg-white shadow-panel transition',
    'p-4' => $compact,
    'p-5' => ! $compact,
])>
    <div class="flex flex-1 flex-col">
        <div class="flex flex-wrap items-center gap-2 text-xs font-bold">
            <span class="inline-flex min-h-7 items-center gap-1.5 rounded-full bg-emerald-50 px-2.5 py-1 text-emerald-800">
                <x-ui.icon name="fa-solid fa-folder-tree" />
                <span class="break-words">{{ $card->categoryPath }}</span>
            </span>
            @if ($card->management)
                <x-ui.status-pill variant="muted">{{ $card->visibilityLabel }}</x-ui.status-pill>
                <x-ui.status-pill variant="muted">{{ $card->moderationStatusLabel }}</x-ui.status-pill>
            @elseif ($card->editorial)
                <x-ui.status-pill variant="warning">{{ $card->typeLabel }}</x-ui.status-pill>
            @endif
            @if ($card->imported)
                <x-ui.status-pill variant="success" icon="fa-solid fa-rotate">{{ $card->importedLabel }}</x-ui.status-pill>
            @endif
            @if ($card->smart)
                <x-ui.status-pill variant="success" icon="fa-solid fa-wand-magic-sparkles">{{ __('collections.smart.badge') }}</x-ui.status-pill>
            @endif
            @if ($card->featured)
                <x-ui.status-pill variant="warning">{{ $card->featuredLabel }}</x-ui.status-pill>
            @endif
        </div>

        @if ($compact)
            <h3 class="mt-3 break-words text-lg font-black leading-snug text-slate-900"><a href="{{ $card->url }}" class="hover:text-emerald-700">{{ $card->name }}</a></h3>
        @else
            <h2 class="mt-3 break-words text-xl font-black leading-snug text-slate-900"><a href="{{ $card->url }}" class="hover:text-emerald-700">{{ $card->name }}</a></h2>
        @endif

        @if ($card->description !== '')
            <p class="mt-2 break-words text-sm leading-6 text-slate-600">{{ $card->description }}</p>
        @endif

        <div class="mt-auto flex flex-wrap items-center gap-x-4 gap-y-2 border-t border-slate-100 pt-4 text-xs font-semibold text-slate-500">
            <span class="inline-flex items-center gap-1.5 text-slate-700">
                <x-ui.icon name="fa-solid fa-list" />
                {{ $card->itemCountLabel }}
            </span>
            @if ($card->ownerName !== null)
                @if ($card->ownerUrl !== null && ! $card->management)
                    <a href="{{ $card->ownerUrl }}" class="min-w-0 break-words font-bold text-emerald-700 hover:text-emerald-600">{{ $card->ownerName }}</a>
                @else
                    <span class="min-w-0 break-words">{{ $card->ownerName }}</span>
                @endif
            @endif
            @if ($card->updatedAt !== '')
                <time datetime="{{ $card->updatedAtIso }}" class="sm:ml-auto">{{ $card->updatedAt }}</time>
            @endif
        </div>
    </div>
</article>
