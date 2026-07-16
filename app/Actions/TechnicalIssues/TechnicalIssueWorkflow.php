<?php

declare(strict_types=1);

namespace App\Actions\TechnicalIssues;

use App\Enums\TechnicalIssueNotificationType;
use App\Enums\TechnicalIssuePriority;
use App\Enums\TechnicalIssueResolutionType;
use App\Enums\TechnicalIssueSeverity;
use App\Enums\TechnicalIssueStatus;
use App\Exceptions\TechnicalIssues\TechnicalIssueActionException;
use App\Models\TechnicalIssue;
use App\Models\TechnicalIssueAssignment;
use App\Models\TechnicalIssueConfirmation;
use App\Models\TechnicalIssueFollower;
use App\Models\TechnicalIssueMerge;
use App\Models\TechnicalIssueMessage;
use App\Models\TechnicalIssueRedaction;
use App\Models\TechnicalIssueStatusHistory;
use App\Models\User;
use App\Services\TechnicalIssues\TechnicalIssueIdentity;
use App\Services\TechnicalIssues\TechnicalIssueNotificationService;
use App\Services\TechnicalIssues\TechnicalIssueOccurrenceService;
use App\Services\TechnicalIssues\TechnicalIssueRateLimiter;
use App\Services\TechnicalIssues\TechnicalIssueTextSanitizer;
use App\Services\TechnicalIssues\TechnicalIssueTypeRegistry;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

