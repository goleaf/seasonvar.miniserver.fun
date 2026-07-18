<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\HelpArticleType;
use App\Enums\HelpAudience;
use App\Enums\HelpEscalationType;
use App\Enums\HelpFeature;
use App\Enums\HelpOwnerTeam;
use App\Enums\HelpPublicationStatus;
use App\Policies\HelpArticlePolicy;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property HelpArticleType $type
 * @property HelpAudience $audience
 * @property HelpPublicationStatus $status
 * @property HelpOwnerTeam $owner_team
 * @property HelpFeature $feature_code
 * @property HelpEscalationType $primary_escalation
 * @property HelpEscalationType $secondary_escalation
 * @property bool $is_featured
 * @property bool $is_indexable
 * @property int $content_version
 */
#[UsePolicy(HelpArticlePolicy::class)]
#[Fillable([
    'public_id', 'code', 'help_category_id', 'replacement_article_id', 'type', 'audience', 'status',
    'owner_team', 'feature_code', 'primary_escalation', 'secondary_escalation',
    'escalation_issue_type', 'escalation_request_type', 'position', 'editorial_priority',
    'is_featured', 'is_indexable', 'created_by_id', 'updated_by_id', 'approved_by_id',
    'content_version', 'published_at', 'last_reviewed_at', 'review_due_at',
])]
final class HelpArticle extends Model
{
    protected $attributes = [
        'audience' => HelpAudience::Everyone->value,
        'status' => HelpPublicationStatus::Draft->value,
        'feature_code' => HelpFeature::General->value,
        'primary_escalation' => HelpEscalationType::None->value,
        'secondary_escalation' => HelpEscalationType::None->value,
        'is_featured' => false,
        'is_indexable' => true,
        'content_version' => 1,
    ];

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    /** @return BelongsTo<HelpCategory, $this> */
    public function category(): BelongsTo
    {
        return $this->belongsTo(HelpCategory::class, 'help_category_id');
    }

    /** @return BelongsTo<HelpArticle, $this> */
    public function replacement(): BelongsTo
    {
        return $this->belongsTo(self::class, 'replacement_article_id');
    }

    /** @return HasMany<HelpArticleTranslation, $this> */
    public function translations(): HasMany
    {
        return $this->hasMany(HelpArticleTranslation::class);
    }

    /** @return HasMany<HelpArticleAlias, $this> */
    public function aliases(): HasMany
    {
        return $this->hasMany(HelpArticleAlias::class);
    }

    /** @return HasMany<HelpArticleRevision, $this> */
    public function revisions(): HasMany
    {
        return $this->hasMany(HelpArticleRevision::class)->latest('revision');
    }

    /** @return HasMany<HelpArticleFeedback, $this> */
    public function feedback(): HasMany
    {
        return $this->hasMany(HelpArticleFeedback::class);
    }

    /** @return HasMany<HelpArticleReport, $this> */
    public function reports(): HasMany
    {
        return $this->hasMany(HelpArticleReport::class);
    }

    /** @return BelongsToMany<HelpArticle, $this> */
    public function relatedArticles(): BelongsToMany
    {
        return $this->belongsToMany(
            self::class,
            'help_article_relations',
            'help_article_id',
            'related_article_id',
        )->withPivot('position')->orderByPivot('position')->orderBy('help_articles.id');
    }

    /** @param Builder<HelpArticle> $query */
    public function scopePublished(Builder $query): void
    {
        $query->where('status', HelpPublicationStatus::Published->value)
            ->whereNotNull('published_at')
            ->where('published_at', '<=', now());
    }

    /** @param Builder<HelpArticle> $query */
    public function scopePubliclyDiscoverable(Builder $query): void
    {
        $query->published()->whereIn('audience', [
            HelpAudience::Everyone->value,
            HelpAudience::Anonymous->value,
            HelpAudience::Authenticated->value,
            HelpAudience::Premium->value,
        ]);
    }

    protected function casts(): array
    {
        return [
            'type' => HelpArticleType::class,
            'audience' => HelpAudience::class,
            'status' => HelpPublicationStatus::class,
            'owner_team' => HelpOwnerTeam::class,
            'feature_code' => HelpFeature::class,
            'primary_escalation' => HelpEscalationType::class,
            'secondary_escalation' => HelpEscalationType::class,
            'position' => 'integer',
            'editorial_priority' => 'integer',
            'is_featured' => 'boolean',
            'is_indexable' => 'boolean',
            'content_version' => 'integer',
            'published_at' => 'immutable_datetime',
            'last_reviewed_at' => 'immutable_datetime',
            'review_due_at' => 'immutable_datetime',
        ];
    }
}
