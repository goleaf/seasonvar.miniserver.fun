<?php

declare(strict_types=1);

namespace App\Livewire\Reviews;

use App\Actions\Reviews\CreateCatalogTitleReview;
use App\Actions\Reviews\DeleteCatalogTitleReview;
use App\Actions\Reviews\ReportCatalogTitleReview;
use App\Actions\Reviews\RestoreCatalogTitleReview;
use App\Actions\Reviews\SetCatalogTitleReviewVote;
use App\Actions\Reviews\UpdateCatalogTitleReview;
use App\DTOs\Reviews\ReviewAggregateData;
use App\DTOs\Reviews\ReviewCriteria;
use App\Enums\ReviewDeletionReason;
use App\Enums\ReviewReportCategory;
use App\Enums\ReviewSort;
use App\Enums\ReviewStatus;
use App\Exceptions\Reviews\ReviewActionException;
use App\Models\CatalogTitle;
use App\Models\CatalogTitleReview;
use App\Models\CatalogTitleUserState;
use App\Models\User;
use App\Services\Catalog\CatalogTitleQuery;
use App\Services\Catalog\CatalogUserStateService;
use App\Services\Reviews\CatalogTitleReviewQuery;
use App\Services\Reviews\ReviewAggregateService;
use App\Services\Reviews\ReviewRateLimiter;
use App\Services\Reviews\ReviewRelationshipService;
use App\Services\Reviews\ReviewRestrictionService;
use App\Services\Reviews\ReviewSchema;
use App\Services\Reviews\ReviewTargetResolver;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Pagination\LengthAwarePaginator as Paginator;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;
use Throwable;

final class CatalogTitleReviews extends Component
{
    use WithPagination;

    #[Locked]
    public int $catalogTitleId;

    #[Locked]
    public string $locale = 'ru';

    #[Locked]
    public ?int $highlightedReviewId = null;

    /** @var list<int> */
    #[Locked]
    public array $revealedReviewIds = [];

    #[Url(as: 'review_sort', except: 'newest', history: true)]
    public string $sort = 'newest';

    #[Url(as: 'review_rating', except: '', history: true)]
    public string $ratingFilter = '';

    #[Url(as: 'review_spoiler', except: '', history: true)]
    public string $spoilerFilter = '';

    #[Url(as: 'review_verified', except: '', history: true)]
    public string $verifiedFilter = '';

    public string $reviewTitle = '';

    public string $reviewBody = '';

    public string $reviewRating = '';

    public bool $reviewSpoiler = false;

    public string $submissionToken = '';

    public ?int $editingReviewId = null;

    public int $editingVersion = 0;

    public ?int $reportingReviewId = null;

    public string $reportCategory = '';

    public string $reportDetails = '';

    public ?string $statusMessage = null;

    public ?string $actionError = null;

    protected CatalogTitleReviewQuery $reviews;

    protected ReviewAggregateService $aggregates;

    protected ReviewSchema $schema;

    protected CatalogTitleQuery $titles;

    protected ReviewRestrictionService $restrictions;

    protected ReviewRelationshipService $relationships;

    public function boot(
        CatalogTitleReviewQuery $reviews,
        ReviewAggregateService $aggregates,
        ReviewSchema $schema,
        CatalogTitleQuery $titles,
        ReviewRestrictionService $restrictions,
        ReviewRelationshipService $relationships,
    ): void {
        $this->reviews = $reviews;
        $this->aggregates = $aggregates;
        $this->schema = $schema;
        $this->titles = $titles;
        $this->restrictions = $restrictions;
        $this->relationships = $relationships;
        $supported = config('reviews.supported_locales', ['ru']);
        $currentLocale = App::getLocale();

        if ($this->locale === (string) config('reviews.default_locale', 'ru')
            && is_array($supported)
            && in_array($currentLocale, $supported, true)) {
            $this->locale = $currentLocale;
        }

        if (! is_array($supported) || ! in_array($this->locale, $supported, true)) {
            $this->locale = (string) config('reviews.default_locale', 'ru');
        }

        App::setLocale($this->locale);
    }

