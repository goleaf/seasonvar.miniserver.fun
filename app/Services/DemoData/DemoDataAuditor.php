<?php

declare(strict_types=1);

namespace App\Services\DemoData;

use App\DTOs\DemoData\DemoAuditReport;
use App\DTOs\DemoData\DemoDataOptions;
use App\Enums\CatalogCollectionReportReason;
use App\Enums\CatalogCollectionReportStatus;
use App\Enums\CommentReportCategory;
use App\Enums\CommentReportStatus;
use App\Enums\CommentStatus;
use App\Enums\CommentTargetType;
use App\Enums\ContentRequestPriority;
use App\Enums\ContentRequestStatus;
use App\Enums\ContentRequestType;
use App\Enums\ReviewOrigin;
use App\Enums\ReviewReportCategory;
use App\Enums\ReviewReportStatus;
use App\Enums\ReviewStatus;
use App\Enums\TechnicalIssuePriority;
use App\Enums\TechnicalIssueResolutionType;
use App\Enums\TechnicalIssueSeverity;
use App\Enums\TechnicalIssueStatus;
use App\Enums\TechnicalIssueTargetType;
use App\Enums\TechnicalIssueType;
use App\Enums\UserProfileReportCategory;
use App\Enums\UserProfileReportStatus;
use App\Models\CatalogCollection;
use App\Models\CatalogCollectionReport;
use App\Models\CatalogTitle;
use App\Models\CatalogTitleReview;
use App\Models\CatalogTitleReviewReport;
use App\Models\CatalogTitleReviewVote;
use App\Models\CatalogTitleUserState;
use App\Models\Comment;
use App\Models\CommentReaction;
use App\Models\CommentReport;
use App\Models\ContentRequest;
use App\Models\TechnicalIssue;
use App\Models\TechnicalIssueAttachment;
use App\Models\User;
use App\Models\UserBlock;
use App\Models\UserMute;
use App\Models\UserProfile;
use App\Models\UserProfileReport;
use App\Models\UserTag;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use LogicException;

