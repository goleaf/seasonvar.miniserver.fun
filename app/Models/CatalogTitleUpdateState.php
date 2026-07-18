<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $user_id
 * @property int $catalog_title_id
 * @property int $acknowledged_release_id
 * @property CarbonInterface|null $acknowledged_at
 * @property int $version
 */
#[Fillable([
    'user_id',
    'catalog_title_id',
    'acknowledged_release_id',
    'acknowledged_at',
    'version',
])]
final class CatalogTitleUpdateState extends Model
{
    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<CatalogTitle, $this> */
    public function catalogTitle(): BelongsTo
    {
        return $this->belongsTo(CatalogTitle::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'acknowledged_release_id' => 'integer',
            'acknowledged_at' => 'datetime',
            'version' => 'integer',
        ];
    }
}