final readonly class TechnicalIssueWorkflow
{
    public function __construct(
        private TechnicalIssueTextSanitizer $text,
        private TechnicalIssueRateLimiter $rateLimiter,
        private TechnicalIssueTypeRegistry $types,
        private TechnicalIssueIdentity $identity,
        private TechnicalIssueNotificationService $notifications,
        private TechnicalIssueOccurrenceService $occurrences,
    ) {}

    /** @param array{summary?: mixed, expected_behavior?: mixed, actual_behavior?: mixed, reproduction_steps?: mixed} $data */
    public function updateRequester(User $actor, TechnicalIssue $issue, array $data): TechnicalIssue
    {
        Gate::forUser($actor)->authorize('update', $issue);
        $this->rateLimiter->ensure($actor, 'update');
        $values = [
            'summary' => $this->text->summary($data['summary'] ?? null),
            'expected_behavior' => $this->text->body($data['expected_behavior'] ?? null, 4000),
            'actual_behavior' => $this->text->body($data['actual_behavior'] ?? null, 4000),
            'reproduction_steps' => $this->text->body($data['reproduction_steps'] ?? null, 6000),
        ];
        $rule = $this->types->rule($issue->type);

        if (($values['summary']->value !== null && mb_strlen($values['summary']->value) < 4)
            || ($rule['requires_actual'] && $values['actual_behavior']->value === null)
            || ($rule['requires_steps'] && $values['reproduction_steps']->value === null)) {
            throw new TechnicalIssueActionException('issues.errors.invalid_input');
        }

        return DB::transaction(function () use ($actor, $issue, $values): TechnicalIssue {
            $locked = TechnicalIssue::query()->lockForUpdate()->findOrFail($issue->id);
            Gate::forUser($actor)->authorize('update', $locked);

            foreach ($values as $field => $value) {
                $locked->{$field} = $value->value;

                foreach ($value->redactionReasons as $reason) {
                    TechnicalIssueRedaction::query()->create([
                        'technical_issue_id' => $locked->id,
                        'actor_id' => $actor->id,
                        'field' => $field,
                        'reason_code' => $reason,
                        'before_hash' => $value->beforeHash,
                        'after_hash' => $value->afterHash,
                    ]);
                }
            }

            $locked->loadMissing('diagnostic');
            $identity = $this->identity->fromIssue($locked);
            $collision = TechnicalIssue::query()
                ->where('active_identity_key', $identity)
                ->whereKeyNot($locked->id)
                ->exists();

            if ($collision) {
                throw new TechnicalIssueActionException('issues.errors.edit_duplicate');
            }

            $locked->exact_identity_hash = $identity;
            $locked->active_identity_key = $locked->status->isOpen() ? $identity : null;

            $locked->version++;
            $locked->save();

            return $locked;
        }, attempts: 3);
    }

    public function withdraw(User $actor, TechnicalIssue $issue): TechnicalIssue
    {
        Gate::forUser($actor)->authorize('withdraw', $issue);
        $this->rateLimiter->ensure($actor, 'update');

        return DB::transaction(function () use ($actor, $issue): TechnicalIssue {
            $locked = TechnicalIssue::query()->lockForUpdate()->findOrFail($issue->id);
            Gate::forUser($actor)->authorize('withdraw', $locked);
            $previous = $locked->status;
            $shared = TechnicalIssueConfirmation::query()
                ->where('technical_issue_id', $locked->id)
                ->where('user_id', '!=', $actor->id)
                ->exists();

            if ($shared) {
                $locked->requester_id = null;
                $locked->status = TechnicalIssueStatus::TriagePending;
                $reason = 'requester_withdrew_shared_incident';
            } else {
                $locked->status = TechnicalIssueStatus::Withdrawn;
                $locked->active_identity_key = null;
                $locked->withdrawn_at = now()->toImmutable();
                $reason = 'requester_withdrew';
            }

            $locked->version++;
            $locked->save();
            TechnicalIssueFollower::query()->where('technical_issue_id', $locked->id)->where('user_id', $actor->id)->delete();
            TechnicalIssueConfirmation::query()->where('technical_issue_id', $locked->id)->where('user_id', $actor->id)->delete();
            TechnicalIssueStatusHistory::query()->create([
                'technical_issue_id' => $locked->id,
                'actor_id' => $actor->id,
                'from_status' => $previous,
                'to_status' => $locked->status,
                'public_reason_code' => $reason,
                'idempotency_key' => hash('sha256', 'withdrawal:'.$locked->id.':'.$actor->id),
            ]);
            DB::afterCommit(fn () => $this->notifications->changed($locked->id, TechnicalIssueNotificationType::StatusChanged, $actor->id));

            return $locked;
        }, attempts: 3);
    }

    public function transition(
        User $actor,
        TechnicalIssue $issue,
        TechnicalIssueStatus $next,
        string $reasonCode,
        ?string $publicMessage = null,
        ?string $privateNote = null,
        ?string $rejectionReason = null,
        ?string $reroutedTo = null,
        ?TechnicalIssueResolutionType $resolution = null,
        bool $markVerified = false,
        bool $incrementReopen = false,
    ): TechnicalIssue {
        $requesterWithdrawal = $next === TechnicalIssueStatus::Withdrawn && $issue->requester_id === $actor->id;
        $ability = match ($next) {
            TechnicalIssueStatus::ResolutionVerified => 'verify',
            TechnicalIssueStatus::Reopened => 'reopen',
            TechnicalIssueStatus::Withdrawn => $requesterWithdrawal ? 'withdraw' : 'manage',
            default => 'manage',
        };
        Gate::forUser($actor)->authorize($ability, $issue);
        $this->rateLimiter->ensure($actor, 'update');

        if ($next === TechnicalIssueStatus::Merged) {
            throw new TechnicalIssueActionException('issues.errors.invalid_merge');
        }

        if ($next === TechnicalIssueStatus::Withdrawn && ! $requesterWithdrawal) {
            throw new TechnicalIssueActionException('issues.errors.invalid_transition');
        }

        if (($next === TechnicalIssueStatus::Resolved && $resolution === null)
            || ($resolution !== null && $next !== TechnicalIssueStatus::Resolved)) {
            throw new TechnicalIssueActionException('issues.errors.invalid_resolution');
        }

        if (($markVerified && $next !== TechnicalIssueStatus::ResolutionVerified)
            || ($incrementReopen && $next !== TechnicalIssueStatus::Reopened)
            || ($next === TechnicalIssueStatus::Reopened && ! $incrementReopen)) {
            throw new TechnicalIssueActionException('issues.errors.invalid_transition');
        }

        $public = $this->text->body($publicMessage, 2000);
        $private = $this->text->body($privateNote, 4000);
        $reasonCode = $this->reasonCode($reasonCode);

        if (in_array($next, [TechnicalIssueStatus::ClarificationNeeded, TechnicalIssueStatus::WaitingForRequester, TechnicalIssueStatus::Reopened, TechnicalIssueStatus::Rejected], true)
            && ($public->value === null || mb_strlen($public->value) < 2)) {
            throw new TechnicalIssueActionException('issues.errors.public_message_required');
        }

        if ($next === TechnicalIssueStatus::Rejected
            && (! is_string($rejectionReason) || ! in_array($rejectionReason, $this->rejectionReasons(), true))) {
            throw new TechnicalIssueActionException('issues.errors.rejection_reason_required');
        }

        if ($reroutedTo !== null && ! in_array($reroutedTo, ['content_request', 'moderation_report', 'account_security', 'rights_holder'], true)) {
            throw new TechnicalIssueActionException('issues.errors.invalid_reroute');
        }

        if ($reroutedTo !== null && ($public->value === null || mb_strlen($public->value) < 2)) {
            throw new TechnicalIssueActionException('issues.errors.public_message_required');
        }

        $updated = DB::transaction(function () use (
            $actor, $issue, $next, $reasonCode, $public, $private, $rejectionReason, $reroutedTo, $ability,
            $resolution, $markVerified, $incrementReopen,
        ): TechnicalIssue {
            $locked = TechnicalIssue::query()->lockForUpdate()->findOrFail($issue->id);
            Gate::forUser($actor)->authorize($ability, $locked);

            if ($locked->status === $next) {
                return $locked;
            }

            if (! $locked->status->canTransitionTo($next)) {
                throw new TechnicalIssueActionException('issues.errors.invalid_transition');
            }

            if ($next === TechnicalIssueStatus::Assigned && $locked->assigned_to_id === null) {
                throw new TechnicalIssueActionException('issues.errors.invalid_assignment');
            }

            $previous = $locked->status;
            $locked->status = $next;
            $locked->active_identity_key = $next->isTerminal() ? null : $locked->exact_identity_hash;
            $locked->rejection_reason = $rejectionReason;
            $locked->rerouted_to = $reroutedTo;
            $locked->resolution_type = $next === TechnicalIssueStatus::Rejected
                ? TechnicalIssueResolutionType::Rejected
                : ($resolution ?? $locked->resolution_type);
            $locked->withdrawn_at = $next === TechnicalIssueStatus::Withdrawn ? now()->toImmutable() : $locked->withdrawn_at;
            $locked->closed_at = $next === TechnicalIssueStatus::Closed ? now()->toImmutable() : $locked->closed_at;
            $locked->resolution_summary = $resolution !== null || $next === TechnicalIssueStatus::Rejected
                ? $public->value
                : $locked->resolution_summary;
            $locked->resolved_at = $resolution !== null || $next === TechnicalIssueStatus::Rejected
                ? now()->toImmutable()
                : $locked->resolved_at;
            $locked->verified_at = $markVerified || $next === TechnicalIssueStatus::ResolutionVerified
                ? now()->toImmutable()
                : $locked->verified_at;
            $locked->reopen_count = $incrementReopen ? $locked->reopen_count + 1 : $locked->reopen_count;
            $locked->version++;
            $locked->save();
            TechnicalIssueStatusHistory::query()->create([
                'technical_issue_id' => $locked->id,
                'actor_id' => $actor->id,
                'from_status' => $previous,
                'to_status' => $next,
                'public_reason_code' => $reasonCode,
                'public_message' => $public->value,
                'private_note' => $private->value,
                'idempotency_key' => hash('sha256', implode(':', ['transition', $locked->id, $locked->version, $next->value, $actor->id])),
            ]);
            DB::afterCommit(fn () => $this->notifications->changed($locked->id, $this->notificationType($next), $actor->id));

            return $locked;
        }, attempts: 3);

        return $updated;
    }

    public function classify(
        User $actor,
        TechnicalIssue $issue,
        TechnicalIssueSeverity $severity,
        TechnicalIssuePriority $priority,
    ): TechnicalIssue {
        Gate::forUser($actor)->authorize('manage', $issue);
        $this->rateLimiter->ensure($actor, 'update');

        return DB::transaction(function () use ($actor, $issue, $severity, $priority): TechnicalIssue {
            $locked = TechnicalIssue::query()->lockForUpdate()->findOrFail($issue->id);
            Gate::forUser($actor)->authorize('manage', $locked);

            if ($locked->severity === $severity && $locked->priority === $priority) {
                return $locked;
            }

            $locked->severity = $severity;
            $locked->priority = $priority;
            $locked->version++;
            $locked->save();
            TechnicalIssueStatusHistory::query()->create([
                'technical_issue_id' => $locked->id,
                'actor_id' => $actor->id,
                'from_status' => $locked->status,
                'to_status' => $locked->status,
                'public_reason_code' => 'internal_classification_changed',
                'private_note' => 'severity='.$severity->value.';priority='.$priority->value,
                'idempotency_key' => hash('sha256', 'classification:'.$locked->id.':'.$locked->version),
            ]);

            return $locked;
        }, attempts: 3);
    }

    public function assign(User $actor, TechnicalIssue $issue, ?int $assigneeId, string $supportTeam): TechnicalIssue
    {
        Gate::forUser($actor)->authorize('manage', $issue);

        if ($issue->status->isTerminal() || ! in_array($supportTeam, config('technical-issues.support_teams', []), true)) {
            throw new TechnicalIssueActionException('issues.errors.invalid_assignment');
        }

        $assignee = $assigneeId !== null
            ? User::query()->whereKey($assigneeId)->whereIn('email', (array) config('seasonvar.admin_emails', []))->first(['id', 'name', 'email'])
            : null;

        if ($assigneeId !== null && ! $assignee instanceof User) {
            throw new TechnicalIssueActionException('issues.errors.invalid_assignment');
        }

        return DB::transaction(function () use ($actor, $issue, $assignee, $supportTeam): TechnicalIssue {
            $locked = TechnicalIssue::query()->lockForUpdate()->findOrFail($issue->id);
            Gate::forUser($actor)->authorize('manage', $locked);

            if ($locked->status->isTerminal()) {
                throw new TechnicalIssueActionException('issues.errors.invalid_assignment');
            }

            $previous = $locked->status;
            $assignmentChanged = $locked->assigned_to_id !== $assignee?->id || $locked->support_team !== $supportTeam;
            $next = match (true) {
                $assignee instanceof User && $previous->canTransitionTo(TechnicalIssueStatus::Assigned) => TechnicalIssueStatus::Assigned,
                $assignee === null && $previous === TechnicalIssueStatus::Assigned
                    && $previous->canTransitionTo(TechnicalIssueStatus::Confirmed) => TechnicalIssueStatus::Confirmed,
                default => $previous,
            };

            if (! $assignmentChanged && $next === $previous) {
                return $locked;
            }

            if ($assignmentChanged) {
                TechnicalIssueAssignment::query()->where('technical_issue_id', $locked->id)->whereNull('ended_at')->update(['ended_at' => now(), 'updated_at' => now()]);
                TechnicalIssueAssignment::query()->create([
                    'technical_issue_id' => $locked->id,
                    'assigned_by_id' => $actor->id,
                    'assignee_id' => $assignee?->id,
                    'support_team' => $supportTeam,
                ]);
                $locked->assigned_to_id = $assignee?->id;
                $locked->support_team = $supportTeam;
            }

            $locked->status = $next;
            $locked->version++;
            $locked->save();

            if ($locked->status !== $previous) {
                TechnicalIssueStatusHistory::query()->create([
                    'technical_issue_id' => $locked->id,
                    'actor_id' => $actor->id,
                    'from_status' => $previous,
                    'to_status' => $locked->status,
                    'public_reason_code' => $assignee instanceof User ? 'assigned' : 'unassigned',
                    'idempotency_key' => hash('sha256', 'assignment:'.$locked->id.':'.$locked->version),
                ]);
            }

            DB::afterCommit(fn () => $this->notifications->changed($locked->id, TechnicalIssueNotificationType::Assigned, $actor->id));

            return $locked;
        }, attempts: 3);
    }

    public function resolve(
        User $actor,
        TechnicalIssue $issue,
        TechnicalIssueResolutionType $resolution,
        string $summary,
        ?string $privateNote = null,
    ): TechnicalIssue {
        Gate::forUser($actor)->authorize('manage', $issue);

        if (! in_array($resolution, $this->types->resolutions($issue->type), true)) {
            throw new TechnicalIssueActionException('issues.errors.invalid_resolution');
        }

        $public = $this->text->body($summary, 2000);

        if ($public->value === null || mb_strlen($public->value) < 4) {
            throw new TechnicalIssueActionException('issues.errors.resolution_summary_required');
        }

        return $this->transition(
            $actor,
            $issue,
            TechnicalIssueStatus::Resolved,
            'resolved_'.$resolution->value,
            $public->value,
            $privateNote,
            resolution: $resolution,
        );
    }

    public function verify(User $actor, TechnicalIssue $issue, bool $fixed, ?string $reason = null): TechnicalIssue
    {
        Gate::forUser($actor)->authorize('verify', $issue);
        $confirmation = $issue->requester_id === $actor->id
            ? null
            : TechnicalIssueConfirmation::query()->where('technical_issue_id', $issue->id)->where('user_id', $actor->id)->first();

        if ($confirmation instanceof TechnicalIssueConfirmation) {
            $confirmation->forceFill(['verification_state' => $fixed ? 'fixed' : 'still_broken'])->save();

            if ($fixed) {
                return $issue->refresh();
            }
        }

        if ($fixed) {
            return $this->transition(
                $actor,
                $issue,
                TechnicalIssueStatus::ResolutionVerified,
                'requester_verified',
                markVerified: true,
            );
        }

        return $this->reopen($actor, $issue, $reason ?? '');
    }

    public function reopen(User $actor, TechnicalIssue $issue, string $reason): TechnicalIssue
    {
        Gate::forUser($actor)->authorize('reopen', $issue);
        $this->rateLimiter->ensure($actor, 'reopen');
        $public = $this->text->body($reason, 2000);

        if ($public->value === null || mb_strlen($public->value) < 4) {
            throw new TechnicalIssueActionException('issues.errors.reopen_reason_required');
        }

        $collision = TechnicalIssue::query()
            ->where('active_identity_key', $issue->exact_identity_hash)
            ->whereKeyNot($issue->id)
            ->first(['public_id']);

        if ($collision instanceof TechnicalIssue) {
            throw new TechnicalIssueActionException('issues.errors.reopen_duplicate', canonicalPublicId: $collision->public_id);
        }

        return $this->transition(
            $actor,
            $issue,
            TechnicalIssueStatus::Reopened,
            'problem_recurred',
            $public->value,
            incrementReopen: true,
        );
    }

    public function merge(User $actor, TechnicalIssue $duplicate, TechnicalIssue $canonical): TechnicalIssue
    {
        Gate::forUser($actor)->authorize('manage', $duplicate);
        Gate::forUser($actor)->authorize('manage', $canonical);

        if ($duplicate->is($canonical) || ! $canonical->status->isOpen() || ! $this->mergeTargetsAreCompatible($duplicate, $canonical)) {
            throw new TechnicalIssueActionException('issues.errors.invalid_merge');
        }

        if (($this->types->requesterPrivate($duplicate->type, $duplicate->target_type)
            || $this->types->requesterPrivate($canonical->type, $canonical->target_type))
            && $duplicate->requester_id !== $canonical->requester_id) {
            throw new TechnicalIssueActionException('issues.errors.invalid_merge');
        }

        return DB::transaction(function () use ($actor, $duplicate, $canonical): TechnicalIssue {
            $lockedIssues = TechnicalIssue::query()
                ->whereKey([$duplicate->id, $canonical->id])
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');
            $locked = $lockedIssues->get($duplicate->id);
            $canonicalLocked = $lockedIssues->get($canonical->id);

            if (! $locked instanceof TechnicalIssue || ! $canonicalLocked instanceof TechnicalIssue) {
                throw new TechnicalIssueActionException('issues.errors.invalid_merge');
            }

            Gate::forUser($actor)->authorize('manage', $locked);
            Gate::forUser($actor)->authorize('manage', $canonicalLocked);

            if ($locked->merged_into_id === $canonicalLocked->id) {
                return $locked;
            }

            if (! $canonicalLocked->status->isOpen()
                || ! $this->mergeTargetsAreCompatible($locked, $canonicalLocked)
                || $locked->merged_into_id !== null
                || ! $locked->status->canTransitionTo(TechnicalIssueStatus::Merged)) {
                throw new TechnicalIssueActionException('issues.errors.invalid_merge');
            }

            if (($this->types->requesterPrivate($locked->type, $locked->target_type)
                || $this->types->requesterPrivate($canonicalLocked->type, $canonicalLocked->target_type))
                && $locked->requester_id !== $canonicalLocked->requester_id) {
                throw new TechnicalIssueActionException('issues.errors.invalid_merge');
            }

            TechnicalIssueConfirmation::query()->where('technical_issue_id', $locked->id)->select(['id', 'user_id'])->eachById(
                fn (TechnicalIssueConfirmation $confirmation) => TechnicalIssueConfirmation::query()->firstOrCreate([
                    'technical_issue_id' => $canonicalLocked->id,
                    'user_id' => $confirmation->user_id,
                ]),
            );
            TechnicalIssueFollower::query()->where('technical_issue_id', $locked->id)->select(['id', 'user_id'])->eachById(
                fn (TechnicalIssueFollower $follower) => TechnicalIssueFollower::query()->firstOrCreate([
                    'technical_issue_id' => $canonicalLocked->id,
                    'user_id' => $follower->user_id,
                ]),
            );
            TechnicalIssueConfirmation::query()
                ->where('technical_issue_id', $canonicalLocked->id)
                ->where('user_id', $canonicalLocked->requester_id)
                ->delete();
            TechnicalIssueMerge::query()->firstOrCreate(
                ['duplicate_issue_id' => $locked->id],
                ['canonical_issue_id' => $canonicalLocked->id, 'merged_by_id' => $actor->id],
            );
            $this->occurrences->mergeIssues($locked, $canonicalLocked);
            $previous = $locked->status;
            $locked->status = TechnicalIssueStatus::Merged;
            $locked->merged_into_id = $canonicalLocked->id;
            $locked->resolution_type = TechnicalIssueResolutionType::Duplicate;
            $locked->resolution_summary = null;
            $locked->active_identity_key = null;
            $locked->version++;
            $locked->save();
            TechnicalIssueStatusHistory::query()->create([
                'technical_issue_id' => $locked->id,
                'actor_id' => $actor->id,
                'from_status' => $previous,
                'to_status' => TechnicalIssueStatus::Merged,
                'public_reason_code' => 'merged',
                'idempotency_key' => hash('sha256', 'merge:'.$locked->id.':'.$canonicalLocked->id),
            ]);
            $canonicalLocked->version++;
            $canonicalLocked->save();
            DB::afterCommit(fn () => $this->notifications->changed(
                $locked->id,
                TechnicalIssueNotificationType::Merged,
                $actor->id,
                $canonicalLocked->public_id,
            ));

            return $locked;
        }, attempts: 3);
    }

    private function mergeTargetsAreCompatible(TechnicalIssue $duplicate, TechnicalIssue $canonical): bool
    {
        return $duplicate->type === $canonical->type
            && $duplicate->target_type === $canonical->target_type
            && $duplicate->catalog_title_id === $canonical->catalog_title_id
            && $duplicate->season_id === $canonical->season_id
            && $duplicate->episode_id === $canonical->episode_id
            && $duplicate->licensed_media_id === $canonical->licensed_media_id
            && $duplicate->translation_id === $canonical->translation_id
            && $duplicate->feature_code === $canonical->feature_code
            && $duplicate->route_name === $canonical->route_name;
    }

    public function redact(User $actor, TechnicalIssue $issue, string $field): TechnicalIssue
    {
        Gate::forUser($actor)->authorize('manage', $issue);

        if (! in_array($field, ['summary', 'expected_behavior', 'actual_behavior', 'reproduction_steps', 'resolution_summary'], true)) {
            throw new TechnicalIssueActionException('issues.errors.invalid_redaction_field');
        }

        return DB::transaction(function () use ($actor, $issue, $field): TechnicalIssue {
            $locked = TechnicalIssue::query()->lockForUpdate()->findOrFail($issue->id);
            Gate::forUser($actor)->authorize('manage', $locked);
            $before = (string) ($locked->{$field} ?? '');

            if ($before === '' || TechnicalIssueRedaction::query()
                ->where('technical_issue_id', $locked->id)
                ->where('field', $field)
                ->where('reason_code', 'manual')
                ->exists()) {
                throw new TechnicalIssueActionException('issues.errors.nothing_to_redact');
            }

            $sanitized = $field === 'summary' ? $this->text->summary($before) : $this->text->body($before, 6000);

            if ($sanitized->redactionReasons === []) {
                $sanitized = $this->text->redactAll($before);
            }

            $locked->{$field} = $sanitized->value;

            if ($field !== 'resolution_summary') {
                $locked->loadMissing('diagnostic');
                $identity = $this->identity->fromIssue($locked);
                $locked->exact_identity_hash = $identity;
                $locked->active_identity_key = $locked->status->isOpen() ? $identity : null;
            }

            $locked->version++;
            $locked->save();

            foreach ($sanitized->redactionReasons as $reason) {
                TechnicalIssueRedaction::query()->create([
                    'technical_issue_id' => $locked->id,
                    'actor_id' => $actor->id,
                    'field' => $field,
                    'reason_code' => $reason,
                    'before_hash' => $sanitized->beforeHash,
                    'after_hash' => $sanitized->afterHash,
                ]);
            }

            return $locked;
        }, attempts: 3);
    }

    public function redactMessage(User $actor, TechnicalIssue $issue, string $messagePublicId): TechnicalIssueMessage
    {
        Gate::forUser($actor)->authorize('manage', $issue);

        return DB::transaction(function () use ($actor, $issue, $messagePublicId): TechnicalIssueMessage {
            $lockedIssue = TechnicalIssue::query()->lockForUpdate()->findOrFail($issue->id);
            Gate::forUser($actor)->authorize('manage', $lockedIssue);
            $message = TechnicalIssueMessage::query()
                ->where('technical_issue_id', $lockedIssue->id)
                ->where('public_id', $messagePublicId)
                ->lockForUpdate()
                ->firstOrFail();

            if ($message->redacted_at !== null) {
                throw new TechnicalIssueActionException('issues.errors.nothing_to_redact');
            }

            $sanitized = $this->text->body($message->body, 6000);

            if ($sanitized->redactionReasons === []) {
                $sanitized = $this->text->redactAll($message->body);
            }

            $message->body = $sanitized->value;
            $message->body_hash = hash('sha256', $sanitized->value ?? '');
            $message->redacted_at = now()->toImmutable();
            $message->save();

            foreach ($sanitized->redactionReasons as $reason) {
                TechnicalIssueRedaction::query()->create([
                    'technical_issue_id' => $lockedIssue->id,
                    'technical_issue_message_id' => $message->id,
                    'actor_id' => $actor->id,
                    'field' => 'message.body',
                    'reason_code' => $reason,
                    'before_hash' => $sanitized->beforeHash,
                    'after_hash' => $sanitized->afterHash,
                ]);
            }

            $lockedIssue->version++;
            $lockedIssue->save();

            return $message;
        }, attempts: 3);
    }

    /** @return list<string> */
    public function rejectionReasons(): array
    {
        return ['invalid', 'insufficient_information', 'abusive', 'duplicate', 'unrelated', 'feature_request', 'content_request', 'intended_behavior', 'unsupported_environment'];
    }

    private function reasonCode(string $value): string
    {
        return preg_match('/^[a-z][a-z0-9_]{1,47}$/D', $value) === 1 ? $value : 'status_changed';
    }

    private function notificationType(TechnicalIssueStatus $status): TechnicalIssueNotificationType
    {
        return match ($status) {
            TechnicalIssueStatus::ClarificationNeeded, TechnicalIssueStatus::WaitingForRequester => TechnicalIssueNotificationType::Clarification,
            TechnicalIssueStatus::Resolved => TechnicalIssueNotificationType::Resolved,
            TechnicalIssueStatus::ResolutionVerified => TechnicalIssueNotificationType::ResolutionVerified,
            TechnicalIssueStatus::Closed => TechnicalIssueNotificationType::Closed,
            TechnicalIssueStatus::Reopened => TechnicalIssueNotificationType::Reopened,
            TechnicalIssueStatus::Rejected => TechnicalIssueNotificationType::Rejected,
            TechnicalIssueStatus::Merged => TechnicalIssueNotificationType::Merged,
            default => TechnicalIssueNotificationType::StatusChanged,
        };
    }
}
