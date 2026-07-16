<?php

declare(strict_types=1);

namespace App\Services\DemoData\Stages;

use App\Contracts\DemoDataStage;
use App\DTOs\DemoData\DemoDataOptions;
use App\DTOs\DemoData\DemoStageReport;
use App\DTOs\DemoData\DemoTitleContext;
use App\Enums\CatalogWatchStatus;
use App\Enums\CommentDeletionReason;
use App\Enums\CommentModerationReason;
use App\Enums\CommentReactionType;
use App\Enums\CommentStatus;
use App\Enums\CommentTargetType;
use App\Enums\ReviewDeletionReason;
use App\Enums\ReviewModerationReason;
use App\Enums\ReviewOrigin;
use App\Enums\ReviewStatus;
use App\Enums\ReviewVoteType;
use App\Models\CatalogCollection;
use App\Models\CatalogTitleReview;
use App\Models\CatalogTitleReviewVote;
use App\Models\CatalogTitleUserState;
use App\Models\Comment;
use App\Models\CommentReaction;
use App\Models\User;
use App\Services\DemoData\DemoBulkWriter;
use App\Services\DemoData\DemoPersonaFactory;
use App\Services\DemoData\DemoRussianText;
use App\Services\DemoData\DemoStableValue;
use App\Services\DemoData\DemoTitleSelector;
use App\Services\Reviews\ReviewIdentity;
use App\ValueObjects\CommentBody;
use App\ValueObjects\ReviewBody;
use App\ValueObjects\ReviewTitle;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Support\Collection;

