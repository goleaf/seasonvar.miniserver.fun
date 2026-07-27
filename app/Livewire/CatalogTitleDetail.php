<?php

declare(strict_types=1);

namespace App\Livewire;

use App\DTOs\CatalogTitleRefreshState;
use App\Enums\CatalogRecommendationFeedback;
use App\Enums\CatalogRecommendationFeedbackReason;
use App\Enums\SeasonvarImportStatus;
use App\Http\Requests\CatalogShowRequest;
use App\Models\CatalogTitle;
use App\Models\Genre;
use App\Models\User;
use App\Services\Catalog\CatalogRecommendationFeedbackService;
use App\Services\Catalog\CatalogRecommendationPreferenceService;
use App\Services\Catalog\CatalogTitlePageBuilder;
use App\Services\Catalog\CatalogTitleQuery;
use App\Services\Collections\CatalogCollectionQuery;
use App\Services\Seasonvar\CatalogTitleRefreshCoordinator;
use App\Services\Seasonvar\CatalogTitleRefreshStateStore;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Throwable;

class CatalogTitleDetail extends Component
{
    #[Locked]
    public int $catalogTitleId;

    #[Locked]
    public ?int $highlightedReviewId = null;

    #[Locked]
    public ?int $highlightedCommentId = null;

    /** @var array{season: int|string, episode: int|string, media: int|string, variant: string, quality: string, format: string} */
    #[Locked]
    public array $initialPlayerSelection = [
        'season' => '',
        'episode' => '',
        'media' => '',
        'variant' => '',
        'quality' => '',
        'format' => '',
    ];

    protected CatalogTitlePageBuilder $pages;

    protected CatalogTitleRefreshCoordinator $refreshes;

    protected CatalogTitleRefreshStateStore $states;

    protected CatalogCollectionQuery $collections;

    protected CatalogTitleQuery $titles;

    protected CatalogRecommendationFeedbackService $feedback;

    protected CatalogRecommendationPreferenceService $preferences;

    #[Locked]
    public ?int $lastRecommendationFeedbackTitleId = null;

    public ?string $recommendationNotice = null;

    public function boot(
        CatalogTitlePageBuilder $pages,
        CatalogTitleRefreshCoordinator $refreshes,
        CatalogTitleRefreshStateStore $states,
        CatalogCollectionQuery $collections,
        CatalogTitleQuery $titles,
        CatalogRecommendationFeedbackService $feedback,
        CatalogRecommendationPreferenceService $preferences,
    ): void {
        $this->pages = $pages;
        $this->refreshes = $refreshes;
        $this->states = $states;
        $this->collections = $collections;
        $this->titles = $titles;
        $this->feedback = $feedback;
        $this->preferences = $preferences;
    }

    public function mount(CatalogShowRequest $request, CatalogTitle $catalogTitle): void
    {
        $requestedSlug = $request->route()?->originalParameter('catalogTitle');

        if (is_string($requestedSlug) && $requestedSlug !== $catalogTitle->slug) {
            throw new HttpResponseException(new RedirectResponse(route('titles.show', $catalogTitle), 301));
        }

        $this->catalogTitleId = $catalogTitle->id;
        $highlightedReviewId = $request->integer('review');
        $this->highlightedReviewId = $highlightedReviewId > 0 ? $highlightedReviewId : null;
        $highlightedCommentId = $request->integer('comment');
        $this->highlightedCommentId = $highlightedCommentId > 0 ? $highlightedCommentId : null;
        $seasonId = $request->integer('season');
        $episodeId = $request->episodeId();
        $mediaId = $request->mediaId();
        $this->initialPlayerSelection = [
            'season' => $seasonId > 0 ? $seasonId : '',
            'episode' => $episodeId > 0 ? $episodeId : '',
            'media' => $mediaId > 0 ? $mediaId : '',
            'variant' => $request->variantKey() ?? '',
            'quality' => $request->quality() ?? '',
            'format' => $request->mediaFormat() ?? '',
        ];
    }

    public function startRefresh(): void
    {
        $this->refreshes->request($this->title());
    }

    public function refreshCatalog(): void
    {
        $this->pages->forget($this->catalogTitleId, $this->user());
        $this->dispatch('catalog-title-refreshed', catalogTitleId: $this->catalogTitleId)
            ->to(ref: 'player');
    }

