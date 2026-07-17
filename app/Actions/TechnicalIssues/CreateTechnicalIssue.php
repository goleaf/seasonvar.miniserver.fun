<?php

declare(strict_types=1);

namespace App\Actions\TechnicalIssues;

use App\DTOs\TechnicalIssues\StoredTechnicalIssueAttachment;
use App\DTOs\TechnicalIssues\TechnicalIssueCreationResult;
use App\DTOs\TechnicalIssues\TechnicalIssueInput;
use App\Enums\TechnicalIssueDuplicateConfidence;
use App\Enums\TechnicalIssuePriority;
use App\Enums\TechnicalIssueStatus;
use App\Exceptions\TechnicalIssues\TechnicalIssueActionException;
use App\Models\TechnicalIssue;
use App\Models\TechnicalIssueAttachment;
use App\Models\TechnicalIssueConfirmation;
use App\Models\TechnicalIssueDiagnostic;
use App\Models\TechnicalIssueFollower;
use App\Models\TechnicalIssueRedaction;
use App\Models\TechnicalIssueStatusHistory;
use App\Models\User;
use App\Services\TechnicalIssues\TechnicalIssueAttachmentService;
use App\Services\TechnicalIssues\TechnicalIssueDuplicateService;
use App\Services\TechnicalIssues\TechnicalIssueIdentity;
use App\Services\TechnicalIssues\TechnicalIssueNotificationService;
use App\Services\TechnicalIssues\TechnicalIssueOccurrenceService;
use App\Services\TechnicalIssues\TechnicalIssueRateLimiter;
use App\Services\TechnicalIssues\TechnicalIssueSchema;
use App\Services\TechnicalIssues\TechnicalIssueTargetResolver;
use App\Services\TechnicalIssues\TechnicalIssueTextSanitizer;
use App\Services\TechnicalIssues\TechnicalIssueTypeRegistry;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Throwable;

