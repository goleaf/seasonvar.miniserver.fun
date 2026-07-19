<article class="group flex h-full min-w-0 flex-col overflow-hidden rounded-panel border border-slate-200 bg-white shadow-panel transition motion-safe:hover:-translate-y-0.5 motion-safe:hover:shadow-panel-hover">
    <a href="{{ $card->url }}" @class(['relative block overflow-hidden bg-slate-100', 'aspect-[3/2]' => $compact, 'aspect-[16/9]' => ! $compact]) aria-label="{{ $card->name }}">
        <x-ui.poster-frame
            :src="$card->imageUrl"
            :alt="$card->imageAlt"
            :empty-label="$card->emptyImageLabel"
            class="h-full w-full"
        />
        <span class="absolute bottom-2 left-2 rounded-full bg-slate-950/80 px-2.5 py-1 text-xs font-bold text-white backdrop-blur-sm">
            {{ $card->itemCountLabel }}
        </span>
        @if ($card->featured)
            <span class="absolute right-2 top-2 rounded-full bg-amber-100 px-2.5 py-1 text-xs font-black text-amber-800">
                {{ $card->featuredLabel }}
            </span>
        @endif
    </a>

    <div @class(['flex flex-1 flex-col', 'p-3' => $compact, 'p-4' => ! $compact])>
        <div class="flex flex-wrap gap-2 text-xs font-bold">
            @if ($card->management)
                <x-ui.status-pill variant="muted">{{ $card->visibilityLabel }}</x-ui.status-pill>
                <x-ui.status-pill variant="muted">{{ $card->moderationStatusLabel }}</x-ui.status-pill>
            @elseif ($card->editorial)
                <x-ui.status-pill variant="warning">{{ $card->typeLabel }}</x-ui.status-pill>
            @endif
            @if ($card->imported)
                <x-ui.status-pill variant="success" icon="fa-solid fa-rotate">{{ $card->importedLabel }}</x-ui.status-pill>
            @endif
        </div>

        @if ($compact)
            <h3 class="mt-3 line-clamp-2 break-words text-base font-black leading-snug text-slate-800"><a href="{{ $card->url }}" class="hover:text-emerald-700">{{ $card->name }}</a></h3>
        @else
            <h2 class="mt-3 break-words text-lg font-black leading-snug text-slate-800"><a href="{{ $card->url }}" class="hover:text-emerald-700">{{ $card->name }}</a></h2>
        @endif

        @if ($card->description !== '')
            <p @class(['mt-2 break-words text-sm text-slate-600', 'line-clamp-2 leading-5' => $compact, 'leading-6' => ! $compact])>{{ $card->description }}</p>
        @endif

        <div class="mt-auto flex flex-wrap items-center justify-between gap-2 pt-4 text-xs font-semibold text-slate-500">
            @if ($card->ownerName !== null)
                @if ($card->ownerUrl !== null && ! $card->management)
                    <a href="{{ $card->ownerUrl }}" class="min-w-0 break-words font-bold text-emerald-700 hover:text-emerald-600">{{ $card->ownerName }}</a>
                @else
                    <span class="min-w-0 break-words">{{ $card->ownerName }}</span>
                @endif
            @endif
            @if ($card->updatedAt !== '')
                <time datetime="{{ $card->updatedAtIso }}">{{ $card->updatedAt }}</time>
            @endif
        </div>
    </div>
</article>
