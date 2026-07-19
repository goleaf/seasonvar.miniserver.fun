<?php

declare(strict_types=1);

namespace App\Livewire\Reviews;

use App\Actions\Reviews\ModerateCatalogTitleReview;
use App\Actions\Reviews\ResolveCatalogTitleReviewReport;
use App\Actions\Reviews\RestrictCatalogTitleReviewer;
use App\Actions\Reviews\RevokeCatalogTitleReviewRestriction;
use App\DTOs\Reviews\AdminReviewItemData;
use App\Enums\ReviewModerationReason;
use App\Enums\ReviewRestrictionReason;
use App\Enums\ReviewRestrictionType;
use App\Enums\ReviewStatus;
use App\Exceptions\Reviews\ReviewActionException;
use App\Livewire\Concerns\InteractsWithPaginationIslands;
use App\Models\User;
use App\Services\Reviews\CatalogTitleReviewQuery;
use App\Services\Reviews\ReviewSchema;
use App\Support\UserPlainText;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;
use Throwable;

final class ReviewModerationManager extends Component
{
    use InteractsWithPaginationIslands;
    use WithPagination;

    #[Locked]
    public ?int $highlightedReviewId = null;

    #[Url(as: 'status', except: 'attention', history: true)]
    public string $status = 'attention';

    #[Url(as: 'author', except: '', history: true)]
    public string $authorSearch = '';

    #[Url(as: 'target', except: '', history: true)]
    public string $targetSearch = '';

    #[Url(as: 'rating', except: '', history: true)]
    public string $ratingFilter = '';

    /** @var array<int, string> */
    public array $moderationStatuses = [];

    /** @var array<int, string> */
    public array $moderationReasons = [];

    /** @var array<int, string> */
    public array $moderationNotes = [];

    /** @var array<int, string> */
    public array $reportNotes = [];

    /** @var array<int, bool> */
    public array $spoilerFlags = [];

    /** @var array<int, string> */
    public array $restrictionTypes = [];

    /** @var array<int, string> */
    public array $restrictionReasons = [];

    /** @var array<int, string> */
    public array $restrictionDurations = [];

    /** @var array<int, string> */
    public array $restrictionNotes = [];

    public ?string $statusMessage = null;

    public ?string $actionError = null;

    public bool $queryFailed = false;

    protected ReviewSchema $schema;

    public function boot(ReviewSchema $schema): void
    {
        $this->schema = $schema;
    }

