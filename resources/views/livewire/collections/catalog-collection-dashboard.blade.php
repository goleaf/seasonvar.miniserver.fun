<div class="space-y-5">
    <header class="rounded-panel border border-slate-200 bg-white p-5 shadow-panel sm:p-6">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div class="min-w-0">
                <h1 class="break-words text-2xl font-black tracking-tight text-slate-800 sm:text-3xl">{{ __('collections.dashboard.title') }}</h1>
                <p class="mt-2 max-w-3xl text-sm leading-6 text-slate-600">{{ __('collections.dashboard.description') }}</p>
            </div>
            @if ($canCreate)
                <button type="button" wire:click="$toggle('showCreate')" aria-expanded="{{ $showCreate ? 'true' : 'false' }}" class="inline-flex min-h-11 w-full items-center justify-center gap-2 rounded-control bg-emerald-700 px-4 py-2.5 text-sm font-bold text-white hover:bg-emerald-600 sm:w-auto">
                    <x-ui.icon name="fa-solid fa-plus" />
                    <span>{{ __('collections.actions.create') }}</span>
                </button>
            @else
                <a href="{{ route('verification.notice') }}" class="inline-flex min-h-11 w-full items-center justify-center gap-2 rounded-control bg-amber-50 px-4 py-2.5 text-sm font-bold text-amber-800 hover:bg-amber-100 sm:w-auto">
                    <x-ui.icon name="fa-solid fa-envelope-circle-check" />
                    <span>{{ __('collections.dashboard.verify_email') }}</span>
                </a>
            @endif
        </div>
    </header>

    @if ($status)
        <x-form.status-message :message="$status" />
    @endif
    <x-form.input-error for="collection" />

    @if ($showCreate && $canCreate)
        <x-ui.panel :title="__('collections.actions.create')" icon="fa-solid fa-folder-plus">
            <form wire:submit="create" class="grid gap-5" novalidate>
                <x-form.field :label="__('collections.form.name')" for="new-collection-name" :placeholder="__('collections.form.name_placeholder')" wire:model="name" required />
                <div>
                    <label for="new-collection-description" class="block text-sm font-bold text-slate-700">{{ __('collections.form.description') }}</label>
                    <textarea id="new-collection-description" wire:model="description" rows="5" maxlength="10000" placeholder="{{ __('collections.form.description_placeholder') }}" class="mt-2 w-full rounded-control border border-slate-300 bg-white px-3 py-2.5 text-base text-slate-800 outline-none placeholder:text-slate-400 focus:border-emerald-600 focus:ring-2 focus:ring-emerald-100"></textarea>
                    <x-form.input-error for="description" id="new-collection-description-error" />
                </div>
                <fieldset>
                    <legend class="text-sm font-bold text-slate-700">{{ __('collections.form.visibility') }}</legend>
                    <div class="mt-2 grid gap-2 sm:grid-cols-3">
                        @foreach ($visibilityOptions as $option)
                            <label class="flex min-h-24 cursor-pointer gap-3 rounded-control border border-slate-200 p-3 has-[:checked]:border-emerald-600 has-[:checked]:bg-emerald-50">
                                <input type="radio" wire:model="visibility" value="{{ $option['value'] }}" class="mt-1 h-5 w-5 border-slate-300 text-emerald-700 focus:ring-emerald-600">
                                <span class="min-w-0">
                                    <span class="block font-bold text-slate-800">{{ $option['label'] }}</span>
                                    <span class="mt-1 block text-xs leading-5 text-slate-500">{{ $option['hint'] }}</span>
                                </span>
                            </label>
                        @endforeach
                    </div>
                    <x-form.input-error for="visibility" />
                </fieldset>
                @if ($showTypeSelector)
                    <div>
                        <label for="new-collection-type" class="block text-sm font-bold text-slate-700">{{ __('collections.form.type') }}</label>
                        <select id="new-collection-type" wire:model="type" class="mt-2 min-h-11 w-full rounded-control border border-slate-300 bg-white px-3 py-2.5 text-sm font-semibold text-slate-700 focus:border-emerald-600 focus:ring-2 focus:ring-emerald-100">
                            @foreach ($typeOptions as $option)
                                <option value="{{ $option['value'] }}">{{ $option['label'] }}</option>
                            @endforeach
                        </select>
                    </div>
                @endif
                <div class="flex flex-wrap gap-2">
                    <button type="submit" wire:loading.attr="disabled" wire:target="create" class="inline-flex min-h-11 flex-1 items-center justify-center gap-2 rounded-control bg-emerald-700 px-4 py-2.5 text-sm font-bold text-white hover:bg-emerald-600 disabled:cursor-wait disabled:opacity-60 sm:flex-none">
                        <x-ui.icon name="fa-solid fa-folder-plus" />
                        <span wire:loading.remove wire:target="create">{{ __('collections.actions.create') }}</span>
                        <span wire:loading wire:target="create">{{ __('collections.page.loading') }}</span>
                    </button>
                    <button type="button" wire:click="$set('showCreate', false)" class="inline-flex min-h-11 flex-1 items-center justify-center rounded-control bg-slate-100 px-4 py-2.5 text-sm font-bold text-slate-700 hover:bg-slate-200 sm:flex-none">{{ __('collections.actions.cancel') }}</button>
                </div>
            </form>
        </x-ui.panel>
    @endif

    <section aria-labelledby="active-collections-title">
        <div class="mb-3 flex items-center justify-between gap-3">
            <h2 id="active-collections-title" class="text-lg font-black text-slate-800">{{ __('collections.dashboard.active') }}</h2>
            <a href="{{ route('discover.index', ['type' => 'popular']).'#collections' }}" class="text-sm font-bold text-emerald-700 hover:text-emerald-600">{{ __('collections.navigation.public_collections') }}</a>
        </div>
        @if ($collections->isEmpty())
            <div class="rounded-panel border border-dashed border-slate-300 bg-white p-8 text-center text-sm font-semibold text-slate-600">{{ __('collections.dashboard.empty') }}</div>
        @else
            <div class="grid min-w-0 gap-4 sm:grid-cols-2 xl:grid-cols-3">
                @foreach ($collections as $collection)
                    <div wire:key="owned-collection-{{ $collection->public_id }}" class="min-w-0">
                        <x-collections.collection-card :collection="$collection" management />
                        <button type="button" wire:click="delete('{{ $collection->public_id }}')" wire:confirm="{{ __('collections.confirmations.delete') }}" wire:loading.attr="disabled" wire:target="delete('{{ $collection->public_id }}')" class="mt-2 inline-flex min-h-11 w-full items-center justify-center gap-2 rounded-control bg-rose-50 px-4 py-2.5 text-sm font-bold text-rose-700 hover:bg-rose-100 disabled:cursor-wait disabled:opacity-60">
                            <x-ui.icon name="fa-solid fa-trash-can" wire:loading.remove wire:target="delete('{{ $collection->public_id }}')" />
                            <x-ui.icon name="fa-solid fa-spinner fa-spin" wire:loading wire:target="delete('{{ $collection->public_id }}')" />
                            <span wire:loading.remove wire:target="delete('{{ $collection->public_id }}')">{{ __('collections.actions.delete') }}</span>
                            <span wire:loading wire:target="delete('{{ $collection->public_id }}')">{{ __('collections.actions.deleting') }}</span>
                        </button>
                    </div>
                @endforeach
            </div>
            <nav class="mt-4" aria-label="{{ __('collections.page.pagination') }}">{{ $collections->links() }}</nav>
        @endif
    </section>

    <x-ui.panel :title="__('collections.dashboard.deleted')" :subtitle="__('collections.dashboard.deleted_hint', ['days' => $restorationDays])" icon="fa-solid fa-clock-rotate-left">
        @if ($deletedCollections->isEmpty())
            <p class="text-sm font-semibold text-slate-500">{{ __('collections.dashboard.empty_deleted') }}</p>
        @else
            <div class="divide-y divide-slate-200">
                @foreach ($deletedCollections as $collection)
                    <article wire:key="deleted-collection-{{ $collection->public_id }}" class="grid gap-3 py-4 first:pt-0 last:pb-0 sm:grid-cols-[minmax(0,1fr)_auto] sm:items-center">
                        <div class="min-w-0">
                            <h3 class="break-words font-black text-slate-800">{{ $collection->display_name }}</h3>
                            <p class="mt-1 text-xs font-semibold text-slate-500">{{ $collection->deleted_at_label }}</p>
                        </div>
                        <div class="flex flex-wrap gap-2">
                            @if ($collection->is_restorable)
                                <button type="button" wire:click="restore('{{ $collection->public_id }}')" wire:loading.attr="disabled" wire:target="restore('{{ $collection->public_id }}')" class="inline-flex min-h-11 flex-1 items-center justify-center gap-2 rounded-control bg-emerald-50 px-3 py-2 text-sm font-bold text-emerald-700 hover:bg-emerald-100 sm:flex-none">
                                    <x-ui.icon name="fa-solid fa-rotate-left" wire:loading.remove wire:target="restore('{{ $collection->public_id }}')" />
                                    <x-ui.icon name="fa-solid fa-spinner fa-spin" wire:loading wire:target="restore('{{ $collection->public_id }}')" />
                                    <span wire:loading.remove wire:target="restore('{{ $collection->public_id }}')">{{ __('collections.actions.restore') }}</span>
                                    <span wire:loading wire:target="restore('{{ $collection->public_id }}')">{{ __('collections.actions.restoring') }}</span>
                                </button>
                            @else
                                <span class="inline-flex min-h-11 items-center rounded-control bg-slate-100 px-3 text-xs font-bold text-slate-500">{{ __('collections.dashboard.restore_expired') }}</span>
                            @endif
                            <button type="button" wire:click="forceDelete('{{ $collection->public_id }}')" wire:confirm="{{ __('collections.confirmations.delete_forever') }}" wire:loading.attr="disabled" wire:target="forceDelete('{{ $collection->public_id }}')" class="inline-flex min-h-11 flex-1 items-center justify-center gap-2 rounded-control bg-rose-50 px-3 py-2 text-sm font-bold text-rose-700 hover:bg-rose-100 sm:flex-none">
                                <x-ui.icon name="fa-solid fa-trash" wire:loading.remove wire:target="forceDelete('{{ $collection->public_id }}')" />
                                <x-ui.icon name="fa-solid fa-spinner fa-spin" wire:loading wire:target="forceDelete('{{ $collection->public_id }}')" />
                                <span wire:loading.remove wire:target="forceDelete('{{ $collection->public_id }}')">{{ __('collections.actions.delete_forever') }}</span>
                                <span wire:loading wire:target="forceDelete('{{ $collection->public_id }}')">{{ __('collections.actions.deleting') }}</span>
                            </button>
                        </div>
                    </article>
                @endforeach
            </div>
            <nav class="mt-4" aria-label="{{ __('collections.page.pagination') }}">{{ $deletedCollections->links() }}</nav>
        @endif
    </x-ui.panel>
</div>
