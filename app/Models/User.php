<?php

namespace App\Models;

use App\Notifications\ResetAccountPassword;
use App\Notifications\VerifyAccountEmail;
use Carbon\CarbonInterface;
use Database\Factories\UserFactory;
use Illuminate\Auth\MustVerifyEmail as MustVerifyEmailBehavior;
use Illuminate\Contracts\Auth\MustVerifyEmail as MustVerifyEmailContract;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Laravel\Sanctum\HasApiTokens;

/**
 * @property string $public_id
 * @property CarbonInterface|null $email_verified_at
 * @property CarbonInterface|null $created_at
 */
#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable implements MustVerifyEmailContract
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, MustVerifyEmailBehavior, Notifiable;

    public function sendEmailVerificationNotification(): void
    {
        $this->notify((new VerifyAccountEmail)->afterCommit());
    }

    /** @param string $token */
    public function sendPasswordResetNotification($token): void
    {
        $this->notify((new ResetAccountPassword($token))->afterCommit());
    }

    /** @return HasMany<CatalogTitleUserState, $this> */
    public function catalogTitleStates(): HasMany
    {
        return $this->hasMany(CatalogTitleUserState::class);
    }

    /** @return HasMany<EpisodeViewProgress, $this> */
    public function episodeViewProgress(): HasMany
    {
        return $this->hasMany(EpisodeViewProgress::class);
    }

    /** @return HasMany<UserTag, $this> */
    public function personalTags(): HasMany
    {
        return $this->hasMany(UserTag::class);
    }

    /** @return HasMany<CatalogCollection, $this> */
    public function catalogCollections(): HasMany
    {
        return $this->hasMany(CatalogCollection::class, 'owner_id');
    }

    /** @return HasMany<Comment, $this> */
    public function comments(): HasMany
    {
        return $this->hasMany(Comment::class);
    }

    /** @return HasMany<CommentReaction, $this> */
    public function commentReactions(): HasMany
    {
        return $this->hasMany(CommentReaction::class);
    }

    /** @return HasMany<CommentRestriction, $this> */
    public function commentRestrictions(): HasMany
    {
        return $this->hasMany(CommentRestriction::class);
    }

    /** @return HasMany<UserBlock, $this> */
    public function blockedUsers(): HasMany
    {
        return $this->hasMany(UserBlock::class, 'blocker_id');
    }

    /** @return HasMany<UserMute, $this> */
    public function mutedUsers(): HasMany
    {
        return $this->hasMany(UserMute::class, 'muter_id');
    }

    /** @return HasOne<CommentNotificationPreference, $this> */
    public function commentNotificationPreference(): HasOne
    {
        return $this->hasOne(CommentNotificationPreference::class);
    }

    /** @return HasMany<CatalogTitleReview, $this> */
    public function catalogTitleReviews(): HasMany
    {
        return $this->hasMany(CatalogTitleReview::class);
    }

    /** @return HasMany<CatalogTitleReviewVote, $this> */
    public function catalogTitleReviewVotes(): HasMany
    {
        return $this->hasMany(CatalogTitleReviewVote::class);
    }

    /** @return HasMany<CatalogTitleReviewRestriction, $this> */
    public function catalogTitleReviewRestrictions(): HasMany
    {
        return $this->hasMany(CatalogTitleReviewRestriction::class);
    }

    /** @return HasOne<CatalogTitleReviewNotificationPreference, $this> */
    public function catalogTitleReviewNotificationPreference(): HasOne
    {
        return $this->hasOne(CatalogTitleReviewNotificationPreference::class);
    }

    protected static function booted(): void
    {
        static::creating(function (self $user): void {
            if (Schema::hasColumn($user->getTable(), 'public_id')) {
                $user->public_id ??= (string) Str::uuid();
            }
        });
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }
}
