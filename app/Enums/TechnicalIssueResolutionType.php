<?php

declare(strict_types=1);

namespace App\Enums;

enum TechnicalIssueResolutionType: string
{
    case Fixed = 'fixed';
    case SourceReplaced = 'source_replaced';
    case FallbackEnabled = 'fallback_enabled';
    case MetadataCorrected = 'metadata_corrected';
    case EpisodeMappingCorrected = 'episode_mapping_corrected';
    case SubtitleCorrected = 'subtitle_corrected';
    case AudioCorrected = 'audio_corrected';
    case ConfigurationCorrected = 'configuration_corrected';
    case UserSettingGuidance = 'user_setting_guidance';
    case CannotReproduce = 'cannot_reproduce';
    case Duplicate = 'duplicate';
    case ExternalProviderIssue = 'external_provider_issue';
    case UnsupportedEnvironment = 'unsupported_environment';
    case IntendedBehavior = 'intended_behavior';
    case Rejected = 'rejected';

    public function label(): string
    {
        return __("issues.resolutions.{$this->value}");
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $type): string => $type->value, self::cases());
    }
}
