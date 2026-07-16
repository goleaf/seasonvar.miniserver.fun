<?php

declare(strict_types=1);

namespace App\Services\DemoData\Stages;

use App\Contracts\DemoDataStage;
use App\DTOs\DemoData\DemoDataOptions;
use App\DTOs\DemoData\DemoStageReport;
use App\Enums\CatalogCollectionReportReason;
use App\Enums\CatalogCollectionReportStatus;
use App\Enums\CommentReportCategory;
use App\Enums\CommentReportStatus;
use App\Enums\CommentRestrictionReason;
use App\Enums\CommentRestrictionType;
use App\Enums\ReviewOrigin;
use App\Enums\ReviewReportCategory;
use App\Enums\ReviewReportStatus;
use App\Enums\ReviewRestrictionReason;
use App\Enums\ReviewRestrictionType;
use App\Enums\UserProfileReportCategory;
use App\Enums\UserProfileReportStatus;
use App\Models\CatalogCollection;
use App\Models\CatalogCollectionReport;
use App\Models\CatalogTitleReview;
use App\Models\CatalogTitleReviewReport;
use App\Models\CatalogTitleReviewRestriction;
use App\Models\Comment;
use App\Models\CommentReport;
use App\Models\CommentRestriction;
use App\Models\User;
use App\Models\UserBlock;
use App\Models\UserMute;
use App\Models\UserProfileReport;
use App\Services\DemoData\DemoBulkWriter;
use App\Services\DemoData\DemoPersonaFactory;
use App\Services\DemoData\DemoRussianText;
use App\Services\DemoData\DemoStableValue;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use LogicException;

