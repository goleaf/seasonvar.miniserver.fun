<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ContentRequestPriority;
use App\Enums\ContentRequestRejectionReason;
use App\Enums\ContentRequestStatus;
use App\Enums\ContentRequestType;
use App\Policies\ContentRequestPolicy;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;

#[UsePolicy(ContentRequestPolicy::class)]
#[Fillable([
    'public_id', 'requester_id', 'type', 'status', 'priority', 'title', 'normalized_title',
    'normalized_title_hash', 'original_title', 'alternative_title', 'release_year', 'country',
    'content_locale', 'original_language', 'audio_language', 'subtitle_language',
    'translation_type', 'translation_studio', 'catalog_title_id', 'season_id', 'episode_id',
    'season_number', 'season_kind', 'episode_number', 'episode_release_date', 'current_quality',
    'requested_quality', 'correction_field', 'current_value', 'proposed_value', 'explanation',
    'different_explanation', 'exact_identity_hash', 'active_identity_key', 'submission_key',
    'probable_duplicate', 'is_public', 'rejection_reason', 'public_note',
    'private_moderator_note', 'merged_into_id', 'completed_catalog_title_id',
    'completed_season_id', 'completed_episode_id', 'completed_media_id', 'source_page_id',
    'import_run_id', 'version', 'partial_completed_at', 'completed_at', 'withdrawn_at',
])]
final class ContentRequest extends Model
{
    protected $attributes = [
        'status' => ContentRequestStatus::Submitted->value,
        'priority' => ContentRequestPriority::Normal->value,
        'version' => 1,
        'probable_duplicate' => false,
        'is_public' => true,
    ];

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    /** @param Builder<ContentRequest> $query */
    public function resolveRouteBindingQuery($query, $value, $field = null): Builder
    {
        $user = Auth::user();

        return parent::resolveRouteBindingQuery($query, $value, $field)
            ->where(function (Builder $visibility) use ($user): void {
                $visibility->where('is_public', true)
                    ->when($user instanceof User, function (Builder $visibility) use ($user): void {
                        $visibility->orWhere('requester_id', $user->id);

                        if (Gate::forUser($user)->allows('manage-content-requests')) {
                            $visibility->orWhereNotNull('id');
                        }
                    });
            });
    }

    /** @return BelongsTo<User, $this> */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requester_id');
    }

    /** @return BelongsTo<CatalogTitle, $this> */
    public function catalogTitle(): BelongsTo
    {
        return $this->belongsTo(CatalogTitle::class)->withTrashed();
    }

    /** @return BelongsTo<Season, $this> */
    public function season(): BelongsTo
    {
        return $this->belongsTo(Season::class)->withTrashed();
    }

    /** @return BelongsTo<Episode, $this> */
    public function episode(): BelongsTo
    {
        return $this->belongsTo(Episode::class)->withTrashed();
    }

    /** @return BelongsTo<ContentRequest, $this> */
    public function mergedInto(): BelongsTo
    {
        return $this->belongsTo(self::class, 'merged_into_id');
    }

    /** @return BelongsTo<CatalogTitle, $this> */
    public function completedCatalogTitle(): BelongsTo
    {
        return $this->belongsTo(CatalogTitle::class, 'completed_catalog_title_id')->withTrashed();
    }

    /** @return BelongsTo<Season, $this> */
    public function completedSeason(): BelongsTo
    {
        return $this->belongsTo(Season::class, 'completed_season_id')->withTrashed();
    }

    /** @return BelongsTo<Episode, $this> */
    public function completedEpisode(): BelongsTo
    {
        return $this->belongsTo(Episode::class, 'completed_episode_id')->withTrashed();
    }

    /** @return BelongsTo<LicensedMedia, $this> */
    public function completedMedia(): BelongsTo
    {
        return $this->belongsTo(LicensedMedia::class, 'completed_media_id')->withTrashed();
    }

    /** @return HasMany<ContentRequestVote, $this> */
    public function votes(): HasMany
    {
        return $this->hasMany(ContentRequestVote::class);
    }

    /** @return HasMany<ContentRequestFollower, $this> */
    public function followers(): HasMany
    {
        return $this->hasMany(ContentRequestFollower::class);
    }

    /** @return HasMany<ContentRequestStatusHistory, $this> */
    public function statusHistory(): HasMany
    {
        return $this->hasMany(ContentRequestStatusHistory::class)->oldest('created_at')->oldest('id');
    }

    /** @return HasMany<ContentRequestSourceLink, $this> */
    public function sourceLinks(): HasMany
    {
        return $this->hasMany(ContentRequestSourceLink::class);
    }

    /** @return HasMany<ContentRequestExternalIdentifier, $this> */
    public function externalIdentifiers(): HasMany
    {
        return $this->hasMany(ContentRequestExternalIdentifier::class);
    }

    /** @return HasMany<ContentRequestClarification, $this> */
    public function clarifications(): HasMany
    {
        return $this->hasMany(ContentRequestClarification::class)->oldest('created_at')->oldest('id');
    }

    /** @param Builder<ContentRequest> $query */
    public function scopePubliclyVisible(Builder $query): void
    {
        $query->where('is_public', true)
            ->whereNotIn('status', [ContentRequestStatus::Merged->value, ContentRequestStatus::Duplicate->value]);
    }

    protected function casts(): array
    {
        return [
            'type' => ContentRequestType::class,
            'status' => ContentRequestStatus::class,
            'priority' => ContentRequestPriority::class,
            'rejection_reason' => ContentRequestRejectionReason::class,
            'release_year' => 'integer',
            'season_number' => 'integer',
            'episode_number' => 'integer',
            'episode_release_date' => 'date',
            'probable_duplicate' => 'boolean',
            'is_public' => 'boolean',
            'version' => 'integer',
            'partial_completed_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
            'withdrawn_at' => 'immutable_datetime',
        ];
    }
}