    public function mount(): void
    {
        Gate::authorize('manage-reviews');
        $reviewId = request()->integer('review');
        $this->highlightedReviewId = $reviewId > 0 ? $reviewId : null;
        if ($this->highlightedReviewId !== null) {
            $this->status = '';
        }
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['status', 'authorSearch', 'targetSearch', 'ratingFilter'], true)) {
            $this->sanitizeFilters();
            $this->resetPage(pageName: 'reviewPage');
            $this->highlightedReviewId = null;
        }
    }

    public function clearFilters(): void
    {
        $this->status = 'attention';
        $this->reset('authorSearch', 'targetSearch', 'ratingFilter');
        $this->highlightedReviewId = null;
        $this->resetPage(pageName: 'reviewPage');
    }

    public function moderateReview(int $reviewId, ModerateCatalogTitleReview $action): void
    {
        try {
            $action->handle(
                $this->user(),
                $reviewId,
                $this->moderationStatuses[$reviewId] ?? ReviewStatus::Published->value,
                $this->moderationReasons[$reviewId] ?? ReviewModerationReason::Approved->value,
                $this->moderationNotes[$reviewId] ?? null,
                $this->spoilerFlags[$reviewId] ?? null,
            );
            $this->statusMessage = __('reviews.messages.moderated');
            $this->actionError = null;
        } catch (Throwable $exception) {
            $this->handleFailure($exception);
        }
    }

    public function resolveReport(
        int $reportId,
        string $status,
        ResolveCatalogTitleReviewReport $action,
    ): void {
        try {
            $action->handle(
                $this->user(),
                $reportId,
                $status,
                $this->reportNotes[$reportId] ?? null,
            );
            $this->statusMessage = __('reviews.messages.moderated');
            $this->actionError = null;
        } catch (Throwable $exception) {
            $this->handleFailure($exception);
        }
    }

    public function restrictReviewer(
        int $reviewId,
        int $userId,
        RestrictCatalogTitleReviewer $action,
    ): void {
        $days = $this->restrictionDurations[$reviewId] ?? '';
        $days = ctype_digit($days) ? (int) $days : null;

        try {
            $action->handle(
                $this->user(),
                $userId,
                $this->restrictionTypes[$reviewId] ?? ReviewRestrictionType::Temporary->value,
                $this->restrictionReasons[$reviewId] ?? ReviewRestrictionReason::RepeatedViolations->value,
                $days,
                $this->restrictionNotes[$reviewId] ?? null,
            );
            $this->statusMessage = __('reviews.messages.restricted');
            $this->actionError = null;
        } catch (Throwable $exception) {
            $this->handleFailure($exception);
        }
    }

    public function revokeRestriction(
        int $restrictionId,
        RevokeCatalogTitleReviewRestriction $action,
    ): void {
        try {
            $action->handle($this->user(), $restrictionId);
            $this->statusMessage = __('reviews.messages.restriction_revoked');
            $this->actionError = null;
        } catch (Throwable $exception) {
            $this->handleFailure($exception);
        }
    }

    public function render(CatalogTitleReviewQuery $reviews): View
    {
        Gate::authorize('manage-reviews');
        $this->sanitizeFilters();
        $paginator = $this->emptyPaginator();
        $this->queryFailed = false;

        if ($this->schema->writable()) {
            try {
                $paginator = $reviews->forModeration(
                    $this->user(),
                    ReviewStatus::tryFrom($this->status),
                    $this->status === 'attention',
                    $this->highlightedReviewId,
                    trim($this->authorSearch),
                    trim($this->targetSearch),
                    $this->ratingFilter !== '' ? (int) $this->ratingFilter : null,
                );
                $this->prepareForms($paginator);
            } catch (Throwable $exception) {
                report($exception);
                $this->queryFailed = true;
            }
        }

        return view('livewire.reviews.review-moderation-manager', [
            'reviews' => $paginator,
            'communityAvailable' => $this->schema->writable(),
            'statusOptions' => [
                ['value' => 'attention', 'label' => __('reviews.moderation.attention')],
                ...$this->enumOptions(ReviewStatus::cases()),
            ],
            'reviewStatusOptions' => $this->enumOptions(ReviewStatus::cases()),
            'moderationReasonOptions' => $this->enumOptions(ReviewModerationReason::cases()),
            'restrictionTypeOptions' => $this->enumOptions(ReviewRestrictionType::cases()),
            'restrictionReasonOptions' => $this->enumOptions(ReviewRestrictionReason::cases()),
            'ratingOptions' => range(
                max(1, (int) config('catalog.user_rating.minimum', 1)),
                max(1, (int) config('catalog.user_rating.maximum', 10)),
            ),
        ])->extends('layouts.app', [
            'title' => __('reviews.moderation.title'),
            'seo' => [
                'title' => __('reviews.moderation.title'),
                'description' => __('reviews.moderation.description'),
                'robots' => 'noindex, nofollow',
                'canonical' => route('admin.reviews'),
            ],
        ])->section('content');
    }

    private function sanitizeFilters(): void
    {
        if ($this->status !== ''
            && $this->status !== 'attention'
            && ReviewStatus::tryFrom($this->status) === null) {
            $this->status = 'attention';
        }

        $minimum = max(1, (int) config('catalog.user_rating.minimum', 1));
        $maximum = max($minimum, (int) config('catalog.user_rating.maximum', 10));

        if ($this->ratingFilter !== ''
            && (! ctype_digit($this->ratingFilter)
                || (int) $this->ratingFilter < $minimum
                || (int) $this->ratingFilter > $maximum)) {
            $this->ratingFilter = '';
        }

        $this->authorSearch = mb_substr(
            str_replace(['%', '_', '\\'], '', UserPlainText::name($this->authorSearch)),
            0,
            120,
        );
        $this->targetSearch = mb_substr(UserPlainText::name($this->targetSearch), 0, 160);
    }

    /** @param LengthAwarePaginator<int, AdminReviewItemData> $reviews */
    private function prepareForms(LengthAwarePaginator $reviews): void
    {
        $items = collect($reviews->items());
        $reviewIds = $items
            ->map(fn (AdminReviewItemData $item): int => $item->review->id)
            ->all();
        $visibleKeys = array_fill_keys($reviewIds, true);
        $reports = $items
            ->flatMap(fn (AdminReviewItemData $item): array => $item->reports);
        $visibleReportKeys = array_fill_keys(
            $reports->pluck('id')->map(fn (mixed $id): int => (int) $id)->all(),
            true,
        );

        foreach ([
            'moderationStatuses',
            'moderationReasons',
            'moderationNotes',
            'spoilerFlags',
            'restrictionTypes',
            'restrictionReasons',
            'restrictionDurations',
            'restrictionNotes',
        ] as $property) {
            $this->{$property} = array_intersect_key($this->{$property}, $visibleKeys);
        }
        $this->reportNotes = array_intersect_key($this->reportNotes, $visibleReportKeys);

        foreach ($reviews as $item) {
            $id = $item->review->id;
            $this->moderationStatuses[$id] ??= $item->review->status;
            $this->moderationReasons[$id] ??= $item->moderationReason
                ?? ReviewModerationReason::Approved->value;
            $this->moderationNotes[$id] ??= $item->moderatorNote ?? '';
            $this->spoilerFlags[$id] ??= $item->review->isSpoiler;
            $this->restrictionTypes[$id] ??= ReviewRestrictionType::Temporary->value;
            $this->restrictionReasons[$id] ??= ReviewRestrictionReason::RepeatedViolations->value;
            $this->restrictionDurations[$id] ??= '7';
            $this->restrictionNotes[$id] ??= '';

            foreach ($item->reports as $report) {
                $this->reportNotes[$report['id']] ??= $report['private_note'] ?? '';
            }
        }
    }

    /**
     * @param  list<ReviewStatus|ReviewModerationReason|ReviewRestrictionType|ReviewRestrictionReason>  $cases
     * @return list<array{value: string, label: string}>
     */
    private function enumOptions(array $cases): array
    {
        return array_map(
            static fn (ReviewStatus|ReviewModerationReason|ReviewRestrictionType|ReviewRestrictionReason $case): array => [
                'value' => $case->value,
                'label' => $case->label(),
            ],
            $cases,
        );
    }

    /** @return LengthAwarePaginator<int, AdminReviewItemData> */
    private function emptyPaginator(): LengthAwarePaginator
    {
        return new LengthAwarePaginator(
            [],
            0,
            max(1, (int) config('reviews.admin_per_page', 20)),
        );
    }

    private function handleFailure(Throwable $exception): void
    {
        $this->statusMessage = null;

        if ($exception instanceof ReviewActionException) {
            $this->actionError = __($exception->translationKey, $exception->replace);

            return;
        }

        if ($exception instanceof AuthorizationException) {
            $this->actionError = __('reviews.errors.forbidden');

            return;
        }

        if ($exception instanceof ModelNotFoundException) {
            $this->actionError = __('reviews.errors.not_found');

            return;
        }

        report($exception);
        $this->actionError = __('reviews.errors.action_failed');
    }

    private function user(): User
    {
        $user = auth()->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
