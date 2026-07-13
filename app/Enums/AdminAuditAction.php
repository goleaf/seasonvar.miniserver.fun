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
}