final readonly class DemoCommunityStage implements DemoDataStage
{
    public function __construct(
        private DemoStableValue $stable,
        private DemoPersonaFactory $personas,
        private DemoRussianText $text,
        private ReviewIdentity $reviewIdentity,
    ) {}

    public function key(): string
    {
        return 'community';
    }

    public function run(DemoDataOptions $options, ?Closure $progress = null): DemoStageReport
    {
        $startedAt = microtime(true);
        $options->assertEnvironment(app()->environment());
        $selector = new DemoTitleSelector($options);
        $writer = new DemoBulkWriter($options);
        $users = $this->users($options);
        $selectedCount = $options->selectedTitleCount($selector->publishedCount());
        $totalPairs = max(1, $selectedCount * $options->userCount);
        $counters = [
            'reviews' => 0,
            'review_votes' => 0,
            'root_comments' => 0,
            'collection_comments' => 0,
            'replies' => 0,
            'comment_reactions' => 0,
        ];

        foreach ($users as $userIndex => $user) {
            $position = 0;

            foreach ($selector->selectedIds($userIndex)->chunk($options->chunkSize) as $titleIds) {
                $ids = $titleIds->values()->all();
                $contexts = $selector->contexts($ids);
                $states = CatalogTitleUserState::query()
                    ->where('user_id', $user->id)
                    ->whereIn('catalog_title_id', $ids)
                    ->get(['catalog_title_id', 'watch_status'])
                    ->keyBy('catalog_title_id');
                $reviewRows = [];
                $reviewSpecs = [];
                $rootRows = [];
                $rootSpecs = [];

                foreach ($ids as $titleId) {
                    $context = $contexts->get($titleId);

                    if (! $context instanceof DemoTitleContext) {
                        $position++;

                        continue;
                    }

                    $globalOrdinal = ($userIndex - 1) * $selectedCount + $position;
                    $createdAt = $this->createdAt($userIndex, $position);
                    $reviewStatus = $this->reviewStatus($globalOrdinal);
                    $reviewBody = ReviewBody::from(
                        $this->text->reviewBody($this->personas->make($userIndex), $context->displayTitle, $globalOrdinal),
                    );
                    $reviewTitle = ReviewTitle::from(
                        $this->text->reviewTitle($this->personas->make($userIndex), $context->displayTitle, $globalOrdinal),
                    );
                    $ownershipKey = $this->reviewIdentity->ownershipKey($user, $titleId);
                    $reviewSubmission = $this->reviewIdentity->submissionKey(
                        $user,
                        $titleId,
                        $this->stable->uuid("community:review:{$globalOrdinal}:submission"),
                    );
                    $reviewDeleted = $globalOrdinal % 50 === 0;
                    $reviewEdited = $globalOrdinal % 7 === 0;
                    $moderator = $users->get($this->otherUserIndex($userIndex, 1, $options->userCount));
                    $moderated = $reviewStatus !== ReviewStatus::Published;
                    $watchStatus = $states->get($titleId)?->watch_status;
                    $reviewRows[] = [
                        'catalog_title_id' => $titleId,
                        'source_page_id' => null,
                        'user_id' => $user->id,
                        'origin' => ReviewOrigin::User->value,
                        'author' => $this->personas->make($userIndex)->displayName,
                        'review_title' => $reviewTitle->value,
                        'body' => $reviewBody->value,
                        'body_hash' => $reviewBody->authorScopedHash((int) $user->id),
                        'original_body_hash' => $reviewEdited ? hash('sha256', 'original:'.$reviewBody->normalizedHash) : null,
                        'is_spoiler' => $this->stable->boolean("community:review:{$globalOrdinal}:spoiler", 14),
                        'is_verified_watch' => in_array($watchStatus, [CatalogWatchStatus::Watching, CatalogWatchStatus::Completed], true),
                        'status' => $reviewStatus->value,
                        'version' => $reviewEdited ? 2 : 1,
                        'edited_at' => $reviewEdited ? $createdAt->addDays(2) : null,
                        'deletion_reason' => $reviewDeleted ? ReviewDeletionReason::Author->value : null,
                        'deleted_by_id' => $reviewDeleted ? $user->id : null,
                        'moderated_by_id' => $moderated ? $moderator?->id : null,
                        'moderation_reason' => $moderated ? $this->reviewModerationReason($reviewStatus)->value : null,
                        'moderator_note' => $moderated ? 'Статус установлен для демонстрации очереди и истории модерации.' : null,
                        'moderated_at' => $moderated ? $createdAt->addHours(8) : null,
                        'ownership_key' => $ownershipKey,
                        'submission_key' => $reviewSubmission,
                        'merged_into_id' => null,
                        'status_before_merge' => null,
                        'deletion_reason_before_merge' => null,
                        'ownership_released_at' => null,
                        'published_at' => $reviewStatus === ReviewStatus::Published ? $createdAt : null,
                        'created_at' => $createdAt,
                        'updated_at' => $reviewEdited ? $createdAt->addDays(2) : $createdAt,
                        'deleted_at' => $reviewDeleted ? $createdAt->addDays(5) : null,
                    ];
                    $reviewSpecs[$ownershipKey] = [
                        'author_index' => $userIndex,
                        'created_at' => $createdAt,
                    ];

                    [$targetType, $targetId] = $this->commentTarget($context, $globalOrdinal);
                    $targetKey = $targetType->value.':'.$targetId;
                    $commentSubmission = $this->commentSubmissionKey(
                        $user,
                        $targetKey,
                        "community:root:{$globalOrdinal}:submission",
                    );
                    $dialogue = $position % 4 === 0;
                    $commentStatus = $this->commentStatus($globalOrdinal, $dialogue);
                    $commentDeleted = ! $dialogue && $globalOrdinal % 50 === 3;
                    $commentEdited = $globalOrdinal % 9 === 0;
                    $commentBody = CommentBody::from(
                        $this->text->commentBody($this->personas->make($userIndex), $context->displayTitle, $globalOrdinal),
                    );
                    $commentModerated = $commentStatus !== CommentStatus::Published;
                    $rootRows[] = [
                        'user_id' => $user->id,
                        'target_type' => $targetType->value,
                        'target_id' => $targetId,
                        'catalog_title_id' => $context->titleId,
                        'parent_id' => null,
                        'reply_to_id' => null,
                        'body' => $commentBody->value,
                        'body_hash' => $commentBody->hash,
                        'is_spoiler' => $this->stable->boolean("community:root:{$globalOrdinal}:spoiler", 12),
                        'status' => $commentStatus->value,
                        'version' => $commentEdited ? 2 : 1,
                        'edited_at' => $commentEdited ? $createdAt->addHours(3) : null,
                        'deletion_reason' => $commentDeleted ? CommentDeletionReason::Author->value : null,
                        'deleted_by_id' => $commentDeleted ? $user->id : null,
                        'moderated_by_id' => $commentModerated ? $moderator?->id : null,
                        'moderation_reason' => $commentModerated ? $this->commentModerationReason($commentStatus)->value : null,
                        'moderator_note' => $commentModerated ? 'Комментарий оставлен в демонстрационном состоянии модерации.' : null,
                        'moderated_at' => $commentModerated ? $createdAt->addHours(2) : null,
                        'submission_key' => $commentSubmission,
                        'created_at' => $createdAt->addMinute(),
                        'updated_at' => $commentEdited ? $createdAt->addHours(3) : $createdAt->addMinute(),
                        'deleted_at' => $commentDeleted ? $createdAt->addDays(4) : null,
                    ];
                    $rootSpecs[$commentSubmission] = [
                        'author_index' => $userIndex,
                        'catalog_title_id' => $context->titleId,
                        'title' => $context->displayTitle,
                        'target_type' => $targetType->value,
                        'target_id' => $targetId,
                        'target_key' => $targetKey,
                        'global_ordinal' => $globalOrdinal,
                        'dialogue' => $dialogue,
                        'published' => $commentStatus === CommentStatus::Published && ! $commentDeleted,
                        'created_at' => $createdAt->addMinute(),
                    ];
                    $position++;
                }

                $writer->upsert(
                    (new CatalogTitleReview)->getTable(),
                    $reviewRows,
                    ['ownership_key'],
                    $this->updates($reviewRows, ['ownership_key', 'created_at']),
                );
                $writer->upsert(
                    (new Comment)->getTable(),
                    $rootRows,
                    ['submission_key'],
                    $this->updates($rootRows, ['submission_key', 'created_at']),
                );
                $reviews = CatalogTitleReview::query()
                    ->whereIn('ownership_key', array_keys($reviewSpecs))
                    ->get(['id', 'ownership_key', 'user_id']);
                $roots = Comment::query()
                    ->withTrashed()
                    ->whereIn('submission_key', array_keys($rootSpecs))
                    ->get(['id', 'submission_key', 'user_id', 'catalog_title_id']);
                $voteRows = $this->reviewVoteRows($reviews, $reviewSpecs, $users, $options);
                $writer->upsert(
                    (new CatalogTitleReviewVote)->getTable(),
                    $voteRows,
                    ['catalog_title_review_id', 'user_id'],
                    ['type', 'updated_at'],
                );
                [$replyRows, $replySpecs] = $this->replyRows(
                    $roots,
                    $rootSpecs,
                    $users,
                    $options,
                    $totalPairs,
                );
                $writer->upsert(
                    (new Comment)->getTable(),
                    $replyRows,
                    ['submission_key'],
                    $this->updates($replyRows, ['submission_key', 'created_at']),
                );
                $replies = Comment::query()
                    ->withTrashed()
                    ->whereIn('submission_key', array_keys($replySpecs))
                    ->get(['id', 'submission_key', 'user_id', 'parent_id']);
                $replyRows = $this->linkReplyRows($replyRows, $replies, $replySpecs, $roots);
                $writer->upsert(
                    (new Comment)->getTable(),
                    $replyRows,
                    ['submission_key'],
                    $this->updates($replyRows, ['submission_key', 'created_at']),
                );
                $reactionRows = $this->commentReactionRows(
                    $roots->concat($replies),
                    $users,
                    $options,
                );
                $writer->upsert(
                    (new CommentReaction)->getTable(),
                    $reactionRows,
                    ['comment_id', 'user_id'],
                    ['type', 'updated_at'],
                );

                $counters['reviews'] += count($reviewRows);
                $counters['review_votes'] += count($voteRows);
                $counters['root_comments'] += count($rootRows);
                $counters['replies'] += count($replyRows);
                $counters['comment_reactions'] += count($reactionRows);
            }

            $collectionRows = $this->collectionCommentRows($user, $userIndex, $totalPairs);
            $writer->upsert(
                (new Comment)->getTable(),
                $collectionRows,
                ['submission_key'],
                $this->updates($collectionRows, ['submission_key', 'created_at']),
            );
            $counters['collection_comments'] += count($collectionRows);
            $progress?->__invoke($this->key(), $userIndex, $options->userCount);
        }

        return new DemoStageReport($this->key(), $counters, microtime(true) - $startedAt);
    }

    private function reviewStatus(int $ordinal): ReviewStatus
    {
        $forced = [
            0 => ReviewStatus::Published,
            1 => ReviewStatus::Pending,
            2 => ReviewStatus::Hidden,
            5 => ReviewStatus::Rejected,
            6 => ReviewStatus::Spam,
            9 => ReviewStatus::Removed,
        ];

        if (isset($forced[$ordinal])) {
            return $forced[$ordinal];
        }

        return match ($ordinal % 100) {
            96 => ReviewStatus::Pending,
            97 => ReviewStatus::Hidden,
            98 => ReviewStatus::Rejected,
            99 => ReviewStatus::Spam,
            default => ReviewStatus::Published,
        };
    }

    private function commentStatus(int $ordinal, bool $dialogue): CommentStatus
    {
        if ($dialogue) {
            return CommentStatus::Published;
        }

        $forced = [
            1 => CommentStatus::Pending,
            2 => CommentStatus::Hidden,
            5 => CommentStatus::Rejected,
            6 => CommentStatus::Spam,
            9 => CommentStatus::Removed,
        ];

        if (isset($forced[$ordinal])) {
            return $forced[$ordinal];
        }

        return match ($ordinal % 100) {
            96 => CommentStatus::Pending,
            97 => CommentStatus::Hidden,
            98 => CommentStatus::Rejected,
            99 => CommentStatus::Spam,
            default => CommentStatus::Published,
        };
    }

    private function reviewModerationReason(ReviewStatus $status): ReviewModerationReason
    {
        return match ($status) {
            ReviewStatus::Spam => ReviewModerationReason::Spam,
            ReviewStatus::Hidden => ReviewModerationReason::UnmarkedSpoiler,
            ReviewStatus::Rejected => ReviewModerationReason::OffTopic,
            ReviewStatus::Removed => ReviewModerationReason::Abuse,
            default => ReviewModerationReason::Other,
        };
    }

    private function commentModerationReason(CommentStatus $status): CommentModerationReason
    {
        return match ($status) {
            CommentStatus::Spam => CommentModerationReason::Spam,
            CommentStatus::Hidden => CommentModerationReason::Spoiler,
            CommentStatus::Rejected => CommentModerationReason::OffTopic,
            CommentStatus::Removed => CommentModerationReason::Abuse,
            default => CommentModerationReason::Other,
        };
    }

    /** @return array{CommentTargetType, int} */
    private function commentTarget(DemoTitleContext $context, int $ordinal): array
    {
        return match ($ordinal % 3) {
            1 => $context->firstSeasonId !== null
                ? [CommentTargetType::Season, $context->firstSeasonId]
                : [CommentTargetType::Title, $context->titleId],
            2 => $context->firstEpisodeId !== null
                ? [CommentTargetType::Episode, $context->firstEpisodeId]
                : [CommentTargetType::Title, $context->titleId],
            default => [CommentTargetType::Title, $context->titleId],
        };
    }

    /**
     * @param  Collection<int, CatalogTitleReview>  $reviews
     * @param  array<string, array{author_index: int, created_at: CarbonImmutable}>  $specs
     * @param  Collection<int, User>  $users
     * @return list<array<string, mixed>>
     */
    private function reviewVoteRows(
        Collection $reviews,
        array $specs,
        Collection $users,
        DemoDataOptions $options,
    ): array {
        $rows = [];

        foreach ($reviews as $review) {
            $spec = $specs[(string) $review->ownership_key];
            $count = min(
                max(0, $options->userCount - 1),
                $this->stable->integer("community:review:{$review->id}:vote-count", 1, 3),
            );

            for ($ordinal = 0; $ordinal < $count; $ordinal++) {
                $voterIndex = $this->otherUserIndex($spec['author_index'], $ordinal + 1, $options->userCount);
                $voter = $users->get($voterIndex);

                if (! $voter instanceof User) {
                    continue;
                }

                $rows[] = [
                    'catalog_title_review_id' => $review->id,
                    'user_id' => $voter->id,
                    'type' => $this->stable->boolean("community:review:{$review->id}:vote:{$ordinal}", 82)
                        ? ReviewVoteType::Helpful->value
                        : ReviewVoteType::NotHelpful->value,
                    'created_at' => $spec['created_at']->addDays($ordinal + 1),
                    'updated_at' => $spec['created_at']->addDays($ordinal + 1),
                ];
            }
        }

        return $rows;
    }

    /**
     * @param  Collection<int, Comment>  $roots
     * @param  array<string, array<string, mixed>>  $rootSpecs
     * @param  Collection<int, User>  $users
     * @return array{list<array<string, mixed>>, array<string, array{root_submission: string, ordinal: int}>}
     */
    private function replyRows(
        Collection $roots,
        array $rootSpecs,
        Collection $users,
        DemoDataOptions $options,
        int $totalPairs,
    ): array {
        $rows = [];
        $specs = [];

        foreach ($roots as $root) {
            $rootSubmission = (string) $root->submission_key;
            $rootSpec = $rootSpecs[$rootSubmission];

            if (! $rootSpec['dialogue'] || ! $rootSpec['published']) {
                continue;
            }

            $replyCount = $this->stable->integer("community:root:{$rootSpec['global_ordinal']}:reply-count", 2, 8);
            $participantCount = min(
                max(1, $options->userCount - 1),
                $this->stable->integer("community:root:{$rootSpec['global_ordinal']}:participants", 2, 6),
            );
            $previousAuthorIndex = $rootSpec['author_index'];

            for ($ordinal = 0; $ordinal < $replyCount; $ordinal++) {
                $authorIndex = $this->otherUserIndex(
                    $rootSpec['author_index'],
                    ($ordinal % $participantCount) + 1,
                    $options->userCount,
                );
                $author = $users->get($authorIndex);

                if (! $author instanceof User) {
                    continue;
                }

                $textOrdinal = $rootSpec['global_ordinal'] + $ordinal * $totalPairs;
                $body = CommentBody::from($this->text->replyBody(
                    $this->personas->make($authorIndex),
                    $this->personas->make($previousAuthorIndex)->givenName,
                    $rootSpec['title'],
                    $textOrdinal,
                ));
                $submission = $this->commentSubmissionKey(
                    $author,
                    $rootSpec['target_key'],
                    "community:root:{$rootSpec['global_ordinal']}:reply:{$ordinal}:submission",
                );
                $createdAt = $rootSpec['created_at']->addMinutes($ordinal + 1);
                $rows[] = [
                    'user_id' => $author->id,
                    'target_type' => $rootSpec['target_type'],
                    'target_id' => $rootSpec['target_id'],
                    'catalog_title_id' => $rootSpec['catalog_title_id'],
                    'parent_id' => $root->id,
                    'reply_to_id' => $root->id,
                    'body' => $body->value,
                    'body_hash' => $body->hash,
                    'is_spoiler' => false,
                    'status' => CommentStatus::Published->value,
                    'version' => 1,
                    'edited_at' => null,
                    'deletion_reason' => null,
                    'deleted_by_id' => null,
                    'moderated_by_id' => null,
                    'moderation_reason' => null,
                    'moderator_note' => null,
                    'moderated_at' => null,
                    'submission_key' => $submission,
                    'created_at' => $createdAt,
                    'updated_at' => $createdAt,
                    'deleted_at' => null,
                ];
                $specs[$submission] = [
                    'root_submission' => $rootSubmission,
                    'ordinal' => $ordinal,
                ];
                $previousAuthorIndex = $authorIndex;
            }
        }

        return [$rows, $specs];
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @param  Collection<int, Comment>  $replies
     * @param  array<string, array{root_submission: string, ordinal: int}>  $replySpecs
     * @param  Collection<int, Comment>  $roots
     * @return list<array<string, mixed>>
     */
    private function linkReplyRows(array $rows, Collection $replies, array $replySpecs, Collection $roots): array
    {
        if ($rows === []) {
            return [];
        }

        $replyIds = $replies->keyBy('submission_key')->map->id;
        $rootIds = $roots->keyBy('submission_key')->map->id;
        $grouped = collect($replySpecs)->groupBy('root_submission', preserveKeys: true);
        $previousBySubmission = [];

        foreach ($grouped as $rootSubmission => $specs) {
            $previousId = (int) $rootIds->get($rootSubmission);

            foreach ($specs->sortBy('ordinal') as $submission => $spec) {
                $previousBySubmission[$submission] = $previousId;
                $previousId = (int) $replyIds->get($submission);
            }
        }

        return array_map(function (array $row) use ($previousBySubmission): array {
            $row['reply_to_id'] = $previousBySubmission[$row['submission_key']];

            return $row;
        }, $rows);
    }

    /**
     * @param  Collection<int, Comment>  $comments
     * @param  Collection<int, User>  $users
     * @return list<array<string, mixed>>
     */
    private function commentReactionRows(Collection $comments, Collection $users, DemoDataOptions $options): array
    {
        $rows = [];
        $userIndexes = $users->mapWithKeys(fn (User $user, int $index): array => [$user->id => $index]);

        foreach ($comments->unique('id') as $comment) {
            $authorIndex = (int) $userIndexes->get($comment->user_id);

            if ($authorIndex < 1) {
                continue;
            }

            $count = min(
                max(0, $options->userCount - 1),
                $this->stable->integer("community:comment:{$comment->id}:reaction-count", 1, 4),
            );

            for ($ordinal = 0; $ordinal < $count; $ordinal++) {
                $reactorIndex = $this->otherUserIndex($authorIndex, $ordinal + 1, $options->userCount);
                $reactor = $users->get($reactorIndex);

                if (! $reactor instanceof User) {
                    continue;
                }

                $createdAt = CarbonImmutable::parse('2026-01-15 12:00:00')->addMinutes($comment->id + $ordinal);
                $rows[] = [
                    'comment_id' => $comment->id,
                    'user_id' => $reactor->id,
                    'type' => $this->stable->boolean("community:comment:{$comment->id}:reaction:{$ordinal}", 86)
                        ? CommentReactionType::Up->value
                        : CommentReactionType::Down->value,
                    'created_at' => $createdAt,
                    'updated_at' => $createdAt,
                ];
            }
        }

        return $rows;
    }

    /** @return list<array<string, mixed>> */
    private function collectionCommentRows(User $user, int $userIndex, int $totalPairs): array
    {
        $rows = [];
        $collections = CatalogCollection::query()
            ->where('owner_id', $user->id)
            ->orderBy('id')
            ->get(['id', 'name']);

        foreach ($collections as $ordinal => $collection) {
            $textOrdinal = $totalPairs + ($userIndex - 1) * max(1, $collections->count()) + $ordinal;
            $body = CommentBody::from($this->text->commentBody(
                $this->personas->make($userIndex),
                $collection->name,
                $textOrdinal,
            ));
            $targetKey = CommentTargetType::Collection->value.':'.$collection->id;
            $createdAt = CarbonImmutable::parse('2026-02-01 12:00:00')->addMinutes($textOrdinal);
            $rows[] = [
                'user_id' => $user->id,
                'target_type' => CommentTargetType::Collection->value,
                'target_id' => $collection->id,
                'catalog_title_id' => null,
                'parent_id' => null,
                'reply_to_id' => null,
                'body' => $body->value,
                'body_hash' => $body->hash,
                'is_spoiler' => false,
                'status' => CommentStatus::Published->value,
                'version' => 1,
                'edited_at' => null,
                'deletion_reason' => null,
                'deleted_by_id' => null,
                'moderated_by_id' => null,
                'moderation_reason' => null,
                'moderator_note' => null,
                'moderated_at' => null,
                'submission_key' => $this->commentSubmissionKey(
                    $user,
                    $targetKey,
                    "community:collection:{$collection->id}:submission",
                ),
                'created_at' => $createdAt,
                'updated_at' => $createdAt,
                'deleted_at' => null,
            ];
        }

        return $rows;
    }

    private function commentSubmissionKey(User $user, string $targetKey, string $scope): string
    {
        return hash('sha256', $user->id.':'.$targetKey.':'.strtolower($this->stable->uuid($scope)));
    }

    private function otherUserIndex(int $authorIndex, int $offset, int $userCount): int
    {
        if ($userCount < 2) {
            return $authorIndex;
        }

        return (($authorIndex - 1 + $offset) % $userCount) + 1;
    }

    private function createdAt(int $userIndex, int $position): CarbonImmutable
    {
        return CarbonImmutable::parse('2025-03-01 12:00:00')
            ->addDays($this->stable->integer("community:user:{$userIndex}:base-day", 0, 240))
            ->addMinutes($position * 10);
    }

    /** @return Collection<int, User> */
    private function users(DemoDataOptions $options): Collection
    {
        $emails = collect(range(1, $options->userCount))
            ->mapWithKeys(fn (int $index): array => ["user{$index}@example.com" => $index]);
        $usersByEmail = User::query()->whereIn('email', $emails->keys())->get()->keyBy('email');

        return $emails->mapWithKeys(function (int $index, string $email) use ($usersByEmail): array {
            /** @var User $user */
            $user = $usersByEmail->get($email) ?? throw new \LogicException("Demo user {$email} is missing.");

            return [$index => $user];
        });
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @param  list<string>  $excluded
     * @return list<string>
     */
    private function updates(array $rows, array $excluded): array
    {
        return $rows === [] ? [] : array_values(array_diff(array_keys($rows[0]), $excluded));
    }
}
