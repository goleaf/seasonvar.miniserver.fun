<div class="space-y-5" data-administration-users>
    <header class="rounded-panel border border-slate-200 bg-white p-4 shadow-panel sm:p-6">
        <p class="text-xs font-bold uppercase tracking-[0.16em] text-emerald-700">{{ __('administration.eyebrow') }}</p>
        <h1 class="mt-2 text-2xl font-black tracking-tight text-slate-800 sm:text-3xl">{{ __('administration.users.title') }}</h1>
        <p class="mt-2 max-w-3xl text-sm leading-6 text-slate-600">{{ __('administration.users.description') }}</p>
    </header>

    <p class="sr-only" role="status" aria-live="polite">{{ $statusMessage }}</p>

    @if ($errors->any())
        <x-administration.state type="error" :title="__('administration.shared.action_failed')" :description="$errors->first()" />
    @endif

    @if ($queryFailed)
        <x-administration.state type="error" :title="__('administration.shared.error')" :description="__('administration.shared.query_failed')" />
    @endif

    <x-administration.filters :label="__('administration.users.filters.label')" :active-count="$activeFilterCount">
        <label class="grid gap-1 text-sm font-bold text-slate-700">
            <span>{{ __('administration.shared.search') }}</span>
            <input type="search" wire:model.live.debounce.400ms="search" maxlength="80" class="min-h-11 rounded-control border border-slate-300 px-3 py-2 font-normal" placeholder="{{ __('administration.users.filters.search_placeholder') }}">
        </label>
        <label class="grid gap-1 text-sm font-bold text-slate-700">
            <span>{{ __('administration.users.filters.verification') }}</span>
            <select wire:model.live="verification" class="min-h-11 rounded-control border border-slate-300 px-3 py-2 font-normal">
                <option value="">{{ __('administration.users.filters.all') }}</option>
                <option value="verified">{{ __('administration.users.verified') }}</option>
                <option value="unverified">{{ __('administration.users.unverified') }}</option>
            </select>
        </label>
        <label class="grid gap-1 text-sm font-bold text-slate-700">
            <span>{{ __('administration.users.filters.restriction') }}</span>
            <select wire:model.live="restriction" class="min-h-11 rounded-control border border-slate-300 px-3 py-2 font-normal">
                <option value="">{{ __('administration.users.filters.all') }}</option>
                <option value="active">{{ __('administration.users.filters.restricted') }}</option>
                <option value="none">{{ __('administration.users.filters.not_restricted') }}</option>
            </select>
        </label>
        <label class="grid gap-1 text-sm font-bold text-slate-700">
            <span>{{ __('administration.users.filters.sort') }}</span>
            <select wire:model.live="sort" class="min-h-11 rounded-control border border-slate-300 px-3 py-2 font-normal">
                <option value="registered">{{ __('administration.users.filters.sort_registered') }}</option>
                <option value="name">{{ __('administration.users.filters.sort_name') }}</option>
            </select>
        </label>
    </x-administration.filters>

    <div wire:loading.delay role="status" aria-live="polite" class="rounded-control bg-emerald-50 px-4 py-3 text-sm font-bold text-emerald-800">
        {{ __('administration.shared.loading') }}
    </div>

    @island(name: 'admin-users-results', always: true, with: $this->paginationIslandPage)
    <x-ui.pagination-region name="admin-users-results">
    <x-administration.table :caption="__('administration.users.table_caption')" :columns="$columns" :empty="$users->isEmpty()">
        @foreach ($users as $user)
            <tr wire:key="admin-user-{{ $user->publicId }}" class="align-top">
                <td class="min-w-56 px-4 py-4">
                    <p class="font-black text-slate-800">{{ $user->name }}</p>
                    <p class="mt-1 text-sm text-slate-500">{{ $user->maskedEmail }}</p>
                    <code class="mt-2 block break-all text-xs text-slate-400">{{ $user->publicId }}</code>
                </td>
                <td class="min-w-48 px-4 py-4 text-sm text-slate-600">
                    <p>{{ $user->verificationLabel }}</p>
                    @foreach ($user->roleLabels as $roleLabel)
                        <span class="mt-2 inline-flex rounded-full bg-sky-50 px-2 py-1 text-xs font-bold text-sky-800">{{ $roleLabel }}</span>
                    @endforeach
                    @foreach ($user->restrictionLabels as $restrictionLabel)
                        <span class="mt-2 inline-flex rounded-full bg-amber-50 px-2 py-1 text-xs font-bold text-amber-800">{{ $restrictionLabel }}</span>
                    @endforeach
                </td>
                <td class="min-w-40 px-4 py-4 text-sm text-slate-600">
                    <p>{{ __('administration.users.activity.comments', ['count' => $user->commentsCount]) }}</p>
                    <p>{{ __('administration.users.activity.reviews', ['count' => $user->reviewsCount]) }}</p>
                    <p>{{ __('administration.users.activity.requests', ['count' => $user->requestsCount]) }}</p>
                </td>
                <td class="whitespace-nowrap px-4 py-4 text-sm text-slate-600">{{ $user->registeredAtLabel }}</td>
                <td class="min-w-64 px-4 py-4">
                    @if ($canRestrict)
                        <details class="rounded-control border border-slate-200 p-3">
                            <summary class="min-h-11 cursor-pointer py-2 text-sm font-black text-slate-700">{{ __('administration.users.restrict_action') }}</summary>
                            <div class="mt-3 grid gap-3">
                                <label class="grid gap-1 text-xs font-bold text-slate-600">
                                    <span>{{ __('administration.users.restriction_type') }}</span>
                                    <select wire:model="restrictionTypes.{{ $user->publicId }}" class="min-h-11 rounded-control border border-slate-300 px-3 py-2 text-sm font-normal">
                                        @foreach ($restrictionTypeOptions as $value => $label)
                                            <option value="{{ $value }}">{{ $label }}</option>
                                        @endforeach
                                    </select>
                                </label>
                                <label class="grid gap-1 text-xs font-bold text-slate-600">
                                    <span>{{ __('administration.users.reason_code') }}</span>
                                    <input wire:model="restrictionReasons.{{ $user->publicId }}" maxlength="64" class="min-h-11 rounded-control border border-slate-300 px-3 py-2 text-sm font-normal" value="manual_review">
                                </label>
                                <label class="grid gap-1 text-xs font-bold text-slate-600">
                                    <span>{{ __('administration.users.duration') }}</span>
                                    <select wire:model="restrictionDurations.{{ $user->publicId }}" class="min-h-11 rounded-control border border-slate-300 px-3 py-2 text-sm font-normal">
                                        <option value="24">{{ __('administration.users.durations.day') }}</option>
                                        <option value="168">{{ __('administration.users.durations.week') }}</option>
                                        <option value="720">{{ __('administration.users.durations.month') }}</option>
                                        <option value="0">{{ __('administration.users.durations.indefinite') }}</option>
                                    </select>
                                </label>
                                <p class="text-xs leading-5 text-amber-800" data-impact-preview>{{ __('administration.users.restriction_impact') }}</p>
                                <button type="button" wire:click="applyRestriction('{{ $user->publicId }}')" wire:confirm="{{ __('administration.users.restriction_confirm') }}" wire:loading.attr="disabled" class="min-h-11 rounded-control bg-amber-700 px-4 py-2 text-sm font-bold text-white disabled:opacity-60">
                                    {{ __('administration.users.apply_restriction') }}
                                </button>
                                @foreach ($user->restrictionPublicIds as $restrictionPublicId)
                                    <button type="button" wire:click="revokeRestriction('{{ $restrictionPublicId }}')" wire:confirm="{{ __('administration.users.restore_confirm') }}" wire:loading.attr="disabled" class="min-h-11 rounded-control border border-emerald-300 px-4 py-2 text-sm font-bold text-emerald-800 disabled:opacity-60">
                                        {{ __('administration.users.restore_action') }}
                                    </button>
                                @endforeach
                            </div>
                        </details>
                    @else
                        <span class="text-sm text-slate-500">{{ __('administration.users.read_only') }}</span>
                    @endif
                </td>
            </tr>
        @endforeach

        <x-slot:emptyState>
            <x-administration.state type="empty" :title="__('administration.shared.empty')" :description="__('administration.users.empty')" />
        </x-slot:emptyState>
        <x-slot:pagination>
            {{ $users->links(data: ['region' => 'admin-users-results']) }}
        </x-slot:pagination>
    </x-administration.table>
    </x-ui.pagination-region>
    @endisland

    <x-administration.state type="unavailable" :title="__('administration.users.merge_unavailable_title')" :description="__('administration.users.merge_unavailable')" />
</div>
