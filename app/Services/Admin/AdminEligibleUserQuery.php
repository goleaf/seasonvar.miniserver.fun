<?php

declare(strict_types=1);

namespace App\Services\Admin;

use App\Enums\AccountRestrictionType;
use App\Enums\AdminMembershipStatus;
use App\Enums\AdminPermission;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final readonly class AdminEligibleUserQuery
{
    public function __construct(private AdminLegacyAccessMap $legacy) {}

    /** @return Builder<User> */
    public function forPermission(AdminPermission $permission): Builder
    {
        $legacyEmails = $this->legacy->emailsFor($permission);
        $blockingTypes = collect(AccountRestrictionType::cases())
            ->filter->blocksAuthentication()
            ->pluck('value')
            ->all();

        $query = User::query()
            ->whereNotNull('email_verified_at')
            ->where(function (Builder $query) use ($permission, $legacyEmails): void {
                if (Schema::hasTable('admin_user_roles')) {
                    $query->whereHas('adminRoleMemberships', fn (Builder $memberships): Builder => $memberships
                        ->where('status', AdminMembershipStatus::Active->value)
                        ->where(fn (Builder $membership): Builder => $membership->whereNull('expires_at')->orWhere('expires_at', '>', now()))
                        ->whereHas('role', fn (Builder $roles): Builder => $roles
                            ->where('is_active', true)
                            ->whereHas('permissions', fn (Builder $permissions): Builder => $permissions->where('code', $permission->value))));
                } else {
                    $query->whereRaw('1 = 0');
                }

                if ($legacyEmails !== []) {
                    $query->orWhereIn(DB::raw('lower(email)'), $legacyEmails);
                }
            });

        if (Schema::hasTable('admin_user_roles')) {
            $query->whereDoesntHave('adminRoleMemberships', fn (Builder $query): Builder => $query->where('status', AdminMembershipStatus::Suspended->value));
        }

        if (Schema::hasTable('account_restrictions')) {
            $query->whereDoesntHave('accountRestrictions', fn (Builder $query): Builder => $query->active()->whereIn('type', $blockingTypes));
        }

        return $query;
    }
}
