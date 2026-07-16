<div class="space-y-5">
    <header class="rounded-panel border border-slate-200 bg-white p-5 shadow-panel sm:p-6">
        <h1 class="text-2xl font-black text-slate-800 sm:text-3xl">{{ __('requests.admin.title') }}</h1>
        <p class="mt-2 text-sm text-slate-600">{{ __('requests.admin.description') }}</p>
    </header>

    @if ($statusMessage)
        <p role="status" aria-live="polite" class="rounded-control bg-emerald-50 p-4 text-sm font-bold text-emerald-800">{{ $statusMessage }}</p>
    @endif

    @if ($actionError)
        <p role="alert" class="rounded-control bg-rose-50 p-4 text-sm font-bold text-rose-800">{{ $actionError }}</p>
    @endif

    <x-ui.panel :title="__('requests.admin.filters')" icon="fa-solid fa-filter">
        <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
            <x-form.field :label="__('requests.fields.search')" for="admin-request-search" wire:model.live.debounce.300ms="search" />
            <div>
                <label for="admin-request-type" class="block text-sm font-bold text-slate-700">{{ __('requests.fields.type') }}</label>
                <select id="admin-request-type" wire:model.live="type" class="mt-2 min-h-11 w-full rounded-control border border-slate-300 px-3">
                    <option value="">{{ __('requests.filters.all_types') }}</option>
                    @foreach ($typeOptions as $option)
                        <option value="{{ $option['value'] }}">{{ $option['label'] }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label for="admin-request-status" class="block text-sm font-bold text-slate-700">{{ __('requests.fields.status') }}</label>
                <select id="admin-request-status" wire:model.live="status" class="mt-2 min-h-11 w-full rounded-control border border-slate-300 px-3">
                    <option value="">{{ __('requests.filters.all_statuses') }}</option>
                    @foreach ($statusOptions as $option)
                        <option value="{{ $option['value'] }}">{{ $option['label'] }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label for="admin-request-sort" class="block text-sm font-bold text-slate-700">{{ __('requests.fields.sort') }}</label>
                <select id="admin-request-sort" wire:model.live="sort" class="mt-2 min-h-11 w-full rounded-control border border-slate-300 px-3">
                    @foreach ($sortOptions as $option)
                        <option value="{{ $option['value'] }}">{{ $option['label'] }}</option>
                    @endforeach
                </select>
            </div>
        </div>
    </x-ui.panel>

    <div wire:loading.delay role="status" aria-live="polite" class="rounded-control bg-sky-50 p-3 text-sm font-bold text-sky-700">
        {{ __('requests.states.loading') }}
    </div>

    @if (! $schemaReady)
        <x-ui.panel><p>{{ __('requests.states.unavailable') }}</p></x-ui.panel>
    @elseif ($requests->isEmpty())
        <x-ui.panel><p class="py-6 text-center text-sm text-slate-600">{{ __('requests.admin.empty') }}</p></x-ui.panel>
    @else
        <section class="space-y-4" aria-label="{{ __('requests.admin.title') }}">
            @foreach ($requests as $request)
                <div wire:key="admin-request-{{ $request->publicId }}" class="rounded-panel border border-slate-200 bg-white p-4 shadow-panel sm:p-5">
                    <x-content-requests.card :request="$request" />

                    <div class="mt-4 grid gap-4 border-t border-slate-100 pt-4 lg:grid-cols-2">
                        <div class="space-y-3">
                            <div class="grid gap-3 sm:grid-cols-2">
                                <div>
                                    <label for="admin-status-{{ $request->id }}" class="block text-sm font-bold text-slate-700">{{ __('requests.fields.status') }}</label>
                                    <select id="admin-status-{{ $request->id }}" wire:model="desiredStatuses.{{ $request->id }}" class="mt-2 min-h-11 w-full rounded-control border border-slate-300 px-3">
                                        @foreach ($statusOptions as $option)
                                            <option value="{{ $option['value'] }}">{{ $option['label'] }}</option>
                                        @endforeach
                                    </select>
                                </div>
                                <div>
                                    <label for="admin-rejection-{{ $request->id }}" class="block text-sm font-bold text-slate-700">{{ __('requests.fields.rejection_reason') }}</label>
                                    <select id="admin-rejection-{{ $request->id }}" wire:model="rejectionReasons.{{ $request->id }}" class="mt-2 min-h-11 w-full rounded-control border border-slate-300 px-3">
                                        @foreach ($rejectionOptions as $option)
                                            <option value="{{ $option['value'] }}">{{ $option['label'] }}</option>
                                        @endforeach
                                    </select>
                                </div>
                            </div>

                            <fieldset class="grid gap-3 rounded-control border border-slate-200 p-3 sm:grid-cols-2">
                                <legend class="px-1 text-sm font-black text-slate-700">{{ __('requests.actions.open_result') }}</legend>
                                <x-form.field :label="__('requests.admin.completion_title_id')" for="completion-title-{{ $request->id }}" type="number" wire:model="completionTitleIds.{{ $request->id }}" />
                                <x-form.field :label="__('requests.admin.completion_season_id')" for="completion-season-{{ $request->id }}" type="number" wire:model="completionSeasonIds.{{ $request->id }}" />
                                <x-form.field :label="__('requests.admin.completion_episode_id')" for="completion-episode-{{ $request->id }}" type="number" wire:model="completionEpisodeIds.{{ $request->id }}" />
                                <x-form.field :label="__('requests.admin.completion_media_id')" for="completion-media-{{ $request->id }}" type="number" wire:model="completionMediaIds.{{ $request->id }}" />
                            </fieldset>

                            <div>
                                <label for="public-reason-{{ $request->id }}" class="block text-sm font-bold text-slate-700">{{ __('requests.fields.public_update') }}</label>
                                <textarea id="public-reason-{{ $request->id }}" wire:model="publicReasons.{{ $request->id }}" rows="3" class="mt-2 w-full rounded-control border border-slate-300 p-3"></textarea>
                            </div>
                            <div>
                                <label for="private-note-{{ $request->id }}" class="block text-sm font-bold text-slate-700">{{ __('requests.admin.private_note') }}</label>
                                <textarea id="private-note-{{ $request->id }}" wire:model="privateNotes.{{ $request->id }}" rows="3" class="mt-2 w-full rounded-control border border-slate-300 p-3"></textarea>
                            </div>
                            <button type="button" wire:click="changeStatus({{ $request->id }})" wire:loading.attr="disabled" wire:target="changeStatus({{ $request->id }})" class="min-h-11 rounded-control bg-slate-800 px-4 py-2 text-sm font-bold text-white disabled:opacity-50">
                                {{ __('requests.admin.apply_status') }}
                            </button>
                        </div>

                        <div class="space-y-3">
                            <div class="grid gap-3 sm:grid-cols-[minmax(0,1fr)_auto]">
                                <div>
                                    <label for="priority-{{ $request->id }}" class="block text-sm font-bold text-slate-700">{{ __('requests.fields.priority') }}</label>
                                    <select id="priority-{{ $request->id }}" wire:model="priorities.{{ $request->id }}" class="mt-2 min-h-11 w-full rounded-control border border-slate-300 px-3">
                                        @foreach ($priorityOptions as $option)
                                            <option value="{{ $option['value'] }}">{{ $option['label'] }}</option>
                                        @endforeach
                                    </select>
                                </div>
                                <button type="button" wire:click="setPriority({{ $request->id }})" wire:loading.attr="disabled" wire:target="setPriority({{ $request->id }})" class="min-h-11 self-end rounded-control bg-slate-100 px-4 py-2 text-sm font-bold text-slate-700 disabled:opacity-50">
                                    {{ __('requests.admin.apply_priority') }}
                                </button>
                            </div>

                            <div>
                                <label for="clarify-{{ $request->id }}" class="block text-sm font-bold text-slate-700">{{ __('requests.admin.clarification_question') }}</label>
                                <textarea id="clarify-{{ $request->id }}" wire:model="clarificationQuestions.{{ $request->id }}" rows="3" class="mt-2 w-full rounded-control border border-slate-300 p-3"></textarea>
                                <button type="button" wire:click="clarify({{ $request->id }})" wire:loading.attr="disabled" wire:target="clarify({{ $request->id }})" class="mt-2 min-h-11 rounded-control bg-amber-100 px-4 py-2 text-sm font-bold text-amber-900 disabled:opacity-50">
                                    {{ __('requests.admin.request_clarification') }}
                                </button>
                            </div>

                            <x-form.field :label="__('requests.admin.merge_target')" for="merge-target-{{ $request->id }}" wire:model="mergeTargets.{{ $request->id }}" />
                            <button type="button" wire:click="merge({{ $request->id }})" wire:confirm="{{ __('requests.confirmations.merge') }}" wire:loading.attr="disabled" wire:target="merge({{ $request->id }})" class="min-h-11 rounded-control bg-rose-700 px-4 py-2 text-sm font-bold text-white disabled:opacity-50">
                                {{ __('requests.admin.merge') }}
                            </button>
                            <button type="button" wire:click="handoff({{ $request->id }})" wire:confirm="{{ __('requests.confirmations.import_handoff') }}" wire:loading.attr="disabled" wire:target="handoff({{ $request->id }})" class="min-h-11 rounded-control bg-emerald-700 px-4 py-2 text-sm font-bold text-white disabled:opacity-50">
                                {{ __('requests.admin.import_handoff') }}
                            </button>
                        </div>
                    </div>
                </div>
            @endforeach
        </section>

        <nav aria-label="{{ __('requests.fields.pagination') }}">{{ $requests->links() }}</nav>
    @endif
</div>