final class DemoDataAuditor
{
    /**
     * @param  array{catalog_titles?: int, provider_reviews?: int}  $preservationBaseline
     */
    public function audit(DemoDataOptions $options, array $preservationBaseline = []): DemoAuditReport
    {
        $emails = array_map(
            static fn (int $index): string => "user{$index}@example.com",
            range(1, $options->userCount),
        );
        $userIds = User::query()->whereIn('email', $emails)->orderBy('id')->pluck('id');
        $publishedTitles = CatalogTitle::query()->published()->count();
        $expectedTitlesPerUser = $options->selectedTitleCount($publishedTitles);
        $expectedPairs = $options->userCount * $expectedTitlesPerUser;
        $stateTable = (new CatalogTitleUserState)->getTable();
        $reviewTable = (new CatalogTitleReview)->getTable();
        $commentTable = (new Comment)->getTable();
        $notificationTable = 'notifications';
        $userReviewQuery = DB::table($reviewTable)
            ->whereIn('user_id', $userIds)
            ->where('origin', ReviewOrigin::User->value);
        $rootTitleCommentQuery = DB::table($commentTable)
            ->whereIn('user_id', $userIds)
            ->whereNull('parent_id')
            ->whereIn('target_type', [
                CommentTargetType::Title->value,
                CommentTargetType::Season->value,
                CommentTargetType::Episode->value,
            ]);
        $violations = [];
        $counters = [
            'demo_users' => $userIds->count(),
            'published_titles' => $publishedTitles,
            'selected_titles_per_user' => $expectedTitlesPerUser,
            'user_title_states' => DB::table($stateTable)->whereIn('user_id', $userIds)->count(),
            'user_reviews' => (clone $userReviewQuery)->count(),
            'root_title_comments' => (clone $rootTitleCommentQuery)->count(),
            'content_requests' => ContentRequest::query()->whereIn('requester_id', $userIds)->count(),
            'personal_tags' => UserTag::query()->whereIn('user_id', $userIds)->count(),
            'collections' => CatalogCollection::query()->whereIn('owner_id', $userIds)->count(),
            'notifications' => DB::table($notificationTable)->whereIn('notifiable_id', $userIds)->count(),
        ];

        if ($userIds->count() !== $options->userCount) {
            $violations[] = sprintf('Ожидалось %d демонстрационных пользователей, найдено %d.', $options->userCount, $userIds->count());
        }

        foreach ([
            'состояний каталога' => [DB::table($stateTable)->whereIn('user_id', $userIds), 'user_id'],
            'рецензий' => [(clone $userReviewQuery), 'user_id'],
            'корневых комментариев' => [(clone $rootTitleCommentQuery), 'user_id'],
        ] as $label => [$query, $ownerColumn]) {
            $counts = $query->selectRaw("{$ownerColumn}, COUNT(*) AS aggregate")
                ->groupBy($ownerColumn)
                ->pluck('aggregate', $ownerColumn);

            foreach ($userIds as $userId) {
                $actual = (int) ($counts[$userId] ?? 0);

                if ($actual !== $expectedTitlesPerUser) {
                    $violations[] = sprintf(
                        'У пользователя %d неверное количество %s: ожидалось %d, найдено %d.',
                        $userId,
                        $label,
                        $expectedTitlesPerUser,
                        $actual,
                    );
                }
            }
        }

        if ($counters['user_title_states'] !== $expectedPairs
            || $counters['user_reviews'] !== $expectedPairs
            || $counters['root_title_comments'] !== $expectedPairs) {
            $violations[] = 'Половинное покрытие каталога расходится между состояниями, рецензиями и комментариями.';
        }

        foreach ([
            'заявок' => [ContentRequest::query()->whereIn('requester_id', $userIds), 'requester_id', $options->requestMinimum, $options->requestMaximum],
            'личных тегов' => [UserTag::query()->whereIn('user_id', $userIds), 'user_id', $options->personalTagMinimum, $options->personalTagMaximum],
            'коллекций' => [CatalogCollection::query()->whereIn('owner_id', $userIds), 'owner_id', $options->collectionMinimum, $options->collectionMaximum],
        ] as $label => [$query, $ownerColumn, $minimum, $maximum]) {
            $counts = $query->selectRaw("{$ownerColumn}, COUNT(*) AS aggregate")
                ->groupBy($ownerColumn)
                ->pluck('aggregate', $ownerColumn);

            foreach ($userIds as $userId) {
                $actual = (int) ($counts[$userId] ?? 0);

                if ($actual < $minimum || $actual > $maximum) {
                    $violations[] = sprintf(
                        'У пользователя %d неверное количество %s: ожидалось %d–%d, найдено %d.',
                        $userId,
                        $label,
                        $minimum,
                        $maximum,
                        $actual,
                    );
                }
            }
        }

        $this->auditDuplicates($userIds->all(), $violations);
        $this->auditSelfInteractions($userIds->all(), $violations);
        $this->auditChronology($userIds->all(), $violations);
        $this->auditReviewIntegrity($userIds->all(), $violations);
        $this->auditCommentIntegrity($userIds->all(), $violations);
        $this->auditEnumCoverage($userIds->all(), $violations);
        $counters['asset_files'] = $this->auditAssets($userIds->all(), $violations);

        $currentProviderReviews = CatalogTitleReview::query()
            ->where('origin', ReviewOrigin::Provider->value)
            ->count();
        $counters['provider_reviews'] = $currentProviderReviews;

        if (isset($preservationBaseline['catalog_titles'])
            && $preservationBaseline['catalog_titles'] !== CatalogTitle::query()->count()) {
            $violations[] = 'Во время демонстрационного наполнения изменилось количество тайтлов каталога.';
        }

        if (isset($preservationBaseline['provider_reviews'])
            && $preservationBaseline['provider_reviews'] !== $currentProviderReviews) {
            $violations[] = 'Во время демонстрационного наполнения изменилось количество рецензий поставщика.';
        }

        return new DemoAuditReport($counters, array_slice($violations, 0, 100));
    }

