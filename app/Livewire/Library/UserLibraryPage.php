<?php

declare(strict_types=1);

namespace App\Livewire\Library;

use App\Enums\CatalogPublicationType;
use App\Livewire\Forms\Library\LibraryFilters;
use App\Models\CatalogTitle;
use App\Models\User;
use App\Services\Catalog\CatalogTitleQuery;
use App\Services\Catalog\CatalogUserStateService;
use App\Services\Catalog\CatalogViewingActivityQuery;
use App\Services\Catalog\CatalogViewingActivityService;
use App\Services\Catalog\UserLibraryQuery;
use App\Services\Catalog\UserLibrarySummaryQuery;
use App\Services\Tags\PersonalTagLibraryQuery;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithPagination;

final class UserLibraryPage extends Component
{
    use WithPagination;

    private const SECTIONS = ['watchlist', 'ratings', 'continue-watching', 'history'];

    #[Locked]
    public string $section = 'watchlist';

    public LibraryFilters $filters;

    public ?string $status = null;

    protected UserLibraryQuery $library;

    protected UserLibrarySummaryQuery $summaries;

    protected CatalogViewingActivityQuery $activity;

    protected CatalogViewingActivityService $activityActions;

    protected CatalogUserStateService $userState;

    protected CatalogTitleQuery $titles;

    protected PersonalTagLibraryQuery $personalTags;

    public function boot(
        UserLibraryQuery $library,
        UserLibrarySummaryQuery $summaries,
        CatalogViewingActivityQuery $activity,
        CatalogViewingActivityService $activityActions,
        CatalogUserStateService $userState,
        CatalogTitleQuery $titles,
        PersonalTagLibraryQuery $personalTags,
    ): void {
        $this->library = $library;
        $this->summaries = $summaries;
        $this->activity = $activity;
        $this->activityActions = $activityActions;
        $this->userState = $userState;
        $this->titles = $titles;
        $this->personalTags = $personalTags;
    }

    public function mount(string $section = 'watchlist'): void
    {
        abort_unless(in_array($section, self::SECTIONS, true), 404);

        $this->section = $section;
    }

    public function applyFilters(): void
    {
        if (! in_array($this->section, ['watchlist', 'ratings'], true)) {
            return;
        }

        $this->filters->normalize();
        $this->filters->validateFor($this->section);
        $this->resetLibraryPages();
        $this->status = null;
    }

    public function resetFilters(): void
    {
        $this->filters->reset();
        $this->resetValidation();
        $this->resetLibraryPages();
        $this->status = null;
    }

    public function setWatchlist(int $catalogTitleId, bool $inWatchlist): void
    {
        $this->userState->setWatchlist(
            $this->user(),
            $this->title($catalogTitleId),
            $inWatchlist,
        );
        $this->resetPage(pageName: 'watchlistPage');
        $this->status = $inWatchlist
            ? 'Тайтл добавлен в список.'
            : 'Тайтл удалён из списка.';
    }

    public function setRating(int $catalogTitleId, mixed $rating): void
    {
        if ($rating === null || $rating === '') {
            $normalizedRating = null;
        } else {
            $normalizedRating = filter_var($rating, FILTER_VALIDATE_INT);

            if ($normalizedRating === false) {
                $this->addError('rating', $this->userState->ratingValidationMessage());

                return;
            }
        }

        $this->resetErrorBag('rating');
        $this->userState->setRating(
            $this->user(),
            $this->title($catalogTitleId),
            $normalizedRating,
        );
        $this->resetPage(pageName: 'ratingsPage');
        $this->status = $normalizedRating === null ? 'Оценка удалена.' : 'Оценка сохранена.';
    }

    public function removeHistoryItem(int $progressId): void
    {
        $this->activityActions->removeOwned($this->user(), $progressId);
        $this->resetPage(pageName: 'historyPage');
        $this->status = 'Запись удалена из истории.';
    }

    public function clearHistory(): void
    {
        $this->activityActions->clear($this->user());
        $this->resetPage(pageName: 'historyPage');
        $this->status = 'История просмотров очищена.';
    }

    public function render(): View
    {
        $user = $this->user();
        $summary = $this->summaries->get($user);
        $data = [
            'summary' => $summary,
            'lastWatchedAtLabel' => $summary->lastWatchedAt?->format('d.m.Y H:i'),
            'publicationTypes' => collect(CatalogPublicationType::cases())
                ->reject(fn (CatalogPublicationType $type): bool => $type === CatalogPublicationType::Unknown)
                ->map(fn (CatalogPublicationType $type): array => [
                    'value' => $type->value,
                    'label' => $type->label(),
                ])
                ->values(),
            'ratingOptions' => $this->userState->ratingOptions(),
            'canInteract' => $user->hasVerifiedEmail(),
            'personalTags' => $this->personalTags->active($user),
            'maximumYear' => now()->year + 1,
            'watchlist' => null,
            'ratings' => null,
            'continueWatching' => null,
            'history' => null,
        ];

        match ($this->section) {
            'watchlist' => $data['watchlist'] = $this->library->watchlist(
                $user,
                $this->filters->toDto($this->section),
                'watchlistPage',
            ),
            'ratings' => $data['ratings'] = $this->library->ratings(
                $user,
                $this->filters->toDto($this->section),
                'ratingsPage',
            ),
            'continue-watching' => $data['continueWatching'] = $this->activity->continueWatching($user, 24),
            'history' => $data['history'] = $this->activity->history($user, 12, 'historyPage'),
            default => abort(404),
        };

        return view('livewire.library.user-library-page', $data)
            ->extends('layouts.app', [
                'title' => 'Моя библиотека',
                'seo' => [
                    'title' => 'Моя библиотека',
                    'description' => 'Личная библиотека, оценки и история просмотров.',
                    'robots' => 'noindex, nofollow',
                    'canonical' => route('library.section', $this->section),
                ],
            ])
            ->section('content');
    }

    private function title(int $catalogTitleId): CatalogTitle
    {
        return $this->titles->visibleTo($this->user())->findOrFail($catalogTitleId);
    }

    private function resetLibraryPages(): void
    {
        $this->resetPage(pageName: 'watchlistPage');
        $this->resetPage(pageName: 'ratingsPage');
    }

    private function user(): User
    {
        $user = auth()->user();

        abort_unless($user instanceof User, 403);

        return $user;
    }
}
