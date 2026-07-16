<?php

declare(strict_types=1);

namespace App\Livewire;

use App\DTOs\CatalogTitleRefreshState;
use App\Enums\CatalogRecommendationFeedback;
use App\Enums\SeasonvarImportStatus;
use App\Models\CatalogTitle;
use App\Models\User;
use App\Services\Catalog\CatalogTitlePageBuilder;
use App\Services\Catalog\CatalogTitleQuery;
use App\Services\Catalog\CatalogUserStateService;
use App\Services\Collections\CatalogCollectionQuery;
use App\Services\Seasonvar\CatalogTitleRefreshCoordinator;
use App\Services\Seasonvar\CatalogTitleRefreshStateStore;
use Illuminate\Contracts\View\View;
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

    protected CatalogTitlePageBuilder $pages;

    protected CatalogTitleRefreshCoordinator $refreshes;

    protected CatalogTitleRefreshStateStore $states;

    protected CatalogCollectionQuery $collections;

    protected CatalogTitleQuery $titles;

    protected CatalogUserStateService $userStates;

    #[Locked]
    public ?int $lastRecommendationFeedbackTitleId = null;

    public ?string $recommendationNotice = null;

    public function boot(
        CatalogTitlePageBuilder $pages,
        CatalogTitleRefreshCoordinator $refreshes,
        CatalogTitleRefreshStateStore $states,
        CatalogCollectionQuery $collections,
        CatalogTitleQuery $titles,
        CatalogUserStateService $userStates,
    ): void {
        $this->pages = $pages;
        $this->refreshes = $refreshes;
        $this->states = $states;
        $this->collections = $collections;
        $this->titles = $titles;
        $this->userStates = $userStates;
    }

    public function mount(int $catalogTitleId): void
    {
        $this->catalogTitleId = $catalogTitleId;
        $highlightedReviewId = request()->integer('review');
        $this->highlightedReviewId = $highlightedReviewId > 0 ? $highlightedReviewId : null;
    }

    public function startRefresh(): void
    {
        $this->refreshes->request($this->title());
    }

    public function refreshCatalog(): void
    {
        $this->pages->forget($this->catalogTitleId, $this->user());
        $this->dispatch('catalog-title-refreshed', catalogTitleId: $this->catalogTitleId)
            ->to(component: CatalogTitlePlayer::class);
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

        try {
            $title = $this->titles->visibleTo($user)->findOrFail($titleId);
            $this->userStates->setRecommendationFeedback($user, $title, $feedback);
            $this->lastRecommendationFeedbackTitleId = $title->id;
            $this->recommendationNotice = __("recommendations.feedback.saved_{$feedback->value}");
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

    public function undoRecommendationFeedback(): void
    {
        $user = $this->user();

        if (! $user instanceof User || $this->lastRecommendationFeedbackTitleId === null) {
            return;
        }

        try {
            $title = $this->titles->visibleTo($user)->findOrFail($this->lastRecommendationFeedbackTitleId);
            $this->userStates->undoRecommendationFeedback($user, $title);
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

        return view('livewire.catalog-title-detail', [
            ...$page,
            'refreshIsActive' => $refreshState->isActive(),
            'refreshStatus' => $this->refreshStatus($refreshState),
            'publicCollections' => $this->collections->publicForTitle($this->catalogTitleId),
            'reviewLocale' => App::getLocale(),
            'contentRequestUrl' => $user instanceof User
                ? route('requests.create', ['type' => 'broken_content_restoration', 'catalog_title_id' => $this->catalogTitleId])
                : route('login'),
        ]);
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