    public function assertValid(DemoAuditReport $report): void
    {
        if ($report->passed()) {
            return;
        }

        throw new LogicException(sprintf(
            "Проверка демонстрационных данных обнаружила %d нарушений:\n%s",
            count($report->violations),
            implode("\n", array_slice($report->violations, 0, 20)),
        ));
    }

    /**
     * @param  list<int>  $userIds
     * @param  list<string>  $violations
     */
    private function auditReviewIntegrity(array $userIds, array &$violations): void
    {
        $reviewTable = (new CatalogTitleReview)->getTable();
        $demoReviews = DB::table($reviewTable)
            ->whereIn('user_id', $userIds)
            ->where('origin', ReviewOrigin::User->value);

        if ((clone $demoReviews)
            ->where('status', ReviewStatus::Removed->value)
            ->where(static function (Builder $query): void {
                $query
                    ->whereNull('deleted_at')
                    ->orWhereNull('deletion_reason')
                    ->orWhereNull('deleted_by_id');
            })
            ->exists()) {
            $violations[] = 'Удалённая модератором рецензия не имеет полного доказательства удаления.';
        }

        if ((clone $demoReviews)
            ->whereNotNull('deleted_at')
            ->whereNull('deletion_reason')
            ->exists()) {
            $violations[] = 'Удалённая рецензия не имеет стабильной причины удаления.';
        }
    }

    /**
     * @param  list<int>  $userIds
     * @param  list<string>  $violations
     */
    private function auditDuplicates(array $userIds, array &$violations): void
    {
        $checks = [
            'состояния пользователь–тайтл' => DB::table((new CatalogTitleUserState)->getTable())
                ->whereIn('user_id', $userIds)
                ->select(['user_id', 'catalog_title_id'])
                ->groupBy(['user_id', 'catalog_title_id']),
            'пользовательские рецензии' => DB::table((new CatalogTitleReview)->getTable())
                ->whereIn('user_id', $userIds)
                ->where('origin', ReviewOrigin::User->value)
                ->select(['user_id', 'catalog_title_id'])
                ->groupBy(['user_id', 'catalog_title_id']),
            'блокировки' => DB::table((new UserBlock)->getTable())
                ->whereIn('blocker_id', $userIds)
                ->select(['blocker_id', 'blocked_id'])
                ->groupBy(['blocker_id', 'blocked_id']),
            'заглушения' => DB::table((new UserMute)->getTable())
                ->whereIn('muter_id', $userIds)
                ->select(['muter_id', 'muted_id'])
                ->groupBy(['muter_id', 'muted_id']),
        ];

        foreach ($checks as $label => $query) {
            if ($query->havingRaw('COUNT(*) > 1')->exists()) {
                $violations[] = "Обнаружены дубли: {$label}.";
            }
        }

        foreach ([
            'рецензий' => DB::table((new CatalogTitleReview)->getTable())
                ->whereIn('user_id', $userIds)
                ->where('origin', ReviewOrigin::User->value)
                ->select('body_hash')
                ->groupBy('body_hash'),
            'комментариев' => DB::table((new Comment)->getTable())
                ->whereIn('user_id', $userIds)
                ->select('body_hash')
                ->groupBy('body_hash'),
        ] as $label => $query) {
            if ($query->havingRaw('COUNT(*) > 1')->exists()) {
                $violations[] = "Обнаружены одинаковые тексты {$label}.";
            }
        }
    }

