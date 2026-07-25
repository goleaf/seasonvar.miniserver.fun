<?php

namespace App\Services\Seasonvar;

use App\Models\SourcePage;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

class SeasonvarPageClaimManager
{
    public function claim(SourcePage $page, int $runId, ?int $seconds = null): ?string
    {
        $now = now();
        $token = Str::uuid()->toString();
        $seconds = max(60, $seconds ?? (int) config('seasonvar.queue.claim_seconds', 86400));

        $claimed = SourcePage::query()
            ->whereKey($page->id)
            ->where(function (Builder $query) use ($now): void {
                $query->whereNull('import_claim_token')
                    ->orWhereNull('import_claim_expires_at')
                    ->orWhere('import_claim_expires_at', '<=', $now);
            })
            ->update([
                'import_claim_token' => $token,
                'import_claimed_at' => $now,
                'import_claim_expires_at' => $now->copy()->addSeconds($seconds),
                'import_claim_run_id' => $runId,
                'updated_at' => $now,
            ]);

        return $claimed === 1 ? $token : null;
    }

    /**
     * @param  list<int>  $pageIds
     * @return array{token: string, page_ids: list<int>}
     */
    public function claimMany(array $pageIds, int $runId, ?int $seconds = null): array
    {
        $pageIds = collect($pageIds)
            ->map(static fn (int $pageId): int => $pageId)
            ->filter(static fn (int $pageId): bool => $pageId > 0)
            ->unique()
            ->sort()
            ->values()
            ->all();
        $token = Str::uuid()->toString();

        if ($pageIds === []) {
            return [
                'token' => $token,
                'page_ids' => [],
            ];
        }

        $now = now();
        $seconds = max(
            60,
            $seconds ?? (int) config('seasonvar.queue.claim_seconds', 86400),
        );

        SourcePage::query()
            ->whereKey($pageIds)
            ->where(function (Builder $query) use ($now): void {
                $query->whereNull('import_claim_token')
                    ->orWhereNull('import_claim_expires_at')
                    ->orWhere('import_claim_expires_at', '<=', $now);
            })
            ->update([
                'import_claim_token' => $token,
                'import_claimed_at' => $now,
                'import_claim_expires_at' => $now->copy()->addSeconds($seconds),
                'import_claim_run_id' => $runId,
                'updated_at' => $now,
            ]);

        $claimedPageIds = SourcePage::query()
            ->whereKey($pageIds)
            ->where('import_claim_run_id', $runId)
            ->where('import_claim_token', $token)
            ->where('import_claim_expires_at', '>', $now)
            ->orderBy('id')
            ->pluck('id')
            ->map(static fn (mixed $pageId): int => (int) $pageId)
            ->all();

        return [
            'token' => $token,
            'page_ids' => $claimedPageIds,
        ];
    }

    public function owns(int $pageId, int $runId, string $token): bool
    {
        return $this->ownedQuery($pageId, $runId, $token)
            ->where('import_claim_expires_at', '>', now())
            ->exists();
    }

    public function extend(int $pageId, int $runId, string $token, int $seconds): bool
    {
        $now = now();

        return $this->ownedQuery($pageId, $runId, $token)
            ->where('import_claim_expires_at', '>', $now)
            ->update([
                'import_claimed_at' => $now,
                'import_claim_expires_at' => $now->copy()->addSeconds(max(60, $seconds)),
                'updated_at' => $now,
            ]) === 1;
    }

    public function release(int $pageId, int $runId, string $token): bool
    {
        return $this->ownedQuery($pageId, $runId, $token)->update($this->releasedAttributes()) === 1;
    }

    public function recoverExpired(): int
    {
        return SourcePage::query()
            ->whereNotNull('import_claim_token')
            ->whereNotNull('import_claim_expires_at')
            ->where('import_claim_expires_at', '<=', now())
            ->update($this->releasedAttributes());
    }

    public function releaseForRun(int $runId): int
    {
        return SourcePage::query()
            ->where('import_claim_run_id', $runId)
            ->update($this->releasedAttributes());
    }

    public function outstandingForRun(int $runId): int
    {
        return SourcePage::query()
            ->where('import_claim_run_id', $runId)
            ->whereNotNull('import_claim_token')
            ->where('import_claim_expires_at', '>', now())
            ->count();
    }

    /**
     * @return Builder<SourcePage>
     */
    private function ownedQuery(int $pageId, int $runId, string $token): Builder
    {
        return SourcePage::query()
            ->whereKey($pageId)
            ->where('import_claim_run_id', $runId)
            ->where('import_claim_token', $token);
    }

    /**
     * @return array<string, mixed>
     */
    private function releasedAttributes(): array
    {
        return [
            'import_claim_token' => null,
            'import_claimed_at' => null,
            'import_claim_expires_at' => null,
            'import_claim_run_id' => null,
            'updated_at' => now(),
        ];
    }
}
