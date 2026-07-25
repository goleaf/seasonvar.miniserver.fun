<div class="mx-auto max-w-7xl space-y-5" data-livewire-catalog-collection-administration-manager>
    <livewire:collections.catalog-collection-category-manager :key="'admin-collection-categories'" />

    @if ($sourceSyncSummary !== null)
        <section data-collection-source-sync-summary class="rounded-panel border border-slate-200 bg-white p-5 shadow-panel sm:p-6" aria-labelledby="collection-source-sync-title">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div class="min-w-0">
                    <h2 id="collection-source-sync-title" class="text-lg font-black text-slate-800">{{ __('collections.sync.title') }}</h2>
                    <p class="mt-1 text-sm leading-6 text-slate-600">{{ __('collections.sync.description') }}</p>
                </div>
                <div class="flex flex-wrap items-center gap-2">
                    <x-ui.status-pill :variant="$sourceSyncSummary['status_variant']">{{ $sourceSyncSummary['status_label'] }}</x-ui.status-pill>
                    <time datetime="{{ $sourceSyncSummary['completed_at_iso'] }}" class="text-xs font-semibold text-slate-500">
                        {{ __('collections.sync.completed_at', ['date' => $sourceSyncSummary['completed_at_label']]) }}
                    </time>
                </div>
            </div>
            <dl class="mt-4 grid gap-3 sm:grid-cols-2 xl:grid-cols-6">
                @foreach ($sourceSyncSummary['metrics'] as $metric)
                    <div class="rounded-control bg-slate-50 px-3 py-3">
                        <dt class="text-xs font-bold text-slate-500">{{ $metric['label'] }}</dt>
                        <dd class="mt-1 text-lg font-black text-slate-800">{{ $metric['value'] }}</dd>
                    </div>
                @endforeach
            </dl>
            <div class="mt-5 space-y-4 border-t border-slate-200 pt-4">
                <div>
                    <h3 class="text-sm font-black text-slate-700">{{ __('collections.sync.health_title') }}</h3>
                    <dl class="mt-3 grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                        @foreach ($sourceSyncSummary['health_metrics'] as $metric)
                            <div class="rounded-control bg-slate-50 px-3 py-3">
                                <dt class="text-xs font-bold text-slate-500">{{ $metric['label'] }}</dt>
                                <dd class="mt-1 text-lg font-black text-slate-800">{{ $metric['value'] }}</dd>
                            </div>
                        @endforeach
                    </dl>
                </div>
                <div>
                    <h3 class="text-sm font-black text-slate-700">{{ __('collections.sync.scope_title') }}</h3>
                    <dl class="mt-3 grid gap-3 sm:grid-cols-3">
                        @foreach ($sourceSyncSummary['scope_metrics'] as $metric)
                            <div class="rounded-control bg-slate-50 px-3 py-3">
                                <dt class="text-xs font-bold text-slate-500">{{ $metric['label'] }}</dt>
                                <dd class="mt-1 text-lg font-black text-slate-800">{{ $metric['value'] }}</dd>
                            </div>
                        @endforeach
                    </dl>
                </div>
                @if ($sourceSyncSummary['match_metrics'] !== [])
                    <div>
                        <h3 class="text-sm font-black text-slate-700">{{ __('collections.sync.breakdown_title') }}</h3>
                        <dl class="mt-3 grid gap-3 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-5">
                            @foreach ($sourceSyncSummary['match_metrics'] as $metric)
                                <div class="rounded-control bg-slate-50 px-3 py-3">
                                    <dt class="text-xs font-bold text-slate-500">{{ $metric['label'] }}</dt>
                                    <dd class="mt-1 text-lg font-black text-slate-800">{{ $metric['value'] }}</dd>
                                </div>
                            @endforeach
                        </dl>
                    </div>
                @endif
            </div>
        </section>
    @endif

    @if ($notice)
        <x-form.status-message :message="$notice" />
    @endif

    <div wire:loading.delay wire:target="moderate,feature,resolveReports" role="status" aria-live="polite">
        <div class="flex items-center gap-2 rounded-control bg-sky-50 px-4 py-3 text-sm font-bold text-sky-700">
            <x-ui.icon name="fa-solid fa-spinner fa-spin" />{{ __('collections.page.loading') }}
        </div>
    </div>

    <x-ui.panel :title="__('collections.directory.search_label')" icon="fa-solid fa-magnifying-glass">
        <x-form.field :label="__('collections.directory.search_label')" for="collection-admin-search" :placeholder="__('collections.directory.search_placeholder')" wire:model.live.debounce.400ms="search" />
    </x-ui.panel>

    @island(name: 'collection-administration-pagination', always: true, with: $this->paginationIslandPage)
    <x-ui.pagination-region name="collection-administration-results">
    @if ($collections->isEmpty())
        <div class="rounded-panel border border-dashed border-slate-300 bg-white p-8 text-center text-sm font-semibold text-slate-600">{{ __('collections.admin.empty') }}</div>
    @else
        <div class="space-y-4">
            @foreach ($collections as $collection)
                <article wire:key="collection-moderation-{{ $collection->public_id }}" class="rounded-panel border border-slate-200 bg-white p-4 shadow-panel sm:p-5">
                    <div class="grid min-w-0 gap-4 lg:grid-cols-[minmax(0,1fr)_auto] lg:items-start">
                        <div class="min-w-0">
                            <div class="flex flex-wrap gap-2">
                                <x-ui.status-pill variant="muted">{{ $collection->presentation_type_label }}</x-ui.status-pill>
                                <x-ui.status-pill variant="muted">{{ $collection->presentation_visibility_label }}</x-ui.status-pill>
                                <x-ui.status-pill variant="warning">{{ $collection->presentation_moderation_label }}</x-ui.status-pill>
                                @if ($collection->presentation_deleted)
                                    <x-ui.status-pill variant="muted">{{ __('collections.admin.deleted') }}</x-ui.status-pill>
                                @endif
                            </div>
                            <h2 class="mt-3 break-words text-lg font-black text-slate-800">{{ $collection->display_name }}</h2>
                            @if ($collection->display_description)
                                <p class="mt-2 whitespace-pre-line break-words text-sm leading-6 text-slate-600">{{ $collection->display_description }}</p>
                            @endif
                            <div class="mt-3 flex flex-wrap gap-x-5 gap-y-2 text-xs font-semibold text-slate-500">
                                <span>{{ __('collections.page.owner') }}: {{ $collection->presentation_owner_label }}</span>
                                <span>{{ $collection->presentation_items_label }}</span>
                                <span>{{ $collection->presentation_open_reports_label }}</span>
                            </div>
                        </div>
                        @unless ($collection->presentation_deleted)
                            <a href="{{ route('collections.show', ['collectionSlug' => $collection->slug]) }}" class="inline-flex min-h-11 items-center justify-center gap-2 rounded-control bg-slate-100 px-3 py-2.5 text-sm font-bold text-slate-700 hover:bg-slate-200"><x-ui.icon name="fa-solid fa-eye" />{{ __('collections.admin.review') }}</a>
                        @endunless
                    </div>

                    <div
                        data-collection-readiness="{{ $collection->public_id }}"
                        data-collection-readiness-state="{{ $collection->presentation_readiness_state }}"
                        class="mt-4 flex min-w-0 items-start gap-3 border-t border-slate-200 pt-4"
                    >
                        <span @class([
                            'inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-control',
                            'bg-emerald-50 text-emerald-700' => $collection->presentation_readiness_state === 'ready',
                            'bg-amber-50 text-amber-800' => $collection->presentation_readiness_state !== 'ready',
                        ])>
                            <x-ui.icon
                                :name="$collection->presentation_readiness_state === 'ready' ? 'fa-solid fa-circle-check' : 'fa-solid fa-triangle-exclamation'"
                            />
                        </span>
                        <div class="min-w-0">
                            <p @class([
                                'text-sm font-black',
                                'text-emerald-800' => $collection->presentation_readiness_state === 'ready',
                                'text-amber-900' => $collection->presentation_readiness_state !== 'ready',
                            ])>{{ $collection->presentation_readiness_label }}</p>
                            <p class="mt-1 text-xs font-semibold leading-5 text-slate-600">{{ $collection->presentation_readiness_count_label }}</p>
                            @if ($collection->presentation_readiness_reasons !== [])
                                <p class="mt-2 text-xs font-bold text-slate-700">{{ __('collections.admin.readiness_reasons_title') }}</p>
                                <ul class="mt-1 space-y-1 text-xs leading-5 text-slate-600">
                                    @foreach ($collection->presentation_readiness_reasons as $reason)
                                        <li class="flex min-w-0 items-start gap-2">
                                            <x-ui.icon name="fa-solid fa-circle-xmark" align="start" class="shrink-0 text-amber-700" />
                                            <span class="min-w-0 break-words">{{ $reason }}</span>
                                        </li>
                                    @endforeach
                                </ul>
                            @endif
                        </div>
                    </div>

                    @if ($canModerateCollections)
                    <div class="mt-4 flex flex-wrap gap-2 border-t border-slate-200 pt-4">
                        @unless ($collection->presentation_deleted)
                            <button type="button" wire:click="moderate('{{ $collection->public_id }}', 'approved')" wire:loading.attr="disabled" class="inline-flex min-h-11 flex-1 items-center justify-center gap-2 rounded-control bg-emerald-50 px-3 text-sm font-bold text-emerald-700 hover:bg-emerald-100 sm:flex-none"><x-ui.icon name="fa-solid fa-check" />{{ __('collections.admin.approve') }}</button>
                            <button type="button" wire:click="moderate('{{ $collection->public_id }}', 'rejected')" wire:loading.attr="disabled" class="inline-flex min-h-11 flex-1 items-center justify-center gap-2 rounded-control bg-rose-50 px-3 text-sm font-bold text-rose-700 hover:bg-rose-100 sm:flex-none"><x-ui.icon name="fa-solid fa-ban" />{{ __('collections.admin.reject') }}</button>
                            <button type="button" wire:click="moderate('{{ $collection->public_id }}', 'hidden')" wire:loading.attr="disabled" class="inline-flex min-h-11 flex-1 items-center justify-center gap-2 rounded-control bg-amber-50 px-3 text-sm font-bold text-amber-800 hover:bg-amber-100 sm:flex-none"><x-ui.icon name="fa-solid fa-eye-slash" />{{ __('collections.admin.hide') }}</button>
                            <button type="button" wire:click="moderate('{{ $collection->public_id }}', 'archived')" wire:loading.attr="disabled" class="inline-flex min-h-11 flex-1 items-center justify-center gap-2 rounded-control bg-slate-100 px-3 text-sm font-bold text-slate-700 hover:bg-slate-200 sm:flex-none"><x-ui.icon name="fa-solid fa-box-archive" />{{ __('collections.admin.archive') }}</button>
                            @if ($collection->presentation_can_feature)
                                <button type="button" data-collection-feature-action="{{ $collection->public_id }}" wire:click="feature('{{ $collection->public_id }}', {{ $collection->presentation_feature_next }})" wire:loading.attr="disabled" class="inline-flex min-h-11 flex-1 items-center justify-center gap-2 rounded-control bg-amber-50 px-3 text-sm font-bold text-amber-800 hover:bg-amber-100 sm:flex-none"><x-ui.icon name="fa-solid fa-star" />{{ $collection->presentation_feature_label }}</button>
                            @endif
                        @endunless
                        @if ($collection->presentation_has_open_reports)
                            <button type="button" wire:click="resolveReports('{{ $collection->public_id }}')" wire:confirm="{{ __('collections.admin.resolve_confirmation') }}" wire:loading.attr="disabled" class="inline-flex min-h-11 flex-1 items-center justify-center gap-2 rounded-control bg-sky-50 px-3 text-sm font-bold text-sky-700 hover:bg-sky-100 sm:flex-none"><x-ui.icon name="fa-solid fa-flag-checkered" />{{ __('collections.admin.resolve_reports') }}</button>
                        @endif
                    </div>
                    @endif
                </article>
            @endforeach
        </div>
        <nav aria-label="{{ __('collections.page.pagination') }}">{{ $collections->links(data: ['region' => 'collection-administration-results']) }}</nav>
    @endif
    </x-ui.pagination-region>
    @endisland
</div>
