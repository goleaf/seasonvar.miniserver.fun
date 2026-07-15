<section class="rounded-control border border-emerald-100 bg-emerald-50/60 p-4" aria-labelledby="title-collection-membership">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div class="min-w-0">
            <h2 id="title-collection-membership" class="flex items-center gap-2 text-sm font-black text-slate-800">
                <x-ui.icon name="fa-solid fa-layer-group text-emerald-700" />
                <span>{{ __('collections.membership.title') }}</span>
            </h2>
            <p class="mt-1 text-xs leading-5 text-slate-600">{{ $authenticated ? __('collections.membership.description') : __('collections.membership.login_hint') }}</p>
        </div>
        @if ($authenticated)
            <button type="button" data-collection-dialog-trigger wire:click="openSelector" wire:loading.attr="disabled" wire:target="openSelector" class="inline-flex min-h-11 w-full items-center justify-center gap-2 rounded-control bg-emerald-700 px-4 py-2.5 text-sm font-bold text-white hover:bg-emerald-600 disabled:cursor-wait disabled:opacity-60 sm:w-auto">
                <x-ui.icon name="fa-solid fa-folder-plus" />
                <span>{{ __('collections.actions.add') }}</span>
            </button>
        @else
            <a href="{{ route('login') }}" class="inline-flex min-h-11 w-full items-center justify-center gap-2 rounded-control bg-emerald-700 px-4 py-2.5 text-sm font-bold text-white hover:bg-emerald-600 sm:w-auto">
                <x-ui.icon name="fa-solid fa-right-to-bracket" />
                <span>{{ __('collections.actions.login') }}</span>
            </a>
        @endif
    </div>

    @if ($notice)
        <div class="mt-3" aria-live="polite"><x-form.status-message :message="$notice" /></div>
    @endif
    <x-form.input-error for="collection" />

    @if ($open && $authenticated)
        <dialog data-collection-dialog data-collection-dialog-open class="max-h-[calc(100dvh-2rem)] w-[min(46rem,calc(100%-1rem))] overflow-y-auto rounded-panel border-0 bg-white p-0 shadow-2xl backdrop:bg-slate-950/60 sm:w-[min(46rem,calc(100%-2rem))]" aria-labelledby="collection-membership-dialog-title">
            <div class="sticky top-0 z-10 flex items-start justify-between gap-4 border-b border-slate-200 bg-white p-4 sm:p-5">
                <div class="min-w-0">
                    <h2 id="collection-membership-dialog-title" class="text-xl font-black text-slate-800">{{ __('collections.membership.title') }}</h2>
                    <p class="mt-1 text-sm leading-6 text-slate-600">{{ __('collections.membership.description') }}</p>
                </div>
                <button type="button" data-collection-dialog-close wire:click="closeSelector" class="grid h-11 w-11 shrink-0 place-items-center rounded-control bg-slate-100 text-slate-700 hover:bg-slate-200" aria-label="{{ __('collections.accessibility.close_dialog') }}">
                    <x-ui.icon name="fa-solid fa-xmark" />
                </button>
            </div>

            <div class="space-y-5 p-4 sm:p-5">
                <form wire:submit="apply" class="space-y-4">
                    @if ($manageableCollections->isEmpty())
                        <div class="rounded-control border border-dashed border-slate-300 bg-slate-50 p-5 text-center text-sm font-semibold text-slate-600">{{ __('collections.membership.no_manageable') }}</div>
                    @else
                        <fieldset>
                            <legend class="sr-only">{{ __('collections.membership.title') }}</legend>
                            <div class="max-h-72 space-y-2 overflow-y-auto overscroll-contain pr-1">
                                @foreach ($manageableCollections as $manageable)
                                    <label wire:key="membership-option-{{ $manageable->public_id }}" class="flex min-h-14 cursor-pointer items-center gap-3 rounded-control border border-slate-200 p-3 has-[:checked]:border-emerald-600 has-[:checked]:bg-emerald-50">
                                        <input type="checkbox" wire:model="selectedCollectionPublicIds" value="{{ $manageable->public_id }}" class="h-5 w-5 shrink-0 rounded border-slate-300 text-emerald-700 focus:ring-emerald-600">
                                        <span class="min-w-0 flex-1">
                                            <span class="block break-words text-sm font-black text-slate-800">{{ $manageable->display_name }}</span>
                                            <span class="mt-1 block text-xs font-semibold text-slate-500">{{ $manageable->visibility->label() }}</span>
                                        </span>
                                    </label>
                                @endforeach
                            </div>
                        </fieldset>
                    @endif
                    <div class="flex flex-wrap items-center justify-between gap-3 border-t border-slate-200 pt-4">
                        <span class="text-sm font-bold text-slate-500" aria-live="polite">{{ __('collections.membership.selected', ['count' => count($selectedCollectionPublicIds)]) }}</span>
                        <div class="flex w-full gap-2 sm:w-auto">
                            <button type="button" data-collection-dialog-close wire:click="closeSelector" class="inline-flex min-h-11 flex-1 items-center justify-center rounded-control bg-slate-100 px-4 py-2.5 text-sm font-bold text-slate-700 hover:bg-slate-200 sm:flex-none">{{ __('collections.actions.cancel') }}</button>
                            <button type="submit" wire:loading.attr="disabled" wire:target="apply" class="inline-flex min-h-11 flex-1 items-center justify-center gap-2 rounded-control bg-emerald-700 px-4 py-2.5 text-sm font-bold text-white hover:bg-emerald-600 disabled:cursor-wait disabled:opacity-60 sm:flex-none"><x-ui.icon name="fa-solid fa-check" />{{ __('collections.actions.apply') }}</button>
                        </div>
                    </div>
                </form>

                <details class="rounded-control border border-slate-200 bg-slate-50 p-4">
                    <summary class="min-h-11 cursor-pointer py-2 text-sm font-black text-slate-800">{{ __('collections.actions.create_and_add') }}</summary>
                    <form wire:submit="createAndAdd" class="mt-4 space-y-4" novalidate>
                        <x-form.field :label="__('collections.form.name')" for="collection-membership-new-name" :placeholder="__('collections.form.name_placeholder')" wire:model="newName" required />
                        <div>
                            <label for="collection-membership-new-description" class="block text-sm font-bold text-slate-700">{{ __('collections.form.description') }}</label>
                            <textarea id="collection-membership-new-description" wire:model="newDescription" rows="4" maxlength="10000" class="mt-2 w-full rounded-control border border-slate-300 bg-white px-3 py-2.5 text-base text-slate-800 focus:border-emerald-600 focus:ring-2 focus:ring-emerald-100"></textarea>
                            <x-form.input-error for="newDescription" />
                        </div>
                        <div>
                            <label for="collection-membership-new-visibility" class="block text-sm font-bold text-slate-700">{{ __('collections.form.visibility') }}</label>
                            <select id="collection-membership-new-visibility" wire:model="newVisibility" class="mt-2 min-h-11 w-full rounded-control border border-slate-300 bg-white px-3 py-2.5 text-sm font-semibold text-slate-700 focus:border-emerald-600 focus:ring-2 focus:ring-emerald-100">
                                @foreach ($visibilityOptions as $option)
                                    <option value="{{ $option->value }}">{{ $option->label() }}</option>
                                @endforeach
                            </select>
                            <x-form.input-error for="newVisibility" />
                        </div>
                        <button type="submit" wire:loading.attr="disabled" wire:target="createAndAdd" class="inline-flex min-h-11 w-full items-center justify-center gap-2 rounded-control bg-slate-800 px-4 py-2.5 text-sm font-bold text-white hover:bg-slate-700 disabled:cursor-wait disabled:opacity-60"><x-ui.icon name="fa-solid fa-folder-plus" />{{ __('collections.actions.create_and_add') }}</button>
                    </form>
                </details>
            </div>
        </dialog>
    @endif
</section>
