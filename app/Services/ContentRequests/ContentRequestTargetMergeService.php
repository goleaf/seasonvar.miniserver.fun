<?php

declare(strict_types=1);

namespace App\Services\ContentRequests;

use App\Enums\ContentRequestStatus;
use App\Models\ContentRequest;
use App\Models\ContentRequestStatusHistory;
use App\Models\Episode;
use App\Models\Season;
use Illuminate\Database\Eloquent\Builder;

final readonly class ContentRequestTargetMergeService
{
    public function __construct(private ContentRequestSchema $schema, private ContentRequestIdentity $identity) {}

    public function moveTitle(int $sourceId, int $canonicalId): void
    {
        if (! $this->schema->ready()) {
            return;
        }

        ContentRequest::query()->where('completed_catalog_title_id', $sourceId)->update(['completed_catalog_title_id' => $canonicalId]);
        $this->retarget(fn (Builder $query): Builder => $query->where('catalog_title_id', $sourceId), ['catalog_title_id' => $canonicalId]);
    }

    public function moveSeason(Season $source, Season $canonical): void
    {
        if (! $this->schema->ready()) {
            return;
        }

        ContentRequest::query()->where('completed_season_id', $source->id)->update(['completed_season_id' => $canonical->id]);
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

        ContentRequest::query()->where('completed_episode_id', $source->id)->update(['completed_episode_id' => $canonical->id]);
        $canonical->loadMissing('season');
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
     * @param array<string, int|string> $target
     */
    private function retarget(callable $scope, array $target): void
    {
        $requests = $scope(ContentRequest::query())
            ->with(['externalIdentifiers', 'votes', 'followers', 'sourceLinks', 'clarifications'])
            ->orderBy('id')->lockForUpdate()->get();

        foreach ($requests as $request) {
            $request->fill([...$target, 'active_identity_key' => null]);
            $request->save();
            $hash = $this->identity->forRequest($request);
            $canonical = ContentRequest::query()
                ->where('active_identity_key', $hash)
                ->whereKeyNot($request->id)
                ->first();

            if ($request->status->isOpen() && $canonical !== null) {
                $this->merge($request, $canonical);

                continue;
            }

            $request->exact_identity_hash = $hash;
            $request->active_identity_key = $request->status->isOpen() ? $hash : null;
            $request->version++;
            $request->save();
        }
    }

    private function merge(ContentRequest $source, ContentRequest $canonical): void
    {
        foreach ($source->votes as $vote) {
            $canonical->votes()->firstOrCreate(['user_id' => $vote->user_id]);
        }

        foreach ($source->followers as $follow) {
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

        $source->clarifications()->update(['content_request_id' => $canonical->id]);
        $source->votes()->delete();
        $source->followers()->delete();
        $from = $source->status;
        $source->status = ContentRequestStatus::Merged;
        $source->merged_into_id = $canonical->id;
        $source->active_identity_key = null;
        $source->version++;
        $source->save();
        ContentRequestStatusHistory::query()->create([
            'content_request_id' => $source->id,
            'from_status' => $from,
            'to_status' => ContentRequestStatus::Merged,
            'public_reason' => null,
            'idempotency_key' => hash('sha256', 'target-merge:'.$source->id.':'.$canonical->id),
        ]);
    }
}
