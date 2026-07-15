<?php

declare(strict_types=1);

namespace App\Services\Api\V1\Sync;

use App\DTOs\ApiSyncMutationResult;
use App\Models\ApiSyncMutation;
use App\Models\CatalogTitle;
use App\Models\CatalogTitleUserState;
use App\Models\EpisodeViewProgress;
use App\Models\User;
use App\Services\Catalog\CatalogTitleQuery;
use App\Services\Catalog\CatalogUserStateService;
use App\Services\Catalog\CatalogViewingActivityService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class ApiSyncMutationService
{
    public function __construct(
        private readonly CatalogTitleQuery $titles,
        private readonly CatalogUserStateService $states,
        private readonly CatalogViewingActivityService $activity,
    ) {}

    /** @param array<string, mixed> $operation */
    public function apply(User $user, array $operation): ApiSyncMutationResult
    {
        $mutationId = (string) $operation['mutation_id'];
        $payloadHash = hash('sha256', json_encode($this->canonicalize($operation), JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($user, $operation, $mutationId, $payloadHash): ApiSyncMutationResult {
            $now = now();
            $inserted = ApiSyncMutation::query()->insertOrIgnore([
                'user_id' => $user->id,
                'mutation_id' => $mutationId,
                'payload_hash' => $payloadHash,
                'status' => 'processing',
                'result' => json_encode([], JSON_THROW_ON_ERROR),
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $receipt = ApiSyncMutation::query()
                ->whereBelongsTo($user)
                ->where('mutation_id', $mutationId)
                ->lockForUpdate()
                ->firstOrFail();

            if (! hash_equals((string) $receipt->payload_hash, $payloadHash)) {
                return new ApiSyncMutationResult(
                    mutationId: $mutationId,
                    status: 'conflict',
                    data: ['code' => 'mutation_id_reused'],
                );
            }

            if ($inserted === 0) {
                return $this->duplicate($mutationId, $receipt);
            }

            $result = $this->execute($user, $operation);
            $receipt->forceFill([
                'status' => $result->status,
                'result' => $result->receipt(),
            ])->save();

            return $result;
        }, attempts: 3);
    }

    /** @param array<string, mixed> $operation */
    private function execute(User $user, array $operation): ApiSyncMutationResult
    {
        $mutationId = (string) $operation['mutation_id'];

        try {
            return match ($operation['type']) {
                'watchlist.set' => $this->watchlist($user, $operation),
                'rating.set' => $this->rating($user, $operation),
                'progress.set' => $this->progress($user, $operation),
                'history.delete' => $this->historyDelete($user, $operation),
                'history.clear' => $this->historyClear($user, $mutationId),
                default => new ApiSyncMutationResult($mutationId, 'rejected', data: [
                    'code' => 'unsupported_operation',
                ]),
            };
        } catch (ModelNotFoundException|NotFoundHttpException) {
            return new ApiSyncMutationResult($mutationId, 'not_found', data: [
                'code' => 'resource_not_found',
            ]);
        } catch (AuthorizationException|ValidationException) {
            return new ApiSyncMutationResult($mutationId, 'rejected', data: [
                'code' => 'operation_rejected',
            ]);
        }
    }

    /** @param array<string, mixed> $operation */
    private function watchlist(User $user, array $operation): ApiSyncMutationResult
    {
        $title = $this->title($user, (string) $operation['title_slug']);
        $result = $this->states->setWatchlistAtVersion(
            $user,
            $title,
            (bool) $operation['value'],
            (int) $operation['expected_version'],
        );

        return new ApiSyncMutationResult(
            mutationId: (string) $operation['mutation_id'],
            status: $result['applied'] ? 'applied' : 'conflict',
            resourceVersion: $result['version'],
            data: $this->stateData($result['state']),
        );
    }

    /** @param array<string, mixed> $operation */
    private function rating(User $user, array $operation): ApiSyncMutationResult
    {
        $title = $this->title($user, (string) $operation['title_slug']);
        $rating = $operation['value'];
        $result = $this->states->setRatingAtVersion(
            $user,
            $title,
            $rating === null ? null : (int) $rating,
            (int) $operation['expected_version'],
        );

        return new ApiSyncMutationResult(
            mutationId: (string) $operation['mutation_id'],
            status: $result['applied'] ? 'applied' : 'conflict',
            resourceVersion: $result['version'],
            data: $this->stateData($result['state']),
        );
    }

    /** @param array<string, mixed> $operation */
    private function progress(User $user, array $operation): ApiSyncMutationResult
    {
        $title = $this->title($user, (string) $operation['title_slug']);
        $progress = $this->states->recordProgress(
            $user,
            $title,
            (int) $operation['episode_id'],
            (string) $operation['playback_session'],
            (int) $operation['event_sequence'],
            (int) $operation['position_seconds'],
            (int) $operation['duration_seconds'],
            (bool) $operation['ended'],
        );

        if (! $progress instanceof EpisodeViewProgress) {
            return new ApiSyncMutationResult(
                (string) $operation['mutation_id'],
                'rejected',
                data: ['code' => 'invalid_playback_progress'],
            );
        }

        return new ApiSyncMutationResult(
            (string) $operation['mutation_id'],
            'applied',
            data: [
                'id' => (int) $progress->id,
                'catalog_title_id' => (int) $progress->catalog_title_id,
                'episode_id' => (int) $progress->episode_id,
                'position_seconds' => (int) $progress->position_seconds,
                'duration_seconds' => (int) $progress->duration_seconds,
                'progress_percent' => $progress->progress_percent === null ? null : (int) $progress->progress_percent,
                'completed' => $progress->completed_at !== null,
                'completed_at' => $progress->completed_at?->toJSON(),
                'last_watched_at' => $progress->last_watched_at->toJSON(),
            ],
        );
    }

    /** @param array<string, mixed> $operation */
    private function historyDelete(User $user, array $operation): ApiSyncMutationResult
    {
        $this->activity->removeOwned($user, (int) $operation['progress_id']);

        return new ApiSyncMutationResult((string) $operation['mutation_id'], 'applied');
    }

    private function historyClear(User $user, string $mutationId): ApiSyncMutationResult
    {
        $this->activity->clear($user);

        return new ApiSyncMutationResult($mutationId, 'applied');
    }

    private function title(User $user, string $slug): CatalogTitle
    {
        return $this->titles->visibleTo($user)->where('slug', $slug)->firstOrFail();
    }

    /** @return array{in_watchlist: bool, rating: int|null, versions: array{watchlist: int, rating: int}} */
    private function stateData(?CatalogTitleUserState $state): array
    {
        if ($state === null) {
            return [
                'in_watchlist' => false,
                'rating' => null,
                'versions' => [
                    'watchlist' => 0,
                    'rating' => 0,
                ],
            ];
        }

        return [
            'in_watchlist' => $state->in_watchlist,
            'rating' => $state->rating,
            'versions' => [
                'watchlist' => $state->watchlistVersion(),
                'rating' => $state->ratingVersion(),
            ],
        ];
    }

    private function duplicate(string $mutationId, ApiSyncMutation $receipt): ApiSyncMutationResult
    {
        $result = $receipt->getAttribute('result');
        $stored = is_array($result) ? $result : [];
        $resourceVersion = $stored['resource_version'] ?? null;
        $data = $stored['data'] ?? [];

        return new ApiSyncMutationResult(
            mutationId: $mutationId,
            status: 'duplicate',
            resourceVersion: is_int($resourceVersion) ? $resourceVersion : null,
            data: is_array($data) ? $data : [],
        );
    }

    private function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map(fn (mixed $item): mixed => $this->canonicalize($item), $value);
        }

        ksort($value);

        return array_map(fn (mixed $item): mixed => $this->canonicalize($item), $value);
    }
}
