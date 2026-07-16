<div
    @if ($hasActiveRun) wire:poll.5s.visible="refreshRuns" @endif
    class="space-y-5"
    data-livewire-seasonvar-import-manager
>
    <header class="rounded-panel border border-slate-200 bg-white p-4 shadow-panel sm:p-5">
        <div class="flex flex-col gap-4 xl:flex-row xl:items-start xl:justify-between">
            <div class="min-w-0">
                <h1 class="flex items-center gap-3 text-2xl font-black tracking-tight text-slate-800 sm:text-3xl">
                    <x-ui.icon name="fa-solid fa-cloud-arrow-down text-emerald-700" />
                    <span>{{ __('catalog.importer.title') }}</span>
                </h1>
                <p class="mt-2 max-w-3xl text-sm leading-6 text-slate-600">
                    {{ __('catalog.importer.description') }}
                </p>
            </div>

            @if ($staleCount > 0)
                <button
                    type="button"
                    wire:click="recoverStaleImports"
                    wire:confirm="{{ __('catalog.importer.recover_confirmation') }}"
                    wire:loading.attr="disabled"
                    wire:target="recoverStaleImports"
                    class="inline-flex min-h-11 w-full items-center justify-center gap-2 rounded-control bg-amber-50 px-4 py-2.5 text-sm font-bold text-amber-800 hover:bg-amber-100 disabled:cursor-wait disabled:opacity-60 sm:w-auto"
                >
                    <x-ui.icon name="fa-solid fa-triangle-exclamation" />
                    <span wire:loading.remove wire:target="recoverStaleImports">{{ __('catalog.importer.recover') }}</span>
                    <span wire:loading wire:target="recoverStaleImports">{{ __('catalog.importer.checking') }}</span>
                </button>
            @endif
        </div>
    </header>

    @if ($notice)
        <div role="status" class="flex items-start gap-2 rounded-control bg-emerald-50 px-4 py-3 text-sm font-semibold text-emerald-800">
            <x-ui.icon name="fa-solid fa-circle-check" align="start" />
            <span class="min-w-0 break-words">{{ $notice }}</span>
        </div>
    @endif

    @error('run')
        <div role="alert" class="flex items-start gap-2 rounded-control border border-rose-200 bg-rose-50 px-4 py-3 text-sm font-semibold text-rose-800">
            <x-ui.icon name="fa-solid fa-circle-exclamation" align="start" />
            <span class="min-w-0 break-words">{{ $message }}</span>
        </div>
    @enderror

    <x-ui.panel :title="__('catalog.importer.health')" :subtitle="__('catalog.importer.health_description')" icon="fa-solid fa-heart-pulse">
        <div class="grid gap-2 sm:grid-cols-2 xl:grid-cols-4">
            @foreach ($mediaHealth as $health)
                <div wire:key="media-health-{{ $health['status'] }}" class="rounded-control bg-slate-50 p-3">
                    <div class="flex items-center gap-2 text-xs font-bold uppercase text-slate-500">
                        <x-ui.icon name="{{ $health['icon'] }} {{ $health['tone'] }}" />
                        <span>{{ $health['label'] }}</span>
                    </div>
                    <div class="mt-1 text-xl font-black tabular-nums text-slate-800">{{ $health['count'] }}</div>
                </div>
            @endforeach
        </div>
        <div class="mt-3 flex items-center gap-2 text-sm font-semibold text-slate-600">
            <x-ui.icon name="fa-solid fa-clock-rotate-left text-sky-700" />
            <span>{{ __('catalog.importer.health_due', ['count' => $mediaDueCount]) }}</span>
        </div>
    </x-ui.panel>

    <x-ui.panel :title="__('catalog.importer.new_run')" :subtitle="__('catalog.importer.new_run_description')" icon="fa-solid fa-play">
        <form wire:submit="startImport" class="grid gap-4 lg:grid-cols-[minmax(0,1fr)_auto] lg:items-end">
            <div class="grid gap-3 sm:grid-cols-2">
                <label class="flex min-h-11 items-center gap-3 rounded-control bg-slate-50 px-3 py-2 text-sm font-semibold text-slate-700">
                    <input type="checkbox" wire:model="discover" class="h-5 w-5 rounded border-slate-300 text-emerald-700">
                    <span>{{ __('catalog.importer.discover') }}</span>
                </label>
                <label class="flex min-h-11 items-center gap-3 rounded-control bg-slate-50 px-3 py-2 text-sm font-semibold text-slate-700">
                    <input type="checkbox" wire:model="force" class="h-5 w-5 rounded border-slate-300 text-emerald-700">
                    <span>{{ __('catalog.importer.force') }}</span>
                </label>
            </div>

            <button
                type="submit"
                wire:loading.attr="disabled"
                wire:target="startImport"
                @disabled($hasActiveRun)
                class="inline-flex min-h-11 w-full items-center justify-center gap-2 rounded-control bg-emerald-700 px-5 py-2.5 text-sm font-bold text-white hover:bg-emerald-600 disabled:cursor-not-allowed disabled:bg-slate-300 lg:w-auto"
            >
                <x-ui.icon name="fa-solid fa-play" wire:loading.remove wire:target="startImport" />
                <x-ui.icon name="fa-solid fa-spinner fa-spin" wire:loading wire:target="startImport" />
                <span wire:loading.remove wire:target="startImport">{{ __('catalog.importer.queue') }}</span>
                <span wire:loading wire:target="startImport">{{ __('catalog.importer.queueing') }}</span>
            </button>
        </form>
    </x-ui.panel>

    <x-ui.panel :title="__('catalog.importer.runs')" :subtitle="trans_choice('catalog.counts.import_runs', count($runs))" icon="fa-solid fa-list-check" :pad="false">
        <div wire:loading.flex wire:target="refreshRuns,retryImport,cancelImport" class="min-h-24 items-center justify-center gap-2 px-4 py-8 text-sm font-semibold text-slate-500">
            <x-ui.icon name="fa-solid fa-spinner fa-spin text-emerald-700" />
            <span>{{ __('catalog.importer.updating') }}</span>
        </div>

        <div wire:loading.remove wire:target="refreshRuns,retryImport,cancelImport">
            @forelse ($runs as $run)
                <article wire:key="seasonvar-import-run-{{ $run['id'] }}" class="border-b border-slate-200 p-4 last:border-b-0 sm:p-5">
                    <div class="flex flex-col gap-4 xl:flex-row xl:items-start xl:justify-between">
                        <div class="min-w-0">
                            <div class="flex flex-wrap items-center gap-2">
                                <span class="text-sm font-black text-slate-800">{{ __('catalog.importer.run', ['id' => $run['id']]) }}</span>
                                <span @class([
                                    'inline-flex items-center gap-2 rounded-full px-3 py-1 text-xs font-bold',
                                    'bg-emerald-50 text-emerald-700' => $run['tone'] === 'success',
                                    'bg-rose-50 text-rose-700' => $run['tone'] === 'danger',
                                    'bg-amber-50 text-amber-800' => $run['tone'] === 'warning',
                                    'bg-sky-50 text-sky-700' => $run['tone'] === 'sky',
                                    'bg-slate-100 text-slate-600' => $run['tone'] === 'muted',
                                ])>
                                    @if ($run['status'] === 'running')
                                        <x-ui.icon name="fa-solid fa-spinner fa-spin" />
                                    @elseif ($run['status'] === 'completed')
                                        <x-ui.icon name="fa-solid fa-circle-check" />
                                    @elseif ($run['status'] === 'failed')
                                        <x-ui.icon name="fa-solid fa-circle-exclamation" />
                                    @elseif ($run['status'] === 'partial')
                                        <x-ui.icon name="fa-solid fa-triangle-exclamation" />
                                    @elseif ($run['status'] === 'cancelled')
                                        <x-ui.icon name="fa-solid fa-ban" />
                                    @else
                                        <x-ui.icon name="fa-solid fa-clock" />
                                    @endif
                                    <span>{{ $run['status_label'] }}</span>
                                </span>
                                @if ($run['is_stale'])
                                    <span class="text-xs font-bold text-amber-800">{{ __('catalog.importer.stale') }}</span>
                                @endif
                            </div>

                            <div class="mt-2 flex min-w-0 flex-wrap gap-x-4 gap-y-1 break-words text-xs font-semibold text-slate-500">
                                <span>{{ trans_choice('catalog.counts.import_records', $run['created'] + $run['updated'] + $run['skipped'] + $run['failed_total']) }}</span>
                                <span>{{ __('catalog.importer.started', ['value' => $run['started_at']]) }}</span>
                                <span>{{ __('catalog.importer.finished', ['value' => $run['finished_at']]) }}</span>
                                <span>{{ __('catalog.importer.heartbeat', ['value' => $run['heartbeat_at']]) }}</span>
                                @if ($run['requested_by'])
                                    <span>{{ __('catalog.importer.requested_by', ['value' => $run['requested_by']]) }}</span>
                                @endif
                                @if ($run['retry_of_run_id'])
                                    <span>{{ __('catalog.importer.retry_of', ['id' => $run['retry_of_run_id']]) }}</span>
                                @endif
                            </div>
                        </div>

                        <div class="grid gap-2 sm:flex sm:flex-wrap">
                            @if ($run['can_retry'])
                                <button
                                    type="button"
                                    wire:click="retryImport({{ $run['id'] }})"
                                    wire:loading.attr="disabled"
                                    wire:target="retryImport({{ $run['id'] }})"
                                    class="inline-flex min-h-11 w-full items-center justify-center gap-2 rounded-control bg-sky-50 px-4 py-2 text-sm font-bold text-sky-700 hover:bg-sky-100 disabled:cursor-wait disabled:opacity-60 sm:w-auto"
                                >
                                    <x-ui.icon name="fa-solid fa-rotate-right" />
                                    <span>{{ __('catalog.importer.retry') }}</span>
                                </button>
                            @endif
                            @if ($run['can_cancel'])
                                <button
                                    type="button"
                                    wire:click="cancelImport({{ $run['id'] }})"
                                    wire:confirm="{{ __('catalog.importer.cancel_confirmation', ['id' => $run['id']]) }}"
                                    wire:loading.attr="disabled"
                                    wire:target="cancelImport({{ $run['id'] }})"
                                    class="inline-flex min-h-11 w-full items-center justify-center gap-2 rounded-control bg-rose-50 px-4 py-2 text-sm font-bold text-rose-700 hover:bg-rose-100 disabled:cursor-wait disabled:opacity-60 sm:w-auto"
                                >
                                    <x-ui.icon name="fa-solid fa-ban" />
                                    <span>{{ __('catalog.importer.cancel') }}</span>
                                </button>
                            @endif
                        </div>
                    </div>

                    <div class="mt-4 h-2 overflow-hidden rounded-full bg-slate-100" role="progressbar" aria-label="{{ __('catalog.importer.progress') }}" aria-valuemin="0" aria-valuemax="100" aria-valuenow="{{ $run['progress'] }}">
                        <div class="h-full rounded-full bg-emerald-600" style="width: {{ $run['progress'] }}%"></div>
                    </div>

                    <div class="mt-4 grid gap-2 sm:grid-cols-2 xl:grid-cols-4">
                        @foreach ([
                            ['label' => __('catalog.importer.created'), 'value' => $run['created'], 'icon' => 'fa-solid fa-plus', 'tone' => 'text-emerald-700'],
                            ['label' => __('catalog.importer.updated'), 'value' => $run['updated'], 'icon' => 'fa-solid fa-arrows-rotate', 'tone' => 'text-sky-700'],
                            ['label' => __('catalog.importer.skipped'), 'value' => $run['skipped'], 'icon' => 'fa-solid fa-forward', 'tone' => 'text-slate-600'],
                            ['label' => __('catalog.importer.failed'), 'value' => $run['failed_total'], 'icon' => 'fa-solid fa-triangle-exclamation', 'tone' => 'text-rose-700'],
                        ] as $count)
                            <div wire:key="seasonvar-run-{{ $run['id'] }}-{{ $count['label'] }}" class="rounded-control bg-slate-50 p-3">
                                <div class="flex items-center gap-2 text-xs font-bold uppercase text-slate-400">
                                    <x-ui.icon name="{{ $count['icon'] }} {{ $count['tone'] }}" />
                                    <span>{{ $count['label'] }}</span>
                                </div>
                                <div class="mt-1 text-xl font-black tabular-nums text-slate-800">{{ $count['value'] }}</div>
                            </div>
                        @endforeach
                    </div>

                    <div class="mt-3 grid gap-2 text-xs font-semibold text-slate-500 sm:grid-cols-2 xl:grid-cols-3">
                        <div>{{ __('catalog.importer.pages', ['parsed' => $run['parsed'], 'selected' => $run['selected']]) }}</div>
                        <div>{{ __('catalog.importer.stored_pages', ['count' => $run['stored']]) }}</div>
                        <div>{{ __('catalog.importer.media', ['attached' => $run['media_attached'], 'updated' => $run['media_updated']]) }}</div>
                        <div>{{ __('catalog.importer.media_failed', ['skipped' => $run['media_skipped'], 'failed' => $run['media_failed']]) }}</div>
                        <div>{{ __('catalog.importer.media_sizes', ['checked' => $run['media_sizes_checked'], 'known' => $run['media_sizes_known'], 'unknown' => $run['media_sizes_unknown'], 'unsupported' => $run['media_sizes_unsupported'], 'failed' => $run['media_size_checks_failed']]) }}</div>
                        <div>{{ __('catalog.importer.media_size_bytes', ['size' => $run['media_size_known_label'], 'bytes' => $run['media_size_known_bytes']]) }}</div>
                    </div>

                    @if ($run['error'])
                        <div class="mt-3 rounded-control bg-rose-50 px-3 py-2 text-sm font-semibold text-rose-800">
                            {{ $run['error'] }}
                        </div>
                    @endif
                </article>
            @empty
                <div class="px-4 py-10 text-center text-sm text-slate-500">
                    <x-ui.icon name="fa-solid fa-inbox text-3xl text-slate-300" />
                    <p class="mt-3">{{ __('catalog.importer.empty') }}</p>
                </div>
            @endforelse
        </div>
    </x-ui.panel>
</div>
