<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'user_id',
    'catalog_title_id',
    'in_watchlist',
    'rating',
    'watchlist_version',
    'rating_version',
])]
class CatalogTitleUserState extends Model
{
    /** @var array<string, int> */
    protected $attributes = [
        'watchlist_version' => 0,
        'rating_version' => 0,
    ];

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

    public function watchlistVersion(): int
    {
        return $this->versionAttribute('watchlist_version');
    }

    public function ratingVersion(): int
    {
        return $this->versionAttribute('rating_version');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'in_watchlist' => 'boolean',
            'rating' => 'integer',
            'watchlist_version' => 'integer',
            'rating_version' => 'integer',
        ];
    }

    private function versionAttribute(string $attribute): int
    {
        if (! array_key_exists($attribute, $this->getAttributes())) {
            return 0;
        }

        $value = $this->getAttribute($attribute);

        return is_int($value) && $value >= 0 ? $value : 0;
    }
}
