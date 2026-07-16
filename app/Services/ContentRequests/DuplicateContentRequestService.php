<?php

declare(strict_types=1);

namespace App\Services\ContentRequests;

use App\DTOs\ContentRequests\ContentRequestDuplicateResult;
use App\DTOs\ContentRequests\ContentRequestInput;
use App\Enums\ContentRequestDuplicateConfidence;
use App\Models\ContentRequest;
use Illuminate\Database\Eloquent\Builder;

final readonly class DuplicateContentRequestService
{
    public function __construct(private ContentRequestIdentity $identity) {}

    /** @param list<array{provider: string, identifier: string, normalized_identifier: string}> $externalIdentifiers */
    public function check(ContentRequestInput $input, array $externalIdentifiers): ContentRequestDuplicateResult
    {
        $hash = $this->identity->exactHash($input, $externalIdentifiers);
        $exact = ContentRequest::query()->where('active_identity_key', $hash)->first();

        if ($exact !== null) {
            return new ContentRequestDuplicateResult(ContentRequestDuplicateConfidence::Exact, $hash, [$this->summary($exact)]);
        }

        $limit = max(1, (int) config('content-requests.duplicate_candidate_limit', 12));
        $normalizedHash = hash('sha256', $this->identity->normalizedTitle($input));
        $candidates = ContentRequest::query()
            ->publiclyVisible()
            ->where('type', $input->type->value)
            ->where(function (Builder $query) use ($input, $normalizedHash): void {
                $query->where(function (Builder $target) use ($input): void {
                    $target->when($input->episodeId !== null, fn (Builder $q): Builder => $q->where('episode_id', $input->episodeId))
                        ->when($input->episodeId === null && $input->seasonId !== null, fn (Builder $q): Builder => $q->where('season_id', $input->seasonId))
                        ->when($input->episodeId === null && $input->seasonId === null && $input->catalogTitleId !== null, fn (Builder $q): Builder => $q->where('catalog_title_id', $input->catalogTitleId));
                })->orWhere(function (Builder $title) use ($input, $normalizedHash): void {
                    $title->where('normalized_title_hash', $normalizedHash)
                        ->when($input->releaseYear !== null, fn (Builder $q): Builder => $q->whereBetween('release_year', [$input->releaseYear - 1, $input->releaseYear + 1]));
                });
            })
            ->orderByDesc('updated_at')->orderByDesc('id')->limit($limit)->get();

        if ($candidates->isEmpty()) {
            return new ContentRequestDuplicateResult(ContentRequestDuplicateConfidence::None, $hash);
        }

        $probable = $candidates->contains(fn (ContentRequest $request): bool =>
            $request->catalog_title_id === $input->catalogTitleId
            || $request->normalized_title_hash === $normalizedHash
        );

        return new ContentRequestDuplicateResult(
            $probable ? ContentRequestDuplicateConfidence::Probable : ContentRequestDuplicateConfidence::Related,
            $hash,
            $candidates->map(fn (ContentRequest $request): array => $this->summary($request))->all(),
        );
    }

    /** @return array{public_id: string, title: string, status: string, url: string} */
    private function summary(ContentRequest $request): array
    {
        return ['public_id' => $request->public_id, 'title' => $request->title, 'status' => $request->status->value, 'url' => route('requests.show', $request)];
    }
}
