<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ReleaseCalendarFeedScope;
use App\Policies\ReleaseCalendarFeedPolicy;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property string $public_id
 * @property int $user_id
 * @property int|null $catalog_collection_id
 * @property int|null $catalog_title_id
 * @property ReleaseCalendarFeedScope $scope
 * @property string $token_hash
 * @property string $token_secret
 * @property string|null $language_code
 * @property string|null $translation_name
 * @property string $locale
 * @property int $version
 * @property CarbonInterface $token_rotated_at
 */
#[Fillable([
    'public_id',
    'user_id',
    'catalog_collection_id',
    'catalog_title_id',
    'scope',
    'token_hash',
    'token_secret',
    'language_code',
    'translation_name',
    'locale',
    'version',
    'token_rotated_at',
])]
#[Hidden(['token_hash', 'token_secret'])]
#[UsePolicy(ReleaseCalendarFeedPolicy::class)]
final class ReleaseCalendarFeed extends Model
{
    protected $attributes = [
        'version' => 1,
    ];

    protected static function booted(): void
    {
        self::creating(function (self $feed): void {
            $feed->public_id = $feed->public_id ?: (string) Str::uuid();
        });
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<CatalogCollection, $this> */
    public function catalogCollection(): BelongsTo
    {
        return $this->belongsTo(CatalogCollection::class);
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
            'scope' => ReleaseCalendarFeedScope::class,
            'token_secret' => 'encrypted',
            'version' => 'integer',
            'token_rotated_at' => 'immutable_datetime',
        ];
    }
}
