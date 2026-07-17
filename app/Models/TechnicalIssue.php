<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\TechnicalIssuePriority;
use App\Enums\TechnicalIssueResolutionType;
use App\Enums\TechnicalIssueSeverity;
use App\Enums\TechnicalIssueStatus;
use App\Enums\TechnicalIssueTargetType;
use App\Enums\TechnicalIssueType;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * @property TechnicalIssueType $type
 * @property TechnicalIssueStatus $status
 * @property TechnicalIssueSeverity $severity
 * @property TechnicalIssuePriority $priority
 * @property int $severity_sort_rank
 * @property int $priority_sort_rank
 * @property TechnicalIssueTargetType $target_type
 * @property TechnicalIssueResolutionType|null $resolution_type
 * @property bool $diagnostics_consent
 * @property int|null $playback_position_seconds
 * @property int $version
 * @property int $reopen_count
 * @property CarbonImmutable|null $last_public_message_at
 * @property CarbonImmutable|null $resolved_at
 * @property CarbonImmutable|null $verified_at
 * @property CarbonImmutable|null $closed_at
 * @property CarbonImmutable|null $withdrawn_at
 * @property-read User|null $requester
 * @property-read LicensedMedia|null $licensedMedia
 * @property-read TechnicalIssue|null $mergedInto
 * @property-read TechnicalIssueDiagnostic|null $diagnostic
 */
#[Fillable([
    'public_id',
    'public_number',
    'requester_id',
    'assigned_to_id',
    'support_team',
    'type',
    'status',
    'severity',
    'priority',
    'severity_sort_rank',
    'priority_sort_rank',
    'target_type',
    'target_label_snapshot',
    'catalog_title_id',
    'season_id',
    'episode_id',
    'licensed_media_id',
    'translation_id',
    'feature_code',
    'route_name',
    'route_path',
    'locale',
    'summary',
    'expected_behavior',
    'actual_behavior',
    'reproduction_steps',
    'playback_position_seconds',
    'audio_language',
    'subtitle_language',
    'quality_code',
    'public_error_code',
    'diagnostics_consent',
    'exact_identity_hash',
    'active_identity_key',
    'submission_key',
    'merged_into_id',
    'resolution_type',
    'resolution_summary',
    'rejection_reason',
    'rerouted_to',
    'version',
    'reopen_count',
    'last_public_message_at',
    'resolved_at',
    'verified_at',
    'closed_at',
    'withdrawn_at',
])]
final class TechnicalIssue extends Model
{
    /** @var array<string, mixed> */
    protected $attributes = [
        'severity_sort_rank' => 2,
        'priority_sort_rank' => 2,
    ];

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    /** @return BelongsTo<User, $this> */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requester_id');
    }

    /** @return BelongsTo<User, $this> */
    public function assignedTo(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to_id');
    }

    /** @return BelongsTo<CatalogTitle, $this> */
    public function catalogTitle(): BelongsTo
    {
        return $this->belongsTo(CatalogTitle::class);
    }

    /** @return BelongsTo<Season, $this> */
    public function season(): BelongsTo
    {
        return $this->belongsTo(Season::class);
    }

    /** @return BelongsTo<Episode, $this> */
    public function episode(): BelongsTo
    {
        return $this->belongsTo(Episode::class);
    }

    /** @return BelongsTo<LicensedMedia, $this> */
    public function licensedMedia(): BelongsTo
    {
        return $this->belongsTo(LicensedMedia::class);
    }

    /** @return BelongsTo<Translation, $this> */
    public function translation(): BelongsTo
    {
        return $this->belongsTo(Translation::class);
    }

    /** @return BelongsTo<TechnicalIssue, $this> */
    public function mergedInto(): BelongsTo
    {
        return $this->belongsTo(self::class, 'merged_into_id');
    }

    /** @return HasMany<TechnicalIssue, $this> */
    public function mergedDuplicates(): HasMany
    {
        return $this->hasMany(self::class, 'merged_into_id');
    }

    /** @return HasOne<TechnicalIssueDiagnostic, $this> */
    public function diagnostic(): HasOne
    {
        return $this->hasOne(TechnicalIssueDiagnostic::class);
    }

    /** @return HasMany<TechnicalIssueMessage, $this> */
    public function messages(): HasMany
    {
        return $this->hasMany(TechnicalIssueMessage::class)->oldest('created_at')->oldest('id');
    }

    /** @return HasMany<TechnicalIssueAttachment, $this> */
    public function attachments(): HasMany
    {
        return $this->hasMany(TechnicalIssueAttachment::class)->oldest('created_at')->oldest('id');
    }

    /** @return HasMany<TechnicalIssueStatusHistory, $this> */
    public function statusHistory(): HasMany
    {
        return $this->hasMany(TechnicalIssueStatusHistory::class)->oldest('created_at')->oldest('id');
    }

    /** @return HasMany<TechnicalIssueAssignment, $this> */
    public function assignments(): HasMany
    {
        return $this->hasMany(TechnicalIssueAssignment::class)->oldest('created_at')->oldest('id');
    }

    /** @return HasMany<TechnicalIssueConfirmation, $this> */
    public function confirmations(): HasMany
    {
        return $this->hasMany(TechnicalIssueConfirmation::class);
    }

    /** @return HasMany<TechnicalIssueFollower, $this> */
    public function followers(): HasMany
    {
        return $this->hasMany(TechnicalIssueFollower::class);
    }

    /** @return HasMany<TechnicalIssueOccurrence, $this> */
    public function occurrences(): HasMany
    {
        return $this->hasMany(TechnicalIssueOccurrence::class);
    }

    /** @return HasMany<TechnicalIssueRedaction, $this> */
    public function redactions(): HasMany
    {
        return $this->hasMany(TechnicalIssueRedaction::class);
    }

    /** @return HasMany<TechnicalIssueSourceAction, $this> */
    public function sourceActions(): HasMany
    {
        return $this->hasMany(TechnicalIssueSourceAction::class);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereIn('status', collect(TechnicalIssueStatus::cases())
            ->filter(fn (TechnicalIssueStatus $status): bool => $status->isOpen())
            ->map(fn (TechnicalIssueStatus $status): string => $status->value));
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'type' => TechnicalIssueType::class,
            'status' => TechnicalIssueStatus::class,
            'severity' => TechnicalIssueSeverity::class,
            'priority' => TechnicalIssuePriority::class,
            'severity_sort_rank' => 'integer',
            'priority_sort_rank' => 'integer',
            'target_type' => TechnicalIssueTargetType::class,
            'resolution_type' => TechnicalIssueResolutionType::class,
            'diagnostics_consent' => 'boolean',
            'playback_position_seconds' => 'integer',
            'version' => 'integer',
            'reopen_count' => 'integer',
            'last_public_message_at' => 'immutable_datetime',
            'resolved_at' => 'immutable_datetime',
            'verified_at' => 'immutable_datetime',
            'closed_at' => 'immutable_datetime',
            'withdrawn_at' => 'immutable_datetime',
        ];
    }
}
