<?php

declare(strict_types=1);

namespace App\Actions\ContentRequests;

use App\Enums\ContentRequestStatus;
use App\Exceptions\ContentRequests\ContentRequestActionException;
use App\Models\ContentRequest;
use App\Models\ContentRequestStatusHistory;
use App\Models\User;
use App\Services\ContentRequests\ContentRequestCacheInvalidator;
use App\Services\ContentRequests\ContentRequestNotificationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

final readonly class MergeContentRequests
{
    public function __construct(private ContentRequestCacheInvalidator $cache, private ContentRequestNotificationService $notifications) {}

    public function handle(User $moderator, int $sourceId, int $canonicalId): ContentRequest
    {
        if ($sourceId === $canonicalId) {
            throw new ContentRequestActionException('requests.errors.invalid_merge');
        }

        $recipientIds = [];
        $sourcePublicId = null;
        $canonical = DB::transaction(function () use ($moderator, $sourceId, $canonicalId, &$recipientIds, &$sourcePublicId): ContentRequest {
            $requests = ContentRequest::query()
                ->whereKey([$sourceId, $canonicalId])
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');
            $source = $requests->get($sourceId);
            $canonical = $requests->get($canonicalId);

            if (! $source instanceof ContentRequest || ! $canonical instanceof ContentRequest) {
                throw new ContentRequestActionException('requests.errors.not_found');
            }

            Gate::forUser($moderator)->authorize('moderate', $source);
            Gate::forUser($moderator)->authorize('moderate', $canonical);
            $this->assertCompatible($source, $canonical);
            $recipientIds = $source->followers()->pluck('user_id')
                ->push($source->requester_id)
                ->filter()->map(fn (mixed $id): int => (int) $id)->unique()->values()->all();
            $sourcePublicId = $source->public_id;
            $sourceStatus = $source->status;

            foreach ($source->votes()->get(['user_id']) as $vote) {
                $canonical->votes()->firstOrCreate(['user_id' => $vote->user_id]);
            }

            foreach ($source->followers()->get(['user_id']) as $follow) {
                $canonical->followers()->firstOrCreate(['user_id' => $follow->user_id]);
            }

            foreach ($source->sourceLinks()->get() as $link) {
                $canonical->sourceLinks()->firstOrCreate(
                    ['url_hash' => $link->url_hash],
                    $link->only(['added_by_id', 'verified_by_id', 'url', 'provider', 'is_public', 'verified_at']),
                );
            }

            foreach ($source->externalIdentifiers()->get() as $identifier) {
                $canonical->externalIdentifiers()->firstOrCreate(
                    ['provider' => $identifier->provider, 'normalized_identifier' => $identifier->normalized_identifier],
                    ['identifier' => $identifier->identifier],
                );
            }

            $source->clarifications()->update(['content_request_id' => $canonical->id]);
            $source->votes()->delete();
            $source->followers()->delete();
            $source->status = ContentRequestStatus::Merged;
            $source->merged_into_id = $canonical->id;
            $source->active_identity_key = null;
            $source->version++;
            $source->save();

            if ($canonical->requester_id === null && $source->requester_id !== null) {
                $canonical->requester_id = $source->requester_id;
            }

            $canonical->probable_duplicate = false;
            $canonical->version++;
            $canonical->save();
            ContentRequestStatusHistory::query()->create([
                'content_request_id' => $source->id,
                'actor_id' => $moderator->id,
                'from_status' => $sourceStatus,
                'to_status' => ContentRequestStatus::Merged,
                'public_reason' => null,
                'idempotency_key' => hash('sha256', 'merge-source:'.$source->id.':'.$canonical->id),
            ]);
            ContentRequestStatusHistory::query()->firstOrCreate(
                ['idempotency_key' => hash('sha256', 'merge-canonical:'.$source->id.':'.$canonical->id)],
                [
                    'content_request_id' => $canonical->id,
                    'actor_id' => $moderator->id,
                    'from_status' => $canonical->status,
                    'to_status' => $canonical->status,
                    'public_reason' => null,
                ],
            );

            return $canonical;
        }, attempts: 3);

        $this->cache->changed($sourcePublicId, sitemap: true);
        $this->cache->changed($canonical->public_id, sitemap: true);
        $source = ContentRequest::query()->findOrFail($sourceId);
        $this->notifications->merged($source, $canonical, $moderator, $recipientIds);

        return $canonical;
    }

    private function assertCompatible(ContentRequest $source, ContentRequest $canonical): void
    {
        if ($source->status->isTerminal() || $canonical->status->isTerminal()
            || $source->type !== $canonical->type
            || $source->catalog_title_id !== $canonical->catalog_title_id
            || $source->season_id !== $canonical->season_id
            || $source->episode_id !== $canonical->episode_id
            || $source->audio_language !== $canonical->audio_language
            || $source->subtitle_language !== $canonical->subtitle_language) {
            throw new ContentRequestActionException('requests.errors.incompatible_merge');
        }
    }
}
