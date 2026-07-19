<div class="space-y-5" data-administration-audit>
    <header class="rounded-panel border border-slate-200 bg-white p-4 shadow-panel sm:p-6">
        <p class="text-xs font-bold uppercase tracking-[0.16em] text-emerald-700">{{ __('administration.eyebrow') }}</p>
        <h1 class="mt-2 text-2xl font-black tracking-tight text-slate-800 sm:text-3xl">{{ __('administration.audit.title') }}</h1>
        <p class="mt-2 max-w-3xl text-sm leading-6 text-slate-600">{{ __('administration.audit.description') }}</p>
    </header>

    @if ($errors->any())
        <x-administration.state type="error" :title="__('administration.shared.action_failed')" :description="$errors->first()" />
    @endif

    @if ($queryFailed)
        <x-administration.state type="error" :title="__('administration.shared.error')" :description="__('administration.shared.query_failed')" />
    @endif

    <x-administration.filters :label="__('administration.audit.filters_label')" :active-count="$activeFilterCount">
        <label class="grid gap-1 text-sm font-bold text-slate-700">
            <span>{{ __('administration.audit.action') }}</span>
            <select wire:model.live="action" class="min-h-11 w-full min-w-0 rounded-control border border-slate-300 px-3 py-2 font-normal">
                <option value="">{{ __('administration.audit.all_actions') }}</option>
                @foreach ($actions as $value => $label)
                    <option value="{{ $value }}">{{ $label }}</option>
                @endforeach
            </select>
        </label>
        <label class="grid gap-1 text-sm font-bold text-slate-700">
            <span>{{ __('administration.audit.resource') }}</span>
            <input wire:model.live.debounce.400ms="resource" maxlength="64" class="min-h-11 w-full min-w-0 rounded-control border border-slate-300 px-3 py-2 font-normal" placeholder="catalog_title">
        </label>
        <label class="grid gap-1 text-sm font-bold text-slate-700">
            <span>{{ __('administration.audit.from') }}</span>
            <input type="date" wire:model.live="from" class="min-h-11 w-full min-w-0 rounded-control border border-slate-300 px-3 py-2 font-normal">
        </label>
        <label class="grid gap-1 text-sm font-bold text-slate-700">
            <span>{{ __('administration.audit.to') }}</span>
            <input type="date" wire:model.live="to" class="min-h-11 w-full min-w-0 rounded-control border border-slate-300 px-3 py-2 font-normal">
        </label>
        @if ($canExport)
            <x-slot:actions>
                <button type="button" wire:click="export" wire:loading.attr="disabled" class="min-h-11 rounded-control bg-emerald-700 px-4 py-2 text-sm font-bold text-white disabled:opacity-60">{{ __('administration.audit.export') }}</button>
            </x-slot:actions>
        @endif
    </x-administration.filters>

    <div wire:loading.delay role="status" aria-live="polite" class="rounded-control bg-emerald-50 px-4 py-3 text-sm font-bold text-emerald-800">{{ __('administration.shared.loading') }}</div>

    @island(name: 'admin-audit-events', always: true, with: $this->paginationIslandPage)
    <x-ui.pagination-region name="admin-audit-events">
    <section class="rounded-panel border border-slate-200 bg-white shadow-panel" aria-labelledby="audit-events-title">
        <h2 id="audit-events-title" class="sr-only">{{ __('administration.audit.events') }}</h2>
        <ol class="divide-y divide-slate-100">
            @forelse ($events as $event)
                <li class="grid gap-3 p-4 lg:grid-cols-[minmax(0,1fr)_minmax(0,0.8fr)_auto] lg:items-start" wire:key="admin-audit-{{ $event->publicId }}">
                    <div>
                        <h3 class="font-black text-slate-800">{{ $event->actionLabel }}</h3>
                        <p class="mt-1 text-sm text-slate-600">{{ $event->resourceLabel }}</p>
                        <code class="mt-1 block break-all text-xs text-slate-400">{{ $event->resourcePublicId }}</code>
                    </div>
                    <div class="text-sm text-slate-600">
                        <p>{{ __('administration.audit.actor', ['name' => $event->actorName]) }}</p>
                        <code class="mt-1 block break-all text-xs text-slate-400">{{ $event->actorPublicId }}</code>
                        @if ($event->changedFieldLabels !== [])
                            <p class="mt-2">{{ __('administration.audit.changed_fields', ['fields' => implode(', ', $event->changedFieldLabels)]) }}</p>
                        @endif
                    </div>
                    <time class="text-sm text-slate-500" datetime="{{ $event->occurredAtIso }}">{{ $event->occurredAtLabel }}</time>
                </li>
            @empty
                <li class="p-4"><x-administration.state type="empty" :title="__('administration.shared.empty')" :description="__('administration.audit.empty')" /></li>
            @endforelse
        </ol>
        <div class="border-t border-slate-100 px-4 py-3">{{ $events->links(data: ['region' => 'admin-audit-events']) }}</div>
    </section>
    </x-ui.pagination-region>
    @endisland
</div>