    public function mount(int $catalogTitleId, ?string $locale = null): void
    {
        $this->catalogTitleId = $catalogTitleId;
        $this->locale = is_string($locale) && $locale !== '' ? $locale : App::getLocale();
        App::setLocale($this->locale);
        $highlighted = request()->integer('review');
        $this->highlightedReviewId = $highlighted > 0 ? $highlighted : null;
        $this->submissionToken = (string) Str::uuid();
    }

    public function updatedSort(): void
    {
        if (ReviewSort::tryFrom($this->sort) === null) {
            $this->sort = ReviewSort::Newest->value;
        }

        $this->resetReviewPage();
    }

    public function updatedRatingFilter(): void
    {
        $range = range(
            max(1, (int) config('catalog.user_rating.minimum', 1)),
            max(1, (int) config('catalog.user_rating.maximum', 10)),
        );

        if ($this->ratingFilter !== ''
            && (! ctype_digit($this->ratingFilter) || ! in_array((int) $this->ratingFilter, $range, true))) {
            $this->ratingFilter = '';
        }

        $this->resetReviewPage();
    }

    public function updatedSpoilerFilter(): void
    {
        if (! in_array($this->spoilerFilter, ['', 'contains', 'spoiler_free'], true)) {
            $this->spoilerFilter = '';
        }

        $this->resetReviewPage();
    }

    public function updatedVerifiedFilter(): void
    {
        if (! in_array($this->verifiedFilter, ['', 'verified', 'unverified'], true)) {
            $this->verifiedFilter = '';
        }

        $this->resetReviewPage();
    }

    public function clearFilters(): void
    {
        $this->reset('ratingFilter', 'spoilerFilter', 'verifiedFilter');
        $this->sort = ReviewSort::Newest->value;
        $this->resetReviewPage();
    }

    public function createReview(CreateCatalogTitleReview $action): void
    {
        $user = $this->user();

        if ($user === null) {
            $this->actionError = __('reviews.errors.authentication_required');

            return;
        }

        try {
            $review = $action->handle(
                $user,
                $this->catalogTitleId,
                $this->reviewTitle,
                $this->reviewBody,
                $this->reviewRating,
                $this->reviewSpoiler,
                $this->submissionToken,
            );
        } catch (Throwable $exception) {
            $this->handleFailure($exception);

            return;
        }

        $this->resetComposer();
        $this->statusMessage = $review->status === ReviewStatus::Pending
            ? __('reviews.messages.created_pending')
            : __('reviews.messages.created');
        $this->resetReviewPage();
        $this->highlightedReviewId = (int) $review->id;
        $this->dispatch('review-draft-clear', key: $this->draftKey());
        $this->dispatch('review-focus', target: 'item', reviewId: (int) $review->id);
    }

    public function startEdit(int $reviewId): void
    {
        try {
            $review = CatalogTitleReview::query()->findOrFail($reviewId);
            Gate::forUser($this->user())->authorize('update', $review);
            abort_unless((int) $review->catalog_title_id === $this->catalogTitleId, 404);
            $rating = CatalogTitleUserState::query()
                ->where('user_id', $review->user_id)
                ->where('catalog_title_id', $review->catalog_title_id)
                ->value('rating');
            $this->editingReviewId = (int) $review->id;
            $this->editingVersion = (int) $review->version;
            $this->reviewTitle = (string) $review->review_title;
            $this->reviewBody = (string) $review->body;
            $this->reviewRating = $rating !== null ? (string) $rating : '';
            $this->reviewSpoiler = (bool) $review->is_spoiler;
            $this->statusMessage = null;
            $this->actionError = null;
            $this->dispatch('review-focus', target: 'editor', reviewId: (int) $review->id);
        } catch (Throwable $exception) {
            $this->handleFailure($exception);
        }
    }

    public function cancelEdit(): void
    {
        $reviewId = $this->editingReviewId;
        $this->dispatch('review-draft-clear', key: $this->draftKey());
        $this->resetComposer();

        if ($reviewId !== null) {
            $this->dispatch('review-focus', target: 'item', reviewId: $reviewId);
        }
    }

