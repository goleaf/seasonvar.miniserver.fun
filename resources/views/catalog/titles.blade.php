@extends('layouts.app', ['title' => 'Titles'])

@section('content')
    <div class="flex flex-col gap-5 sm:flex-row sm:items-end sm:justify-between">
        <div>
            <h1 class="text-3xl font-semibold text-white">Titles</h1>
            <p class="mt-2 text-sm text-zinc-400">Search imported catalog metadata and open source references.</p>
        </div>

        <form method="GET" action="{{ route('titles.index') }}" class="flex w-full max-w-md gap-2">
            <input
                name="q"
                value="{{ $search }}"
                placeholder="Search title, description..."
                class="min-w-0 flex-1 rounded-md border border-white/10 bg-white/[0.05] px-3 py-2 text-sm text-white placeholder:text-zinc-500 focus:border-emerald-300 focus:outline-none"
            >
            <button class="rounded-md bg-emerald-400 px-4 py-2 text-sm font-semibold text-zinc-950 hover:bg-emerald-300">Search</button>
        </form>
    </div>

    <div class="mt-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
        @forelse ($titles as $catalogTitle)
            <x-title-card :title="$catalogTitle" />
        @empty
            <div class="col-span-full border border-dashed border-white/15 bg-white/[0.03] p-8 text-sm text-zinc-300">
                No matching titles.
            </div>
        @endforelse
    </div>

    <div class="mt-8">
        {{ $titles->links() }}
    </div>
@endsection
