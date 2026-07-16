<?php

declare(strict_types=1);

namespace App\Services\TechnicalIssues;

use App\DTOs\TechnicalIssues\TechnicalIssueDuplicateResult;
use App\DTOs\TechnicalIssues\TechnicalIssueInput;
use App\DTOs\TechnicalIssues\TechnicalIssueTargetData;
use App\Enums\TechnicalIssueDuplicateConfidence;
use App\Models\TechnicalIssue;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;

final readonly class TechnicalIssueDuplicateService
{
    public function __construct(private TechnicalIssueIdentity $identity, private TechnicalIssueTypeRegistry $types) {}

    public function find(User $user, TechnicalIssueInput $input, TechnicalIssueTargetData $target): TechnicalIssueDuplicateResult
    {
        $input = $this->types->allowlistedInput($input);
        $identity = $this->identity->make($user, $input, $target);
        $exact = TechnicalIssue::query()->where('active_identity_key', $identity)->first(['id', 'public_id', 'public_number', 'type', 'status']);

        if ($exact instanceof TechnicalIssue) {
            return new TechnicalIssueDuplicateResult(TechnicalIssueDuplicateConfidence::Exact, [$this->candidate($exact)]);
        }

        $query = TechnicalIssue::query()
            ->open()
            ->where('type', $input->type->value)
            ->when($this->types->requesterPrivate($input->type, $target->type), fn (Builder $query) => $query->where('requester_id', $user->id));
        $this->narrowTarget($query, $target);
        $candidates = $query
            ->latest('updated_at')
            ->latest('id')
            ->limit(max(1, min(25, (int) config('technical-issues.duplicate_candidate_limit', 12))))
            ->get(['id', 'public_id', 'public_number', 'type', 'status']);

        if ($candidates->isNotEmpty()) {
            return new TechnicalIssueDuplicateResult(
                TechnicalIssueDuplicateConfidence::Probable,
                $candidates->map(fn (TechnicalIssue $issue): array => $this->candidate($issue))->all(),
            );
        }

        if ($target->catalogTitleId !== null) {
            $related = TechnicalIssue::query()
                ->open()
                ->where('catalog_title_id', $target->catalogTitleId)
                ->when($this->types->requesterPrivate($input->type, $target->type), fn (Builder $query) => $query->where('requester_id', $user->id))
                ->latest('updated_at')
                ->latest('id')
                ->limit(5)
                ->get(['id', 'public_id', 'public_number', 'type', 'status']);

            if ($related->isNotEmpty()) {
                return new TechnicalIssueDuplicateResult(
                    TechnicalIssueDuplicateConfidence::Related,
                    $related->map(fn (TechnicalIssue $issue): array => $this->candidate($issue))->all(),
                );
            }
        }

        return new TechnicalIssueDuplicateResult(TechnicalIssueDuplicateConfidence::None, []);
    }

    /** @return list<array{public_id: string, number: string, type: string, status: string}> */
    public function visibleCandidates(User $user, TechnicalIssueDuplicateResult $result): array
    {
        if ($result->candidates === [] || Gate::forUser($user)->allows('manage-technical-issues')) {
            return $result->candidates;
        }

        $publicIds = array_column($result->candidates, 'public_id');
        $visible = TechnicalIssue::query()
            ->whereIn('public_id', $publicIds)
            ->where(function (Builder $query) use ($user): void {
                $query->where('requester_id', $user->id)
                    ->orWhereHas('followers', fn (Builder $query) => $query->where('user_id', $user->id))
                    ->orWhereHas('confirmations', fn (Builder $query) => $query->where('user_id', $user->id));
            })
            ->pluck('public_id')
            ->all();

        return array_values(array_filter(
            $result->candidates,
            static fn (array $candidate): bool => in_array($candidate['public_id'], $visible, true),
        ));
    }

    /** @param Builder<TechnicalIssue> $query */
    private function narrowTarget(Builder $query, TechnicalIssueTargetData $target): void
    {
        if ($target->episodeId !== null) {
            $query->where('episode_id', $target->episodeId);

            return;
        }

        if ($target->seasonId !== null) {
            $query->where('target_type', $target->type->value)->where('season_id', $target->seasonId);

            return;
        }

        if ($target->catalogTitleId !== null) {
            $query->where('target_type', $target->type->value)->where('catalog_title_id', $target->catalogTitleId);

            return;
        }

        $query->where('target_type', $target->type->value);
        $target->featureCode === null
            ? $query->whereNull('feature_code')
            : $query->where('feature_code', $target->featureCode);
        $target->routeName === null
            ? $query->whereNull('route_name')
            : $query->where('route_name', $target->routeName);
    }

    /** @return array{public_id: string, number: string, type: string, status: string} */
    private function candidate(TechnicalIssue $issue): array
    {
        return [
            'public_id' => $issue->public_id,
            'number' => $issue->public_number,
            'type' => $issue->type->value,
            'status' => $issue->status->value,
        ];
    }
}