    /**
     * @param  list<int>  $userIds
     * @param  list<string>  $violations
     */
    private function auditSelfInteractions(array $userIds, array &$violations): void
    {
        $reviewTable = (new CatalogTitleReview)->getTable();
        $commentTable = (new Comment)->getTable();
        $collectionTable = (new CatalogCollection)->getTable();
        $checks = [
            'голоса за собственные рецензии' => DB::table((new CatalogTitleReviewVote)->getTable().' as votes')
                ->join($reviewTable.' as reviews', 'reviews.id', '=', 'votes.catalog_title_review_id')
                ->whereIn('votes.user_id', $userIds)
                ->whereColumn('votes.user_id', 'reviews.user_id'),
            'реакции на собственные комментарии' => DB::table((new CommentReaction)->getTable().' as reactions')
                ->join($commentTable.' as comments', 'comments.id', '=', 'reactions.comment_id')
                ->whereIn('reactions.user_id', $userIds)
                ->whereColumn('reactions.user_id', 'comments.user_id'),
            'самоблокировки' => DB::table((new UserBlock)->getTable())
                ->whereIn('blocker_id', $userIds)
                ->whereColumn('blocker_id', 'blocked_id'),
            'самозаглушения' => DB::table((new UserMute)->getTable())
                ->whereIn('muter_id', $userIds)
                ->whereColumn('muter_id', 'muted_id'),
            'жалобы на собственные комментарии' => DB::table((new CommentReport)->getTable().' as reports')
                ->join($commentTable.' as comments', 'comments.id', '=', 'reports.comment_id')
                ->whereIn('reports.reporter_id', $userIds)
                ->whereColumn('reports.reporter_id', 'comments.user_id'),
            'жалобы на собственные рецензии' => DB::table((new CatalogTitleReviewReport)->getTable().' as reports')
                ->join($reviewTable.' as reviews', 'reviews.id', '=', 'reports.catalog_title_review_id')
                ->whereIn('reports.reporter_id', $userIds)
                ->whereColumn('reports.reporter_id', 'reviews.user_id'),
            'жалобы на собственные коллекции' => DB::table((new CatalogCollectionReport)->getTable().' as reports')
                ->join($collectionTable.' as collections', 'collections.id', '=', 'reports.catalog_collection_id')
                ->whereIn('reports.reporter_id', $userIds)
                ->whereColumn('reports.reporter_id', 'collections.owner_id'),
            'жалобы на собственный профиль' => DB::table((new UserProfileReport)->getTable())
                ->whereIn('reporter_id', $userIds)
                ->whereColumn('reporter_id', 'target_user_id'),
        ];

        foreach ($checks as $label => $query) {
            if ($query->exists()) {
                $violations[] = "Обнаружены {$label}.";
            }
        }
    }

    /**
     * @param  list<int>  $userIds
     * @param  list<string>  $violations
     */
    private function auditChronology(array $userIds, array &$violations): void
    {
        $commentTable = (new Comment)->getTable();

        if (DB::table($commentTable.' as replies')
            ->join($commentTable.' as parents', 'parents.id', '=', 'replies.parent_id')
            ->whereIn('replies.user_id', $userIds)
            ->whereColumn('replies.created_at', '<=', 'parents.created_at')
            ->exists()) {
            $violations[] = 'Ответ в обсуждении создан не позже родительского комментария.';
        }

        foreach ([
            new CommentReport,
            new CatalogTitleReviewReport,
            new CatalogCollectionReport,
            new UserProfileReport,
        ] as $report) {
            if (DB::table($report->getTable())
                ->whereIn('reporter_id', $userIds)
                ->whereNotNull('resolved_at')
                ->whereColumn('resolved_at', '<', 'created_at')
                ->exists()) {
                $violations[] = "В {$report->getTable()} решение датировано раньше жалобы.";
            }
        }
    }

