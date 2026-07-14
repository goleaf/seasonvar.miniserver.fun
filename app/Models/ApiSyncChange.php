<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'scope',
    'user_id',
    'resource_type',
    'resource_key',
    'operation',
    'changed_at',
])]
class ApiSyncChange extends Model
{
    public const string OPERATION_DELETE = 'delete';

    public const string OPERATION_CLEAR = 'clear';

    public const string OPERATION_UPSERT = 'upsert';

    public const string SCOPE_CATALOG = 'catalog';

    public const string SCOPE_USER = 'user';

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'changed_at' => 'datetime',
        ];
    }
}
