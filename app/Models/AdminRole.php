<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AdminRoleCode;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['code', 'is_active', 'is_system'])]
final class AdminRole extends Model
{
    /** @return BelongsToMany<AdminPermissionRecord, $this> */
    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(
            AdminPermissionRecord::class,
            'admin_role_permissions',
            'admin_role_id',
            'admin_permission_id',
        )->withPivot('created_at');
    }

    /** @return HasMany<AdminUserRole, $this> */
    public function memberships(): HasMany
    {
        return $this->hasMany(AdminUserRole::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'code' => AdminRoleCode::class,
            'is_active' => 'boolean',
            'is_system' => 'boolean',
        ];
    }
}