final readonly class DemoModerationStage implements DemoDataStage
{
    public function __construct(
        private DemoStableValue $stable,
        private DemoPersonaFactory $personas,
        private DemoRussianText $text,
    ) {}

    public function key(): string
    {
        return 'moderation';
    }

    public function run(DemoDataOptions $options, ?Closure $progress = null): DemoStageReport
    {
        $startedAt = microtime(true);
        $options->assertEnvironment(app()->environment());
        $writer = new DemoBulkWriter($options);
        $users = $this->users($options);
        $moderator = $users->last() ?? throw new LogicException('Demo moderator is missing.');
        $commentReports = [];
        $reviewReports = [];
        $collectionReports = [];
        $profileReports = [];
        $blocks = [];
        $mutes = [];
        $commentOrdinal = 0;
        $reviewOrdinal = 0;
        $collectionOrdinal = 0;

        foreach ($users as $userIndex => $reporter) {
            $persona = $this->personas->make($userIndex);
            $comments = Comment::query()
                ->withTrashed()
                ->where('user_id', '!=', $reporter->id)
                ->orderBy('id')
                ->limit(4)
                ->get(['id']);
            $reviews = CatalogTitleReview::query()
                ->where('origin', ReviewOrigin::User->value)
                ->where('user_id', '!=', $reporter->id)
                ->orderBy('id')
                ->limit(4)
                ->get(['id']);
            $collections = CatalogCollection::query()
                ->withTrashed()
                ->where('owner_id', '!=', $reporter->id)
                ->orderBy('id')
                ->limit(2)
                ->get(['id', 'public_id', 'content_version']);

            if ($comments->count() < 4 || $reviews->count() < 4 || $collections->count() < 2) {
                throw new LogicException('Community and organization demo stages must run before moderation.');
            }

            foreach ($comments as $comment) {
                $status = $this->enumAt(CommentReportStatus::cases(), $commentOrdinal);
                $createdAt = $this->createdAt($userIndex, $commentOrdinal);
                $commentReports[] = [
                    'comment_id' => $comment->id,
                    'reporter_id' => $reporter->id,
                    'moderator_id' => $status === CommentReportStatus::Open ? null : $moderator->id,
                    'category' => $this->enumAt(CommentReportCategory::cases(), $commentOrdinal)->value,
                    'details' => $this->text->report($persona, 'комментарий в обсуждении сериала', $commentOrdinal),
                    'status' => $status->value,
                    'private_note' => $status === CommentReportStatus::Open
                        ? null : 'Модератор сопоставил жалобу с правилами сообщества и зафиксировал решение.',
                    'deduplication_key' => $this->stable->hash("moderation:comment-report:{$commentOrdinal}"),
                    'resolved_at' => $status->isOpen() ? null : $createdAt->addDay(),
                    'created_at' => $createdAt,
                    'updated_at' => $status === CommentReportStatus::Open ? $createdAt : $createdAt->addDay(),
                ];
                $commentOrdinal++;
            }

            foreach ($reviews as $review) {
                $status = $this->enumAt(ReviewReportStatus::cases(), $reviewOrdinal);
                $createdAt = $this->createdAt($userIndex, $reviewOrdinal + 10);
                $reviewReports[] = [
                    'catalog_title_review_id' => $review->id,
                    'reporter_id' => $reporter->id,
                    'moderator_id' => $status === ReviewReportStatus::Open ? null : $moderator->id,
                    'category' => $this->enumAt(ReviewReportCategory::cases(), $reviewOrdinal)->value,
                    'details' => $this->text->report($persona, 'пользовательскую рецензию', $reviewOrdinal + 100),
                    'status' => $status->value,
                    'private_note' => $status === ReviewReportStatus::Open
                        ? null : 'Рецензия проверена целиком, включая отметку о спойлерах и историю редактирования.',
                    'deduplication_key' => $this->stable->hash("moderation:review-report:{$reviewOrdinal}"),
                    'resolved_at' => $status->isOpen() ? null : $createdAt->addDay(),
                    'created_at' => $createdAt,
                    'updated_at' => $status === ReviewReportStatus::Open ? $createdAt : $createdAt->addDay(),
                ];
                $reviewOrdinal++;
            }

            foreach ($collections as $collection) {
                $status = $this->enumAt(CatalogCollectionReportStatus::cases(), $collectionOrdinal);
                $createdAt = $this->createdAt($userIndex, $collectionOrdinal + 20);
                $collectionReports[] = [
                    'catalog_collection_id' => $collection->id,
                    'collection_public_id' => $collection->public_id,
                    'collection_content_version' => $collection->content_version,
                    'reporter_id' => $reporter->id,
                    'moderator_id' => $status === CatalogCollectionReportStatus::Open ? null : $moderator->id,
                    'reason' => $this->enumAt(CatalogCollectionReportReason::cases(), $collectionOrdinal)->value,
                    'details' => $this->text->report($persona, 'публичную подборку', $collectionOrdinal + 200),
                    'status' => $status->value,
                    'resolution_note' => $status === CatalogCollectionReportStatus::Open
                        ? null : 'Название, описание, обложка и состав подборки проверены модератором.',
                    'deduplication_key' => $this->stable->hash("moderation:collection-report:{$collectionOrdinal}"),
                    'resolved_at' => $status === CatalogCollectionReportStatus::Open ? null : $createdAt->addDay(),
                    'created_at' => $createdAt,
                    'updated_at' => $status === CatalogCollectionReportStatus::Open ? $createdAt : $createdAt->addDay(),
                ];
                $collectionOrdinal++;
            }

            $targetIndex = ($userIndex % $options->userCount) + 1;
            $target = $users->get($targetIndex) ?? throw new LogicException('Demo profile report target is missing.');
            $profileStatus = $this->enumAt(UserProfileReportStatus::cases(), $userIndex - 1);
            $profileCreatedAt = $this->createdAt($userIndex, 40);
            $profileReports[] = [
                'public_id' => $this->stable->uuid("moderation:profile-report:{$userIndex}:public"),
                'target_user_id' => $target->id,
                'target_public_id' => $target->public_id,
                'reporter_id' => $reporter->id,
                'moderator_id' => $profileStatus === UserProfileReportStatus::Open ? null : $moderator->id,
                'category' => $this->enumAt(UserProfileReportCategory::cases(), $userIndex - 1)->value,
                'details' => $this->text->report($persona, 'публичный профиль пользователя', $userIndex + 300),
                'status' => $profileStatus->value,
                'private_note' => $profileStatus === UserProfileReportStatus::Open
                    ? null : 'Профиль проверен по имени, биографии и загруженным изображениям.',
                'deduplication_key' => $this->stable->hash("moderation:profile-report:{$userIndex}"),
                'resolved_at' => $profileStatus === UserProfileReportStatus::Open ? null : $profileCreatedAt->addDay(),
                'created_at' => $profileCreatedAt,
                'updated_at' => $profileStatus === UserProfileReportStatus::Open ? $profileCreatedAt : $profileCreatedAt->addDay(),
            ];

            for ($offset = 1; $offset <= 2; $offset++) {
                $blocked = $users->get((($userIndex - 1 + $offset) % $options->userCount) + 1);
                $muted = $users->get((($userIndex - 1 + $offset + 2) % $options->userCount) + 1);
                $createdAt = $this->createdAt($userIndex, 50 + $offset);
                $blocks[] = ['blocker_id' => $reporter->id, 'blocked_id' => $blocked?->id, 'created_at' => $createdAt, 'updated_at' => $createdAt];
                $mutes[] = ['muter_id' => $reporter->id, 'muted_id' => $muted?->id, 'created_at' => $createdAt, 'updated_at' => $createdAt];
            }

            $this->writeRestrictionRows($reporter, $moderator, $userIndex);
            $progress?->__invoke($this->key(), $userIndex, $options->userCount);
        }

        $writer->upsert((new CommentReport)->getTable(), $commentReports, ['deduplication_key'], $this->updates($commentReports, ['deduplication_key', 'created_at']));
        $writer->upsert((new CatalogTitleReviewReport)->getTable(), $reviewReports, ['deduplication_key'], $this->updates($reviewReports, ['deduplication_key', 'created_at']));
        $writer->upsert((new CatalogCollectionReport)->getTable(), $collectionReports, ['deduplication_key'], $this->updates($collectionReports, ['deduplication_key', 'created_at']));
        $writer->upsert((new UserProfileReport)->getTable(), $profileReports, ['deduplication_key'], $this->updates($profileReports, ['deduplication_key', 'created_at']));
        $writer->upsert((new UserBlock)->getTable(), $blocks, ['blocker_id', 'blocked_id'], ['updated_at']);
        $writer->upsert((new UserMute)->getTable(), $mutes, ['muter_id', 'muted_id'], ['updated_at']);

        return new DemoStageReport($this->key(), [
            'comment_reports' => count($commentReports),
            'review_reports' => count($reviewReports),
            'collection_reports' => count($collectionReports),
            'profile_reports' => count($profileReports),
            'blocks' => count($blocks),
            'mutes' => count($mutes),
            'comment_restrictions' => $options->userCount,
            'review_restrictions' => $options->userCount,
        ], microtime(true) - $startedAt);
    }

    private function writeRestrictionRows(User $user, User $moderator, int $ordinal): void
    {
        $startsAt = CarbonImmutable::parse('2025-04-01 09:00:00')->addDays($ordinal);
        $commentType = $this->enumAt(CommentRestrictionType::cases(), $ordinal - 1);
        $reviewType = $this->enumAt(ReviewRestrictionType::cases(), $ordinal - 1);

        DB::table((new CommentRestriction)->getTable())->updateOrInsert(
            ['user_id' => $user->id, 'starts_at' => $startsAt],
            [
                'moderator_id' => $moderator->id,
                'revoked_by_id' => $ordinal % 4 === 0 ? $moderator->id : null,
                'type' => $commentType->value,
                'reason_code' => $this->enumAt(CommentRestrictionReason::cases(), $ordinal - 1)->value,
                'private_note' => 'Ограничение создано по итогам повторной проверки демонстрационных жалоб.',
                'expires_at' => $commentType === CommentRestrictionType::Temporary ? $startsAt->addDays(14) : null,
                'revoked_at' => $ordinal % 4 === 0 ? $startsAt->addDays(3) : null,
                'created_at' => $startsAt,
                'updated_at' => $startsAt->addDay(),
            ],
        );
        DB::table((new CatalogTitleReviewRestriction)->getTable())->updateOrInsert(
            ['user_id' => $user->id, 'starts_at' => $startsAt->addHour()],
            [
                'moderator_id' => $moderator->id,
                'revoked_by_id' => $ordinal % 5 === 0 ? $moderator->id : null,
                'type' => $reviewType->value,
                'reason_code' => $this->enumAt(ReviewRestrictionReason::cases(), $ordinal - 1)->value,
                'private_note' => 'Ограничение публикации рецензий отражает отдельный сценарий модерации.',
                'expires_at' => $reviewType === ReviewRestrictionType::Temporary ? $startsAt->addDays(21) : null,
                'revoked_at' => $ordinal % 5 === 0 ? $startsAt->addDays(5) : null,
                'created_at' => $startsAt,
                'updated_at' => $startsAt->addDay(),
            ],
        );
    }

    private function createdAt(int $userIndex, int $ordinal): CarbonImmutable
    {
        return CarbonImmutable::parse('2025-03-01 08:00:00')->addDays(($userIndex - 1) * 4)->addMinutes($ordinal);
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
