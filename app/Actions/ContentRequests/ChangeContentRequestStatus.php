<?php

declare(strict_types=1);

namespace App\Actions\ContentRequests;

use App\Enums\ContentRequestRejectionReason;
use App\Enums\ContentRequestStatus;
use App\Enums\ContentRequestType;
use App\Exceptions\ContentRequests\ContentRequestActionException;
use App\Models\CatalogTitle;
use App\Models\ContentRequest;
use App\Models\ContentRequestStatusHistory;
use App\Models\Episode;
use App\Models\LicensedMedia;
use App\Models\Season;
use App\Models\User;
use App\Services\ContentRequests\ContentRequestCacheInvalidator;
use App\Services\ContentRequests\ContentRequestNotificationService;
use App\Services\ContentRequests\ContentRequestRateLimiter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

final readonly class ChangeContentRequestStatus
{
    public function __construct(
        private ContentRequestRateLimiter $rateLimiter,
        private ContentRequestCacheInvalidator $cache,
        private ContentRequestNotificationService $notifications,
    ) {}

    /** @param array{catalog_title_id?: int|null, season_id?: int|null, episode_id?: int|null, media_id?: int|null} $completion */
    public function handle(
        User $actor,
        int $requestId,
        ContentRequestStatus|string $desired,
        int $expectedVersion,
        ?string $publicReason = null,
        ?string $privateNote = null,
        ContentRequestRejectionReason|string|null $rejectionReason = null,
        array $completion = [],
    ): ContentRequest {
        $desired = is_string($desired) ? ContentRequestStatus::tryFrom($desired) : $desired;
        $rejectionReason = is_string($rejectionReason) ? ContentRequestRejectionReason::tryFrom($rejectionReason) : $rejectionReason;

        if ($desired === null) {
            throw new ContentRequestActionException('requests.errors.invalid_status');
        }

        $request = ContentRequest::query()->findOrFail($requestId);
        Gate::forUser($actor)->authorize('moderate', $request);
        $this->rateLimiter->hit('moderate', $actor, (string) $requestId);
        $publicReason = $this->clean($publicReason, 1_000);
        $privateNote = $this->clean($privateNote, 4_000);

        if ($desired === ContentRequestStatus::Rejected && $rejectionReason === null) {
            throw new ContentRequestActionException('requests.errors.rejection_reason_required');
        }

        $updated = DB::transaction(function () use (
            $actor,
            $requestId,
            $desired,
            $expectedVersion,
            $publicReason,
            $privateNote,
            $rejectionReason,
            $completion,
        ): ContentRequest {
            $request = ContentRequest::query()->lockForUpdate()->findOrFail($requestId);
            Gate::forUser($actor)->authorize('moderate', $request);

            if ($request->version !== $expectedVersion) {
                throw new ContentRequestActionException('requests.errors.stale_request');
            }

            if ($request->status === $desired) {
                return $request;
            }

            if (! in_array($desired, $request->status->transitions(), true)) {
                throw new ContentRequestActionException('requests.errors.invalid_transition');
            }

            $this->assertCompletion($request, $desired, $completion);
            $from = $request->status;
            $request->status = $desired;
            $request->version++;
            $request->public_note = $publicReason;
            $request->private_moderator_note = $privateNote ?: $request->private_moderator_note;
            $request->rejection_reason = $desired === ContentRequestStatus::Rejected ? $rejectionReason : null;

            if ($desired->isTerminal()) {
                $request->active_identity_key = null;
            }

            if ($desired === ContentRequestStatus::PartiallyCompleted) {
                $request->partial_completed_at = now();
            }

            if ($desired === ContentRequestStatus::Completed) {
                $request->completed_at = now();
            }

            if (in_array($desired, [
                ContentRequestStatus::Approved,
                ContentRequestStatus::Planned,
                ContentRequestStatus::InProgress,
                ContentRequestStatus::PartiallyCompleted,
                ContentRequestStatus::Completed,
            ], true)) {
                $request->is_public = true;
            }

            $request->completed_catalog_title_id = $completion['catalog_title_id'] ?? $request->completed_catalog_title_id;
            $request->completed_season_id = $completion['season_id'] ?? $request->completed_season_id;
            $request->completed_episode_id = $completion['episode_id'] ?? $request->completed_episode_id;
            $request->completed_media_id = $completion['media_id'] ?? $request->completed_media_id;
            $request->save();

            ContentRequestStatusHistory::query()->create([
                'content_request_id' => $request->id,
                'actor_id' => $actor->id,
                'from_status' => $from,
                'to_status' => $desired,
                'public_reason' => $publicReason,
                'private_note' => $privateNote,
                'idempotency_key' => hash('sha256', 'status:'.$request->id.':'.$request->version.':'.$desired->value),
            ]);

            return $request;
        }, attempts: 3);

        $this->cache->changed($updated->public_id, sitemap: true);
        $this->notifications->statusChanged($updated, $actor);

        return $updated;
    }

    /** @param array{catalog_title_id?: int|null, season_id?: int|null, episode_id?: int|null, media_id?: int|null} $completion */
    private function assertCompletion(ContentRequest $request, ContentRequestStatus $status, array $completion): void
    {
        if (! in_array($status, [ContentRequestStatus::PartiallyCompleted, ContentRequestStatus::Completed], true)) {
            return;
        }

        $targetIds = array_filter([
            'catalog_title_id' => $completion['catalog_title_id'] ?? null,
            'season_id' => $completion['season_id'] ?? null,
            'episode_id' => $completion['episode_id'] ?? null,
            'media_id' => $completion['media_id'] ?? null,
        ], static fn (?int $id): bool => $id !== null);

        if ($targetIds === []) {
            throw new ContentRequestActionException('requests.errors.completion_target_required');
        }

        $title = isset($targetIds['catalog_title_id'])
            ? CatalogTitle::query()->availableTo(null)->find($targetIds['catalog_title_id'])
            : null;
        $season = isset($targetIds['season_id'])
            ? Season::query()->availableTo(null)->find($targetIds['season_id'])
            : null;
        $episode = isset($targetIds['episode_id'])
            ? Episode::query()->availableTo(null)->with('season:id,catalog_title_id,number')->find($targetIds['episode_id'])
            : null;
        $media = isset($targetIds['media_id'])
            ? LicensedMedia::query()->published()
                ->with(['season:id,catalog_title_id', 'episode:id,season_id', 'episode.season:id,catalog_title_id'])
                ->find($targetIds['media_id'])
            : null;

        if ((isset($targetIds['catalog_title_id']) && ! $title instanceof CatalogTitle)
            || (isset($targetIds['season_id']) && ! $season instanceof Season)
            || (isset($targetIds['episode_id']) && ! $episode instanceof Episode)
            || (isset($targetIds['media_id']) && ! $media instanceof LicensedMedia)) {
            throw new ContentRequestActionException('requests.errors.completion_target_required');
        }

        if ($request->type === ContentRequestType::Serial && ! $title instanceof CatalogTitle) {
            throw new ContentRequestActionException('requests.errors.completion_target_required');
        }

        if ($request->type === ContentRequestType::Season
            && (! $season instanceof Season
                || $season->catalog_title_id !== $request->catalog_title_id
                || $season->number !== $request->season_number)) {
            throw new ContentRequestActionException('requests.errors.completion_target_required');
        }

        if ($request->type === ContentRequestType::Episode
            && (! $episode instanceof Episode
                || $episode->season_id !== $request->season_id
                || $episode->number !== $request->episode_number)) {
            throw new ContentRequestActionException('requests.errors.completion_target_required');
        }

        $resultTitleId = $title?->id
            ?? $season?->catalog_title_id
            ?? $episode?->season?->catalog_title_id
            ?? $media?->catalog_title_id
            ?? $media?->season?->catalog_title_id
            ?? $media?->episode?->season?->catalog_title_id;

        if ($request->catalog_title_id !== null && $resultTitleId !== $request->catalog_title_id) {
            throw new ContentRequestActionException('requests.errors.completion_target_required');
        }

        if (in_array($request->type, [
            ContentRequestType::Translation,
            ContentRequestType::Subtitles,
            ContentRequestType::QualityUpgrade,
            ContentRequestType::BrokenContentRestoration,
        ], true) && ! $media instanceof LicensedMedia) {
            throw new ContentRequestActionException('requests.errors.completion_target_required');
        }

        if ($media instanceof LicensedMedia) {
            $mediaEpisodeId = $media->episode_id;
            $mediaSeasonId = $media->season_id ?? $media->episode?->season_id;

            if (($request->episode_id !== null && $mediaEpisodeId !== $request->episode_id)
                || ($request->episode_id === null && $request->season_id !== null && $mediaSeasonId !== $request->season_id)) {
                throw new ContentRequestActionException('requests.errors.completion_target_required');
            }
        }
    }

    private function clean(?string $value, int $limit): ?string
    {
        $clean = trim(mb_substr(strip_tags((string) $value), 0, $limit));

        return $clean !== '' ? $clean : null;
    }
}