    /**
     * @param  list<int>  $userIds
     * @param  list<string>  $violations
     */
    private function auditCommentIntegrity(array $userIds, array &$violations): void
    {
        $commentTable = (new Comment)->getTable();
        $demoComments = DB::table($commentTable)->whereIn('user_id', $userIds);

        if ((clone $demoComments)
            ->where('status', CommentStatus::Removed->value)
            ->whereNull('deleted_at')
            ->exists()) {
            $violations[] = 'Удалённый модератором комментарий не имеет отметки удаления.';
        }

        if ((clone $demoComments)
            ->whereNotNull('deleted_at')
            ->whereNull('deletion_reason')
            ->exists()) {
            $violations[] = 'Удалённый комментарий не имеет стабильной причины удаления.';
        }

        if (DB::table($commentTable.' as replies')
            ->join($commentTable.' as parents', 'parents.id', '=', 'replies.parent_id')
            ->whereIn('replies.user_id', $userIds)
            ->where(static function (Builder $query): void {
                $query
                    ->whereColumn('replies.target_type', '!=', 'parents.target_type')
                    ->orWhereColumn('replies.target_id', '!=', 'parents.target_id')
                    ->orWhereNotNull('parents.parent_id');
            })
            ->exists()) {
            $violations[] = 'Ответ демонстрационного обсуждения нарушает target scope или допустимую глубину.';
        }
    }

    /**
     * @param  list<int>  $userIds
     * @param  list<string>  $violations
     */
    private function auditEnumCoverage(array $userIds, array &$violations): void
    {
        $this->requireEnumCases(
            DB::table((new CatalogTitleReview)->getTable())->whereIn('user_id', $userIds)->where('origin', ReviewOrigin::User->value),
            'status',
            ReviewStatus::cases(),
            'статусы рецензий',
            $violations,
        );
        $this->requireEnumCases(
            DB::table((new Comment)->getTable())->whereIn('user_id', $userIds),
            'status',
            CommentStatus::cases(),
            'статусы комментариев',
            $violations,
        );

        foreach ([
            [ContentRequest::class, 'requester_id', ContentRequestType::cases(), 'type', 'типы заявок'],
            [ContentRequest::class, 'requester_id', ContentRequestStatus::cases(), 'status', 'статусы заявок'],
            [ContentRequest::class, 'requester_id', ContentRequestPriority::cases(), 'priority', 'приоритеты заявок'],
            [TechnicalIssue::class, 'requester_id', TechnicalIssueType::cases(), 'type', 'типы технических обращений'],
            [TechnicalIssue::class, 'requester_id', TechnicalIssueStatus::cases(), 'status', 'статусы технических обращений'],
            [TechnicalIssue::class, 'requester_id', TechnicalIssueTargetType::cases(), 'target_type', 'цели технических обращений'],
            [TechnicalIssue::class, 'requester_id', TechnicalIssueSeverity::cases(), 'severity', 'критичность технических обращений'],
            [TechnicalIssue::class, 'requester_id', TechnicalIssuePriority::cases(), 'priority', 'приоритеты технических обращений'],
            [TechnicalIssue::class, 'requester_id', TechnicalIssueResolutionType::cases(), 'resolution_type', 'решения технических обращений'],
            [CommentReport::class, 'reporter_id', CommentReportCategory::cases(), 'category', 'категории жалоб на комментарии'],
            [CommentReport::class, 'reporter_id', CommentReportStatus::cases(), 'status', 'статусы жалоб на комментарии'],
            [CatalogTitleReviewReport::class, 'reporter_id', ReviewReportCategory::cases(), 'category', 'категории жалоб на рецензии'],
            [CatalogTitleReviewReport::class, 'reporter_id', ReviewReportStatus::cases(), 'status', 'статусы жалоб на рецензии'],
            [CatalogCollectionReport::class, 'reporter_id', CatalogCollectionReportReason::cases(), 'reason', 'причины жалоб на коллекции'],
            [CatalogCollectionReport::class, 'reporter_id', CatalogCollectionReportStatus::cases(), 'status', 'статусы жалоб на коллекции'],
            [UserProfileReport::class, 'reporter_id', UserProfileReportCategory::cases(), 'category', 'категории жалоб на профили'],
            [UserProfileReport::class, 'reporter_id', UserProfileReportStatus::cases(), 'status', 'статусы жалоб на профили'],
        ] as [$modelClass, $ownerColumn, $cases, $column, $label]) {
            $model = new $modelClass;
            $this->requireEnumCases(
                DB::table($model->getTable())->whereIn($ownerColumn, $userIds),
                $column,
                $cases,
                $label,
                $violations,
            );
        }
    }

