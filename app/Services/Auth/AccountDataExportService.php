<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Models\CatalogTitle;
use App\Models\CatalogTitleUserState;
use App\Models\EpisodeViewProgress;
use App\Models\User;
use App\Models\UserTag;
use App\Services\Collections\CatalogCollectionAccountService;
use App\Services\Comments\CommentAccountService;
use App\Services\ContentRequests\ContentRequestAccountService;
use App\Services\ReleaseCalendar\ReleaseCalendarAccountService;
use App\Services\Reviews\ReviewAccountService;
use App\Services\TechnicalIssues\TechnicalIssueAccountService;
use Illuminate\Support\Facades\DB;

final class AccountDataExportService
{
    public function __construct(
        private readonly CatalogCollectionAccountService $collections,
        private readonly CommentAccountService $comments,
        private readonly ReviewAccountService $reviews,
        private readonly ContentRequestAccountService $contentRequests,
        private readonly TechnicalIssueAccountService $technicalIssues,
        private readonly ReleaseCalendarAccountService $releaseCalendar,
        private readonly AccountSettingsService $settings,
    ) {}

    /** @return array<string, mixed> */
    public function export(User $user): array
    {
        $personalTags = UserTag::query()
            ->withTrashed()
            ->ownedBy($user)
            ->orderBy('created_at')
            ->get();
        $assignmentRows = DB::table('catalog_title_user_tag')
            ->whereIn('user_tag_id', $personalTags->modelKeys())
            ->orderBy('user_tag_id')
            ->orderBy('position')
            ->orderBy('catalog_title_id')
            ->get(['user_tag_id', 'catalog_title_id', 'position']);
        $assignedTitles = CatalogTitle::query()
            ->withTrashed()
            ->whereKey($assignmentRows->pluck('catalog_title_id')->unique())
            ->get(['id', 'slug', 'title', 'deleted_at'])
            ->keyBy('id');
        $assignmentsByTag = $assignmentRows->groupBy('user_tag_id');

        return [
            'exported_at' => now()->toAtomString(),
            'account' => [
                'public_id' => $user->public_id,
                'name' => $user->name,
                'email' => $user->email,
                'email_verified_at' => $user->email_verified_at?->toAtomString(),
                'created_at' => $user->created_at?->toAtomString(),
            ],
            'settings' => $this->settings->resolve($user)->toExportArray(),
            'collections' => $this->collections->export($user),
            'personal_tags' => $personalTags
                ->map(fn (UserTag $tag): array => [
                    'public_id' => $tag->public_id,
                    'name' => $tag->name,
                    'description' => $tag->description,
                    'content_locale' => $tag->content_locale,
                    'visibility' => 'private',
                    'created_at' => $tag->created_at?->toAtomString(),
                    'updated_at' => $tag->updated_at?->toAtomString(),
                    'deleted_at' => $tag->deleted_at?->toAtomString(),
                    'assignments' => $assignmentsByTag->get($tag->id, collect())
                        ->map(function (object $assignment) use ($assignedTitles): ?array {
                            $title = $assignedTitles->get((int) $assignment->catalog_title_id);

                            if (! $title instanceof CatalogTitle) {
                                return null;
                            }

                            return [
                                'title_slug' => $title->slug,
                                'title' => $title->title,
                                'position' => (int) $assignment->position,
                                'deleted_at' => $title->deleted_at?->toAtomString(),
                            ];
                        })->filter()->values()->all(),
                ])->all(),
            'discussions' => $this->comments->export($user),
            'reviews' => $this->reviews->export($user),
            'content_requests' => $this->contentRequests->export($user),
            'technical_issues' => $this->technicalIssues->export($user),
            'release_calendar' => $this->releaseCalendar->export($user),
            'library' => CatalogTitleUserState::query()
                ->whereBelongsTo($user)
                ->with('catalogTitle:id,slug,title')
                ->orderBy('created_at')
                ->get()
                ->map(fn (CatalogTitleUserState $state): array => [
                    'title_slug' => $state->catalogTitle?->slug,
                    'title' => $state->catalogTitle?->title,
                    'in_watchlist' => $state->in_watchlist,
                    'rating' => $state->rating,
                    'watch_status' => $state->watch_status?->value,
                    'recommendation_feedback' => $state->recommendation_feedback?->value,
                    'recommendation_feedback_updated_at' => $state->recommendation_feedback_updated_at?->toAtomString(),
                    'updated_at' => $state->updated_at?->toAtomString(),
                ])->all(),
            'view_progress' => EpisodeViewProgress::query()
                ->whereBelongsTo($user)
                ->with(['catalogTitle:id,slug,title', 'episode:id,season_id,number,title'])
                ->orderBy('last_watched_at')
                ->get()
                ->map(fn (EpisodeViewProgress $progress): array => [
                    'title_slug' => $progress->catalogTitle?->slug,
                    'title' => $progress->catalogTitle?->title,
                    'episode_number' => $progress->episode?->number,
                    'episode_title' => $progress->episode?->title,
                    'position_seconds' => $progress->position_seconds,
                    'duration_seconds' => $progress->duration_seconds,
                    'progress_percent' => $progress->progress_percent,
                    'completed_at' => $progress->completed_at?->toAtomString(),
                    'last_watched_at' => $progress->last_watched_at->toAtomString(),
                ])->all(),
        ];
    }
}
