<?php

declare(strict_types=1);

namespace App\Services\ContentRequests;

use App\Enums\ContentRequestStatus;
use App\Models\ContentRequest;
use App\Models\ContentRequestStatusHistory;
use App\Models\Episode;
use App\Models\Season;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

final readonly class ContentRequestTargetMergeService
{
    public function __construct(
        private ContentRequestSchema $schema,
        private ContentRequestIdentity $identity,
        private ContentRequestCacheInvalidator $cache,
        private ContentRequestNotificationService $notifications,
    ) {}

    public function moveTitle(int $sourceId, int $canonicalId): void
    {
        if (! $this->schema->ready()) {
            return;
        }

        $this->moveCompletion('completed_catalog_title_id', $sourceId, $canonicalId);
        $this->retarget(fn (Builder $query): Builder => $query->where('catalog_title_id', $sourceId), ['catalog_title_id' => $canonicalId]);
    }

    public function moveSeason(Season $source, Season $canonical): void
    {
        if (! $this->schema->ready()) {
            return;
        }

        $this->moveCompletion('completed_season_id', $source->id, $canonical->id);
        $this->retarget(fn (Builder $query): Builder => $query->where('season_id', $source->id), [
            'catalog_title_id' => $canonical->catalog_title_id,
            'season_id' => $canonical->id,
            'season_number' => $canonical->number,
            'season_kind' => $canonical->kind->value,
        ]);
    }

    public function moveEpisode(Episode $source, Episode $canonical): void
    {
        if (! $this->schema->ready()) {
            return;
        }

        $this->moveCompletion('completed_episode_id', $source->id, $canonical->id);
        $canonical->loadMissing('season:id,catalog_title_id,number,kind');
        $this->retarget(fn (Builder $query): Builder => $query->where('episode_id', $source->id), [
            'catalog_title_id' => $canonical->season->catalog_title_id,
            'season_id' => $canonical->season_id,
            'episode_id' => $canonical->id,
            'season_number' => $canonical->season->number,
            'season_kind' => $canonical->season->kind->value,
            'episode_number' => $canonical->number,
        ]);
    }

    /** @param callable(Builder<ContentRequest>): Builder<ContentRequest> $scope
     * @param  array<string, int|string>  $target
     */
    private function retarget(callable $scope, array $target): void
    {
        $requests = $scope(ContentRequest::query())
            ->with([
                'externalIdentifiers:id,content_request_id,provider,identifier,normalized_identifier',
                'votes:id,content_request_id,user_id',
                'followers:id,content_request_id,user_id',
                'sourceLinks:id,content_request_id,added_by_id,verified_by_id,url,url_hash,provider,is_public,verified_at',
            ])
            ->orderBy('id')->lockForUpdate()->get();

        foreach ($requests as $request) {
            $request->fill([...$target, 'active_identity_key' => null]);
            $request->save();
            $hash = $this->identity->forRequest($request);
            $canonical = ContentRequest::query()
                ->where('active_identity_key', $hash)
                ->whereKeyNot($request->id)
                ->first();

            if ($request->status->isOpen() && $canonical !== null && $this->canMerge($request, $canonical)) {
                $this->merge($request, $canonical);

                continue;
            }

            $request->exact_identity_hash = $hash;
            $request->active_identity_key = $request->status->isOpen() && $canonical === null ? $hash : null;
            $request->probable_duplicate = $request->status->isOpen() && $canonical !== null;
            $request->version++;
            $request->save();
            $this->cache->changed($request->public_id, sitemap: true);
        }
    }

    private function merge(ContentRequest $source, ContentRequest $canonical): void
    {
        $recipients = [];

        if ($source->requester_id !== null) {
            $recipients[$source->requester_id] = 'requester';
        }

        foreach ($source->votes as $vote) {
            $recipients[$vote->user_id] ??= 'voted';
            $canonical->votes()->firstOrCreate(['user_id' => $vote->user_id]);
        }

        foreach ($source->followers as $follow) {
            $recipients[$follow->user_id] ??= 'followed';
            $canonical->followers()->firstOrCreate(['user_id' => $follow->user_id]);
        }

        foreach ($source->sourceLinks as $link) {
            $canonical->sourceLinks()->firstOrCreate(['url_hash' => $link->url_hash], $link->only(['added_by_id', 'verified_by_id', 'url', 'provider', 'is_public', 'verified_at']));
        }

        foreach ($source->externalIdentifiers as $identifier) {
            $canonical->externalIdentifiers()->firstOrCreate(
                ['provider' => $identifier->provider, 'normalized_identifier' => $identifier->normalized_identifier],
                ['identifier' => $identifier->identifier],
            );
        }

        if ($canonical->requester_id === null || $source->requester_id === $canonical->requester_id) {
            $source->clarifications()->update(['content_request_id' => $canonical->id]);
        }
        $source->votes()->delete();
        $source->followers()->delete();
        $from = $source->status;
        $source->status = ContentRequestStatus::Merged;
        $source->merged_into_id = $canonical->id;
        $source->active_identity_key = null;
        $source->version++;
        $source->save();

        if ($canonical->requester_id === null && $source->requester_id !== null) {
            $canonical->requester_id = $source->requester_id;
        }

        $canonical->is_public = $canonical->is_public || $source->is_public;
        $canonical->version++;
        $canonical->save();
        ContentRequestStatusHistory::query()->create([
            'content_request_id' => $source->id,
            'from_status' => $from,
            'to_status' => ContentRequestStatus::Merged,
            'public_reason' => null,
            'idempotency_key' => hash('sha256', 'target-merge:'.$source->id.':'.$canonical->id),
        ]);
        $this->cache->changed($source->public_id, sitemap: true);
        $this->cache->changed($canonical->public_id, sitemap: true);
        $notify = fn () => $this->notifications->merged($source, $canonical, null, $recipients);
        DB::transactionLevel() > 0 ? DB::afterCommit($notify) : $notify();
    }

    private function canMerge(ContentRequest $source, ContentRequest $canonical): bool
    {
        return ($source->is_public && $canonical->is_public)
            || $source->requester_id === $canonical->requester_id;
    }

    private function moveCompletion(string $column, int $sourceId, int $canonicalId): void
    {
        $publicIds = ContentRequest::query()->where($column, $sourceId)->pluck('public_id')->all();
        ContentRequest::query()->where($column, $sourceId)->update([$column => $canonicalId]);

        foreach ($publicIds as $publicId) {
            $this->cache->changed((string) $publicId, sitemap: true);
        }
    }
}
