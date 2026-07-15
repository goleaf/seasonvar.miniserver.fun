<?php

declare(strict_types=1);

namespace App\Livewire\Profile;

use App\Enums\ReviewSort;
use App\Enums\ReviewStatus;
use App\Exceptions\Reviews\ReviewActionException;
use App\Models\CatalogTitleReview;
use App\Models\User;
use App\Services\Reviews\CatalogTitleReviewQuery;
use App\Services\Reviews\ReviewRateLimiter;
use App\Services\Reviews\ReviewSchema;
use App\Services\Reviews\ReviewTargetResolver;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;
use Throwable;

final class ReviewHistoryPage extends Component
{
    use WithPagination;

    #[Locked]
    public string $locale = 'ru';

    /** @var list<int> */
    #[Locked]
    public array $revealedReviewIds = [];

    #[Url(as: 'sort', except: 'newest', history: true)]
    public string $sort = 'newest';

    #[Url(as: 'status', except: '', history: true)]
    public string $status = '';

    public ?string $statusMessage = null;

    public ?string $actionError = null;

    public bool $queryFailed = false;

    protected ReviewSchema $schema;

    public function boot(ReviewSchema $schema): void
    {
        $this->schema = $schema;
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

    public function mount(): void
    {
        $this->locale = App::getLocale();
        App::setLocale($this->locale);

    }

    public function updatedSort(): void
    {
        if (ReviewSort::tryFrom($this->sort) === null) {
            $this->sort = ReviewSort::Newest->value;
        }

        $this->resetPage(pageName: 'reviewPage');
    }

    public function updatedStatus(): void
    {
        if ($this->status !== '' && ReviewStatus::tryFrom($this->status) === null) {
            $this->status = '';
        }

        $this->resetPage(pageName: 'reviewPage');
    }

    public function revealReview(
        int $reviewId,
        ReviewTargetResolver $targets,
        ReviewRateLimiter $rateLimiter,
    ): void {
        try {
            $review = CatalogTitleReview::query()->findOrFail($reviewId);
            Gate::forUser($this->user())->authorize('view', $review);
            abort_unless((int) $review->user_id === (int) $this->user()->id, 404);
            $targets->fromReview($review, $this->user());
            $rateLimiter->hit('reveal_global', $this->user(), 'global');
            $rateLimiter->hit('reveal', $this->user(), 'review:'.$review->id);
            $this->revealedReviewIds = collect([...$this->revealedReviewIds, $reviewId])
                ->unique()
                ->take(50)
                ->values()
                ->all();
            $this->actionError = null;
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

    public function render(CatalogTitleReviewQuery $reviews): View
    {
        $this->normalizeFilterState();
        $paginator = new LengthAwarePaginator([], 0, max(1, (int) config('reviews.profile_per_page', 12)));
        $this->queryFailed = false;

        if ($this->schema->communityAvailable()) {
            try {
                $paginator = $reviews->forAuthor(
                    $this->user(),
                    ReviewSort::tryFrom($this->sort) ?? ReviewSort::Newest,
                    $this->status !== '' ? ReviewStatus::tryFrom($this->status) : null,
                    $this->revealedReviewIds,
                );
            } catch (Throwable $exception) {
                report($exception);
                $this->queryFailed = true;
            }
        }

        return view('livewire.profile.review-history-page', [
            'reviews' => $paginator,
            'communityAvailable' => $this->schema->communityAvailable(),
            'notificationsAvailable' => $this->schema->notificationsAvailable(),
            'sortOptions' => collect(ReviewSort::cases())->map(fn (ReviewSort $sort): array => [
                'value' => $sort->value,
                'label' => $sort->label(),
            ])->all(),
            'statusOptions' => collect(ReviewStatus::cases())->map(fn (ReviewStatus $status): array => [
                'value' => $status->value,
                'label' => $status->label(),
            ])->all(),
        ])->extends('layouts.app', [
            'title' => __('reviews.profile.title'),
            'seo' => [
                'title' => __('reviews.profile.title'),
                'description' => __('reviews.profile.description'),
                'robots' => 'noindex, nofollow',
                'canonical' => route('profile.reviews'),
            ],
        ])->section('content');
    }

    private function user(): User
    {
        $user = auth()->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }

    private function normalizeFilterState(): void
    {
        if (ReviewSort::tryFrom($this->sort) === null) {
            $this->sort = ReviewSort::Newest->value;
        }

        if ($this->status !== '' && ReviewStatus::tryFrom($this->status) === null) {
            $this->status = '';
        }
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
}