    /**
     * @param  list<int>  $userIds
     * @param  list<string>  $violations
     */
    private function auditAssets(array $userIds, array &$violations): int
    {
        $assets = [];
        $assetDisk = (string) config('demo-data.asset_disk');
        $profiles = DB::table((new UserProfile)->getTable())
            ->whereIn('user_id', $userIds)
            ->get(['user_id', 'avatar_disk', 'avatar_path', 'avatar_mime_type', 'cover_disk', 'cover_path', 'cover_mime_type']);
        $publicIds = User::query()->whereIn('id', $userIds)->pluck('public_id', 'id');

        foreach ($profiles as $profile) {
            $expectedPrefix = 'user-profiles/'.($publicIds[$profile->user_id] ?? '').'/';

            if ($profile->avatar_disk !== $assetDisk
                || $profile->cover_disk !== $assetDisk
                || $profile->avatar_mime_type !== 'image/webp'
                || $profile->cover_mime_type !== 'image/webp'
                || ! str_starts_with((string) $profile->avatar_path, $expectedPrefix.'avatar/')
                || ! str_starts_with((string) $profile->cover_path, $expectedPrefix.'cover/')) {
                $violations[] = 'Профиль демонстрационного пользователя содержит недоставляемое изображение.';
            }

            if ($profile->avatar_disk === $assetDisk && is_string($profile->avatar_path) && $profile->avatar_path !== '') {
                $assets[] = [$profile->avatar_disk, $profile->avatar_path];
            }

            if ($profile->cover_disk === $assetDisk && is_string($profile->cover_path) && $profile->cover_path !== '') {
                $assets[] = [$profile->cover_disk, $profile->cover_path];
            }
        }

        $collections = DB::table((new CatalogCollection)->getTable())
            ->whereIn('owner_id', $userIds)
            ->get(['public_id', 'cover_disk', 'cover_path', 'cover_mime_type']);

        foreach ($collections as $collection) {
            if ($collection->cover_disk !== $assetDisk
                || $collection->cover_mime_type !== 'image/webp'
                || ! str_starts_with((string) $collection->cover_path, 'catalog-collections/'.$collection->public_id.'/')) {
                $violations[] = 'Демонстрационная коллекция содержит недоставляемую обложку.';
            }

            if ($collection->cover_disk === $assetDisk && is_string($collection->cover_path) && $collection->cover_path !== '') {
                $assets[] = [$collection->cover_disk, $collection->cover_path];
            }
        }

        $attachments = DB::table((new TechnicalIssueAttachment)->getTable().' as attachments')
            ->join((new TechnicalIssue)->getTable().' as issues', 'issues.id', '=', 'attachments.technical_issue_id')
            ->whereIn('issues.requester_id', $userIds)
            ->get(['attachments.disk', 'attachments.path']);

        foreach ($attachments as $attachment) {
            $assets[] = [(string) $attachment->disk, (string) $attachment->path];
        }

        foreach ($assets as [$disk, $path]) {
            if ($disk === '' || $path === '' || ! Storage::disk($disk)->exists($path)) {
                $violations[] = "Не найден демонстрационный файл {$disk}:{$path}.";

                if (count($violations) >= 100) {
                    break;
                }
            }
        }

        return count($assets);
    }

    /**
     * @param  list<\BackedEnum>  $cases
     * @param  list<string>  $violations
     */
    private function requireEnumCases(
        Builder $query,
        string $column,
        array $cases,
        string $label,
        array &$violations,
    ): void {
        $expected = array_column($cases, 'value');
        $actual = (clone $query)->whereNotNull($column)->distinct()->pluck($column)->all();
        $missing = array_values(array_diff($expected, $actual));

        if ($missing !== []) {
            $violations[] = sprintf('Не покрыты %s: %s.', $label, implode(', ', $missing));
        }
    }
}
