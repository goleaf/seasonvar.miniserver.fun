<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AdminPermission;
use App\Enums\AdminPermissionSensitivity;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

#[Fillable(['code', 'sensitivity'])]
final class AdminPermissionRecord extends Model
{
    protected $table = 'admin_permissions';

    /** @return BelongsToMany<AdminRole, $this> */
    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(
            AdminRole::class,
            'admin_role_permissions',
            'admin_permission_id',
            'admin_role_id',
        )->withPivot('created_at');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'code' => AdminPermission::class,
            'sensitivity' => AdminPermissionSensitivity::class,
        ];
    }
}
