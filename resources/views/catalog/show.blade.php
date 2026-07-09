@extends('layouts.app', ['title' => $title->title])

@section('content')
    <article class="grid gap-8 lg:grid-cols-[280px_1fr]">
        <div>
            <div class="aspect-[2/3] overflow-hidden border border-white/10 bg-white/[0.04]">
                @if ($title->poster_url)
                    <img src="{{ $title->poster_url }}" alt="{{ $title->title }}" class="h-full w-full object-cover">
                @else
                    <div class="flex h-full items-center justify-center px-8 text-center text-sm text-zinc-500">No poster</div>
                @endif
            </div>
        </div>

        <div>
            <div class="flex flex-wrap items-center gap-2 text-sm text-zinc-400">
                <span>{{ ucfirst($title->type) }}</span>
                @if ($title->year)
                    <span>/ {{ $title->year }}</span>
                @endif
                <span>/ {{ $title->source->name }}</span>
            </div>

            <h1 class="mt-3 text-4xl font-semibold text-white">{{ $title->title }}</h1>

            @if ($title->description)
                <p class="mt-5 max-w-3xl text-base leading-8 text-zinc-300">{{ $title->description }}</p>
            @endif

            <div class="mt-6 flex flex-wrap gap-2">
                @foreach ($title->taxonomies as $taxonomy)
                    <span class="rounded-full border border-white/10 px-3 py-1 text-xs text-zinc-300">{{ $taxonomy->name }}</span>
                @endforeach
            </div>

            <div class="mt-8 grid gap-4 sm:grid-cols-2">
                <div class="border border-white/10 bg-white/[0.04] p-5">
                    <h2 class="text-lg font-semibold text-white">Seasons</h2>
                    <div class="mt-4 space-y-2">
                        @forelse ($title->seasons as $season)
                            <div class="flex items-center justify-between border-b border-white/10 pb-2 text-sm">
                                <span class="text-zinc-200">{{ $season->title ?? 'Season '.$season->number }}</span>
                                <span class="text-zinc-500">{{ $season->episodes->count() }} episodes</span>
                            </div>
                        @empty
                            <p class="text-sm text-zinc-400">No seasons parsed yet.</p>
                        @endforelse
                    </div>
                </div>

                <div class="border border-white/10 bg-white/[0.04] p-5">
                    <h2 class="text-lg font-semibold text-white">Source</h2>
                    <dl class="mt-4 space-y-3 text-sm">
                        <div>
                            <dt class="text-zinc-500">External id</dt>
                            <dd class="text-zinc-200">{{ $title->external_id ?? 'Unknown' }}</dd>
                        </div>
                        <div>
                            <dt class="text-zinc-500">Indexed</dt>
                            <dd class="text-zinc-200">{{ $title->indexed_at?->toDayDateTimeString() ?? 'Not indexed' }}</dd>
                        </div>
                        <div>
                            <dt class="text-zinc-500">Original page</dt>
                            <dd><a href="{{ $title->source_url }}" rel="nofollow noopener" class="break-all text-emerald-300 hover:text-emerald-200">{{ $title->source_url }}</a></dd>
                        </div>
                    </dl>
                </div>
            </div>
        </div>
    </article>
@endsection
