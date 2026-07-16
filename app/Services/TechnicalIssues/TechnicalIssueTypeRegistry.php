<?php

declare(strict_types=1);

namespace App\Services\TechnicalIssues;

use App\DTOs\TechnicalIssues\TechnicalIssueInput;
use App\DTOs\TechnicalIssues\TechnicalIssueTargetData;
use App\Enums\TechnicalIssueResolutionType;
use App\Enums\TechnicalIssueSeverity;
use App\Enums\TechnicalIssueTargetType;
use App\Enums\TechnicalIssueType;
use App\Exceptions\TechnicalIssues\TechnicalIssueActionException;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

final class TechnicalIssueTypeRegistry
{
    /** @return array{targets: list<TechnicalIssueTargetType>, severity: TechnicalIssueSeverity, team: string, requires_actual: bool, requires_steps: bool, timestamp: bool, requires_timestamp: bool, requires_audio: bool, requires_subtitle: bool, requires_quality: bool, resolutions: list<TechnicalIssueResolutionType>} */
    public function rule(TechnicalIssueType $type): array
    {
        $playbackTargets = [TechnicalIssueTargetType::Media, TechnicalIssueTargetType::Episode];
        $pageTargets = [TechnicalIssueTargetType::Page, TechnicalIssueTargetType::General];
        $defaultResolutions = [
            TechnicalIssueResolutionType::Fixed,
            TechnicalIssueResolutionType::ConfigurationCorrected,
            TechnicalIssueResolutionType::CannotReproduce,
            TechnicalIssueResolutionType::UnsupportedEnvironment,
            TechnicalIssueResolutionType::IntendedBehavior,
            TechnicalIssueResolutionType::ExternalProviderIssue,
        ];

        $rule = [
            'targets' => $pageTargets,
            'severity' => TechnicalIssueSeverity::Medium,
            'team' => 'support',
            'requires_actual' => true,
            'requires_steps' => true,
            'timestamp' => false,
            'requires_timestamp' => false,
            'requires_audio' => false,
            'requires_subtitle' => false,
            'requires_quality' => false,
            'resolutions' => $defaultResolutions,
        ];

        if (in_array($type, [
            TechnicalIssueType::VideoUnavailable,
            TechnicalIssueType::VideoLoadingFailure,
            TechnicalIssueType::PlaybackStops,
            TechnicalIssueType::ExcessiveBuffering,
            TechnicalIssueType::WrongVideo,
            TechnicalIssueType::QualityUnavailable,
            TechnicalIssueType::QualityLabelMismatch,
            TechnicalIssueType::FullscreenProblem,
            TechnicalIssueType::AutoplayProblem,
            TechnicalIssueType::PlayerControlsProblem,
        ], true)) {
            $rule = [
                ...$rule,
                'targets' => $playbackTargets,
                'severity' => TechnicalIssueSeverity::Medium,
                'team' => 'video',
                'requires_actual' => ! in_array($type, [TechnicalIssueType::VideoUnavailable, TechnicalIssueType::VideoLoadingFailure], true),
                'requires_steps' => false,
                'timestamp' => in_array($type, [TechnicalIssueType::PlaybackStops, TechnicalIssueType::ExcessiveBuffering, TechnicalIssueType::WrongVideo], true),
                'requires_timestamp' => false,
                'requires_quality' => in_array($type, [TechnicalIssueType::QualityUnavailable, TechnicalIssueType::QualityLabelMismatch], true),
                'resolutions' => [
                    TechnicalIssueResolutionType::Fixed,
                    TechnicalIssueResolutionType::SourceReplaced,
                    TechnicalIssueResolutionType::FallbackEnabled,
                    TechnicalIssueResolutionType::ConfigurationCorrected,
                    TechnicalIssueResolutionType::CannotReproduce,
                    TechnicalIssueResolutionType::ExternalProviderIssue,
                ],
            ];
        }

        if (in_array($type, [
            TechnicalIssueType::WrongEpisode,
            TechnicalIssueType::WrongSeason,
            TechnicalIssueType::DuplicateEpisode,
            TechnicalIssueType::MissingEpisode,
            TechnicalIssueType::IncorrectEpisodeOrder,
            TechnicalIssueType::IncorrectEpisodeNumber,
            TechnicalIssueType::MetadataError,
            TechnicalIssueType::ImageProblem,
        ], true)) {
            $contentTargets = match ($type) {
                TechnicalIssueType::WrongEpisode => [TechnicalIssueTargetType::Media, TechnicalIssueTargetType::Episode],
                TechnicalIssueType::WrongSeason => [TechnicalIssueTargetType::Media, TechnicalIssueTargetType::Episode, TechnicalIssueTargetType::Season],
                TechnicalIssueType::DuplicateEpisode,
                TechnicalIssueType::MissingEpisode,
                TechnicalIssueType::IncorrectEpisodeOrder => [TechnicalIssueTargetType::Season, TechnicalIssueTargetType::Title],
                TechnicalIssueType::IncorrectEpisodeNumber => [TechnicalIssueTargetType::Episode],
                TechnicalIssueType::MetadataError,
                TechnicalIssueType::ImageProblem => [TechnicalIssueTargetType::Title, TechnicalIssueTargetType::Season, TechnicalIssueTargetType::Episode],
            };

            $rule = [
                ...$rule,
                'targets' => $contentTargets,
                'severity' => $type === TechnicalIssueType::ImageProblem ? TechnicalIssueSeverity::Low : TechnicalIssueSeverity::Medium,
                'team' => 'content',
                'requires_actual' => true,
                'requires_steps' => false,
                'resolutions' => [
                    TechnicalIssueResolutionType::MetadataCorrected,
                    TechnicalIssueResolutionType::EpisodeMappingCorrected,
                    TechnicalIssueResolutionType::CannotReproduce,
                    TechnicalIssueResolutionType::IntendedBehavior,
                ],
            ];
        }

        if (in_array($type, [
            TechnicalIssueType::AudioMissing,
            TechnicalIssueType::AudioLanguageMismatch,
            TechnicalIssueType::AudioSync,
            TechnicalIssueType::TranslationStudioMismatch,
            TechnicalIssueType::SubtitlesMissing,
            TechnicalIssueType::SubtitleLanguageMismatch,
            TechnicalIssueType::SubtitleSync,
            TechnicalIssueType::SubtitleTextError,
        ], true)) {
            $rule = [
                ...$rule,
                'targets' => $playbackTargets,
                'severity' => TechnicalIssueSeverity::Medium,
                'team' => 'subtitles',
                'requires_actual' => true,
                'requires_steps' => false,
                'timestamp' => in_array($type, [TechnicalIssueType::AudioSync, TechnicalIssueType::SubtitleSync, TechnicalIssueType::SubtitleTextError], true),
                'requires_timestamp' => in_array($type, [TechnicalIssueType::AudioSync, TechnicalIssueType::SubtitleSync, TechnicalIssueType::SubtitleTextError], true),
                'requires_audio' => in_array($type, [TechnicalIssueType::AudioMissing, TechnicalIssueType::AudioLanguageMismatch, TechnicalIssueType::AudioSync, TechnicalIssueType::TranslationStudioMismatch], true),
                'requires_subtitle' => in_array($type, [TechnicalIssueType::SubtitlesMissing, TechnicalIssueType::SubtitleLanguageMismatch, TechnicalIssueType::SubtitleSync, TechnicalIssueType::SubtitleTextError], true),
                'resolutions' => [
                    TechnicalIssueResolutionType::SubtitleCorrected,
                    TechnicalIssueResolutionType::AudioCorrected,
                    TechnicalIssueResolutionType::SourceReplaced,
                    TechnicalIssueResolutionType::CannotReproduce,
                ],
            ];
        }

        if (in_array($type, [
            TechnicalIssueType::ProgressNotSaved,
            TechnicalIssueType::ProgressIncorrect,
            TechnicalIssueType::ContinueWatchingProblem,
        ], true)) {
            $rule = [...$rule, 'targets' => [...$playbackTargets, TechnicalIssueTargetType::Page, TechnicalIssueTargetType::Account], 'team' => 'support', 'timestamp' => true];
        }

        if (in_array($type, [TechnicalIssueType::SearchProblem, TechnicalIssueType::FilterProblem], true)) {
            $rule = [...$rule, 'targets' => [TechnicalIssueTargetType::Search, TechnicalIssueTargetType::Page], 'team' => 'support'];
        }

        if ($type === TechnicalIssueType::NotificationProblem) {
            $rule = [...$rule, 'targets' => [TechnicalIssueTargetType::Notification, TechnicalIssueTargetType::Account], 'team' => 'support'];
        }

        if ($type === TechnicalIssueType::CalendarProblem) {
            $rule = [...$rule, 'targets' => [TechnicalIssueTargetType::Calendar, TechnicalIssueTargetType::Page, TechnicalIssueTargetType::General], 'team' => 'content'];
        }

        if (in_array($type, [TechnicalIssueType::AccountProblem, TechnicalIssueType::RegionalAccessProblem, TechnicalIssueType::PremiumAccessProblem], true)) {
            $rule = [
                ...$rule,
                'targets' => [TechnicalIssueTargetType::Account, TechnicalIssueTargetType::Media],
                'team' => 'accounts',
                'resolutions' => [...$defaultResolutions, TechnicalIssueResolutionType::UserSettingGuidance],
            ];
        }

        if ($type === TechnicalIssueType::AccessibilityProblem) {
            $rule = [
                ...$rule,
                'targets' => [...$pageTargets, TechnicalIssueTargetType::Media, TechnicalIssueTargetType::Episode],
                'team' => 'accessibility',
                'severity' => TechnicalIssueSeverity::Medium,
            ];
        }

        if (in_array($type, [TechnicalIssueType::PerformanceProblem, TechnicalIssueType::PageRenderingProblem, TechnicalIssueType::LivewireInteractionProblem], true)) {
            $rule = [...$rule, 'targets' => $pageTargets, 'team' => 'infrastructure'];
        }

        if (in_array($type, [TechnicalIssueType::BrowserCompatibility, TechnicalIssueType::MobileDeviceProblem], true)) {
            $rule = [...$rule, 'targets' => [...$pageTargets, TechnicalIssueTargetType::Media], 'team' => 'support'];
        }

        if (in_array($type, [TechnicalIssueType::BrokenInternalLink, TechnicalIssueType::BrokenExternalReference], true)) {
            $rule = [...$rule, 'targets' => $pageTargets, 'severity' => TechnicalIssueSeverity::Low, 'requires_steps' => false];
        }

        if ($type === TechnicalIssueType::OtherTechnicalIssue) {
            $rule['targets'] = TechnicalIssueTargetType::cases();
        }

        return $rule;
    }