    public function saveEdit(UpdateCatalogTitleReview $action): void
    {
        $user = $this->user();

        if ($user === null || $this->editingReviewId === null) {
            $this->actionError = __('reviews.errors.authentication_required');

            return;
        }

        try {
            $review = $action->handle(
                $user,
                $this->editingReviewId,
                $this->editingVersion,
                $this->reviewTitle,
                $this->reviewBody,
                $this->reviewRating,
                $this->reviewSpoiler,
            );
        } catch (Throwable $exception) {
            $this->handleFailure($exception);

            return;
        }

        $draftKey = $this->draftKey();
        $this->resetComposer();
        $this->highlightedReviewId = (int) $review->id;
        $this->statusMessage = $review->status === ReviewStatus::Pending
            ? __('reviews.messages.updated_pending')
            : __('reviews.messages.updated');
        $this->dispatch('review-draft-clear', key: $draftKey);
        $this->dispatch('review-focus', target: 'item', reviewId: (int) $review->id);
    }

    public function deleteReview(int $reviewId, DeleteCatalogTitleReview $action): void
    {
        if ($this->runMutation(
            fn (User $user) => $action->handle($user, $reviewId),
            'reviews.messages.deleted',
        )) {
            $this->dispatch('review-focus', target: 'item', reviewId: $reviewId);
        }
    }

    public function restoreReview(int $reviewId, RestoreCatalogTitleReview $action): void
    {
        if ($this->runMutation(
            fn (User $user) => $action->handle($user, $reviewId),
            'reviews.messages.restored',
        )) {
            $this->dispatch('review-focus', target: 'item', reviewId: $reviewId);
        }
    }

    public function vote(int $reviewId, string $type, SetCatalogTitleReviewVote $action): void
    {
        $this->runMutation(
            fn (User $user) => $action->handle($user, $reviewId, $type),
            'reviews.messages.vote_saved',
        );
    }

    public function removeVote(int $reviewId, SetCatalogTitleReviewVote $action): void
    {
        $this->runMutation(
            fn (User $user) => $action->handle($user, $reviewId, null),
            'reviews.messages.vote_removed',
        );
    }

    public function revealReview(
        int $reviewId,
        ReviewTargetResolver $targets,
        ReviewRateLimiter $rateLimiter,
    ): void {
        try {
            $review = CatalogTitleReview::query()->findOrFail($reviewId);
            abort_unless((int) $review->catalog_title_id === $this->catalogTitleId, 404);
            Gate::forUser($this->user())->authorize('view', $review);
            $targets->fromReview($review, $this->user());
            $context = $this->relationships->context($this->user());
            abort_if($context->hides($review->user_id), 404);

            if (($user = $this->user()) instanceof User) {
                $rateLimiter->hit('reveal', $user, 'review:'.$review->id);
            } else {
                $rateLimiter->hitGuest('reveal', (string) request()->ip(), 'review:'.$review->id);
            }

            $this->revealedReviewIds = collect([...$this->revealedReviewIds, $reviewId])
                ->unique()
                ->take(50)
                ->values()
                ->all();
            $this->dispatch('review-focus', target: 'spoiler', reviewId: $reviewId);
        } catch (Throwable $exception) {
            $this->handleFailure($exception);
        }
    }

    public function hideReview(int $reviewId): void
    {
        $this->revealedReviewIds = collect($this->revealedReviewIds)
            ->reject(fn (int $id): bool => $id === $reviewId)
            ->values()
            ->all();
        $this->dispatch('review-focus', target: 'spoiler', reviewId: $reviewId);
    }

    public function startReport(int $reviewId): void
    {
        if ($this->user() === null) {
            $this->actionError = __('reviews.errors.authentication_required');

            return;
        }

        $this->reportingReviewId = $reviewId;
        $this->reportCategory = ReviewReportCategory::Spam->value;
        $this->reportDetails = '';
        $this->actionError = null;
        $this->dispatch('review-focus', target: 'report', reviewId: $reviewId);
    }

    public function cancelReport(): void
    {
        $reviewId = $this->reportingReviewId;
        $this->reset('reportingReviewId', 'reportCategory', 'reportDetails');

        if ($reviewId !== null) {
            $this->dispatch('review-focus', target: 'item', reviewId: $reviewId);
        }
    }

