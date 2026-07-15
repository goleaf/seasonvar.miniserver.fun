<div class="mx-auto max-w-7xl space-y-5">
    <header class="rounded-panel border border-slate-200 bg-white p-5 shadow-panel sm:p-6">
        <h1 class="text-2xl font-black tracking-tight text-slate-800 sm:text-3xl">{{ __('collections.admin.title') }}</h1>
        <p class="mt-2 max-w-3xl text-sm leading-6 text-slate-600">{{ __('collections.admin.description') }}</p>
    </header>

    @if ($notice)
        <x-form.status-message :message="$notice" />
    @endif

    <x-ui.panel :title="__('collections.directory.search_label')" icon="fa-solid fa-magnifying-glass">
        <x-form.field :label="__('collections.directory.search_label')" for="collection-admin-search" :placeholder="__('collections.directory.search_placeholder')" wire:model.live.debounce.400ms="search" />
    </x-ui.panel>

    @if ($collections->isEmpty())
        <div class="rounded-panel border border-dashed border-slate-300 bg-white p-8 text-center text-sm font-semibold text-slate-600">{{ __('collections.admin.empty') }}</div>
    @else
        <div class="space-y-4">
            @foreach ($collections as $collection)
                <article wire:key="collection-moderation-{{ $collection->public_id }}" class="rounded-panel border border-slate-200 bg-white p-4 shadow-panel sm:p-5">
                    <div class="grid min-w-0 gap-4 lg:grid-cols-[minmax(0,1fr)_auto] lg:items-start">
                        <div class="min-w-0">
                            <div class="flex flex-wrap gap-2">
                                <x-ui.status-pill variant="muted">{{ $collection->type->label() }}</x-ui.status-pill>
                                <x-ui.status-pill variant="muted">{{ $collection->visibility->label() }}</x-ui.status-pill>
                                <x-ui.status-pill variant="warning">{{ $collection->moderation_status->label() }}</x-ui.status-pill>
                                @if ($collection->trashed())
                                    <x-ui.status-pill variant="muted">{{ __('collections.admin.deleted') }}</x-ui.status-pill>
                                @endif
                            </div>
                            <h2 class="mt-3 break-words text-lg font-black text-slate-800">{{ $collection->display_name }}</h2>
                            @if ($collection->display_description)
                                <p class="mt-2 whitespace-pre-line break-words text-sm leading-6 text-slate-600">{{ $collection->display_description }}</p>
                            @endif
                            <div class="mt-3 flex flex-wrap gap-x-5 gap-y-2 text-xs font-semibold text-slate-500">
                                <span>{{ __('collections.page.owner') }}: {{ $collection->owner?->name ?: __('collections.admin.system_owner') }}</span>
                                <span>{{ trans_choice('collections.page.items', $collection->total_items_count, ['count' => $collection->total_items_count]) }}</span>
                                <span>{{ __('collections.admin.open_reports', ['count' => $collection->open_reports_count]) }}</span>
                            </div>
                        </div>
                        @unless ($collection->trashed())
                            @unless ($collection->trashed())
                                <a href="{{ route('collections.show', ['collectionSlug' => $collection->slug]) }}" class="inline-flex min-h-11 items-center justify-center gap-2 rounded-control bg-slate-100 px-3 py-2.5 text-sm font-bold text-slate-700 hover:bg-slate-200"><x-ui.icon name="fa-solid fa-eye" />{{ __('collections.admin.review') }}</a>
                            @endunless
                        @endunless
                    </div>

                    <div class="mt-4 flex flex-wrap gap-2 border-t border-slate-200 pt-4">
                        @unless ($collection->trashed())
                            <button type="button" wire:click="moderate('{{ $collection->public_id }}', 'approved')" wire:loading.attr="disabled" class="inline-flex min-h-11 flex-1 items-center justify-center gap-2 rounded-control bg-emerald-50 px-3 text-sm font-bold text-emerald-700 hover:bg-emerald-100 sm:flex-none"><x-ui.icon name="fa-solid fa-check" />{{ __('collections.admin.approve') }}</button>
                            <button type="button" wire:click="moderate('{{ $collection->public_id }}', 'rejected')" wire:loading.attr="disabled" class="inline-flex min-h-11 flex-1 items-center justify-center gap-2 rounded-control bg-rose-50 px-3 text-sm font-bold text-rose-700 hover:bg-rose-100 sm:flex-none"><x-ui.icon name="fa-solid fa-ban" />{{ __('collections.admin.reject') }}</button>
                            <button type="button" wire:click="moderate('{{ $collection->public_id }}', 'hidden')" wire:loading.attr="disabled" class="inline-flex min-h-11 flex-1 items-center justify-center gap-2 rounded-control bg-amber-50 px-3 text-sm font-bold text-amber-800 hover:bg-amber-100 sm:flex-none"><x-ui.icon name="fa-solid fa-eye-slash" />{{ __('collections.admin.hide') }}</button>
                            <button type="button" wire:click="moderate('{{ $collection->public_id }}', 'archived')" wire:loading.attr="disabled" class="inline-flex min-h-11 flex-1 items-center justify-center gap-2 rounded-control bg-slate-100 px-3 text-sm font-bold text-slate-700 hover:bg-slate-200 sm:flex-none"><x-ui.icon name="fa-solid fa-box-archive" />{{ __('collections.admin.archive') }}</button>
                            @if ($collection->type->value === 'editorial' && $collection->visibility->value === 'public' && $collection->moderation_status->value === 'approved')
                                <button type="button" wire:click="feature('{{ $collection->public_id }}', {{ $collection->is_featured ? 'false' : 'true' }})" wire:loading.attr="disabled" class="inline-flex min-h-11 flex-1 items-center justify-center gap-2 rounded-control bg-amber-50 px-3 text-sm font-bold text-amber-800 hover:bg-amber-100 sm:flex-none"><x-ui.icon name="fa-solid fa-star" />{{ $collection->is_featured ? __('collections.admin.unfeature') : __('collections.admin.feature') }}</button>
                            @endif
                        @endunless
                        @if ($collection->open_reports_count > 0)
                            <button type="button" wire:click="resolveReports('{{ $collection->public_id }}')" wire:confirm="{{ __('collections.admin.resolve_confirmation') }}" wire:loading.attr="disabled" class="inline-flex min-h-11 flex-1 items-center justify-center gap-2 rounded-control bg-sky-50 px-3 text-sm font-bold text-sky-700 hover:bg-sky-100 sm:flex-none"><x-ui.icon name="fa-solid fa-flag-checkered" />{{ __('collections.admin.resolve_reports') }}</button>
                        @endif
                    </div>
                </article>
            @endforeach
        </div>
        <nav aria-label="{{ __('collections.page.pagination') }}">{{ $collections->links() }}</nav>
    @endif
</div>
