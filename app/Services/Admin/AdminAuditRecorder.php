<?php

declare(strict_types=1);

namespace App\Services\Admin;

use App\Enums\AdminAuditAction;
use App\Models\AdminAuditEvent;
use App\Models\CatalogCollection;
use App\Models\CatalogTitle;
use App\Models\CatalogTitleReview;
use App\Models\CatalogTitleReviewReport;
use App\Models\CatalogTitleReviewRestriction;
use App\Models\Comment;
use App\Models\CommentReport;
use App\Models\CommentRestriction;
use App\Models\Episode;
use App\Models\LicensedMedia;
use App\Models\Season;
use App\Models\Tag;
use App\Models\TagProviderMapping;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

final class AdminAuditRecorder
{
    public const ABSENT_VERSION = 'd72ca2df93c242920a805089b61c9b739f88251bbb6ed3f12d7aae84d607792e';

    /** @var array<class-string<Model>, string> */
    private const RESOURCE_TYPES = [
        CatalogTitle::class => 'catalog_title',
        Season::class => 'season',
        Episode::class => 'episode',
        LicensedMedia::class => 'licensed_media',
        CatalogCollection::class => 'catalog_collection',
        Comment::class => 'comment',
        CommentReport::class => 'comment_report',
        CommentRestriction::class => 'comment_restriction',
        CatalogTitleReview::class => 'catalog_title_review',
        CatalogTitleReviewReport::class => 'catalog_title_review_report',
        CatalogTitleReviewRestriction::class => 'catalog_title_review_restriction',
        Tag::class => 'tag',
        TagProviderMapping::class => 'tag_provider_mapping',
    ];

    /** @var list<string> */
    private const CHANGED_FIELDS = [
        'external_id',
        'name',
        'slug',
        'title',
        'original_title',
        'type',
        'year',
        'description',
        'poster_url',
        'is_published',
        'publication_status',
        'audience',
        'available_from',
        'available_until',
        'number',
        'kind',
        'sort_order',
        'released_at',
        'summary',
        'source',
        'quality',
        'translation_name',
        'format',
        'has_subtitles',
        'duration_seconds',
        'status',
        'relations.actor',
        'relations.director',
        'relations.genre',
        'relations.country',
        'relations.translation',
        'moderation_status',
        'is_featured',
        'report_status',
        'code',
        'visibility',
        'translations',
        'aliases',
        'synonyms',
        'provider_mapping_status',
        'merged_into_id',
        'archived_at',
        'archived_from_visibility',
        'archived_from_moderation_status',
        'comment_status',
        'review_status',
        'is_spoiler',
        'moderation_reason',
        'moderator_note',
        'deleted_at',
        'restriction_type',
        'reason_code',
        'starts_at',
        'expires_at',
        'revoked_at',
    ];

    /** @param array<int, mixed> $changedFields */
    public function record(
        User $actor,
        AdminAuditAction $action,
        Model $resource,
        string $beforeVersion,
        string $afterVersion,
        array $changedFields,
    ): void {
        $resourceType = self::RESOURCE_TYPES[$resource::class] ?? null;

        if ($resourceType === null || ! $resource->exists || ! is_int($resource->getKey())) {
            throw new InvalidArgumentException('Неподдерживаемый ресурс административного аудита.');
        }

        foreach ([$beforeVersion, $afterVersion] as $version) {
            if (preg_match('/^[a-f0-9]{64}$/D', $version) !== 1) {
                throw new InvalidArgumentException('Версия ресурса должна быть SHA-256 fingerprint.');
            }
        }

        $fields = array_values(array_unique($changedFields));

        if (count(array_filter($fields, 'is_string')) !== count($fields)
            || array_diff($fields, self::CHANGED_FIELDS) !== []) {
            throw new InvalidArgumentException('Передано неподдерживаемое поле административного аудита.');
        }

        sort($fields, SORT_STRING);

        AdminAuditEvent::query()->create([
            'actor_id' => $actor->id,
            'action' => $action,
            'resource_type' => $resourceType,
            'resource_id' => $resource->getKey(),
            'before_version' => $beforeVersion,
            'after_version' => $afterVersion,
            'changed_fields' => $fields,
            'occurred_at' => now(),
        ]);
    }
}
