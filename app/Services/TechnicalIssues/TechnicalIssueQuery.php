<?php

declare(strict_types=1);

namespace App\Services\TechnicalIssues;

use App\DTOs\TechnicalIssues\TechnicalIssueCardData;
use App\DTOs\TechnicalIssues\TechnicalIssueDetailData;
use App\Enums\TechnicalIssueSort;
use App\Enums\TechnicalIssueStatus;
use App\Enums\TechnicalIssueTargetType;
use App\Enums\TechnicalIssueType;
use App\Models\TechnicalIssue;
use App\Models\TechnicalIssueMessage;
use App\Models\TechnicalIssueOccurrence;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;

final readonly class TechnicalIssueQuery
{
    public function __construct(private TechnicalIssuePresenter $presenter) {}

    /** @return LengthAwarePaginator<int, TechnicalIssueCardData> */
    public function mine(
        User $user,
        string $scope,
        ?TechnicalIssueStatus $status,
        ?TechnicalIssueType $type,
        string $search,
        TechnicalIssueSort $sort,
    ): LengthAwarePaginator {
        $query = $this->baseCardQuery($user);

        match ($scope) {
            'followed' => $query->whereHas('followers', fn (Builder $query) => $query->where('user_id', $user->id)),
            'confirmed' => $query->whereHas('confirmations', fn (Builder $query) => $query->where('user_id', $user->id)),
            'waiting' => $query->where('requester_id', $user->id)->whereIn('status', [TechnicalIssueStatus::ClarificationNeeded, TechnicalIssueStatus::WaitingForRequester]),
            default => $query->where('requester_id', $user->id),
        };

        if ($status !== null) {
            $query->where('status', $status->value);
        }

        if ($type !== null) {
            $query->where('type', $type->value);
        }

        $this->search($query, $search, $user);
        $this->sort($query, $sort, support: false);

        return $query
            ->paginate(max(1, (int) config('technical-issues.per_page', 12)), pageName: 'issuePage')
            ->withQueryString()
            ->through(fn (TechnicalIssue $issue): TechnicalIssueCardData => $this->presenter->card($issue, $user));
    }

    /** @return LengthAwarePaginator<int, TechnicalIssueCardData> */
    public function support(
        User $user,
        ?TechnicalIssueStatus $status,
        ?TechnicalIssueType $type,
        ?string $severity,
        ?string $priority,
        ?string $team,
        ?TechnicalIssueTargetType $targetType,
        string $assignment,
        ?string $sourceHealth,
        string $search,
        TechnicalIssueSort $sort,
    ): LengthAwarePaginator {
        Gate::forUser($user)->authorize('manage-technical-issues');
        $query = $this->baseCardQuery($user, staff: true)->with(['requester:id,name', 'licensedMedia:id,health_status']);

        if ($status !== null) {
            $query->where('status', $status->value);
        }

        if ($type !== null) {
            $query->where('type', $type->value);
        }

        if (in_array($severity, ['low', 'medium', 'high', 'critical'], true)) {
            $query->where('severity', $severity);
        }

        if (in_array($priority, ['low', 'normal', 'high', 'urgent'], true)) {
            $query->where('priority', $priority);
        }

        if (is_string($team) && in_array($team, config('technical-issues.support_teams', []), true)) {
            $query->where('support_team', $team);
        }

        if ($targetType !== null) {
            $query->where('target_type', $targetType->value);
        }

        if ($assignment === 'unassigned') {
            $query->whereNull('assigned_to_id');
        } elseif ($assignment === 'mine') {
            $query->where('assigned_to_id', $user->id);
        }

        if (is_string($sourceHealth) && in_array($sourceHealth, ['active', 'degraded', 'unavailable', 'disabled'], true)) {
            $query->whereHas('licensedMedia', fn (Builder $query) => $query->where('health_status', $sourceHealth));
        }

        $this->search($query, $search);
        $this->sort($query, $sort, support: true);

        return $query
            ->paginate(max(1, (int) config('technical-issues.support_per_page', 20)), pageName: 'supportIssuePage')
            ->withQueryString()
            ->through(fn (TechnicalIssue $issue): TechnicalIssueCardData => $this->presenter->card($issue, $user, staff: true));
    }

    /** @return array<string, int> */
    public function supportCounts(User $user): array
    {
        Gate::forUser($user)->authorize('manage-technical-issues');

        return TechnicalIssue::query()
            ->selectRaw('status, COUNT(*) AS aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status')
            ->map(static fn (mixed $count): int => (int) $count)
            ->all();
    }

    /** @return array{created: int, waiting: int, followed: int, confirmed: int} */
    public function mineCounts(User $user): array
    {
        $row = TechnicalIssue::query()
            ->where(function (Builder $query) use ($user): void {
                $query->where('requester_id', $user->id)
                    ->orWhereHas('followers', fn (Builder $query) => $query->where('user_id', $user->id))
                    ->orWhereHas('confirmations', fn (Builder $query) => $query->where('user_id', $user->id));
            })
            ->selectRaw(
                'SUM(CASE WHEN requester_id = ? THEN 1 ELSE 0 END) AS created_count, '
                .'SUM(CASE WHEN requester_id = ? AND status IN (?, ?) THEN 1 ELSE 0 END) AS waiting_count, '
                .'SUM(CASE WHEN EXISTS (SELECT 1 FROM technical_issue_followers tif WHERE tif.technical_issue_id = technical_issues.id AND tif.user_id = ?) THEN 1 ELSE 0 END) AS followed_count, '
                .'SUM(CASE WHEN EXISTS (SELECT 1 FROM technical_issue_confirmations tic WHERE tic.technical_issue_id = technical_issues.id AND tic.user_id = ?) THEN 1 ELSE 0 END) AS confirmed_count',
                [$user->id, $user->id, TechnicalIssueStatus::ClarificationNeeded->value, TechnicalIssueStatus::WaitingForRequester->value, $user->id, $user->id],
            )
            ->toBase()
            ->first();

        return [
            'created' => (int) ($row->created_count ?? 0),
            'waiting' => (int) ($row->waiting_count ?? 0),
            'followed' => (int) ($row->followed_count ?? 0),
            'confirmed' => (int) ($row->confirmed_count ?? 0),
        ];
    }

    public function detail(User $user, TechnicalIssue $issue): TechnicalIssueDetailData
    {
        Gate::forUser($user)->authorize('view', $issue);
        $staff = Gate::forUser($user)->allows('manage-technical-issues');
        $requester = $issue->requester_id === $user->id;
        $query = TechnicalIssue::query()
            ->whereKey($issue->id)
            ->with([
                'catalogTitle:id,title,slug',
                'season:id,catalog_title_id,number,kind',
                'episode:id,season_id,number,kind,title',
                'requester:id,name',
                'mergedInto:id,public_id,public_number,status',
                'diagnostic',
            ])
            ->withCount([
                'attachments' => fn (Builder $query) => $query->when(! $staff, fn (Builder $query) => $query
                    ->where(fn (Builder $query) => $query
                        ->where(fn (Builder $query) => $query
                            ->whereNull('technical_issue_message_id')
                            ->where('uploader_id', $user->id))
                        ->orWhereHas('message', fn (Builder $query) => $query->where('visibility', 'requester_visible')))),
                'messages' => fn (Builder $query) => $query->when(! $staff, fn (Builder $query) => $query->where('visibility', 'requester_visible')),
                'confirmations',
                'occurrences',
            ])
            ->withExists([
                'followers as viewer_is_following' => fn (Builder $query) => $query->where('user_id', $user->id),
                'confirmations as viewer_has_confirmed' => fn (Builder $query) => $query->where('user_id', $user->id),
            ]);

        if ($staff || $requester) {
            $query->with([
                'statusHistory' => fn ($query) => $query->select([
                    'id', 'technical_issue_id', 'from_status', 'to_status', 'public_reason_code', 'public_message',
                    ...($staff ? ['private_note'] : []), 'created_at',
                ])->when(! $staff, fn ($query) => $query->whereNotIn('public_reason_code', ['internal_classification_changed'])),
                'attachments' => fn ($query) => $query
                    ->whereNull('technical_issue_message_id')
                    ->when(! $staff, fn ($query) => $query->where('uploader_id', $user->id))
                    ->select([
                        'id', 'public_id', 'technical_issue_id', 'technical_issue_message_id', 'uploader_id',
                        'display_name', 'mime_type', 'extension', 'size_bytes', 'width', 'height',
                    ]),
            ]);
        }

        $loaded = $query->firstOrFail();
        $messagePages = null;

        if ($staff || $requester) {
            $messagePages = TechnicalIssueMessage::query()
                ->where('technical_issue_id', $loaded->id)
                ->when(! $staff, fn (Builder $query) => $query->where('visibility', 'requester_visible'))
                ->with([
                    'author:id,name',
                    'attachments:id,public_id,technical_issue_id,technical_issue_message_id,uploader_id,display_name,mime_type,extension,size_bytes,width,height',
                ])
                ->latest('created_at')
                ->latest('id')
                ->paginate(20, pageName: 'issueMessagePage')
                ->withQueryString();
            $messagePages->setCollection($messagePages->getCollection()->reverse()->values());
            $loaded->setRelation('messages', $messagePages->getCollection());
        }

        if ($staff) {
            $loaded->setAttribute('occurrence_browser_distribution', $this->occurrenceDistribution($loaded->id, 'browser_family'));
            $loaded->setAttribute('occurrence_device_distribution', $this->occurrenceDistribution($loaded->id, 'device_category'));
        }
        $related = [];

        if ($staff) {
            $related = TechnicalIssue::query()
                ->whereKeyNot($loaded->id)
                ->where(function (Builder $query) use ($loaded): void {
                    $query->where('merged_into_id', $loaded->id);

                    if ($loaded->catalog_title_id !== null) {
                        $query->orWhere(fn (Builder $query) => $query
                            ->where('catalog_title_id', $loaded->catalog_title_id)
                            ->where('type', $loaded->type->value));
                    }
                })
                ->latest('updated_at')
                ->latest('id')
                ->limit(8)
                ->get(['id', 'public_id', 'public_number', 'type', 'status'])
                ->map(fn (TechnicalIssue $related): array => [
                    'number' => $related->public_number,
                    'type' => $related->type->label(),
                    'status' => $related->status->label(),
                    'url' => $this->presenter->issueUrl($related),
                ])->all();
        }

        return $this->presenter->detail($loaded, $user, $staff, $related, $messagePages);
    }

    /** @return Builder<TechnicalIssue> */
    private function baseCardQuery(User $user, bool $staff = false): Builder
    {
        return TechnicalIssue::query()
            ->select([
                'id', 'public_id', 'public_number', 'requester_id', 'assigned_to_id', 'support_team', 'type', 'status',
                'severity', 'priority', 'target_type', 'target_label_snapshot', 'catalog_title_id', 'season_id', 'episode_id', 'licensed_media_id',
                'feature_code', 'summary', 'public_error_code', 'resolution_type', 'resolution_summary', 'merged_into_id',
                'version', 'created_at', 'updated_at',
            ])
            ->with([
                'catalogTitle:id,title,slug',
                'season:id,catalog_title_id,number,kind',
                'episode:id,season_id,number,kind,title',
            ])
            ->withCount([
                'attachments' => fn (Builder $query) => $query->when(! $staff, fn (Builder $query) => $query
                    ->where(fn (Builder $query) => $query
                        ->where(fn (Builder $query) => $query
                            ->whereNull('technical_issue_message_id')
                            ->where('uploader_id', $user->id))
                        ->orWhereHas('message', fn (Builder $query) => $query->where('visibility', 'requester_visible')))),
                'messages' => fn (Builder $query) => $query->when(! $staff, fn (Builder $query) => $query->where('visibility', 'requester_visible')),
                'confirmations',
                'occurrences',
            ])
            ->withExists([
                'followers as viewer_is_following' => fn (Builder $query) => $query->where('user_id', $user->id),
                'confirmations as viewer_has_confirmed' => fn (Builder $query) => $query->where('user_id', $user->id),
            ]);
    }

    /** @param Builder<TechnicalIssue> $query */
    private function search(Builder $query, string $search, ?User $requesterScope = null): void
    {
        $search = mb_substr(trim($search), 0, 120);

        if ($search === '') {
            return;
        }

        $like = addcslashes($search, '%_\\').'%';
        $number = strtoupper($search);
        $exactNumber = preg_match('/^ISS-[A-F0-9]{20}$/D', $number) === 1;
        $query->where(function (Builder $query) use ($like, $number, $exactNumber, $requesterScope): void {
            $query->where('public_number', $exactNumber ? '=' : 'like', $exactNumber ? $number : $like)
                ->orWhere('public_error_code', 'like', $like)
                ->orWhere('target_label_snapshot', 'like', $like);

            if ($requesterScope instanceof User) {
                $query->orWhere(fn (Builder $query) => $query
                    ->where('requester_id', $requesterScope->id)
                    ->where('summary', 'like', $like));
            } else {
                $query->orWhere('summary', 'like', $like);
            }
        });
    }

    /** @param Builder<TechnicalIssue> $query */
    private function sort(Builder $query, TechnicalIssueSort $sort, bool $support): void
    {
        if ($support && $sort === TechnicalIssueSort::Priority) {
            $query->orderByRaw("CASE priority WHEN 'urgent' THEN 0 WHEN 'high' THEN 1 WHEN 'normal' THEN 2 ELSE 3 END")
                ->oldest('created_at')->oldest('id');

            return;
        }

        if ($support && $sort === TechnicalIssueSort::Severity) {
            $query->orderByRaw("CASE severity WHEN 'critical' THEN 0 WHEN 'high' THEN 1 WHEN 'medium' THEN 2 ELSE 3 END")
                ->oldest('created_at')->oldest('id');

            return;
        }

        match ($sort) {
            TechnicalIssueSort::Newest => $query->latest('created_at')->latest('id'),
            TechnicalIssueSort::Oldest => $query->oldest('created_at')->oldest('id'),
            default => $query->latest('updated_at')->latest('id'),
        };
    }

    private function occurrenceDistribution(int $issueId, string $column): string
    {
        if (! in_array($column, ['browser_family', 'device_category'], true)) {
            return '';
        }

        return TechnicalIssueOccurrence::query()
            ->where('technical_issue_id', $issueId)
            ->whereNotNull($column)
            ->selectRaw($column.', COUNT(*) AS aggregate')
            ->groupBy($column)
            ->orderByDesc('aggregate')
            ->orderBy($column)
            ->pluck('aggregate', $column)
            ->map(fn (mixed $count, string $value): string => $value.': '.(int) $count)
            ->implode(', ');
    }
}
