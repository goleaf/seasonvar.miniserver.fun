<?php

namespace App\Models;

use App\Notifications\ResetAccountPassword;
use App\Notifications\VerifyAccountEmail;
use App\ValueObjects\NormalizedEmail;
use Carbon\CarbonInterface;
use Database\Factories\UserFactory;
use Illuminate\Auth\MustVerifyEmail as MustVerifyEmailBehavior;
use Illuminate\Contracts\Auth\MustVerifyEmail as MustVerifyEmailContract;
use Illuminate\Contracts\Translation\HasLocalePreference;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
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
class User extends Authenticatable implements HasLocalePreference, MustVerifyEmailContract
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

    public function preferredLocale(): string
    {
        $supported = (array) config('catalog-collections.supported_locales', []);
        $locale = null;

        if (Schema::hasTable('user_account_settings')) {
            $setting = $this->relationLoaded('accountSetting')
                ? $this->accountSetting
                : $this->accountSetting()->first();
            $locale = is_string($setting?->locale) ? $setting->locale : null;
        }

        if (is_string($locale) && in_array($locale, $supported, true)) {
            return $locale;
        }

        $current = app()->getLocale();

        return in_array($current, $supported, true)
            ? $current
            : (string) config('account-settings.default_locale', config('app.locale', 'ru'));
    }

    /** @param Builder<User> $query */
    public function scopeWhereEmailIdentity(Builder $query, string $email): void
    {
        $query->whereRaw('lower(email) = ?', [NormalizedEmail::value($email)]);
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

    /** @return HasMany<EpisodePlaybackMarker, $this> */
    public function episodePlaybackMarkers(): HasMany
    {
        return $this->hasMany(EpisodePlaybackMarker::class);
    }

    /** @return HasMany<CatalogTitleUpdateState, $this> */
    public function catalogTitleUpdateStates(): HasMany
    {
        return $this->hasMany(CatalogTitleUpdateState::class);
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

    /** @return HasOne<UserProfile, $this> */
    public function profile(): HasOne
    {
        return $this->hasOne(UserProfile::class);
    }

    /** @return HasMany<UserProfileUsernameHistory, $this> */
    public function profileUsernameHistory(): HasMany
    {
        return $this->hasMany(UserProfileUsernameHistory::class);
    }

    /** @return HasMany<UserProfileReport, $this> */
    public function profileReports(): HasMany
    {
        return $this->hasMany(UserProfileReport::class, 'target_user_id');
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

    /** @return HasMany<ContentRequest, $this> */
    public function contentRequests(): HasMany
    {
        return $this->hasMany(ContentRequest::class, 'requester_id');
    }

    /** @return HasMany<ContentRequestVote, $this> */
    public function contentRequestVotes(): HasMany
    {
        return $this->hasMany(ContentRequestVote::class);
    }

    /** @return HasMany<ContentRequestFollower, $this> */
    public function followedContentRequests(): HasMany
    {
        return $this->hasMany(ContentRequestFollower::class);
    }

    /** @return HasOne<ContentRequestNotificationPreference, $this> */
    public function contentRequestNotificationPreference(): HasOne
    {
        return $this->hasOne(ContentRequestNotificationPreference::class);
    }

    /** @return HasMany<ReleaseCalendarSubscription, $this> */
    public function releaseCalendarSubscriptions(): HasMany
    {
        return $this->hasMany(ReleaseCalendarSubscription::class);
    }

    /** @return HasOne<ReleaseCalendarNotificationPreference, $this> */
    public function releaseCalendarNotificationPreference(): HasOne
    {
        return $this->hasOne(ReleaseCalendarNotificationPreference::class);
    }

    /** @return HasMany<TechnicalIssue, $this> */
    public function technicalIssues(): HasMany
    {
        return $this->hasMany(TechnicalIssue::class, 'requester_id');
    }

    /** @return HasMany<TechnicalIssueConfirmation, $this> */
    public function technicalIssueConfirmations(): HasMany
    {
        return $this->hasMany(TechnicalIssueConfirmation::class);
    }

    /** @return HasMany<TechnicalIssueFollower, $this> */
    public function followedTechnicalIssues(): HasMany
    {
        return $this->hasMany(TechnicalIssueFollower::class);
    }

    /** @return HasMany<TechnicalIssueOccurrence, $this> */
    public function technicalIssueOccurrences(): HasMany
    {
        return $this->hasMany(TechnicalIssueOccurrence::class);
    }

    /** @return HasMany<TechnicalIssue, $this> */
    public function assignedTechnicalIssues(): HasMany
    {
        return $this->hasMany(TechnicalIssue::class, 'assigned_to_id');
    }

    /** @return HasOne<TechnicalIssueNotificationPreference, $this> */
    public function technicalIssueNotificationPreference(): HasOne
    {
        return $this->hasOne(TechnicalIssueNotificationPreference::class);
    }

    /** @return HasOne<UserAccountSetting, $this> */
    public function accountSetting(): HasOne
    {
        return $this->hasOne(UserAccountSetting::class);
    }

    /** @return HasMany<PremiumEntitlement, $this> */
    public function premiumEntitlements(): HasMany
    {
        return $this->hasMany(PremiumEntitlement::class);
    }

    /** @return HasMany<PremiumSubscription, $this> */
    public function premiumSubscriptions(): HasMany
    {
        return $this->hasMany(PremiumSubscription::class);
    }

    /** @return HasMany<PremiumPayment, $this> */
    public function premiumPayments(): HasMany
    {
        return $this->hasMany(PremiumPayment::class);
    }

    /** @return HasMany<PremiumCouponRedemption, $this> */
    public function premiumCouponRedemptions(): HasMany
    {
        return $this->hasMany(PremiumCouponRedemption::class);
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
