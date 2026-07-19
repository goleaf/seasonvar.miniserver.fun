<?php

declare(strict_types=1);

namespace App\Livewire\Administration;

use App\Actions\Administration\AssignAdminRole;
use App\Actions\Administration\RevokeAdminRole;
use App\Actions\Administration\SetAdminMembershipStatus;
use App\Enums\AdminMembershipStatus;
use App\Enums\AdminPermission;
use App\Enums\AdminRoleCode;
use App\Exceptions\AdministrationAccessException;
use App\Livewire\Concerns\InteractsWithPaginationIslands;
use App\Models\AdminUserRole;
use App\Models\User;
use App\Services\Admin\AdminAccessManagementQuery;
use App\Services\Admin\AdminAccessResolver;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Component;
use Livewire\WithPagination;

final class AdminAccessManagementPage extends Component
{
    use InteractsWithPaginationIslands;
    use WithPagination;

    public string $userPublicId = '';

    public string $roleCode = '';

    public string $reasonCode = 'staffing_change';

    public string $statusMessage = '';

    public function mount(): void
    {
        Gate::authorize(AdminPermission::RolesView->value);
    }

    public function assignRole(AssignAdminRole $action): void
    {
        Gate::authorize(AdminPermission::RolesManage->value);
        $validated = $this->validate([
            'userPublicId' => ['required', 'uuid', Rule::exists('users', 'public_id')],
            'roleCode' => ['required', Rule::enum(AdminRoleCode::class)],
            'reasonCode' => ['required', 'regex:/^[a-z][a-z0-9_.-]{2,63}$/'],
        ]);
        $target = User::query()->where('public_id', $validated['userPublicId'])->firstOrFail();
        $this->perform(function () use ($action, $target, $validated): void {
            $action->handle($this->user(), $target, AdminRoleCode::from($validated['roleCode']), $validated['reasonCode']);
            $this->statusMessage = __('administration.access.role_assigned');
            $this->reset(['userPublicId', 'roleCode']);
        });
    }

    public function revokeRole(string $membershipPublicId, RevokeAdminRole $action): void
    {
        $membership = $this->membership($membershipPublicId);
        $this->perform(function () use ($action, $membership): void {
            $action->handle($this->user(), $membership, 'administrator_revoked', true);
            $this->statusMessage = __('administration.access.role_revoked');
        });
    }

    public function suspendRole(string $membershipPublicId, SetAdminMembershipStatus $action): void
    {
        $membership = $this->membership($membershipPublicId);
        $this->perform(function () use ($action, $membership): void {
            $action->handle($this->user(), $membership, AdminMembershipStatus::Suspended, 'security_review', true);
            $this->statusMessage = __('administration.access.membership_suspended');
        });
    }

    public function restoreRole(string $membershipPublicId, SetAdminMembershipStatus $action): void
    {
        $membership = $this->membership($membershipPublicId);
        $this->perform(function () use ($action, $membership): void {
            $action->handle($this->user(), $membership, AdminMembershipStatus::Active, 'review_completed', true);
            $this->statusMessage = __('administration.access.membership_restored');
        });
    }

    public function render(AdminAccessManagementQuery $query, AdminAccessResolver $access): View
    {
        Gate::authorize(AdminPermission::RolesView->value);
        $user = $this->user();

        return view('livewire.administration.access', [
            'roles' => $query->roles(),
            'memberships' => $query->memberships($this->getPage('membershipsPage')),
            'canManage' => $access->allows($user, AdminPermission::RolesManage),
            'roleOptions' => collect(AdminRoleCode::cases())->mapWithKeys(fn (AdminRoleCode $role): array => [$role->value => $role->label()])->all(),
        ])->extends('layouts.app', [
            'title' => __('administration.access.title'),
            'seo' => [
                'title' => __('administration.access.title'),
                'description' => __('administration.access.description'),
                'robots' => 'noindex,nofollow',
                'canonical' => route('admin.access'),
                'alternates' => [],
                'social' => false,
                'jsonLd' => [],
            ],
        ])->section('content');
    }

    private function membership(string $publicId): AdminUserRole
    {
        Gate::authorize(AdminPermission::RolesManage->value);

        return AdminUserRole::query()->where('public_id', $publicId)->firstOrFail();
    }

    private function user(): User
    {
        $user = request()->user();
        abort_unless($user instanceof User, 401);

        return $user;
    }

    private function perform(callable $operation): void
    {
        $this->resetErrorBag('action');

        try {
            $operation();
        } catch (AdministrationAccessException $exception) {
            $this->addError('action', __($exception->translationKey));
        }
    }
}