    public function submitReport(ReportCatalogTitleReview $action): void
    {
        $user = $this->user();

        if ($user === null || $this->reportingReviewId === null) {
            $this->actionError = __('reviews.errors.authentication_required');

            return;
        }

        try {
            $action->handle(
                $user,
                $this->reportingReviewId,
                $this->reportCategory,
                $this->reportDetails,
            );
        } catch (Throwable $exception) {
            $this->handleFailure($exception);

            return;
        }

        $this->cancelReport();
        $this->statusMessage = __('reviews.messages.reported');
    }

    public function render(CatalogUserStateService $userStates): View
    {
        $this->normalizeFilterState();
        $title = $this->title();
        $user = $this->user();
        $restriction = null;
        $existingReview = null;
        $queryFailed = false;

        try {
            $restriction = $user !== null ? $this->restrictions->activeFor($user) : null;
            $reviews = $this->reviews->forTitle(
                $title,
                $user,
                $this->criteria(),
                $this->revealedReviewIds,
                $this->highlightedReviewId,
                $restriction !== null,
            );
            $aggregate = $this->aggregates->forTitle($title);
            $existingReview = $this->existingReview($user);
        } catch (Throwable $exception) {
            report($exception);
            $queryFailed = true;
            $reviews = new Paginator([], 0, max(1, (int) config('reviews.per_page', 10)));
            $aggregate = new ReviewAggregateData(0, 0, null);
        }

        $canCreate = ! $queryFailed
            && $this->schema->writable()
            && $user !== null
            && $existingReview === null
            && $restriction === null
            && Gate::forUser($user)->allows('create', CatalogTitleReview::class);
        $state = $user !== null ? $userStates->state($user, $title) : null;
        $restrictionMessage = $restriction === null
            ? null
            : ($restriction->expires_at !== null
                ? __('reviews.errors.temporarily_restricted', [
                    'reason' => $restriction->reason_code->label(),
                    'expires' => $restriction->expires_at->translatedFormat('d.m.Y H:i'),
                ])
                : __('reviews.errors.permanently_restricted', [
                    'reason' => $restriction->reason_code->label(),
                ]));

        return view('livewire.reviews.catalog-title-reviews', [
            'title' => $title,
            'reviews' => $reviews,
            'aggregate' => $aggregate,
            'queryFailed' => $queryFailed,
            'communityAvailable' => $this->schema->writable(),
            'reviewsEnabled' => (bool) config('reviews.enabled', true),
            'canCreate' => $canCreate,
            'existingReview' => $existingReview,
            'restrictionMessage' => $restrictionMessage,
            'requiresEmailVerification' => $user !== null && ! $user->hasVerifiedEmail(),
            'ratingOptions' => $userStates->ratingOptions(),
            'currentPortalRating' => $state?->rating,
            'emptyRatingLabel' => $this->editingReviewId !== null || $state?->rating === null
                ? __('reviews.composer.rating_empty')
                : __('reviews.composer.rating_keep'),
            'isAuthenticated' => $user !== null,
            'ratingMaximum' => max(1, (int) config('catalog.user_rating.maximum', 10)),
            'reviewTitleMinimum' => max(1, (int) config('reviews.title.minimum_length', 5)),
            'reviewTitleMaximum' => max(1, (int) config('reviews.title.maximum_length', 120)),
            'reviewBodyMinimum' => max(1, (int) config('reviews.body.minimum_length', 100)),
            'reviewBodyMaximum' => max(1, (int) config('reviews.body.maximum_length', 12_000)),
            'aggregateRatingDisplay' => $aggregate->ratingAverage !== null
                ? number_format($aggregate->ratingAverage, 1, ',', ' ')
                : null,
            'sortOptions' => collect(ReviewSort::cases())->map(fn (ReviewSort $sort): array => [
                'value' => $sort->value,
                'label' => $sort->label(),
            ])->all(),
            'reportCategories' => collect(ReviewReportCategory::cases())->map(fn (ReviewReportCategory $category): array => [
                'value' => $category->value,
                'label' => $category->label(),
            ])->all(),
            'loginUrl' => route('login'),
            'draftKey' => $this->draftKey(),
        ]);
    }