    public function setRecommendationFeedback(mixed $catalogTitleId, mixed $feedback): void
    {
        $user = $this->user();

        if (! $user instanceof User) {
            $this->redirectRoute('login', navigate: true);

            return;
        }

        $titleId = filter_var($catalogTitleId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $feedback = is_string($feedback) ? CatalogRecommendationFeedback::tryFrom($feedback) : null;

        if (! is_int($titleId) || ! $feedback instanceof CatalogRecommendationFeedback || $titleId === $this->catalogTitleId) {
            $this->addError('recommendationFeedback', __('recommendations.feedback.error'));

            return;
        }

        if ($feedback === CatalogRecommendationFeedback::NotInterested) {
            $this->addError('recommendationFeedback', __('recommendations.feedback.reason_required'));

            return;
        }

        try {
            $title = $this->titles->visibleTo($user)->findOrFail($titleId);

            if ($feedback === CatalogRecommendationFeedback::MoreLikeThis) {
                $this->feedback->savePositive($user, $title);
                $this->recommendationNotice = __('recommendations.feedback.saved_more_like_this');
            } else {
                $this->feedback->save($user, $title, CatalogRecommendationFeedbackReason::NotThisTitle);
                $this->recommendationNotice = __('recommendations.feedback.saved_reason');
            }

            $this->lastRecommendationFeedbackTitleId = $title->id;
            $this->pages->forget($this->catalogTitleId, $user);
            $this->resetErrorBag('recommendationFeedback');
        } catch (ValidationException $exception) {
            $this->addError(
                'recommendationFeedback',
                (string) ($exception->errors()['recommendationFeedback'][0] ?? __('recommendations.feedback.error')),
            );
        } catch (Throwable $exception) {
            report($exception);
            $this->addError('recommendationFeedback', __('recommendations.feedback.error'));
        }
    }

    public function setRecommendationFeedbackReason(
        mixed $catalogTitleId,
        mixed $reason,
        mixed $subjectId = null,
    ): void {
        $user = $this->user();

        if (! $user instanceof User) {
            $this->redirectRoute('login', navigate: true);

            return;
        }

        $titleId = filter_var($catalogTitleId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $reason = is_string($reason) ? CatalogRecommendationFeedbackReason::tryFrom($reason) : null;
        $subjectId = $subjectId === null
            ? null
            : filter_var($subjectId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        if (! is_int($titleId)
            || $titleId === $this->catalogTitleId
            || ! $reason instanceof CatalogRecommendationFeedbackReason
            || ($subjectId !== null && ! is_int($subjectId))) {
            $this->addError('recommendationFeedback', __('recommendations.feedback.invalid_reason'));

            return;
        }

        try {
            $title = $this->titles->visibleTo($user)->findOrFail($titleId);
            $this->feedback->save($user, $title, $reason, $subjectId);
            $this->lastRecommendationFeedbackTitleId = $title->id;
            $this->recommendationNotice = __('recommendations.feedback.saved_reason');
            $this->pages->forget($this->catalogTitleId, $user);
            $this->resetErrorBag('recommendationFeedback');
        } catch (ValidationException $exception) {
            $this->addError(
                'recommendationFeedback',
                (string) (
                    $exception->errors()['recommendationFeedbackSubject'][0]
                    ?? $exception->errors()['recommendationFeedback'][0]
                    ?? __('recommendations.feedback.error')
                ),
            );
        } catch (Throwable $exception) {
            report($exception);
            $this->addError('recommendationFeedback', __('recommendations.feedback.error'));
        }
    }

    public function hideRecommendationGenre(mixed $genreId): void
    {
        $user = $this->user();
        $genreId = filter_var($genreId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        if (! $user instanceof User || ! is_int($genreId)) {
            $this->addError('recommendationFeedback', __('recommendations.preferences.invalid'));

            return;
        }

        try {
            $genre = Genre::query()->findOrFail($genreId);
            $this->preferences->hideGenre($user, $genre);
            $this->recommendationNotice = __('recommendations.preferences.genre_hidden');
            $this->pages->forget($this->catalogTitleId, $user);
            $this->resetErrorBag('recommendationFeedback');
        } catch (ValidationException $exception) {
            $this->addError(
                'recommendationFeedback',
                (string) ($exception->errors()['recommendationPreferences'][0] ?? __('recommendations.feedback.error')),
            );
        } catch (Throwable $exception) {
            report($exception);
            $this->addError('recommendationFeedback', __('recommendations.feedback.error'));
        }
    }

    public function undoRecommendationFeedback(): void
    {
        $user = $this->user();

        if (! $user instanceof User || $this->lastRecommendationFeedbackTitleId === null) {
            return;
        }

        try {
            $title = $this->titles->visibleTo($user)->findOrFail($this->lastRecommendationFeedbackTitleId);
            $this->feedback->undo($user, $title);
            $this->lastRecommendationFeedbackTitleId = null;
            $this->recommendationNotice = __('recommendations.feedback.undone');
            $this->pages->forget($this->catalogTitleId, $user);
            $this->resetErrorBag('recommendationFeedback');
        } catch (ValidationException $exception) {
            $this->addError(
                'recommendationFeedback',
                (string) ($exception->errors()['recommendationFeedback'][0] ?? __('recommendations.feedback.error')),
            );
        } catch (Throwable $exception) {
            report($exception);
            $this->addError('recommendationFeedback', __('recommendations.feedback.error'));
        }
    }

    public function render(): View
    {
        $user = $this->user();
        $page = $this->pages->dataForId($this->catalogTitleId, $user);
        $refreshState = $this->states->read($this->catalogTitleId);
        $title = $page['title'];
        abort_unless($title instanceof CatalogTitle, 404);
        $seo = $this->pages->seo($title, $user);

        if (request()->hasAny([
            'review',
            'reviewPage',
            'review_sort',
            'review_rating',
            'review_spoiler',
            'review_verified',
            'discussion_scope',
            'discussion_sort',
            'comments_page',
            'thread',
            'comment',
        ])) {
            $seo['robots'] = 'noindex,follow,max-image-preview:large,max-snippet:-1,max-video-preview:-1';
        }

        return view('livewire.catalog-title-detail', [
            ...$page,
            'refreshShouldInitialize' => $this->refreshes->shouldRequest($title, $refreshState),
            'refreshIsActive' => $refreshState->isActive(),
            'refreshStatus' => $this->refreshStatus($refreshState),
            'publicCollections' => $this->collections->publicForTitle($this->catalogTitleId),
            'reviewLocale' => App::getLocale(),
            'contentRequestUrl' => route('requests.create', [
                'type' => 'broken_content_restoration',
                'catalog_title_id' => $this->catalogTitleId,
            ]),
            'releaseCalendarUrl' => route('calendar.upcoming', ['title' => $this->catalogTitleId]),
            'shareData' => [
                'url' => (string) ($seo['canonical'] ?? route('titles.show', $title)),
                'title' => $title->display_title,
            ],
        ])->extends('layouts.app', [
            'title' => $seo['title'] ?? $title->display_title,
            'seo' => $seo,
        ])->section('content');
    }

    private function title(): CatalogTitle
    {
        $title = $this->pages->dataForId($this->catalogTitleId, $this->user())['title'];

        abort_unless($title instanceof CatalogTitle, 404);

        return $title;
    }

    private function user(): ?User
    {
        $user = Auth::user();

        return $user instanceof User ? $user : null;
    }

    /** @return array{label: string, icon: string, tone: string}|null */
    private function refreshStatus(CatalogTitleRefreshState $state): ?array
    {
        return match ($state->status) {
            SeasonvarImportStatus::Queued, SeasonvarImportStatus::Running => [
                'label' => __('catalog.title.refreshing'),
                'icon' => 'fa-solid fa-arrows-rotate fa-spin',
                'tone' => 'active',
            ],
            SeasonvarImportStatus::Completed => [
                'label' => __('catalog.title.refreshed'),
                'icon' => 'fa-solid fa-circle-check',
                'tone' => 'completed',
            ],
            SeasonvarImportStatus::Failed => [
                'label' => __('catalog.title.refresh_failed'),
                'icon' => 'fa-solid fa-triangle-exclamation',
                'tone' => 'failed',
            ],
            default => null,
        };
    }
}
