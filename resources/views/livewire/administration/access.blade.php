<div class="space-y-5" data-administration-access>
    <header class="rounded-panel border border-slate-200 bg-white p-4 shadow-panel sm:p-6">
        <p class="text-xs font-bold uppercase tracking-[0.16em] text-emerald-700">{{ __('administration.eyebrow') }}</p>
        <h1 class="mt-2 text-2xl font-black tracking-tight text-slate-800 sm:text-3xl">{{ __('administration.access.title') }}</h1>
        <p class="mt-2 max-w-3xl text-sm leading-6 text-slate-600">{{ __('administration.access.description') }}</p>
    </header>

    <x-administration.state type="security" :title="__('administration.access.superadministrator_title')" :description="__('administration.access.superadministrator_policy')" />
    <p class="sr-only" role="status" aria-live="polite">{{ $statusMessage }}</p>

    @if ($errors->any())
        <x-administration.state type="error" :title="__('administration.shared.action_failed')" :description="$errors->first()" />
    @endif

    @if ($canManage)
        <form wire:submit="assignRole" class="rounded-panel border border-slate-200 bg-white p-4 shadow-panel" aria-labelledby="assign-admin-role-title">
            <h2 id="assign-admin-role-title" class="text-lg font-black text-slate-800">{{ __('administration.access.assign_title') }}</h2>
            <p class="mt-1 text-sm leading-6 text-slate-600">{{ __('administration.access.assign_impact') }}</p>
            <div class="mt-4 grid gap-3 md:grid-cols-3">
                <label class="grid gap-1 text-sm font-bold text-slate-700">
                    <span>{{ __('administration.access.user_public_id') }}</span>
                    <input wire:model="userPublicId" required maxlength="36" class="min-h-11 rounded-control border border-slate-300 px-3 py-2 font-normal">
                </label>
                <label class="grid gap-1 text-sm font-bold text-slate-700">
                    <span>{{ __('administration.access.role') }}</span>
                    <select wire:model="roleCode" required class="min-h-11 rounded-control border border-slate-300 px-3 py-2 font-normal">
                        <option value="">{{ __('administration.access.choose_role') }}</option>
                        @foreach ($roleOptions as $value => $label)
                            <option value="{{ $value }}">{{ $label }}</option>
                        @endforeach
                    </select>
                </label>
                <label class="grid gap-1 text-sm font-bold text-slate-700">
                    <span>{{ __('administration.users.reason_code') }}</span>
                    <input wire:model="reasonCode" required maxlength="64" class="min-h-11 rounded-control border border-slate-300 px-3 py-2 font-normal">
                </label>
            </div>
            <p class="mt-3 text-xs leading-5 text-amber-800" data-impact-preview>{{ __('administration.access.recent_auth_required') }}</p>
            <button type="submit" wire:confirm="{{ __('administration.access.assign_confirm') }}" wire:loading.attr="disabled" class="mt-4 min-h-11 rounded-control bg-emerald-700 px-4 py-2 text-sm font-bold text-white disabled:opacity-60">
                {{ __('administration.access.assign_action') }}
            </button>
        </form>
    @endif

    <section class="rounded-panel border border-slate-200 bg-white p-4 shadow-panel" aria-labelledby="role-matrix-title">
        <h2 id="role-matrix-title" class="text-lg font-black text-slate-800">{{ __('administration.access.matrix_title') }}</h2>
        <p class="mt-1 text-sm leading-6 text-slate-600">{{ __('administration.access.matrix_description') }}</p>
        <div class="mt-4 grid gap-3 lg:grid-cols-2">
            @foreach ($roles as $role)
                <details class="rounded-control border border-slate-200 p-3" wire:key="admin-role-{{ $role->code }}">
                    <summary class="flex min-h-11 cursor-pointer items-center justify-between gap-3 py-2 font-black text-slate-800">
                        <span>{{ $role->label }}</span>
                        <span class="rounded-full px-2 py-1 text-xs {{ $role->active ? 'bg-emerald-50 text-emerald-800' : 'bg-slate-100 text-slate-600' }}">
                            {{ $role->active ? __('administration.access.role_active') : __('administration.access.role_inactive') }}
                        </span>
                    </summary>
                    <code class="text-xs text-slate-400">{{ $role->code }}</code>
                    <ul class="mt-3 space-y-2">
                        @foreach ($role->permissions as $permission)
                            <li class="flex items-start justify-between gap-3 border-t border-slate-100 pt-2">
                                <span class="text-sm text-slate-700">{{ $permission->label }}</span>
                                <span class="shrink-0 rounded-full bg-slate-100 px-2 py-1 text-[0.6875rem] font-bold text-slate-600">{{ $permission->sensitivityLabel }}</span>
                            </li>
                        @endforeach
                    </ul>
                </details>
            @endforeach
        </div>
    </section>

    @island(name: 'admin-access-memberships', always: true, with: $this->paginationIslandPage)
    <x-ui.pagination-region name="admin-access-memberships">
    <section class="rounded-panel border border-slate-200 bg-white p-4 shadow-panel" aria-labelledby="memberships-title">
        <h2 id="memberships-title" class="text-lg font-black text-slate-800">{{ __('administration.access.memberships_title') }}</h2>
        <div class="mt-4 grid gap-3">
            @forelse ($memberships as $membership)
                <article class="grid gap-3 rounded-control border border-slate-200 p-3 lg:grid-cols-[minmax(0,1fr)_minmax(0,0.8fr)_auto] lg:items-center" wire:key="admin-membership-{{ $membership->publicId }}">
                    <div class="min-w-0">
                        <h3 class="font-black text-slate-800">{{ $membership->userName }}</h3>
                        <p class="mt-1 text-sm text-slate-500">{{ $membership->maskedEmail }}</p>
                        <code class="mt-1 block break-all text-xs text-slate-400">{{ $membership->userPublicId }}</code>
                    </div>
                    <div class="text-sm text-slate-600">
                        <p class="font-bold text-slate-800">{{ $membership->roleLabel }}</p>
                        <p>{{ $membership->statusLabel }}</p>
                        <p>{{ __('administration.access.assigned_at', ['time' => $membership->assignedAtLabel]) }}</p>
                        <p>{{ __('administration.access.expires_at', ['time' => $membership->expiresAtLabel]) }}</p>
                    </div>
                    @if ($canManage)
                        <div class="flex flex-wrap gap-2 lg:justify-end">
                            @if ($membership->status === 'active')
                                <button type="button" wire:click="suspendRole('{{ $membership->publicId }}')" wire:confirm="{{ __('administration.access.suspend_confirm') }}" class="min-h-11 rounded-control border border-amber-300 px-3 py-2 text-sm font-bold text-amber-800">{{ __('administration.access.suspend') }}</button>
                            @else
                                <button type="button" wire:click="restoreRole('{{ $membership->publicId }}')" wire:confirm="{{ __('administration.access.restore_confirm') }}" class="min-h-11 rounded-control border border-emerald-300 px-3 py-2 text-sm font-bold text-emerald-800">{{ __('administration.access.restore') }}</button>
                            @endif
                            <button type="button" wire:click="revokeRole('{{ $membership->publicId }}')" wire:confirm="{{ __('administration.access.revoke_confirm') }}" class="min-h-11 rounded-control bg-rose-700 px-3 py-2 text-sm font-bold text-white">{{ __('administration.access.revoke') }}</button>
                        </div>
                    @endif
                </article>
            @empty
                <x-administration.state type="empty" :title="__('administration.shared.empty')" :description="__('administration.access.memberships_empty')" />
            @endforelse
        </div>
        <div class="mt-4">{{ $memberships->links(data: ['region' => 'admin-access-memberships']) }}</div>
    </section>
    </x-ui.pagination-region>
    @endisland
</div>