    private function title(): CatalogTitle
    {
        return $this->titles->visibleTo($this->user())->findOrFail($this->catalogTitleId);
    }

    private function normalizeFilterState(): void
    {
        if (ReviewSort::tryFrom($this->sort) === null) {
            $this->sort = ReviewSort::Newest->value;
        }

        $minimum = max(1, (int) config('catalog.user_rating.minimum', 1));
        $maximum = max($minimum, (int) config('catalog.user_rating.maximum', 10));

        if ($this->ratingFilter !== ''
            && (! ctype_digit($this->ratingFilter)
                || (int) $this->ratingFilter < $minimum
                || (int) $this->ratingFilter > $maximum)) {
            $this->ratingFilter = '';
        }

        if (! in_array($this->spoilerFilter, ['', 'contains', 'spoiler_free'], true)) {
            $this->spoilerFilter = '';
        }

        if (! in_array($this->verifiedFilter, ['', 'verified', 'unverified'], true)) {
            $this->verifiedFilter = '';
        }
    }

    private function criteria(): ReviewCriteria
    {
        try {
            return ReviewCriteria::from(
                $this->sort,
                $this->ratingFilter,
                $this->spoilerFilter,
                $this->verifiedFilter,
            );
        } catch (ReviewActionException) {
            return ReviewCriteria::from(ReviewSort::Newest->value, null, null, null);
        }
    }

    private function existingReview(?User $user): ?CatalogTitleReview
    {
        if ($user === null || ! $this->schema->communityAvailable()) {
            return null;
        }

        $review = CatalogTitleReview::query()
            ->where('user_id', $user->id)
            ->where('catalog_title_id', $this->catalogTitleId)
            ->whereNull('merged_into_id')
            ->whereNotNull('ownership_key')
            ->first();

        if (! $review instanceof CatalogTitleReview) {
            return null;
        }

        $days = max(1, (int) config('reviews.editing.restoration_days', 30));

        return $review->isDeleted()
            && $review->deletion_reason === ReviewDeletionReason::Author
            && $review->deleted_at?->lessThanOrEqualTo(now()->subDays($days)) === true
                ? null
                : $review;
    }

    private function resetComposer(): void
    {
        $this->reset(
            'reviewTitle',
            'reviewBody',
            'reviewRating',
            'reviewSpoiler',
            'editingReviewId',
            'editingVersion',
            'actionError',
        );
        $this->submissionToken = (string) Str::uuid();
    }

    private function resetReviewPage(): void
    {
        $this->resetPage(pageName: 'reviewPage');
        $this->highlightedReviewId = null;
    }

    private function runMutation(callable $callback, string $successKey): bool
    {
        $user = $this->user();

        if ($user === null) {
            $this->actionError = __('reviews.errors.authentication_required');

            return false;
        }

        try {
            $callback($user);
        } catch (Throwable $exception) {
            $this->handleFailure($exception);

            return false;
        }

        $this->actionError = null;
        $this->statusMessage = __($successKey);

        return true;
    }

    private function handleFailure(Throwable $exception): void
    {
        $this->statusMessage = null;

        if ($exception instanceof ReviewActionException) {
            $this->actionError = __($exception->translationKey, $exception->replace);

            return;
        }

        if ($exception instanceof ValidationException) {
            $this->actionError = collect($exception->errors())->flatten()->first()
                ?? __('reviews.errors.action_failed');

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

    private function user(): ?User
    {
        $user = auth()->user();

        return $user instanceof User ? $user : null;
    }

    private function draftKey(): string
    {
        $user = $this->user();
        $owner = $user instanceof User
            ? substr(hash_hmac(
                'sha256',
                (string) $user->getAuthIdentifier(),
                (string) config('app.key', 'seasonvar-review-draft'),
            ), 0, 24)
            : 'guest';

        return 'review:'.$owner.':title:'.$this->catalogTitleId.':'.(
            $this->editingReviewId !== null ? 'edit:'.$this->editingReviewId : 'new'
        );
    }
}
