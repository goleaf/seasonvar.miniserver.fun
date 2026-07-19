<div class="space-y-5" data-administration-dashboard>
    <header class="rounded-panel border border-slate-200 bg-white p-4 shadow-panel sm:p-6">
        <p class="text-xs font-bold uppercase tracking-[0.16em] text-emerald-700">{{ __('administration.eyebrow') }}</p>
        <h1 class="mt-2 text-2xl font-black tracking-tight text-slate-800 sm:text-3xl">{{ __('administration.dashboard.title') }}</h1>
        <p class="mt-2 max-w-3xl text-sm leading-6 text-slate-600">{{ __('administration.dashboard.description') }}</p>
    </header>

    @if ($sections === [])
        <x-ui.panel>
            <div role="status" class="flex items-start gap-3 text-sm leading-6 text-slate-600">
                <x-ui.icon name="fa-solid fa-circle-info mt-1 text-slate-400" />
                <p>{{ __('administration.dashboard.states.empty') }}</p>
            </div>
        </x-ui.panel>
    @else
        <div class="grid gap-4 lg:grid-cols-2" aria-label="{{ __('administration.dashboard.summary_label') }}">
            @foreach ($sections as $section)
                <section class="overflow-hidden rounded-panel border border-slate-200 bg-white shadow-panel" aria-labelledby="dashboard-section-{{ $section->code }}" wire:key="dashboard-section-{{ $section->code }}">
                    <header class="flex items-start justify-between gap-4 border-b border-slate-100 px-4 py-4 sm:px-5">
                        <div class="flex min-w-0 items-start gap-3">
                            <span class="grid size-10 shrink-0 place-items-center rounded-xl bg-slate-100 text-slate-600" aria-hidden="true">
                                <x-ui.icon :name="$section->icon" />
                            </span>
                            <div>
                                <h2 id="dashboard-section-{{ $section->code }}" class="text-base font-black text-slate-800">{{ $section->label }}</h2>
                                <p class="mt-1 text-sm leading-5 text-slate-500">{{ $section->description }}</p>
                            </div>
                        </div>
                        <time class="shrink-0 text-xs text-slate-400" datetime="{{ $section->readAtIso }}">{{ $section->readAtLabel }}</time>
                    </header>

                    @if ($section->available)
                        <dl class="grid grid-cols-2 divide-x divide-y divide-slate-100 sm:grid-cols-3">
                            @foreach ($section->metrics as $metric)
                                <div class="min-w-0 px-4 py-4" wire:key="dashboard-metric-{{ $section->code }}-{{ $metric->code }}">
                                    <dt class="text-xs font-bold leading-5 text-slate-500">{{ $metric->label }}</dt>
                                    <dd class="mt-1 text-2xl font-black tabular-nums text-slate-800" data-dashboard-metric-value="{{ $metric->value }}">{{ $metric->formattedValue }}</dd>
                                </div>
                            @endforeach
                        </dl>
                    @else
                        <div class="flex items-start gap-3 px-4 py-5 text-sm leading-6 text-amber-800" role="status">
                            <x-ui.icon name="fa-solid fa-triangle-exclamation mt-1 text-amber-500" />
                            <p>{{ __('administration.dashboard.states.unavailable') }}</p>
                        </div>
                    @endif
                </section>
            @endforeach
        </div>
    @endif
</div>
