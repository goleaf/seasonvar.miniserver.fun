<x-ui.poster-card
    :src="$title->poster_url"
    :alt="$posterAlt"
    layout="list"
    data-home-latest-media-group="{{ $title->id }}"
>
    <h3 class="font-bold leading-5 text-slate-700">
        <a href="{{ $titleUrl }}" class="break-words hover:text-emerald-700 hover:underline">
            {{ $title->display_title }}
        </a>
    </h3>
    @if ($title->display_original_title)
        <p class="mt-0.5 break-words text-xs font-semibold leading-5 text-slate-500">{{ $title->display_original_title }}</p>
    @endif
    <div class="mt-3 divide-y divide-slate-100 border-t border-slate-100">
        @foreach ($items as $item)
            <article class="py-3 first:pt-3 last:pb-0">
                <div class="flex flex-wrap gap-1 text-xs font-semibold">
                    @if ($item['season_label'])
                        <span class="inline-flex items-center gap-1 rounded-full bg-emerald-50 px-2 py-1 text-emerald-700">
                            <x-ui.icon name="fa-solid fa-layer-group" />
                            <span>{{ $item['season_label'] }}</span>
                        </span>
                    @endif
                    @if ($item['episode_label'])
                        <span class="inline-flex items-center gap-1 rounded-full bg-sky-50 px-2 py-1 text-sky-700">
                            <x-ui.icon name="fa-solid fa-list-ol" />
                            <span>{{ $item['episode_label'] }}</span>
                        </span>
                    @endif
                </div>

                @if ($item['title'])
                    <p class="mt-1.5 break-words text-sm font-semibold text-slate-700">{{ $item['title'] }}</p>
                @endif

                <div class="mt-2 space-y-1.5">
                    @forelse ($item['media'] as $mediaItem)
                        <a href="{{ $mediaItem['url'] }}" class="flex min-h-11 min-w-0 items-start gap-2 rounded-control bg-slate-50 px-3 py-2 text-xs text-slate-600 hover:bg-emerald-50 hover:text-emerald-700">
                            @if ($mediaItem['quality'])
                                <span class="inline-flex shrink-0 items-center gap-1 rounded-full bg-amber-50 px-2 py-1 font-bold text-amber-700">
                                    <x-ui.icon name="fa-solid fa-display" />
                                    <span>{{ $mediaItem['quality'] }}</span>
                                </span>
                            @endif
                            <span class="min-w-0 break-words py-1">{{ $mediaItem['meta'] }}</span>
                        </a>
                    @empty
                        <p class="text-xs text-slate-500">Видео для серии пока не добавлено.</p>
                    @endforelse
                </div>
            </article>
        @endforeach
    </div>
</x-ui.poster-card>
