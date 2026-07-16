<?php

declare(strict_types=1);

namespace App\Services\DemoData\Stages;

use App\Contracts\DemoDataStage;
use App\DTOs\DemoData\DemoDataOptions;
use App\DTOs\DemoData\DemoStageReport;
use App\DTOs\DemoData\DemoTitleContext;
use App\Enums\CommentNotificationType;
use App\Enums\ContentRequestNotificationType;
use App\Enums\ReviewNotificationType;
use App\Enums\ReviewOrigin;
use App\Enums\TechnicalIssueNotificationType;
use App\Models\CatalogTitle;
use App\Models\CatalogTitleReview;
use App\Models\CatalogTitleUserState;
use App\Models\Comment;
use App\Models\ContentRequest;
use App\Models\TechnicalIssue;
use App\Models\User;
use App\Services\Api\V1\Sync\ApiSyncMutationService;
use App\Services\DemoData\DemoBulkWriter;
use App\Services\DemoData\DemoStableValue;
use App\Services\DemoData\DemoTitleSelector;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Support\Collection;
use LogicException;

final readonly class DemoNotificationSyncStage implements DemoDataStage
{
    public function __construct(
        private DemoStableValue $stable,
        private ApiSyncMutationService $mutations,
    ) {}

    public function key(): string
    {
        return 'notifications_sync';
    }

    public function run(DemoDataOptions $options, ?Closure $progress = null): DemoStageReport
    {
        $startedAt = microtime(true);
        $options->assertEnvironment(app()->environment());
        $writer = new DemoBulkWriter($options);
        $selector = new DemoTitleSelector($options);
        $users = $this->users($options);
        $notificationRows = [];

        foreach ($users as $userIndex => $user) {
            $count = $this->stable->integer(
                "notifications:user:{$userIndex}:count",
                $options->notificationMinimum,
                $options->notificationMaximum,
            );
            $entities = $this->notificationEntities($user);

            for ($ordinal = 0; $ordinal < $count; $ordinal++) {
                $createdAt = CarbonImmutable::parse('2025-06-01 08:00:00')
                    ->addDays(($userIndex - 1) * 3)
                    ->addMinutes($ordinal * 17);
                [$type, $data] = $this->notification($entities, $ordinal);
                $notificationRows[] = [
                    'id' => $this->stable->uuid("notifications:user:{$userIndex}:{$ordinal}"),
                    'type' => $type,
                    'notifiable_type' => User::class,
                    'notifiable_id' => $user->id,
                    'data' => json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                    'read_at' => $ordinal % 3 === 0 ? null : $createdAt->addHours(2),
                    'created_at' => $createdAt,
                    'updated_at' => $createdAt,
                ];
            }

            $this->applySyncScenarios($user, $userIndex, $selector);
            $progress?->__invoke($this->key(), $userIndex, $options->userCount);
        }

        $writer->upsert('notifications', $notificationRows, ['id'], ['type', 'notifiable_type', 'notifiable_id', 'data', 'read_at', 'updated_at']);

        return new DemoStageReport($this->key(), [
            'notifications' => count($notificationRows),
            'sync_applied' => $options->userCount * 3,
            'sync_duplicate' => $options->userCount,
            'sync_conflict' => $options->userCount,
            'sync_rejected' => $options->userCount,
            'sync_not_found' => $options->userCount,
        ], microtime(true) - $startedAt);
    }

    /**
     * @return array{
     *     comments: Collection<int, Comment>, reviews: Collection<int, CatalogTitleReview>,
     *     requests: Collection<int, ContentRequest>, issues: Collection<int, TechnicalIssue>
     * }
     */
    private function notificationEntities(User $user): array
    {
        $entities = [
            'comments' => Comment::query()->withTrashed()->where('user_id', $user->id)->orderBy('id')->limit(12)->get(['id', 'status']),
            'reviews' => CatalogTitleReview::query()->where('user_id', $user->id)->where('origin', ReviewOrigin::User->value)->orderBy('id')->limit(12)->get(['id', 'status']),
            'requests' => ContentRequest::query()->where('requester_id', $user->id)->orderBy('id')->limit(10)->get(['id', 'public_id', 'status']),
            'issues' => TechnicalIssue::query()->where('requester_id', $user->id)->orderBy('id')->limit(6)->get(['id', 'public_id', 'public_number', 'type', 'status', 'version']),
        ];

        foreach ($entities as $domain => $records) {
            if ($records->isEmpty()) {
                throw new LogicException("Demo notification source {$domain} is missing.");
            }
        }

        return $entities;
    }

    /**
     * @param  array{comments: Collection<int, Comment>, reviews: Collection<int, CatalogTitleReview>, requests: Collection<int, ContentRequest>, issues: Collection<int, TechnicalIssue>}  $entities
     * @return array{string, array<string, mixed>}
     */
    private function notification(array $entities, int $ordinal): array
    {
        $domainOrdinal = intdiv($ordinal, 4);

        return match ($ordinal % 4) {
            0 => $this->commentNotification($entities['comments'], $domainOrdinal),
            1 => $this->reviewNotification($entities['reviews'], $domainOrdinal),
            2 => $this->requestNotification($entities['requests'], $domainOrdinal),
            default => $this->issueNotification($entities['issues'], $domainOrdinal),
        };
    }

    /** @param Collection<int, Comment> $comments @return array{string, array<string, mixed>} */
    private function commentNotification(Collection $comments, int $ordinal): array
    {
        $comment = $comments->get($ordinal % $comments->count());
        $kind = $this->enumAt(CommentNotificationType::cases(), $ordinal);

        return ['comment.activity', [
            'kind' => $kind->value,
            'comment_id' => $comment?->id,
            'reaction_id' => null,
            'report_id' => null,
            'moderation_status' => $comment?->status?->value,
        ]];
    }

    /** @param Collection<int, CatalogTitleReview> $reviews @return array{string, array<string, mixed>} */
    private function reviewNotification(Collection $reviews, int $ordinal): array
    {
        $review = $reviews->get($ordinal % $reviews->count());
        $kind = $this->enumAt(ReviewNotificationType::cases(), $ordinal);

        return ['review.activity', [
            'kind' => $kind->value,
            'review_id' => $review?->id,
            'vote_id' => null,
            'report_id' => null,
            'moderation_status' => $review?->status?->value,
        ]];
    }

    /** @param Collection<int, ContentRequest> $requests @return array{string, array<string, mixed>} */
    private function requestNotification(Collection $requests, int $ordinal): array
    {
        $request = $requests->get($ordinal % $requests->count());
        $kind = $this->enumAt(ContentRequestNotificationType::cases(), $ordinal);

        return ['content-request.activity', [
            'kind' => $kind->value,
            'request_public_id' => $request?->public_id,
            'status' => $request?->status?->value,
            'canonical_public_id' => null,
        ]];
    }

    /** @param Collection<int, TechnicalIssue> $issues @return array{string, array<string, mixed>} */
    private function issueNotification(Collection $issues, int $ordinal): array
    {
        $issue = $issues->get($ordinal % $issues->count());
        $kind = $this->enumAt(TechnicalIssueNotificationType::cases(), $ordinal);

        return ['technical-issue.activity', [
            'kind' => $kind->value,
            'issue_public_id' => $issue?->public_id,
            'public_number' => $issue?->public_number,
            'issue_type' => $issue?->type?->value,
            'status' => $issue?->status?->value,
            'revision' => $issue?->version ?? 1,
            'canonical_public_id' => null,
        ]];
    }

    private function applySyncScenarios(User $user, int $userIndex, DemoTitleSelector $selector): void
    {
        $titleId = $selector->selectedIds($userIndex)->first();
        $context = is_int($titleId) ? $selector->contexts([$titleId])->get($titleId) : null;
        $title = is_int($titleId) ? CatalogTitle::query()->find($titleId, ['id', 'slug']) : null;
        $state = is_int($titleId) ? CatalogTitleUserState::query()
            ->where('user_id', $user->id)
            ->where('catalog_title_id', $titleId)
            ->first(['rating']) : null;

        if (! $context instanceof DemoTitleContext || ! $title instanceof CatalogTitle || ! $state instanceof CatalogTitleUserState
            || $context->firstEpisodeId === null) {
            throw new LogicException('Catalog activity demo stage must run before API sync scenarios.');
        }

        $watchlist = [
            'mutation_id' => $this->stable->uuid("sync:user:{$userIndex}:watchlist"),
            'type' => 'watchlist.set',
            'title_slug' => $title->slug,
            'value' => true,
            'expected_version' => 1,
        ];
        $rating = [
            'mutation_id' => $this->stable->uuid("sync:user:{$userIndex}:rating"),
            'type' => 'rating.set',
            'title_slug' => $title->slug,
            'value' => ((int) $state->rating % 10) + 1,
            'expected_version' => 1,
        ];
        $progress = [
            'mutation_id' => $this->stable->uuid("sync:user:{$userIndex}:progress"),
            'type' => 'progress.set',
            'title_slug' => $title->slug,
            'episode_id' => $context->firstEpisodeId,
            'playback_session' => $this->stable->ulid("sync:user:{$userIndex}:playback-session"),
            'event_sequence' => 100_000 + $userIndex,
            'position_seconds' => 900 + $userIndex,
            'duration_seconds' => $context->durationSeconds ?? 2_400,
            'ended' => false,
        ];
        $unsupported = [
            'mutation_id' => $this->stable->uuid("sync:user:{$userIndex}:rejected"),
            'type' => 'demo.unsupported',
        ];
        $missing = [
            'mutation_id' => $this->stable->uuid("sync:user:{$userIndex}:not-found"),
            'type' => 'watchlist.set',
            'title_slug' => 'nesushchestvuyushchiy-serial-demo',
            'value' => true,
            'expected_version' => 0,
        ];

        $this->mutations->apply($user, $watchlist);
        $this->mutations->apply($user, $rating);
        $this->mutations->apply($user, $progress);
        $this->mutations->apply($user, $watchlist);
        $this->mutations->apply($user, [...$watchlist, 'value' => false]);
        $this->mutations->apply($user, $unsupported);
        $this->mutations->apply($user, $missing);
    }

    /** @return Collection<int, User> */
    private function users(DemoDataOptions $options): Collection
    {
        $emails = collect(range(1, $options->userCount))->mapWithKeys(fn (int $index): array => ["user{$index}@example.com" => $index]);
        $usersByEmail = User::query()->whereIn('email', $emails->keys())->get()->keyBy('email');

        return $emails->mapWithKeys(function (int $index, string $email) use ($usersByEmail): array {
            $user = $usersByEmail->get($email) ?? throw new LogicException("Demo user {$email} is missing.");

            return [$index => $user];
        });
    }

    /** @template T of \BackedEnum @param non-empty-list<T> $cases @return T */
    private function enumAt(array $cases, int $ordinal): \BackedEnum
    {
        return $cases[$ordinal % count($cases)];
    }
}