    public function assert(TechnicalIssueInput $input, TechnicalIssueTargetData $target): void
    {
        $rule = $this->rule($input->type);

        if (! in_array($target->type, $rule['targets'], true)) {
            throw new TechnicalIssueActionException('issues.errors.invalid_target');
        }

        $validator = Validator::make([
            'summary' => $input->summary,
            'expected' => $input->expectedBehavior,
            'actual' => $input->actualBehavior,
            'steps' => $input->reproductionSteps,
            'position' => $input->playbackPositionSeconds,
            'audio_language' => $input->audioLanguage,
            'subtitle_language' => $input->subtitleLanguage,
            'quality' => $input->qualityCode,
            'error_code' => $input->publicErrorCode,
            'browser' => $input->browserFamily,
            'browser_major' => $input->browserMajor,
            'operating_system' => $input->operatingSystem,
            'device' => $input->deviceCategory,
            'viewport_width' => $input->viewportWidth,
            'viewport_height' => $input->viewportHeight,
            'timezone' => $input->timezone,
            'network_online' => $input->networkOnline,
        ], [
            'summary' => ['nullable', 'string', 'min:4', 'max:240'],
            'expected' => ['nullable', 'string', 'max:4000'],
            'actual' => [$rule['requires_actual'] ? 'required' : 'nullable', 'string', 'max:4000'],
            'steps' => [$rule['requires_steps'] ? 'required' : 'nullable', 'string', 'max:6000'],
            'position' => [$rule['requires_timestamp'] ? 'required' : 'nullable', 'integer', 'min:0', 'max:86400'],
            'audio_language' => [$rule['requires_audio'] ? 'required' : 'nullable', 'string', 'max:16', 'regex:/^[a-z]{2,3}(?:-[A-Z]{2})?$/D'],
            'subtitle_language' => [$rule['requires_subtitle'] ? 'required' : 'nullable', 'string', 'max:16', 'regex:/^[a-z]{2,3}(?:-[A-Z]{2})?$/D'],
            'quality' => [$rule['requires_quality'] ? 'required' : 'nullable', 'string', 'max:24', 'regex:/^(?:\d{3,4}p|auto|source|4k|uhd|hd|sd)$/Di'],
            'error_code' => ['nullable', 'string', 'max:48', 'regex:/^[A-Za-z0-9._-]+$/D'],
            'browser' => ['nullable', Rule::in(config('technical-issues.browser_families', []))],
            'browser_major' => ['nullable', 'integer', 'min:1', 'max:999'],
            'operating_system' => ['nullable', Rule::in(config('technical-issues.operating_systems', []))],
            'device' => ['nullable', Rule::in(config('technical-issues.device_categories', []))],
            'viewport_width' => ['nullable', 'integer', 'min:1', 'max:10000'],
            'viewport_height' => ['nullable', 'integer', 'min:1', 'max:10000'],
            'timezone' => ['nullable', 'timezone', 'max:64'],
            'network_online' => ['nullable', 'boolean'],
        ], [
            'required' => __('issues.validation.required'),
            'max' => __('issues.validation.max'),
            'min' => __('issues.validation.min'),
            'regex' => __('issues.validation.invalid'),
            'in' => __('issues.validation.invalid'),
            'timezone' => __('issues.validation.invalid'),
        ]);

        if ($validator->fails()) {
            throw new TechnicalIssueActionException('issues.errors.invalid_input');
        }

        if ($rule['requires_quality'] && $target->selectedQualityCode !== null
            && mb_strtolower((string) $input->qualityCode) !== mb_strtolower($target->selectedQualityCode)) {
            throw new TechnicalIssueActionException('issues.errors.invalid_input');
        }
    }

