<?php

declare(strict_types=1);

namespace App\Enums;

enum SeasonvarImportFinalizationStage: string
{
    case StorageMaintenance = 'storage_maintenance';
    case ProviderAvailability = 'provider_availability';
    case MetadataBackfill = 'metadata_backfill';
    case SourceStatus = 'source_status';
    case MediaMetadata = 'media_metadata';
    case MediaSourceKey = 'media_source_key';
    case MediaAvailability = 'media_availability';
    case MediaFileSize = 'media_file_size';
    case RelationCleanup = 'relation_cleanup';
    case Merge = 'merge';
    case Recommendations = 'recommendations';
    case RecommendationSignalPrune = 'recommendation_signal_prune';
    case Terminal = 'terminal';

    /** @return list<self> */
    public static function ordered(): array
    {
        return [
            self::StorageMaintenance,
            self::ProviderAvailability,
            self::MetadataBackfill,
            self::SourceStatus,
            self::MediaMetadata,
            self::MediaSourceKey,
            self::MediaAvailability,
            self::MediaFileSize,
            self::RelationCleanup,
            self::Merge,
            self::Recommendations,
            self::RecommendationSignalPrune,
            self::Terminal,
        ];
    }

    public function resultKey(): ?string
    {
        return match ($this) {
            self::StorageMaintenance => 'storage_maintenance',
            self::ProviderAvailability => 'provider_availability_backfill',
            self::MetadataBackfill => 'metadata_backfill',
            self::SourceStatus => 'source_status_backfill',
            self::MediaMetadata => 'media_metadata_backlog',
            self::MediaSourceKey => 'media_source_key_backlog',
            self::MediaAvailability => 'media_backlog',
            self::MediaFileSize => 'media_size_backlog',
            self::RelationCleanup => 'relation_cleanup',
            self::Merge => 'merge',
            self::Recommendations => 'recommendations',
            self::RecommendationSignalPrune => 'recommendation_signal_prune',
            self::Terminal => null,
        };
    }
}
