<div class="space-y-5" data-administration-operations>
    <header class="rounded-panel border border-slate-200 bg-white p-4 shadow-panel sm:p-6">
        <p class="text-xs font-bold uppercase tracking-[0.16em] text-emerald-700">{{ __('administration.eyebrow') }}</p>
        <h1 class="mt-2 text-2xl font-black tracking-tight text-slate-800 sm:text-3xl">{{ __('administration.operations.title') }}</h1>
        <p class="mt-2 max-w-3xl text-sm leading-6 text-slate-600">{{ __('administration.operations.description') }}</p>
    </header>

    <p class="sr-only" role="status" aria-live="polite">{{ $statusMessage }}</p>

    @if ($errors->any())
        <x-administration.state type="error" :title="__('administration.shared.action_failed')" :description="$errors->first()" />
    @endif

    <section class="rounded-panel border border-slate-200 bg-white p-4 shadow-panel" aria-labelledby="system-readiness-title">
        <h2 id="system-readiness-title" class="text-lg font-black text-slate-800">{{ __('administration.operations.health_title') }}</h2>
        <div class="mt-3 flex flex-wrap items-center gap-3">
            <span class="rounded-full px-3 py-1 text-sm font-bold {{ $health['ready'] ? 'bg-emerald-50 text-emerald-800' : 'bg-amber-50 text-amber-800' }}">{{ $health['status_label'] }}</span>
            <time class="text-sm text-slate-500" datetime="{{ $health['checked_at'] }}">{{ __('administration.operations.checked_at', ['time' => $health['checked_at']]) }}</time>
        </div>
        <p class="mt-3 text-sm leading-6 text-slate-600">{{ __('administration.operations.health_scope') }}</p>
    </section>

    <section aria-labelledby="capabilities-title">
        <h2 id="capabilities-title" class="text-lg font-black text-slate-800">{{ __('administration.operations.capabilities_title') }}</h2>
        @if ($capabilities_error)
            <x-administration.state type="error" :title="__('administration.shared.error')" :description="__('administration.shared.query_failed')" />
        @else
            <div class="mt-3 grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
                @foreach ($capabilities as $capability)
                    <article class="rounded-panel border border-slate-200 bg-white p-4 shadow-panel" wire:key="admin-capability-{{ $capability->code }}">
                        <div class="flex items-start justify-between gap-3">
                            <h3 class="font-black text-slate-800">{{ $capability->label }}</h3>
                            <span class="shrink-0 rounded-full px-2 py-1 text-xs font-bold {{ $capability->installed ? 'bg-emerald-50 text-emerald-800' : 'bg-slate-100 text-slate-600' }}">{{ $capability->statusLabel }}</span>
                        </div>
                        <p class="mt-2 text-sm leading-6 text-slate-600">{{ $capability->description }}</p>
                    </article>
                @endforeach
            </div>
        @endif
    </section>

    <div class="grid gap-4 xl:grid-cols-2">
        <section class="rounded-panel border border-slate-200 bg-white p-4 shadow-panel" aria-labelledby="cache-operation-title">
            <h2 id="cache-operation-title" class="text-lg font-black text-slate-800">{{ __('administration.operations.cache_title') }}</h2>
            <p class="mt-1 text-sm leading-6 text-slate-600">{{ __('administration.operations.cache_description') }}</p>
            @if ($canInvalidateCache)
                <form wire:submit="invalidateCache" class="mt-4 grid gap-3">
                    <label class="grid gap-1 text-sm font-bold text-slate-700">
                        <span>{{ __('administration.operations.cache_domain') }}</span>
                        <select wire:model="cacheDomain" required class="min-h-11 rounded-control border border-slate-300 px-3 py-2 font-normal">
                            <option value="">{{ __('administration.operations.choose_cache_domain') }}</option>
                            @foreach ($cacheDomains as $value => $label)
                                <option value="{{ $value }}">{{ $label }}</option>
                            @endforeach
                        </select>
                    </label>
                    <p class="text-xs leading-5 text-amber-800" data-impact-preview>{{ __('administration.operations.cache_impact') }}</p>
                    <button type="submit" wire:confirm="{{ __('administration.operations.cache_confirm') }}" wire:loading.attr="disabled" class="min-h-11 rounded-control bg-amber-700 px-4 py-2 text-sm font-bold text-white disabled:opacity-60">{{ __('administration.operations.invalidate') }}</button>
                </form>
            @else
                <p class="mt-4 text-sm text-slate-500">{{ __('administration.users.read_only') }}</p>
            @endif
        </section>

        <section class="rounded-panel border border-slate-200 bg-white p-4 shadow-panel" aria-labelledby="search-operation-title">
            <h2 id="search-operation-title" class="text-lg font-black text-slate-800">{{ __('administration.operations.search_title') }}</h2>
            @if ($search_error)
                <x-administration.state type="error" :title="__('administration.shared.error')" :description="__('administration.shared.query_failed')" />
            @elseif ($search !== null)
                <dl class="mt-3 grid grid-cols-2 gap-3 text-sm">
                    <div><dt class="font-bold text-slate-500">{{ __('administration.operations.search_state') }}</dt><dd class="mt-1 text-slate-800">{{ $search['status_label'] }}</dd></div>
                    <div><dt class="font-bold text-slate-500">{{ __('administration.operations.search_version') }}</dt><dd class="mt-1 text-slate-800">{{ $search['version'] }}</dd></div>
                    <div><dt class="font-bold text-slate-500">{{ __('administration.operations.search_sources') }}</dt><dd class="mt-1 text-slate-800">{{ $search['source_count'] }}</dd></div>
                    <div><dt class="font-bold text-slate-500">{{ __('administration.operations.search_documents') }}</dt><dd class="mt-1 text-slate-800">{{ $search['document_count'] }}</dd></div>
                </dl>
                @if ($canReindex)
                    <form wire:submit="reindexResource" class="mt-4 grid gap-3 border-t border-slate-100 pt-4">
                        <label class="grid gap-1 text-sm font-bold text-slate-700">
                            <span>{{ __('administration.operations.catalog_slug') }}</span>
                            <input wire:model="catalogSlug" required maxlength="255" class="min-h-11 rounded-control border border-slate-300 px-3 py-2 font-normal">
                        </label>
                        <p class="text-xs leading-5 text-amber-800" data-impact-preview>{{ __('administration.operations.reindex_impact') }}</p>
                        <button type="submit" wire:confirm="{{ __('administration.operations.reindex_confirm') }}" wire:loading.attr="disabled" class="min-h-11 rounded-control bg-emerald-700 px-4 py-2 text-sm font-bold text-white disabled:opacity-60">{{ __('administration.operations.reindex') }}</button>
                    </form>
                @endif
            @else
                <x-administration.state type="unavailable" :title="__('administration.shared.unavailable')" :description="__('administration.operations.search_unavailable')" />
            @endif
        </section>
    </div>

    <section class="rounded-panel border border-slate-200 bg-white p-4 shadow-panel" aria-labelledby="operations-history-title">
        <h2 id="operations-history-title" class="text-lg font-black text-slate-800">{{ __('administration.operations.history_title') }}</h2>
        @if ($events_error)
            <x-administration.state type="error" :title="__('administration.shared.error')" :description="__('administration.shared.query_failed')" />
        @else
            <ol class="mt-3 divide-y divide-slate-100">
            @forelse ($events as $event)
                <li class="grid gap-1 py-3 text-sm sm:grid-cols-[minmax(0,1fr)_auto]" wire:key="admin-operation-{{ $event['public_id'] }}">
                    <div><span class="font-bold text-slate-800">{{ $event['action'] }}</span><span class="ml-2 text-slate-500">{{ $event['target'] }}</span></div>
                    <div class="text-slate-500">{{ $event['status'] }} · {{ $event['time'] }}</div>
                </li>
            @empty
                <li><x-administration.state type="empty" :title="__('administration.shared.empty')" :description="__('administration.operations.history_empty')" /></li>
            @endforelse
            </ol>
        @endif
    </section>
</div>
