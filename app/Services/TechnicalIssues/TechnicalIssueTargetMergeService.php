<?php

declare(strict_types=1);

namespace App\Services\TechnicalIssues;

use App\Enums\TechnicalIssueNotificationType;
use App\Enums\TechnicalIssueResolutionType;
use App\Enums\TechnicalIssueStatus;
use App\Models\TechnicalIssue;
use App\Models\TechnicalIssueConfirmation;
use App\Models\TechnicalIssueFollower;
use App\Models\TechnicalIssueMerge;
use App\Models\TechnicalIssueStatusHistory;
use Illuminate\Support\Facades\DB;

final readonly class TechnicalIssueTargetMergeService
{
    public function __construct(
        private TechnicalIssueSchema $schema,
        private TechnicalIssueIdentity $identity,
        private TechnicalIssueNotificationService $notifications,
        private TechnicalIssueOccurrenceService $occurrences,
    ) {}

    public function moveTitle(int $sourceTitleId, int $targetTitleId): void
    {
        $this->retarget('catalog_title_id', $sourceTitleId, $targetTitleId);
    }

    public function moveSeason(int $sourceSeasonId, int $targetSeasonId): void
    {
        $this->retarget('season_id', $sourceSeasonId, $targetSeasonId);
    }

    public function moveEpisode(int $sourceEpisodeId, int $targetEpisodeId): void
    {
        $this->retarget('episode_id', $sourceEpisodeId, $targetEpisodeId);
    }

    public function moveMedia(int $sourceMediaId, int $targetMediaId): void
    {
        $this->retarget('licensed_media_id', $sourceMediaId, $targetMediaId);
    }

    private function retarget(string $column, int $sourceId, int $targetId): void
    {
        if (! $this->schema->ready() || $sourceId === $targetId) {
            return;
        }

        DB::transaction(function () use ($column, $sourceId, $targetId): void {
            TechnicalIssue::query()->where($column, $sourceId)->with('diagnostic')->eachById(function (TechnicalIssue $issue) use ($column, $targetId): void {
                $issue->{$column} = $targetId;
                $newIdentity = $this->identity->fromIssue($issue);
                $canonical = ! $issue->status->isTerminal()
                    ? TechnicalIssue::query()->where('active_identity_key', $newIdentity)->whereKeyNot($issue->id)->first()
                    : null;

                if ($canonical instanceof TechnicalIssue) {
                    TechnicalIssueConfirmation::query()->where('technical_issue_id', $issue->id)->eachById(
                        fn (TechnicalIssueConfirmation $confirmation) => TechnicalIssueConfirmation::query()->firstOrCreate([
                            'technical_issue_id' => $canonical->id,
                            'user_id' => $confirmation->user_id,
                        ]),
                    );
                    TechnicalIssueFollower::query()->where('technical_issue_id', $issue->id)->eachById(
                        fn (TechnicalIssueFollower $follower) => TechnicalIssueFollower::query()->firstOrCreate([
                            'technical_issue_id' => $canonical->id,
                            'user_id' => $follower->user_id,
                        ]),
                    );
                    TechnicalIssueConfirmation::query()
                        ->where('technical_issue_id', $canonical->id)
                        ->where('user_id', $canonical->requester_id)
                        ->delete();
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
                    TechnicalIssueStatusHistory::query()->create([
                        'technical_issue_id' => $issue->id,
                        'from_status' => $previous,
                        'to_status' => TechnicalIssueStatus::Merged,
                        'public_reason_code' => 'target_merged',
                        'idempotency_key' => hash('sha256', 'target-merge:'.$issue->id.':'.$canonical->id),
                    ]);
                    DB::afterCommit(fn () => $this->notifications->changed(
                        $issue->id,
                        TechnicalIssueNotificationType::Merged,
                        canonicalPublicId: $canonical->public_id,
                    ));
                } else {
                    $issue->exact_identity_hash = $newIdentity;
                    $issue->active_identity_key = ! $issue->status->isTerminal() ? $newIdentity : null;
                }

                $issue->version++;
                $issue->save();
            });
        }, attempts: 3);
    }
}
