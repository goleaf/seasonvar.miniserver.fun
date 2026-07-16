<?php

declare(strict_types=1);

namespace App\Actions\TechnicalIssues;

use App\Enums\TechnicalIssueMessageVisibility;
use App\Enums\TechnicalIssueNotificationType;
use App\Enums\TechnicalIssueStatus;
use App\Exceptions\TechnicalIssues\TechnicalIssueActionException;
use App\Models\TechnicalIssue;
use App\Models\TechnicalIssueAttachment;
use App\Models\TechnicalIssueMessage;
use App\Models\TechnicalIssueRedaction;
use App\Models\TechnicalIssueStatusHistory;
use App\Models\User;
use App\Services\TechnicalIssues\TechnicalIssueAttachmentService;
use App\Services\TechnicalIssues\TechnicalIssueNotificationService;
use App\Services\TechnicalIssues\TechnicalIssueRateLimiter;
use App\Services\TechnicalIssues\TechnicalIssueTextSanitizer;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Throwable;

final readonly class AddTechnicalIssueMessage
{
    public function __construct(
        private TechnicalIssueTextSanitizer $text,
        private TechnicalIssueAttachmentService $attachments,
        private TechnicalIssueRateLimiter $rateLimiter,
        private TechnicalIssueNotificationService $notifications,
    ) {}

    /** @param array<int, UploadedFile> $screenshots */
    public function handle(
        User $actor,
        TechnicalIssue $issue,
        string $body,
        string $submissionToken,
        bool $internal = false,
        array $screenshots = [],
    ): TechnicalIssueMessage {
        Gate::forUser($actor)->authorize($internal ? 'manage' : 'reply', $issue);

        if (! Str::isUuid($submissionToken)) {
            throw new TechnicalIssueActionException('issues.errors.invalid_submission');
        }

        $submissionKey = hash('sha256', $actor->id.':'.$issue->id.':'.Str::lower($submissionToken));
        $existing = TechnicalIssueMessage::query()->where('submission_key', $submissionKey)->first();

        if ($existing instanceof TechnicalIssueMessage) {
            return $existing;
        }

        $this->rateLimiter->ensure($actor, 'message');
        $sanitized = $this->text->body($body, 6000);

        if ($sanitized->value === null || mb_strlen($sanitized->value) < 2) {
            throw new TechnicalIssueActionException('issues.errors.message_required');
        }

        $stored = $this->attachments->store($screenshots, $issue->public_id);

        try {
            return DB::transaction(function () use ($actor, $issue, $internal, $sanitized, $submissionKey, $stored): TechnicalIssueMessage {
                $locked = TechnicalIssue::query()->lockForUpdate()->findOrFail($issue->id);
                Gate::forUser($actor)->authorize($internal ? 'manage' : 'reply', $locked);
                $visibility = $internal ? TechnicalIssueMessageVisibility::Internal : TechnicalIssueMessageVisibility::RequesterVisible;
                $message = TechnicalIssueMessage::query()->create([
                    'public_id' => (string) Str::uuid(),
                    'technical_issue_id' => $locked->id,
                    'author_id' => $actor->id,
                    'visibility' => $visibility,
                    'kind' => $internal ? 'internal_note' : ($locked->requester_id === $actor->id ? 'requester_reply' : 'support_reply'),
                    'body' => $sanitized->value,
                    'body_hash' => hash('sha256', $sanitized->value),
                    'submission_key' => $submissionKey,
                ]);

                foreach ($stored as $attachment) {
                    TechnicalIssueAttachment::query()->create([
                        'public_id' => (string) Str::uuid(),
                        'technical_issue_id' => $locked->id,
                        'technical_issue_message_id' => $message->id,
                        'uploader_id' => $actor->id,
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

                foreach ($sanitized->redactionReasons as $reason) {
                    TechnicalIssueRedaction::query()->create([
                        'technical_issue_id' => $locked->id,
                        'technical_issue_message_id' => $message->id,
                        'actor_id' => $actor->id,
                        'field' => 'message.body',
                        'reason_code' => $reason,
                        'before_hash' => $sanitized->beforeHash,
                        'after_hash' => $sanitized->afterHash,
                    ]);
                }

                if (! $internal) {
                    $previous = $locked->status;
                    $next = $previous;

                    if ($locked->requester_id === $actor->id && in_array($previous, [TechnicalIssueStatus::ClarificationNeeded, TechnicalIssueStatus::WaitingForRequester], true)) {
                        $next = $previous === TechnicalIssueStatus::ClarificationNeeded ? TechnicalIssueStatus::TriagePending : TechnicalIssueStatus::InProgress;
                    }

                    if ($next !== $previous) {
                        $locked->status = $next;
                        TechnicalIssueStatusHistory::query()->create([
                            'technical_issue_id' => $locked->id,
                            'actor_id' => $actor->id,
                            'from_status' => $previous,
                            'to_status' => $next,
                            'public_reason_code' => 'requester_replied',
                            'idempotency_key' => hash('sha256', 'message-status:'.$message->public_id),
                        ]);
                    }

                    $locked->last_public_message_at = now()->toImmutable();
                    $locked->version++;
                    $locked->save();
                    DB::afterCommit(fn () => $this->notifications->changed(
                        $locked->id,
                        $locked->requester_id === $actor->id
                            ? TechnicalIssueNotificationType::StatusChanged
                            : TechnicalIssueNotificationType::SupportReply,
                        $actor->id,
                    ));
                } else {
                    $locked->version++;
                    $locked->save();
                }

                return $message;
            }, attempts: 3);
        } catch (Throwable $exception) {
            foreach ($stored as $attachment) {
                rescue(fn () => $this->attachments->delete($attachment->path), report: true);
            }

            if ($exception instanceof QueryException) {
                $existing = TechnicalIssueMessage::query()->where('submission_key', $submissionKey)->first();

                if ($existing instanceof TechnicalIssueMessage) {
                    return $existing;
                }
            }

            throw $exception;
        }
    }
}
