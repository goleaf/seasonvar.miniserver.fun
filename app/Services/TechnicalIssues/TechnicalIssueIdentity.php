<?php

declare(strict_types=1);

namespace App\Services\TechnicalIssues;

use App\DTOs\TechnicalIssues\SanitizedIssueText;
use App\DTOs\TechnicalIssues\TechnicalIssueInput;
use App\DTOs\TechnicalIssues\TechnicalIssueTargetData;
use App\Enums\TechnicalIssueType;
use App\Models\TechnicalIssue;
use App\Models\User;
use Illuminate\Support\Str;

final readonly class TechnicalIssueIdentity
{
    public function __construct(
        private TechnicalIssueTypeRegistry $types,
        private TechnicalIssueTextSanitizer $text,
    ) {}

    public function make(User $user, TechnicalIssueInput $input, TechnicalIssueTargetData $target): string
    {
        $dimensions = [
            'v2',
            $input->type->value,
            $target->type->value,
            $target->catalogTitleId,
            $target->seasonId,
            $target->episodeId,
            $target->licensedMediaId,
            $target->translationId,
            $target->featureCode,
            $target->routeName,
        ];

        if ($this->types->supportsTimestamp($input->type) && $input->playbackPositionSeconds !== null) {
            $dimensions[] = (int) floor($input->playbackPositionSeconds / 30);
        }

        if (in_array($input->type, [
            TechnicalIssueType::AudioMissing,
            TechnicalIssueType::AudioLanguageMismatch,
            TechnicalIssueType::AudioSync,
            TechnicalIssueType::TranslationStudioMismatch,
            TechnicalIssueType::SubtitlesMissing,
            TechnicalIssueType::SubtitleLanguageMismatch,
            TechnicalIssueType::SubtitleSync,
            TechnicalIssueType::SubtitleTextError,
        ], true)) {
            $dimensions[] = $input->audioLanguage;
            $dimensions[] = $input->subtitleLanguage;
        }

        if (in_array($input->type, [TechnicalIssueType::QualityUnavailable, TechnicalIssueType::QualityLabelMismatch], true)) {
            $dimensions[] = Str::lower((string) $input->qualityCode);
        }

        if (in_array($input->type, [
            TechnicalIssueType::BrowserCompatibility,
            TechnicalIssueType::MobileDeviceProblem,
            TechnicalIssueType::PageRenderingProblem,
            TechnicalIssueType::LivewireInteractionProblem,
            TechnicalIssueType::PerformanceProblem,
        ], true)) {
            $dimensions[] = $input->browserFamily;
            $dimensions[] = $input->operatingSystem;
            $dimensions[] = $input->deviceCategory;
        }

        if ($input->publicErrorCode !== null) {
            $dimensions[] = Str::upper($input->publicErrorCode);
        }

        if ($this->types->requesterPrivate($input->type, $target->type)) {
            $dimensions[] = 'requester:'.$user->id;
        }

        $evidence = $this->evidenceFingerprint(
            $input->summary,
            $input->expectedBehavior,
            $input->actualBehavior,
            $input->reproductionSteps,
        );

        if ($evidence !== null) {
            $dimensions[] = $evidence;
        }

        return hash('sha256', implode('|', array_map(static fn (mixed $value): string => $value === null ? '-' : (string) $value, $dimensions)));
    }

    public function fromIssue(TechnicalIssue $issue, ?int $requesterId = null): string
    {
        $dimensions = [
            'v2',
            $issue->type->value,
            $issue->target_type->value,
            $issue->catalog_title_id,
            $issue->season_id,
            $issue->episode_id,
            $issue->licensed_media_id,
            $issue->translation_id,
            $issue->feature_code,
            $issue->route_name,
        ];

        if ($this->types->supportsTimestamp($issue->type) && $issue->playback_position_seconds !== null) {
            $dimensions[] = (int) floor($issue->playback_position_seconds / 30);
        }

        if (in_array($issue->type, [
            TechnicalIssueType::AudioMissing,
            TechnicalIssueType::AudioLanguageMismatch,
            TechnicalIssueType::AudioSync,
            TechnicalIssueType::TranslationStudioMismatch,
            TechnicalIssueType::SubtitlesMissing,
            TechnicalIssueType::SubtitleLanguageMismatch,
            TechnicalIssueType::SubtitleSync,
            TechnicalIssueType::SubtitleTextError,
        ], true)) {
            $dimensions[] = $issue->audio_language;
            $dimensions[] = $issue->subtitle_language;
        }

        if (in_array($issue->type, [TechnicalIssueType::QualityUnavailable, TechnicalIssueType::QualityLabelMismatch], true)) {
            $dimensions[] = Str::lower((string) $issue->quality_code);
        }

        if (in_array($issue->type, [
            TechnicalIssueType::BrowserCompatibility,
            TechnicalIssueType::MobileDeviceProblem,
            TechnicalIssueType::PageRenderingProblem,
            TechnicalIssueType::LivewireInteractionProblem,
            TechnicalIssueType::PerformanceProblem,
        ], true)) {
            $dimensions[] = $issue->diagnostic?->browser_family;
            $dimensions[] = $issue->diagnostic?->operating_system;
            $dimensions[] = $issue->diagnostic?->device_category;
        }

        if ($issue->public_error_code !== null) {
            $dimensions[] = Str::upper($issue->public_error_code);
        }

        if ($this->types->requesterPrivate($issue->type, $issue->target_type)) {
            $dimensions[] = 'requester:'.($requesterId ?? $issue->requester_id ?? 0);
        }

        $evidence = $this->evidenceFingerprint(
            $issue->summary,
            $issue->expected_behavior,
            $issue->actual_behavior,
            $issue->reproduction_steps,
        );

        if ($evidence !== null) {
            $dimensions[] = $evidence;
        }

        if ($this->text->containsStoredMarker(
            $issue->summary,
            $issue->expected_behavior,
            $issue->actual_behavior,
            $issue->reproduction_steps,
        )) {
            $dimensions[] = 'redacted-issue:'.$issue->id;
        }

        return hash('sha256', implode('|', array_map(static fn (mixed $value): string => $value === null ? '-' : (string) $value, $dimensions)));
    }

    private function evidenceFingerprint(
        mixed $summary,
        mixed $expectedBehavior,
        mixed $actualBehavior,
        mixed $reproductionSteps,
    ): ?string {
        $values = [
            'summary' => $this->text->summary($summary),
            'expected' => $this->text->body($expectedBehavior, 4000),
            'actual' => $this->text->body($actualBehavior, 4000),
            'steps' => $this->text->body($reproductionSteps, 6000),
        ];

        if (! collect($values)->contains(static fn (SanitizedIssueText $value): bool => $value->value !== null)) {
            return null;
        }

        $normalized = array_map(static function (SanitizedIssueText $value): array {
            if ($value->value === null) {
                return [
                    'value' => null,
                    'source_hash' => $value->redactionReasons === [] ? null : $value->beforeHash,
                ];
            }

            $collapsed = preg_replace('/\s+/u', ' ', Str::lower($value->value));

            return [
                'value' => is_string($collapsed) ? trim($collapsed) : Str::lower($value->value),
                'source_hash' => $value->redactionReasons === [] ? null : $value->beforeHash,
            ];
        }, $values);

        return 'evidence:'.hash('sha256', (string) json_encode(
            $normalized,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE,
        ));
    }
}
