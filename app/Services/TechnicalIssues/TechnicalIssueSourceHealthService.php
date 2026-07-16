<?php

declare(strict_types=1);

namespace App\Services\TechnicalIssues;

use App\Enums\MediaHealthStatus;
use App\Exceptions\TechnicalIssues\TechnicalIssueActionException;
use App\Models\LicensedMedia;
use App\Models\TechnicalIssue;
use App\Models\TechnicalIssueSourceAction;
use App\Models\User;
use App\Services\Catalog\CatalogCacheInvalidator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

final readonly class TechnicalIssueSourceHealthService
{
    public function __construct(
        private TechnicalIssueTextSanitizer $text,
        private CatalogCacheInvalidator $cache,
    ) {}

    public function apply(User $actor, TechnicalIssue $issue, string $action, ?string $privateNote = null): LicensedMedia
    {
        Gate::forUser($actor)->authorize('manage', $issue);

        if (! in_array($action, config('technical-issues.source_actions', []), true) || $issue->licensed_media_id === null) {
            throw new TechnicalIssueActionException('issues.errors.invalid_source_action');
        }

        $note = $this->text->body($privateNote, 4000);

        $media = DB::transaction(function () use ($actor, $issue, $action, $note): LicensedMedia {
            $lockedIssue = TechnicalIssue::query()->lockForUpdate()->findOrFail($issue->id);
            Gate::forUser($actor)->authorize('manage', $lockedIssue);
            $media = LicensedMedia::query()->lockForUpdate()->findOrFail($lockedIssue->licensed_media_id);

            if ($media->catalog_title_id !== $lockedIssue->catalog_title_id
                || $lockedIssue->episode_id !== null && $media->episode_id !== $lockedIssue->episode_id) {
                throw new TechnicalIssueActionException('issues.errors.invalid_source_action');
            }

            $before = $media->health_status;

            if ($action === 'disabled' && $before === MediaHealthStatus::Disabled
                || $action === 'restored' && $before === MediaHealthStatus::Active && $media->status === 'published'
                || $action === 'under_review' && TechnicalIssueSourceAction::query()
                    ->where('technical_issue_id', $lockedIssue->id)
                    ->where('licensed_media_id', $media->id)
                    ->where('action', 'under_review')
                    ->exists()) {
                return $media;
            }

            if ($action === 'disabled' && $media->status !== 'published') {
                throw new TechnicalIssueActionException('issues.errors.invalid_source_action');
            }

            if ($action === 'restored' && ($before !== MediaHealthStatus::Disabled
                || $media->status !== 'unavailable'
                || ! TechnicalIssueSourceAction::query()
                    ->where('licensed_media_id', $media->id)
                    ->where('action', 'disabled')
                    ->exists())) {
                throw new TechnicalIssueActionException('issues.errors.invalid_source_action');
            }

            if ($action === 'disabled') {
                $media->forceFill([
                    'health_status' => MediaHealthStatus::Disabled,
                    'status' => 'unavailable',
                    'next_check_at' => null,
                ])->save();
            } elseif ($action === 'restored') {
                $media->forceFill([
                    'health_status' => MediaHealthStatus::Active,
                    'status' => 'published',
                    'check_status' => 'not_checked',
                    'last_error_category' => null,
                    'consecutive_failures' => 0,
                    'next_check_at' => now(),
                ])->save();
            }

            TechnicalIssueSourceAction::query()->create([
                'technical_issue_id' => $lockedIssue->id,
                'licensed_media_id' => $media->id,
                'actor_id' => $actor->id,
                'action' => $action,
                'from_health_status' => $before->value,
                'to_health_status' => $media->health_status->value,
                'private_note' => $note->value,
            ]);
            $lockedIssue->version++;
            $lockedIssue->save();

            if ($action !== 'under_review' && $media->catalog_title_id !== null) {
                DB::afterCommit(fn () => $this->cache->catalogChanged([$media->catalog_title_id]));
            }

            return $media;
        }, attempts: 3);

        return $media;
    }
}