    public function allowlistedInput(TechnicalIssueInput $input): TechnicalIssueInput
    {
        $rule = $this->rule($input->type);
        $diagnosticsConsent = $input->diagnosticsConsent;

        return new TechnicalIssueInput(
            type: $input->type,
            contextToken: $input->contextToken,
            featureCode: $input->featureCode,
            summary: $input->summary,
            expectedBehavior: $input->expectedBehavior,
            actualBehavior: $input->actualBehavior,
            reproductionSteps: $input->reproductionSteps,
            playbackPositionSeconds: $rule['timestamp'] ? $input->playbackPositionSeconds : null,
            audioLanguage: $rule['requires_audio'] ? $input->audioLanguage : null,
            subtitleLanguage: $rule['requires_subtitle'] ? $input->subtitleLanguage : null,
            qualityCode: $rule['requires_quality'] ? $input->qualityCode : null,
            publicErrorCode: $input->publicErrorCode,
            diagnosticsConsent: $diagnosticsConsent,
            browserFamily: $diagnosticsConsent ? $input->browserFamily : null,
            browserMajor: $diagnosticsConsent ? $input->browserMajor : null,
            operatingSystem: $diagnosticsConsent ? $input->operatingSystem : null,
            deviceCategory: $diagnosticsConsent ? $input->deviceCategory : null,
            viewportWidth: $diagnosticsConsent ? $input->viewportWidth : null,
            viewportHeight: $diagnosticsConsent ? $input->viewportHeight : null,
            timezone: $diagnosticsConsent ? $input->timezone : null,
            networkOnline: $diagnosticsConsent ? $input->networkOnline : null,
            submissionToken: $input->submissionToken,
        );
    }

    public function defaultSeverity(TechnicalIssueType $type): TechnicalIssueSeverity
    {
        return $this->rule($type)['severity'];
    }

    public function supportTeam(TechnicalIssueType $type): string
    {
        return $this->rule($type)['team'];
    }

    public function supportsTimestamp(TechnicalIssueType $type): bool
    {
        return $this->rule($type)['timestamp'];
    }

    /** @return list<TechnicalIssueResolutionType> */
    public function resolutions(TechnicalIssueType $type): array
    {
        return $this->rule($type)['resolutions'];
    }

    public function requesterPrivate(TechnicalIssueType $type, ?TechnicalIssueTargetType $targetType = null): bool
    {
        return in_array($targetType, [TechnicalIssueTargetType::Account, TechnicalIssueTargetType::Notification], true)
            || in_array($type, [
                TechnicalIssueType::AccountProblem,
                TechnicalIssueType::NotificationProblem,
                TechnicalIssueType::RegionalAccessProblem,
                TechnicalIssueType::PremiumAccessProblem,
                TechnicalIssueType::ProgressNotSaved,
                TechnicalIssueType::ProgressIncorrect,
                TechnicalIssueType::ContinueWatchingProblem,
            ], true);
    }
}
