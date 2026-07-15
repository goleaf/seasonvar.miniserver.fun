<?php

declare(strict_types=1);

namespace App\Enums;

enum AdminAuditAction: string
{
    case TitleUpdated = 'title.updated';
    case TitleArchived = 'title.archived';
    case RelationAttached = 'relation.attached';
    case RelationDetached = 'relation.detached';
    case LookupCreated = 'lookup.created';
    case SeasonCreated = 'season.created';
    case SeasonUpdated = 'season.updated';
    case SeasonArchived = 'season.archived';
    case EpisodeCreated = 'episode.created';
    case EpisodeUpdated = 'episode.updated';
    case EpisodeArchived = 'episode.archived';
    case MediaCreated = 'media.created';
    case MediaUpdated = 'media.updated';
    case MediaArchived = 'media.archived';
    case CollectionModerated = 'collection.moderated';
    case CollectionFeatured = 'collection.featured';
    case CollectionReportResolved = 'collection.report_resolved';
    case CommentModerated = 'comment.moderated';
    case CommentReportResolved = 'comment.report_resolved';
    case CommentRestrictionApplied = 'comment.restriction_applied';
    case CommentRestrictionRevoked = 'comment.restriction_revoked';
    case ReviewModerated = 'review.moderated';
    case ReviewReportResolved = 'review.report_resolved';
    case ReviewRestrictionApplied = 'review.restriction_applied';
    case ReviewRestrictionRevoked = 'review.restriction_revoked';
    case TagCreated = 'tag.created';
    case TagUpdated = 'tag.updated';
    case TagArchived = 'tag.archived';
    case TagRestored = 'tag.restored';
    case TagMerged = 'tag.merged';
    case TagTranslationUpdated = 'tag.translation_updated';
    case TagAliasUpdated = 'tag.alias_updated';
    case TagSynonymUpdated = 'tag.synonym_updated';
    case TagProviderMappingUpdated = 'tag.provider_mapping_updated';
}
