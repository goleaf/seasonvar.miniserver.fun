<?php

declare(strict_types=1);

namespace App\Enums;

enum TechnicalIssueType: string
{
    case VideoUnavailable = 'video_unavailable';
    case VideoLoadingFailure = 'video_loading_failure';
    case PlaybackStops = 'playback_stops';
    case ExcessiveBuffering = 'excessive_buffering';
    case WrongVideo = 'wrong_video';
    case WrongEpisode = 'wrong_episode';
    case WrongSeason = 'wrong_season';
    case DuplicateEpisode = 'duplicate_episode';
    case MissingEpisode = 'missing_episode';
    case IncorrectEpisodeOrder = 'incorrect_episode_order';
    case IncorrectEpisodeNumber = 'incorrect_episode_number';
    case AudioMissing = 'audio_missing';
    case AudioLanguageMismatch = 'audio_language_mismatch';
    case AudioSync = 'audio_sync';
    case TranslationStudioMismatch = 'translation_studio_mismatch';
    case SubtitlesMissing = 'subtitles_missing';
    case SubtitleLanguageMismatch = 'subtitle_language_mismatch';
    case SubtitleSync = 'subtitle_sync';
    case SubtitleTextError = 'subtitle_text_error';
    case QualityUnavailable = 'quality_unavailable';
    case QualityLabelMismatch = 'quality_label_mismatch';
    case FullscreenProblem = 'fullscreen_problem';
    case AutoplayProblem = 'autoplay_problem';
    case PlayerControlsProblem = 'player_controls_problem';
    case ProgressNotSaved = 'progress_not_saved';
    case ProgressIncorrect = 'progress_incorrect';
    case ContinueWatchingProblem = 'continue_watching_problem';
    case BrowserCompatibility = 'browser_compatibility';
    case MobileDeviceProblem = 'mobile_device_problem';
    case PageRenderingProblem = 'page_rendering_problem';
    case LivewireInteractionProblem = 'livewire_interaction_problem';
    case BrokenInternalLink = 'broken_internal_link';
    case BrokenExternalReference = 'broken_external_reference';
    case ImageProblem = 'image_problem';
    case SearchProblem = 'search_problem';
    case FilterProblem = 'filter_problem';
    case NotificationProblem = 'notification_problem';
    case CalendarProblem = 'calendar_problem';
    case AccountProblem = 'account_problem';
    case RegionalAccessProblem = 'regional_access_problem';
    case PremiumAccessProblem = 'premium_access_problem';
    case AccessibilityProblem = 'accessibility_problem';
    case PerformanceProblem = 'performance_problem';
    case MetadataError = 'metadata_error';
    case OtherTechnicalIssue = 'other_technical_issue';

    public function label(): string
    {
        return __("issues.types.{$this->value}.title");
    }

    public function help(): string
    {
        return __("issues.types.{$this->value}.help");
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $type): string => $type->value, self::cases());
    }
}
