@props([
    'spotlight' => null,
    'candidates' => collect(),
])

<div class="grid grid-cols-2 gap-x-3 gap-y-5 lg:grid-cols-[minmax(0,1.5fr)_repeat(2,minmax(0,0.75fr))] lg:gap-4">
    @if ($spotlight !== null)
        <div class="col-span-1 lg:row-span-2">
            <x-catalog.title-card
                :title="$spotlight->title"
                layout="spotlight"
                :reason-labels="[__('home.trending.reason')]"
            />
        </div>
    @endif

    @foreach ($candidates as $candidate)
        <x-catalog.title-card :title="$candidate->title" layout="trend" :show-description="false" />
    @endforeach
</div>

@if ($spotlight === null && $candidates->isEmpty())
    <p class="border-t border-slate-200 py-6 text-sm text-slate-600">{{ __('home.empty_states.trending') }}</p>
@endif
