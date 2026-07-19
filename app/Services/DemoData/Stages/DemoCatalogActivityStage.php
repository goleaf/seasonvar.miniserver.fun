<?php

declare(strict_types=1);

namespace App\Services\DemoData\Stages;

use App\Contracts\DemoDataStage;
use App\DTOs\DemoData\DemoDataOptions;
use App\DTOs\DemoData\DemoStageReport;
use App\DTOs\DemoData\DemoTitleContext;
use App\Enums\CatalogRecommendationFeedback;
use App\Enums\CatalogWatchStatus;
use App\Models\CatalogTitleUserState;
use App\Models\EpisodeViewProgress;
use App\Models\User;
use App\Services\DemoData\DemoBulkWriter;
use App\Services\DemoData\DemoStableValue;
use App\Services\DemoData\DemoTitleSelector;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Support\Collection;

final readonly class DemoCatalogActivityStage implements DemoDataStage
{
    public function __construct(private DemoStableValue $stable) {}

    public function key(): string
    {
        return 'catalog_activity';
    }

    public function run(DemoDataOptions $options, ?Closure $progress = null): DemoStageReport
    {
        $options->assertEnvironment(app()->environment());

        return $this->repairKnownDemoUsers($options, $progress);
    }

    public function repairKnownDemoUsers(DemoDataOptions $options, ?Closure $progress = null): DemoStageReport
    {
        $startedAt = microtime(true);
        $selector = new DemoTitleSelector($options);
        $writer = new DemoBulkWriter($options);
        $users = $this->users($options);
        $selectedCount = $options->selectedTitleCount($selector->publishedCount());
        $stateCount = 0;
        $progressCount = 0;

        foreach ($users as $userIndex => $user) {
            $position = 0;
            $feedbackOffset = $selectedCount > 0
                ? $this->stable->integer("activity:user:{$userIndex}:feedback-offset", 0, $selectedCount - 1)
                : 0;

            foreach ($selector->selectedIds($userIndex)->chunk($options->chunkSize) as $titleIds) {
                $ids = $titleIds->values()->all();
                $contexts = $selector->contexts($ids);
                $stateRows = [];
                $progressRows = [];

                foreach ($ids as $titleId) {
                    $status = $this->watchStatus($position);
                    $activityAt = $this->activityAt($userIndex, $position);
                    $feedback = $this->feedback($position, $selectedCount, $feedbackOffset);
                    $stateRows[] = [
                        'user_id' => $user->id,
                        'catalog_title_id' => $titleId,
                        'in_watchlist' => $status !== CatalogWatchStatus::Dropped
                            && $this->stable->boolean("activity:user:{$userIndex}:title:{$titleId}:watchlist", 72),
                        'rating' => $this->stable->integer("activity:user:{$userIndex}:title:{$titleId}:rating", 1, 10),
                        'watchlist_version' => 1,
                        'watchlist_updated_at' => $activityAt,
                        'rating_version' => 1,
                        'rating_updated_at' => $activityAt->addMinutes(1),
                        'recommendation_feedback' => $feedback?->value,
                        'recommendation_feedback_version' => $feedback === null ? 0 : 1,
                        'recommendation_feedback_updated_at' => $feedback === null ? null : $activityAt->addMinutes(3),
                        'watch_status' => $status->value,
                        'watch_status_version' => 1,
                        'watch_status_updated_at' => $activityAt->addMinutes(2),
                        'created_at' => $activityAt->subDays(2),
                        'updated_at' => $activityAt->addMinutes($feedback === null ? 2 : 3),
                    ];
                    $context = $contexts->get($titleId);

                    if ($context instanceof DemoTitleContext
                        && $context->firstEpisodeId !== null
                        && $context->licensedMediaId !== null) {
                        $progressRows[] = $this->progressRow(
                            $user,
                            $userIndex,
                            $context,
                            $status,
                            $activityAt,
                        );
                    }

                    $position++;
                }

                $writer->upsert(
                    (new CatalogTitleUserState)->getTable(),
                    $stateRows,
                    ['user_id', 'catalog_title_id'],
                    $this->updates($stateRows, ['user_id', 'catalog_title_id', 'created_at']),
                );
                $writer->upsert(
                    (new EpisodeViewProgress)->getTable(),
                    $progressRows,
                    ['user_id', 'episode_id'],
                    $this->updates($progressRows, ['user_id', 'episode_id', 'created_at']),
                );
                $stateCount += count($stateRows);
                $progressCount += count($progressRows);
            }

            $progress?->__invoke($this->key(), $userIndex, $options->userCount);
        }

        return new DemoStageReport($this->key(), [
            'states' => $stateCount,
            'progress' => $progressCount,
        ], microtime(true) - $startedAt);
    }

    private function watchStatus(int $position): CatalogWatchStatus
    {
        if ($position < count(CatalogWatchStatus::cases())) {
            return CatalogWatchStatus::cases()[$position];
        }

        return match (($position - count(CatalogWatchStatus::cases())) % 100) {
            0, 1, 2, 3, 4, 5, 6, 7, 8, 9 => CatalogWatchStatus::Planned,
            10, 11, 12, 13, 14, 15, 16, 17, 18, 19,
            20, 21, 22, 23, 24, 25, 26, 27, 28, 29,
            30, 31, 32, 33, 34 => CatalogWatchStatus::Watching,
            35, 36, 37, 38, 39, 40, 41, 42, 43, 44,
            45, 46, 47, 48, 49, 50, 51, 52, 53, 54,
            55, 56, 57, 58, 59, 60, 61, 62, 63, 64,
            65, 66, 67, 68, 69, 70, 71, 72, 73, 74,
            75, 76, 77, 78, 79, 80, 81, 82, 83, 84 => CatalogWatchStatus::Completed,
            default => CatalogWatchStatus::Dropped,
        };
    }

    private function feedback(int $position, int $selectedCount, int $offset): ?CatalogRecommendationFeedback
    {
        if ($selectedCount < 1) {
            return null;
        }

        $rank = ($position + $offset) % $selectedCount;
        $notInterestedCount = intdiv($selectedCount * 5, 100);
        $blacklistedCount = intdiv($selectedCount * 2, 100);

        if ($rank < $notInterestedCount) {
            return CatalogRecommendationFeedback::NotInterested;
        }

        if ($rank < $notInterestedCount + $blacklistedCount) {
            return CatalogRecommendationFeedback::Blacklisted;
        }

        return null;
    }

    /** @return array<string, mixed> */
    private function progressRow(
        User $user,
        int $userIndex,
        DemoTitleContext $context,
        CatalogWatchStatus $status,
        CarbonImmutable $activityAt,
    ): array {
        $scope = "activity:user:{$userIndex}:title:{$context->titleId}:progress";
        $percent = match ($status) {
            CatalogWatchStatus::Planned => $this->stable->integer($scope.':percent', 1, 8),
            CatalogWatchStatus::Watching => $this->stable->integer($scope.':percent', 20, 89),
            CatalogWatchStatus::Paused => $this->stable->integer($scope.':percent', 10, 80),
            CatalogWatchStatus::Completed => $this->stable->integer($scope.':percent', 95, 100),
            CatalogWatchStatus::Dropped => $this->stable->integer($scope.':percent', 5, 75),
        };
        $duration = $context->durationSeconds !== null && $context->durationSeconds > 0
            ? $context->durationSeconds
            : $this->stable->integer($scope.':duration', 1_800, 4_200);
        $position = min($duration, intdiv($duration * $percent, 100));
        $firstStartedAt = $activityAt->subDays($this->stable->integer($scope.':days', 1, 30));

        return [
            'user_id' => $user->id,
            'catalog_title_id' => $context->titleId,
            'episode_id' => $context->firstEpisodeId,
            'licensed_media_id' => $context->licensedMediaId,
            'position_seconds' => $position,
            'duration_seconds' => $duration,
            'progress_percent' => $percent,
            'first_started_at' => $firstStartedAt,
            'playback_session_id' => $this->stable->ulid($scope.':session'),
            'playback_event_sequence' => $this->stable->integer($scope.':sequence', 1, 2_000),
            'completed_at' => $status === CatalogWatchStatus::Completed ? $activityAt : null,
            'last_watched_at' => $activityAt,
            'created_at' => $firstStartedAt,
            'updated_at' => $activityAt,
        ];
    }

    private function activityAt(int $userIndex, int $position): CarbonImmutable
    {
        return CarbonImmutable::parse('2025-01-01 12:00:00')
            ->addDays($this->stable->integer("activity:user:{$userIndex}:base-day", 0, 300))
            ->addMinutes($position);
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
