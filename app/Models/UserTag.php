<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

#[Fillable([
    'public_id', 'name', 'normalized_name', 'normalized_name_hash', 'description', 'content_locale',
    'content_version',
])]
final class UserTag extends Model
{
    use SoftDeletes;

    /** @return BelongsTo<User, $this> */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /** @return BelongsToMany<CatalogTitle, $this> */
    public function catalogTitles(): BelongsToMany
    {
        return $this->belongsToMany(CatalogTitle::class, 'catalog_title_user_tag')
            ->withPivot('position')
            ->withTimestamps()
            ->orderByPivot('position')
            ->orderBy('catalog_titles.id');
    }

    /** @param Builder<UserTag> $query */
    public function scopeOwnedBy(Builder $query, User $user): void
    {
        $query->where('user_id', $user->getKey());
    }

    public function isOwnedBy(?User $user): bool
    {
        return $user !== null && (int) $this->user_id === (int) $user->getKey();
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    protected static function booted(): void
    {
        self::creating(function (self $tag): void {
            $tag->public_id ??= (string) Str::uuid();
        });
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'content_version' => 'integer',
        ];
    }
}
