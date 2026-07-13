<div
    @if ($hasActiveRun) wire:poll.5s.visible="refreshRuns" @endif
    class="space-y-5"
    data-livewire-seasonvar-import-manager
>
    <header class="rounded-panel border border-slate-200 bg-white p-4 shadow-panel sm:p-5">
        <div class="flex flex-col gap-4 xl:flex-row xl:items-start xl:justify-between">
            <div class="min-w-0">
                <h1 class="flex items-center gap-3 text-2xl font-black tracking-tight text-slate-800 sm:text-3xl">
                    <i class="fa-solid fa-cloud-arrow-down text-emerald-700" aria-hidden="true"></i>
                    <span>Импорт Seasonvar</span>
                </h1>
                <p class="mt-2 max-w-3xl text-sm leading-6 text-slate-600">
                    Запуск передаётся фоновой очереди. Страница показывает состояние и безопасные итоговые счётчики.
                </p>
            </div>

            @if ($staleCount > 0)
                <button
                    type="button"
                    wire:click="recoverStaleImports"
                    wire:confirm="Закрыть зависшие запуски без живых задач?"
                    wire:loading.attr="disabled"
                    wire:target="recoverStaleImports"
                    class="inline-flex min-h-11 items-center justify-center gap-2 rounded-control bg-amber-50 px-4 py-2.5 text-sm font-bold text-amber-800 hover:bg-amber-100 disabled:cursor-wait disabled:opacity-60"
                >
                    <i class="fa-solid fa-triangle-exclamation" aria-hidden="true"></i>
                    <span wire:loading.remove wire:target="recoverStaleImports">Закрыть зависшие</span>
                    <span wire:loading wire:target="recoverStaleImports">Проверяем…</span>
                </button>
            @endif
        </div>
    </header>

    @if ($notice)
        <div role="status" class="rounded-control bg-emerald-50 px-4 py-3 text-sm font-semibold text-emerald-800">
            {{ $notice }}
        </div>
    @endif

    @error('run')
        <div role="alert" class="rounded-control bg-rose-50 px-4 py-3 text-sm font-semibold text-rose-800">
            {{ $message }}
        </div>
    @enderror

    <x-ui.panel title="Здоровье видеоисточников" subtitle="Агрегаты без адресов источников и внутренних диагностических данных." icon="fa-solid fa-heart-pulse">
        <div class="grid gap-2 sm:grid-cols-2 xl:grid-cols-4">
            @foreach ($mediaHealth as $health)
                <div wire:key="media-health-{{ $health['status'] }}" class="rounded-control bg-slate-50 p-3">
                    <div class="flex items-center gap-2 text-xs font-bold uppercase text-slate-500">
                        <i class="{{ $health['icon'] }} {{ $health['tone'] }}" aria-hidden="true"></i>
                        <span>{{ $health['label'] }}</span>
                    </div>
                    <div class="mt-1 text-xl font-black text-slate-800">{{ $health['count'] }}</div>
                </div>
            @endforeach
        </div>
        <div class="mt-3 flex items-center gap-2 text-sm font-semibold text-slate-600">
            <i class="fa-solid fa-clock-rotate-left text-sky-700" aria-hidden="true"></i>
            <span>Ожидают проверки: {{ $mediaDueCount }}</span>
        </div>
    </x-ui.panel>

    <x-ui.panel title="Новый запуск" subtitle="Одновременно допускается только один queued или running запуск." icon="fa-solid fa-play">
        <form wire:submit="startImport" class="grid gap-4 lg:grid-cols-[minmax(0,1fr)_auto] lg:items-end">
            <div class="grid gap-3 sm:grid-cols-2">
                <label class="flex min-h-11 items-center gap-3 rounded-control bg-slate-50 px-3 py-2 text-sm font-semibold text-slate-700">
                    <input type="checkbox" wire:model="discover" class="h-4 w-4 rounded border-slate-300 text-emerald-700 focus:ring-emerald-600">
                    <span>Обновить карту сайта</span>
                </label>
                <label class="flex min-h-11 items-center gap-3 rounded-control bg-slate-50 px-3 py-2 text-sm font-semibold text-slate-700">
                    <input type="checkbox" wire:model="force" class="h-4 w-4 rounded border-slate-300 text-emerald-700 focus:ring-emerald-600">
                    <span>Принудительно обновить страницы</span>
                </label>
            </div>

            <button
                type="submit"
                wire:loading.attr="disabled"
                wire:target="startImport"
                @disabled($hasActiveRun)
                class="inline-flex min-h-11 items-center justify-center gap-2 rounded-control bg-emerald-700 px-5 py-2.5 text-sm font-bold text-white hover:bg-emerald-600 disabled:cursor-not-allowed disabled:bg-slate-300"
            >
                <i wire:loading.remove wire:target="startImport" class="fa-solid fa-play" aria-hidden="true"></i>
                <i wire:loading wire:target="startImport" class="fa-solid fa-spinner fa-spin" aria-hidden="true"></i>
                <span wire:loading.remove wire:target="startImport">Поставить в очередь</span>
                <span wire:loading wire:target="startImport">Ставим…</span>
            </button>
        </form>
    </x-ui.panel>

    <x-ui.panel title="Запуски" subtitle="Создано и обновлено показывают сумму операций по страницам источника и медиа." icon="fa-solid fa-list-check" :pad="false">
        <div wire:loading.flex wire:target="refreshRuns,retryImport,cancelImport" class="min-h-24 items-center justify-center gap-2 px-4 py-8 text-sm font-semibold text-slate-500">
            <i class="fa-solid fa-spinner fa-spin text-emerald-700" aria-hidden="true"></i>
            <span>Обновляем состояние…</span>
        </div>

        <div wire:loading.remove wire:target="refreshRuns,retryImport,cancelImport">
            @forelse ($runs as $run)
                <article wire:key="seasonvar-import-run-{{ $run['id'] }}" class="border-b border-slate-200 p-4 last:border-b-0 sm:p-5">
                    <div class="flex flex-col gap-4 xl:flex-row xl:items-start xl:justify-between">
                        <div class="min-w-0">
                            <div class="flex flex-wrap items-center gap-2">
                                <span class="text-sm font-black text-slate-800">Запуск #{{ $run['id'] }}</span>
                                <span @class([
                                    'inline-flex items-center gap-2 rounded-full px-3 py-1 text-xs font-bold',
                                    'bg-emerald-50 text-emerald-700' => $run['tone'] === 'success',
                                    'bg-rose-50 text-rose-700' => $run['tone'] === 'danger',
                                    'bg-amber-50 text-amber-800' => $run['tone'] === 'warning',
                                    'bg-sky-50 text-sky-700' => $run['tone'] === 'sky',
                                    'bg-slate-100 text-slate-600' => $run['tone'] === 'muted',
                                ])>
                                    @if ($run['status'] === 'running')
                                        <i class="fa-solid fa-spinner fa-spin" aria-hidden="true"></i>
                                    @elseif ($run['status'] === 'completed')
                                        <i class="fa-solid fa-circle-check" aria-hidden="true"></i>
                                    @elseif ($run['status'] === 'failed')
                                        <i class="fa-solid fa-circle-exclamation" aria-hidden="true"></i>
                                    @else
                                        <i class="fa-solid fa-clock" aria-hidden="true"></i>
                                    @endif
                                    <span>{{ $run['status_label'] }}</span>
                                </span>
                                @if ($run['is_stale'])
                                    <span class="text-xs font-bold text-amber-800">Нет свежего heartbeat</span>
                                @endif
                            </div>

                            <div class="mt-2 flex flex-wrap gap-x-4 gap-y-1 text-xs font-semibold text-slate-500">
                                <span>Старт: {{ $run['started_at'] }}</span>
                                <span>Финиш: {{ $run['finished_at'] }}</span>
                                <span>Heartbeat: {{ $run['heartbeat_at'] }}</span>
                                @if ($run['requested_by'])
                                    <span>Запросил: {{ $run['requested_by'] }}</span>
                                @endif
                                @if ($run['retry_of_run_id'])
                                    <span>Повтор запуска #{{ $run['retry_of_run_id'] }}</span>
                                @endif
                            </div>
                        </div>

                        <div class="flex flex-wrap gap-2">
                            @if ($run['can_retry'])
                                <button
                                    type="button"
                                    wire:click="retryImport({{ $run['id'] }})"
                                    wire:loading.attr="disabled"
                                    wire:target="retryImport({{ $run['id'] }})"
                                    class="inline-flex min-h-11 items-center justify-center gap-2 rounded-control bg-sky-50 px-4 py-2 text-sm font-bold text-sky-700 hover:bg-sky-100 disabled:cursor-wait disabled:opacity-60"
                                >
                                    <i class="fa-solid fa-rotate-right" aria-hidden="true"></i>
                                    <span>Повторить</span>
                                </button>
                            @endif
                            @if ($run['can_cancel'])
                                <button
                                    type="button"
                                    wire:click="cancelImport({{ $run['id'] }})"
                                    wire:confirm="Отменить запуск #{{ $run['id'] }}? Новые страницы обрабатываться не будут."
                                    wire:loading.attr="disabled"
                                    wire:target="cancelImport({{ $run['id'] }})"
                                    class="inline-flex min-h-11 items-center justify-center gap-2 rounded-control bg-rose-50 px-4 py-2 text-sm font-bold text-rose-700 hover:bg-rose-100 disabled:cursor-wait disabled:opacity-60"
                                >
                                    <i class="fa-solid fa-ban" aria-hidden="true"></i>
                                    <span>Отменить</span>
                                </button>
                            @endif
                        </div>
                    </div>

                    <div class="mt-4 h-2 overflow-hidden rounded-full bg-slate-100" aria-label="Выполнено {{ $run['progress'] }}%">
                        <div class="h-full rounded-full bg-emerald-600" style="width: {{ $run['progress'] }}%"></div>
                    </div>

                    <div class="mt-4 grid gap-2 sm:grid-cols-2 xl:grid-cols-4">
                        @foreach ([
                            ['label' => 'Создано', 'value' => $run['created'], 'icon' => 'fa-solid fa-plus', 'tone' => 'text-emerald-700'],
                            ['label' => 'Обновлено', 'value' => $run['updated'], 'icon' => 'fa-solid fa-arrows-rotate', 'tone' => 'text-sky-700'],
                            ['label' => 'Пропущено', 'value' => $run['skipped'], 'icon' => 'fa-solid fa-forward', 'tone' => 'text-slate-600'],
                            ['label' => 'Ошибок', 'value' => $run['failed_total'], 'icon' => 'fa-solid fa-triangle-exclamation', 'tone' => 'text-rose-700'],
                        ] as $count)
                            <div wire:key="seasonvar-run-{{ $run['id'] }}-{{ $count['label'] }}" class="rounded-control bg-slate-50 p-3">
                                <div class="flex items-center gap-2 text-xs font-bold uppercase text-slate-400">
                                    <i class="{{ $count['icon'] }} {{ $count['tone'] }}" aria-hidden="true"></i>
                                    <span>{{ $count['label'] }}</span>
                                </div>
                                <div class="mt-1 text-xl font-black text-slate-800">{{ $count['value'] }}</div>
                            </div>
                        @endforeach
                    </div>

                    <div class="mt-3 grid gap-2 text-xs font-semibold text-slate-500 sm:grid-cols-2 xl:grid-cols-4">
                        <div>Страницы: {{ $run['parsed'] }} / {{ $run['selected'] }}</div>
                        <div>Новые страницы: {{ $run['stored'] }}</div>
                        <div>Медиа: +{{ $run['media_attached'] }} / ~{{ $run['media_updated'] }}</div>
                        <div>Медиа пропущено/ошибок: {{ $run['media_skipped'] }} / {{ $run['media_failed'] }}</div>
                    </div>

                    @if ($run['error'])
                        <div class="mt-3 rounded-control bg-rose-50 px-3 py-2 text-sm font-semibold text-rose-800">
                            {{ $run['error'] }}
                        </div>
                    @endif
                </article>
            @empty
                <div class="px-4 py-10 text-center text-sm text-slate-500">
                    Запуски импорта пока не создавались.
                </div>
            @endforelse
        </div>
    </x-ui.panel>
</div>