final readonly class CreateTechnicalIssue
{
    public function __construct(
        private TechnicalIssueSchema $schema,
        private TechnicalIssueTargetResolver $targets,
        private TechnicalIssueTypeRegistry $types,
        private TechnicalIssueIdentity $identity,
        private TechnicalIssueDuplicateService $duplicates,
        private TechnicalIssueTextSanitizer $text,
        private TechnicalIssueAttachmentService $attachments,
        private TechnicalIssueRateLimiter $rateLimiter,
        private TechnicalIssueNotificationService $notifications,
        private TechnicalIssueOccurrenceService $occurrences,
    ) {}

    /** @param array<int, UploadedFile> $screenshots */
    public function handle(User $user, TechnicalIssueInput $input, array $screenshots = []): TechnicalIssueCreationResult
    {
        if (! $this->schema->ready()) {
            throw new TechnicalIssueActionException('issues.errors.action_unavailable');
        }

        Gate::forUser($user)->authorize('create', TechnicalIssue::class);

        if (! Str::isUuid($input->submissionToken)) {
            throw new TechnicalIssueActionException('issues.errors.invalid_submission');
        }

        $submissionKey = hash('sha256', $user->id.':'.Str::lower($input->submissionToken));
        $existingSubmission = TechnicalIssue::query()->where('submission_key', $submissionKey)->first();

        if ($existingSubmission instanceof TechnicalIssue) {
            return new TechnicalIssueCreationResult($existingSubmission, false);
        }

        $this->rateLimiter->ensure($user, 'create');
        $target = $this->targets->resolve($user, $input->contextToken, $input->featureCode);
        $input = $this->types->allowlistedInput($input);
        $input = $this->clampedInput($input, $target->knownDurationSeconds);
        $this->types->assert($input, $target);
        $summary = $this->text->summary($input->summary);
        $expected = $this->text->body($input->expectedBehavior, 4000);
        $actual = $this->text->body($input->actualBehavior, 4000);
        $steps = $this->text->body($input->reproductionSteps, 6000);
        $rule = $this->types->rule($input->type);

        if (($summary->value !== null && mb_strlen($summary->value) < 4)
            || ($rule['requires_actual'] && $actual->value === null)
            || ($rule['requires_steps'] && $steps->value === null)) {
            throw new TechnicalIssueActionException('issues.errors.invalid_input');
        }

        $identity = $this->identity->make($user, $input, $target);

        $duplicate = $this->duplicates->find($user, $input, $target);

        if ($duplicate->confidence === TechnicalIssueDuplicateConfidence::Exact && $screenshots === []) {
            $publicId = $duplicate->candidates[0]['public_id'] ?? null;
            $canonical = is_string($publicId) ? TechnicalIssue::query()->where('public_id', $publicId)->first() : null;

            if ($canonical instanceof TechnicalIssue) {
                $joined = DB::transaction(function () use ($canonical, $user, $input, $target, $identity): bool {
                    $locked = TechnicalIssue::query()->lockForUpdate()->findOrFail($canonical->id);

                    if ($locked->active_identity_key !== $identity || ! $locked->status->isOpen()) {
                        return false;
                    }

                    if ($locked->requester_id !== $user->id) {
                        TechnicalIssueConfirmation::query()->firstOrCreate(['technical_issue_id' => $locked->id, 'user_id' => $user->id]);
                    }

                    TechnicalIssueFollower::query()->firstOrCreate(['technical_issue_id' => $locked->id, 'user_id' => $user->id]);
                    $this->occurrences->record($locked, $user, $input, $target);
                    $locked->version++;
                    $locked->save();

                    return true;
                }, attempts: 3);

                if ($joined) {
                    return new TechnicalIssueCreationResult($canonical, true);
                }
            }
        }

        $publicId = (string) Str::uuid();
        $storedAttachments = $this->attachments->store($screenshots, $publicId);

        try {
            $issue = DB::transaction(function () use (
                $user, $input, $target, $submissionKey, $publicId, $storedAttachments, $identity,
                $summary, $expected, $actual, $steps,
            ): TechnicalIssue {
                $issue = TechnicalIssue::query()->create([
                    'public_id' => $publicId,
                    'public_number' => $this->publicNumber(),
                    'requester_id' => $user->id,
                    'support_team' => $this->types->supportTeam($input->type),
                    'type' => $input->type,
                    'status' => TechnicalIssueStatus::Submitted,
                    'severity' => $this->types->defaultSeverity($input->type),
                    'severity_sort_rank' => $this->types->defaultSeverity($input->type)->sortRank(),
                    'priority' => TechnicalIssuePriority::Normal,
                    'priority_sort_rank' => TechnicalIssuePriority::Normal->sortRank(),
                    'target_type' => $target->type,
                    'target_label_snapshot' => $target->label,
                    'catalog_title_id' => $target->catalogTitleId,
                    'season_id' => $target->seasonId,
                    'episode_id' => $target->episodeId,
                    'licensed_media_id' => $target->licensedMediaId,
                    'translation_id' => $target->translationId,
                    'feature_code' => $target->featureCode,
                    'route_name' => $target->routeName,
                    'route_path' => $target->routePath,
                    'locale' => app()->getLocale(),
                    'summary' => $summary->value,
                    'expected_behavior' => $expected->value,
                    'actual_behavior' => $actual->value,
                    'reproduction_steps' => $steps->value,
                    'playback_position_seconds' => $input->playbackPositionSeconds,
                    'audio_language' => $input->audioLanguage,
                    'subtitle_language' => $input->subtitleLanguage,
                    'quality_code' => $input->qualityCode,
                    'public_error_code' => $input->publicErrorCode,
                    'diagnostics_consent' => $input->diagnosticsConsent,
                    'exact_identity_hash' => $identity,
                    'active_identity_key' => $identity,
                    'submission_key' => $submissionKey,
                ]);

                if ($input->diagnosticsConsent) {
                    TechnicalIssueDiagnostic::query()->create([
                        'technical_issue_id' => $issue->id,
                        'authenticated_category' => 'authenticated',
                        'browser_family' => $input->browserFamily,
                        'browser_major' => $input->browserMajor,
                        'operating_system' => $input->operatingSystem,
                        'device_category' => $input->deviceCategory,
                        'viewport_width' => $input->viewportWidth,
                        'viewport_height' => $input->viewportHeight,
                        'timezone' => $input->timezone,
                        'network_online' => $input->networkOnline,
                        'player_component' => $target->playerComponent,
                        'source_health_code' => $target->sourceHealthCode,
                    ]);
                }

                TechnicalIssueStatusHistory::query()->create([
                    'technical_issue_id' => $issue->id,
                    'actor_id' => $user->id,
                    'from_status' => null,
                    'to_status' => TechnicalIssueStatus::Submitted,
                    'public_reason_code' => 'submitted',
                    'idempotency_key' => hash('sha256', 'technical-issue:submitted:'.$submissionKey),
                ]);
                TechnicalIssueFollower::query()->firstOrCreate(['technical_issue_id' => $issue->id, 'user_id' => $user->id]);
                $this->occurrences->record($issue, $user, $input, $target);

                $this->createAttachmentRows($issue, $user, $storedAttachments);

                foreach (['summary' => $summary, 'expected_behavior' => $expected, 'actual_behavior' => $actual, 'reproduction_steps' => $steps] as $field => $value) {
                    foreach ($value->redactionReasons as $reason) {
                        TechnicalIssueRedaction::query()->create([
                            'technical_issue_id' => $issue->id,
                            'actor_id' => $user->id,
                            'field' => $field,
                            'reason_code' => $reason,
                            'before_hash' => $value->beforeHash,
                            'after_hash' => $value->afterHash,
                        ]);
                    }
                }

                DB::afterCommit(fn () => $this->notifications->submitted($issue->id));

                return $issue;
            }, attempts: 3);
        } catch (Throwable $exception) {
            if ($exception instanceof QueryException) {
                $canonical = TechnicalIssue::query()->where('active_identity_key', $identity)->first();

                if ($canonical instanceof TechnicalIssue) {
                    $joined = DB::transaction(function () use (
                        $canonical,
                        $user,
                        $input,
                        $target,
                        $identity,
                        $storedAttachments,
                    ): bool {
                        $locked = TechnicalIssue::query()->lockForUpdate()->findOrFail($canonical->id);

                        if ($locked->active_identity_key !== $identity || ! $locked->status->isOpen()) {
                            return false;
                        }

                        if ($locked->requester_id !== $user->id) {
                            TechnicalIssueConfirmation::query()->firstOrCreate(['technical_issue_id' => $locked->id, 'user_id' => $user->id]);
                        }

                        TechnicalIssueFollower::query()->firstOrCreate(['technical_issue_id' => $locked->id, 'user_id' => $user->id]);
                        $this->occurrences->record($locked, $user, $input, $target);
                        $this->createAttachmentRows($locked, $user, $storedAttachments);
                        $locked->version++;
                        $locked->save();

                        return true;
                    }, attempts: 3);

                    if ($joined) {
                        return new TechnicalIssueCreationResult($canonical, true);
                    }
                }
            }

            foreach ($storedAttachments as $attachment) {
                rescue(fn () => $this->attachments->delete($attachment->path), report: true);
            }

            throw $exception;
        }

        return new TechnicalIssueCreationResult($issue, false);
    }

    private function clampedInput(TechnicalIssueInput $input, ?int $knownDuration): TechnicalIssueInput
    {
        $position = $input->playbackPositionSeconds;

        if ($position !== null && $knownDuration !== null && $knownDuration > 0) {
            $position = min($position, $knownDuration);
        }

        return new TechnicalIssueInput(
            type: $input->type,
            contextToken: $input->contextToken,
            featureCode: $input->featureCode,
            summary: $input->summary,
            expectedBehavior: $input->expectedBehavior,
            actualBehavior: $input->actualBehavior,
            reproductionSteps: $input->reproductionSteps,
            playbackPositionSeconds: $position,
            audioLanguage: $input->audioLanguage,
            subtitleLanguage: $input->subtitleLanguage,
            qualityCode: $input->qualityCode,
            publicErrorCode: $input->publicErrorCode,
            diagnosticsConsent: $input->diagnosticsConsent,
            browserFamily: $input->browserFamily,
            browserMajor: $input->browserMajor,
            operatingSystem: $input->operatingSystem,
            deviceCategory: $input->deviceCategory,
            viewportWidth: $input->viewportWidth,
            viewportHeight: $input->viewportHeight,
            timezone: $input->timezone,
            networkOnline: $input->networkOnline,
            submissionToken: $input->submissionToken,
        );
    }

    private function publicNumber(): string
    {
        do {
            $number = 'ISS-'.Str::upper(bin2hex(random_bytes(10)));
        } while (TechnicalIssue::query()->where('public_number', $number)->exists());

        return $number;
    }

    /** @param array<int, StoredTechnicalIssueAttachment> $attachments */
    private function createAttachmentRows(
        TechnicalIssue $issue,
        User $user,
        array $attachments,
    ): void {
        foreach ($attachments as $attachment) {
            $existing = TechnicalIssueAttachment::query()
                ->where('technical_issue_id', $issue->id)
                ->where('uploader_id', $user->id)
                ->whereNull('technical_issue_message_id')
                ->where('content_hash', $attachment->contentHash)
                ->first(['id']);

            if ($existing instanceof TechnicalIssueAttachment) {
                DB::afterCommit(fn () => rescue(
                    fn () => $this->attachments->delete($attachment->path),
                    report: true,
                ));

                continue;
            }

            TechnicalIssueAttachment::query()->create([
                'public_id' => (string) Str::uuid(),
                'technical_issue_id' => $issue->id,
                'uploader_id' => $user->id,
                'disk' => $attachment->disk,
                'path' => $attachment->path,
                'display_name' => $attachment->displayName,
                'mime_type' => $attachment->mimeType,
                'extension' => $attachment->extension,
                'size_bytes' => $attachment->sizeBytes,
                'width' => $attachment->width,
                'height' => $attachment->height,
                'content_hash' => $attachment->contentHash,
            ]);
        }
    }
}
