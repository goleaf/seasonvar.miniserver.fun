<?php

declare(strict_types=1);

namespace App\Services\Admin;

use App\Enums\AdminPermission;
use App\Models\User;
use Illuminate\Contracts\Auth\Access\Gate;

final readonly class AdminGateRegistrar
{
    public function __construct(
        private Gate $gate,
        private AdminAccessRegistry $registry,
    ) {}

    public function register(): void
    {
        foreach (AdminPermission::cases() as $permission) {
            $this->gate->define(
                $permission->value,
                fn (User $user): bool => app(AdminAccessResolver::class)->allows($user, $permission),
            );
        }

        foreach ($this->registry->legacyGatePermissions() as $gateName => $permission) {
            $this->gate->define(
                $gateName,
                fn (User $user): bool => app(AdminAccessResolver::class)->allows($user, $permission),
            );
        }
    }
}
