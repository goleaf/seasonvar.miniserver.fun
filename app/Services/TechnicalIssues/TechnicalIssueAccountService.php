<?php

declare(strict_types=1);

namespace App\Services\TechnicalIssues;

use App\Enums\TechnicalIssueNotificationType;
use App\Enums\TechnicalIssueResolutionType;
use App\Enums\TechnicalIssueStatus;
use App\Models\TechnicalIssue;
use App\Models\TechnicalIssueAttachment;
use App\Models\TechnicalIssueConfirmation;
use App\Models\TechnicalIssueFollower;
use App\Models\TechnicalIssueMerge;
use App\Models\TechnicalIssueMessage;
use App\Models\TechnicalIssueNotificationPreference;
use App\Models\TechnicalIssueOccurrence;
use App\Models\TechnicalIssueStatusHistory;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final readonly class TechnicalIssueAccountService
{
    public function __construct(
        private TechnicalIssueSchema $schema,
        private TechnicalIssueIdentity $identity,
        private TechnicalIssueNotificationService $notifications,
        private TechnicalIssueOccurrenceService $occurrences,
        private TechnicalIssueTextSanitizer $text,
    ) {}

    /** @return array<string, mixed> */
    public function export(User $user): array
    {
        if (! $this->schema->ready()) {
            return ['tickets' => [], 'confirmations' => [], 'follows' => [], 'occurrences' => []];
        }

        $tickets = TechnicalIssue::query()
            ->where('requester_id', $user->id)
            ->with([
                'messages' => fn ($query) => $query
                    ->where('visibility', 'requester_visible')
                    ->select(['id', 'public_id', 'technical_issue_id', 'author_id', 'kind', 'body', 'created_at']),
                'statusHistory' => fn ($query) => $query
                    ->whereNotIn('public_reason_code', ['internal_classification_changed'])
                    ->select(['id', 'technical_issue_id', 'from_status', 'to_status', 'public_reason_code', 'public_message', 'created_at']),
                'attachments' => fn ($query) => $query->where('uploader_id', $user->id)->select([
                    'id', 'public_id', 'technical_issue_id', 'display_name', 'mime_type', 'extension', 'size_bytes', 'width', 'height', 'created_at',
                ]),
            ])
            ->oldest('id')
            ->get()
            ->map(fn (TechnicalIssue $issue): array => [
                'public_number' => $issue->public_number,
                'public_id' => $issue->public_id,
                'type' => $issue->type->value,
                'status' => $issue->status->value,
                'target_type' => $issue->target_type->value,
                'summary' => $this->text->display($issue->summary),
                'expected_behavior' => $this->text->display($issue->expected_behavior),
                'actual_behavior' => $this->text->display($issue->actual_behavior),
                'reproduction_steps' => $this->text->display($issue->reproduction_steps),
                'resolution_type' => $issue->resolution_type?->value,
                'resolution_summary' => $this->text->display($issue->resolution_summary),
                'created_at' => $issue->created_at->toAtomString(),
                'updated_at' => $issue->updated_at->toAtomString(),
                'messages' => $issue->messages->map(fn (TechnicalIssueMessage $message): array => [
                    'public_id' => $message->public_id,
                    'kind' => $message->kind,
                    'body' => $this->text->display($message->body),
                    'created_at' => $message->created_at->toAtomString(),
                ])->all(),
                'status_history' => $issue->statusHistory->map(fn (TechnicalIssueStatusHistory $history): array => [
                    'from_status' => $history->from_status?->value,
                    'to_status' => $history->to_status->value,
                    'reason_code' => $history->public_reason_code,
                    'message' => $this->text->display($history->public_message),
                    'created_at' => $history->created_at->toAtomString(),
                ])->all(),
                'attachments' => $issue->attachments->values()->map(fn (TechnicalIssueAttachment $attachment, int $index): array => [
                    'public_id' => $attachment->public_id,
                    'name' => __('issues.attachments.screenshot_name', ['number' => $index + 1]).'.'.$attachment->extension,
                    'mime_type' => $attachment->mime_type,
                    'size_bytes' => $attachment->size_bytes,
                    'width' => $attachment->width,
                    'height' => $attachment->height,
                    'created_at' => $attachment->created_at->toAtomString(),
                ])->all(),
            ])->all();

        return [
            'tickets' => $tickets,
            'confirmations' => TechnicalIssueConfirmation::query()
                ->where('user_id', $user->id)
                ->with('technicalIssue:id,public_id,public_number')
                ->oldest('id')
                ->get()
                ->map(fn (TechnicalIssueConfirmation $confirmation): array => [
                    'public_id' => $confirmation->technicalIssue?->public_id,
                    'public_number' => $confirmation->technicalIssue?->public_number,
                    'verification_state' => $confirmation->verification_state,
                ])->all(),
            'follows' => TechnicalIssueFollower::query()
                ->where('user_id', $user->id)
                ->with('technicalIssue:id,public_id,public_number')
                ->oldest('id')
                ->get()
                ->map(fn (TechnicalIssueFollower $follow): array => [
                    'public_id' => $follow->technicalIssue?->public_id,
                    'public_number' => $follow->technicalIssue?->public_number,
                ])->all(),
            'occurrences' => TechnicalIssueOccurrence::query()
                ->where('user_id', $user->id)
                ->with('technicalIssue:id,public_id,public_number')
                ->oldest('id')
                ->get()
                ->map(fn (TechnicalIssueOccurrence $occurrence): array => [
                    'public_id' => $occurrence->technicalIssue?->public_id,
                    'public_number' => $occurrence->technicalIssue?->public_number,
                    'browser_family' => $occurrence->browser_family,
                    'browser_major' => $occurrence->browser_major,
                    'operating_system' => $occurrence->operating_system,
                    'device_category' => $occurrence->device_category,
                    'viewport_width' => $occurrence->viewport_width,
                    'viewport_height' => $occurrence->viewport_height,
                    'timezone' => $occurrence->timezone,
                    'network_online' => $occurrence->network_online,
                    'playback_position_seconds' => $occurrence->playback_position_seconds,
                    'public_error_code' => $occurrence->public_error_code,
                    'occurred_at' => $occurrence->occurred_at->toAtomString(),
                ])->all(),
        ];
    }

    public function prepareForDeletion(User $user): void
    {
        if (! $this->schema->ready()) {
            return;
        }

        $issueIds = TechnicalIssue::query()->where('requester_id', $user->id)->pluck('id');
        DB::table('technical_issue_diagnostics')->whereIn('technical_issue_id', $issueIds)->delete();
        DB::table('technical_issue_attachments')->where('uploader_id', $user->id)->update(['uploader_id' => null]);
        DB::table('technical_issue_messages')->where('author_id', $user->id)->update(['author_id' => null]);
        TechnicalIssue::query()->where('requester_id', $user->id)->update(['requester_id' => null]);
        TechnicalIssue::query()->where('assigned_to_id', $user->id)->update(['assigned_to_id' => null]);
        TechnicalIssueConfirmation::query()->where('user_id', $user->id)->delete();
        TechnicalIssueFollower::query()->where('user_id', $user->id)->delete();
        TechnicalIssueNotificationPreference::query()->where('user_id', $user->id)->delete();
        $user->notifications()->where('type', 'technical-issue.activity')->delete();
    }

    public function mergeUsers(User $source, User $target): void
    {
        if (! $this->schema->ready() || $source->is($target)) {
            return;
        }

        DB::transaction(function () use ($source, $target): void {
            TechnicalIssue::query()
                ->where('requester_id', $source->id)
                ->with('diagnostic:id,technical_issue_id,browser_family,operating_system,device_category')
                ->eachById(function (TechnicalIssue $issue) use ($target): void {
                    $issue->requester_id = $target->id;
                    $identity = $this->identity->fromIssue($issue, $target->id);
                    $canonical = ! $issue->status->isTerminal()
                        ? TechnicalIssue::query()->where('active_identity_key', $identity)->whereKeyNot($issue->id)->first()
                        : null;
                    $issue->exact_identity_hash = $identity;

                    if ($canonical instanceof TechnicalIssue) {
                        TechnicalIssueConfirmation::query()->where('technical_issue_id', $issue->id)->eachById(
                            fn (TechnicalIssueConfirmation $confirmation) => TechnicalIssueConfirmation::query()->firstOrCreate(
                                ['technical_issue_id' => $canonical->id, 'user_id' => $confirmation->user_id],
                                ['verification_state' => $confirmation->verification_state],
                            ),
                        );
                        TechnicalIssueFollower::query()->where('technical_issue_id', $issue->id)->eachById(
                            fn (TechnicalIssueFollower $follow) => TechnicalIssueFollower::query()->firstOrCreate([
                                'technical_issue_id' => $canonical->id,
                                'user_id' => $follow->user_id,
                            ]),
                        );
                        TechnicalIssueMerge::query()->firstOrCreate(
                            ['duplicate_issue_id' => $issue->id],
                            ['canonical_issue_id' => $canonical->id],
                        );
                        $this->occurrences->mergeIssues($issue, $canonical);
                        $previous = $issue->status;
                        $issue->status = TechnicalIssueStatus::Merged;
                        $issue->merged_into_id = $canonical->id;
                        $issue->resolution_type = TechnicalIssueResolutionType::Duplicate;
                        $issue->resolution_summary = null;
                        $issue->active_identity_key = null;
                        TechnicalIssueStatusHistory::query()->firstOrCreate(
                            ['idempotency_key' => hash('sha256', 'account-merge:'.$issue->id.':'.$canonical->id)],
                            [
                                'technical_issue_id' => $issue->id,
                                'from_status' => $previous,
                                'to_status' => TechnicalIssueStatus::Merged,
                                'public_reason_code' => 'account_merged',
                            ],
                        );
                        DB::afterCommit(fn () => $this->notifications->changed(
                            $issue->id,
                            TechnicalIssueNotificationType::Merged,
                            canonicalPublicId: $canonical->public_id,
                        ));
                    } else {
                        $issue->active_identity_key = ! $issue->status->isTerminal() ? $identity : null;
                    }

                    $issue->version++;
                    $issue->save();
                });
            DB::table('technical_issue_messages')->where('author_id', $source->id)->update(['author_id' => $target->id]);
            DB::table('technical_issue_attachments')->where('uploader_id', $source->id)->update(['uploader_id' => $target->id]);
            TechnicalIssueConfirmation::query()->where('user_id', $source->id)->eachById(function (TechnicalIssueConfirmation $confirmation) use ($target): void {
                TechnicalIssueConfirmation::query()->firstOrCreate(
                    ['technical_issue_id' => $confirmation->technical_issue_id, 'user_id' => $target->id],
                    ['verification_state' => $confirmation->verification_state],
                );
                $confirmation->delete();
            });
            TechnicalIssueFollower::query()->where('user_id', $source->id)->eachById(function (TechnicalIssueFollower $follow) use ($target): void {
                TechnicalIssueFollower::query()->firstOrCreate(['technical_issue_id' => $follow->technical_issue_id, 'user_id' => $target->id]);
                $follow->delete();
            });
            $this->occurrences->mergeUsers($source, $target);
            TechnicalIssueConfirmation::query()
                ->where('user_id', $target->id)
                ->whereHas('technicalIssue', fn ($query) => $query->where('requester_id', $target->id))
                ->delete();
            $sourcePreference = TechnicalIssueNotificationPreference::query()->find($source->id);

            if ($sourcePreference instanceof TechnicalIssueNotificationPreference) {
                $targetPreference = TechnicalIssueNotificationPreference::query()->find($target->id);

                if ($targetPreference instanceof TechnicalIssueNotificationPreference) {
                    $targetPreference->fill([
                        'requester_updates' => $targetPreference->requester_updates && $sourcePreference->requester_updates,
                        'confirmer_updates' => $targetPreference->confirmer_updates && $sourcePreference->confirmer_updates,
                        'follower_updates' => $targetPreference->follower_updates && $sourcePreference->follower_updates,
                        'support_replies' => $targetPreference->support_replies && $sourcePreference->support_replies,
                    ])->save();
                } else {
                    TechnicalIssueNotificationPreference::query()->create([
                        'user_id' => $target->id,
                        'requester_updates' => $sourcePreference->requester_updates,
                        'confirmer_updates' => $sourcePreference->confirmer_updates,
                        'follower_updates' => $sourcePreference->follower_updates,
                        'support_replies' => $sourcePreference->support_replies,
                    ]);
                }

                $sourcePreference->delete();
            }
        }, attempts: 3);
    }
}
