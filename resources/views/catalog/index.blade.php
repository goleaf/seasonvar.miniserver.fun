@extends('layouts.app')

@section('content')
    <section class="grid gap-6 lg:grid-cols-[1.4fr_0.8fr]">
        <div>
            <p class="text-sm font-medium uppercase tracking-wide text-emerald-300">Metadata portal</p>
            <h1 class="mt-3 max-w-3xl text-4xl font-semibold text-white sm:text-5xl">Catalog indexer for public series metadata.</h1>
            <p class="mt-4 max-w-3xl text-base leading-7 text-zinc-300">
                The project is prepared for SQLite, Laravel Boost MCP, catalog discovery, parsing, review, and licensed media records.
            </p>
        </div>

        <div class="grid grid-cols-2 gap-3">
            <x-stat label="Titles" :value="$stats['titles']" />
            <x-stat label="Source pages" :value="$stats['sourcePages']" />
            <x-stat label="Pending" :value="$stats['pendingPages']" />
            <x-stat label="Owned media" :value="$stats['licensedMedia']" />
        </div>
    </section>

    <section class="mt-10 grid gap-8 lg:grid-cols-[1fr_320px]">
        <div>
            <div class="flex items-end justify-between gap-4">
                <div>
                    <h2 class="text-xl font-semibold text-white">Latest titles</h2>
                    <p class="mt-1 text-sm text-zinc-400">Parsed catalog entries appear here after running the importer.</p>
                </div>
                <a href="{{ route('titles.index') }}" class="rounded-md bg-emerald-400 px-4 py-2 text-sm font-semibold text-zinc-950 hover:bg-emerald-300">Browse</a>
            </div>

            <div class="mt-5 grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
                @forelse ($latestTitles as $catalogTitle)
                    <x-title-card :title="$catalogTitle" />
                @empty
                    <div class="col-span-full border border-dashed border-white/15 bg-white/[0.03] p-8 text-sm text-zinc-300">
                        No titles yet. Start with <code class="text-emerald-300">php artisan seasonvar:seed-source</code>, then discover and parse metadata pages.
                    </div>
                @endforelse
            </div>
        </div>

        <aside class="space-y-4">
            <div class="border border-white/10 bg-white/[0.04] p-5">
                <h2 class="text-lg font-semibold text-white">Importer commands</h2>
                <div class="mt-4 space-y-3 text-sm text-zinc-300">
                    <code class="block rounded bg-black/40 p-3 text-emerald-200">php artisan seasonvar:seed-source</code>
                    <code class="block rounded bg-black/40 p-3 text-emerald-200">php artisan seasonvar:discover --limit=100</code>
                    <code class="block rounded bg-black/40 p-3 text-emerald-200">php artisan seasonvar:parse-page --limit=25</code>
                </div>
            </div>

            <div class="border border-white/10 bg-white/[0.04] p-5">
                <h2 class="text-lg font-semibold text-white">Top genres</h2>
                <div class="mt-4 flex flex-wrap gap-2">
                    @forelse ($genres as $genre)
                        <span class="rounded-full border border-white/10 px-3 py-1 text-xs text-zinc-300">{{ $genre->name }}</span>
                    @empty
                        <p class="text-sm text-zinc-400">Genres will be created during parsing.</p>
                    @endforelse
                </div>
            </div>
        </aside>
    </section>
@endsection
