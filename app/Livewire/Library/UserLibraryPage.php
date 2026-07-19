<?php

declare(strict_types=1);

namespace App\Livewire\Library;

use App\Enums\CatalogPublicationType;
use App\Enums\CatalogRecommendationFeedback;
use App\Enums\CatalogWatchStatus;
use App\Livewire\Forms\Library\LibraryFilters;
use App\Models\CatalogTitle;
use App\Models\EpisodePlaybackMarker;
use App\Models\User;
use App\Services\Auth\AccountDateTimeFormatter;
use App\Services\Auth\AccountSettingsService;
use App\Services\Catalog\CatalogManualPlaybackService;
use App\Services\Catalog\CatalogPersonalUpdateService;
use App\Services\Catalog\CatalogTitleQuery;
use App\Services\Catalog\CatalogUserStateService;
use App\Services\Catalog\CatalogViewingActivityQuery;
use App\Services\Catalog\CatalogViewingActivityService;
use App\Services\Catalog\PersonalLibrarySchema;
use App\Services\Catalog\PlaybackTimeFormatter;
use App\Services\Catalog\UserLibraryQuery;
use App\Services\Catalog\UserLibrarySummaryQuery;
use App\Services\Tags\PersonalTagLibraryQuery;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Illuminate\Support\Number;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithPagination;

final class UserLibraryPage extends Component
{
    use WithPagination;

    private const SECTIONS = [
        'watchlist',
        'ratings',
        'planned',
        'watching',
        'paused',
        'completed',
        'dropped',
        'not-interested',
        'blacklisted',
        'with-updates',
        'without-updates',
        'markers',
        'continue-watching',
        'history',
        'hidden-recommendations',
    ];

    private const FILTERABLE_SECTIONS = [
        'watchlist',
        'ratings',
        'planned',
        'watching',
        'paused',
        'completed',
        'dropped',
        'with-updates',
        'without-updates',
        'markers',
    ];

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

    protected CatalogManualPlaybackService $manualPlayback;

    protected CatalogPersonalUpdateService $personalUpdates;

    protected PersonalLibrarySchema $personalLibrarySchema;

    protected PlaybackTimeFormatter $playbackTimes;

    public function boot(
        UserLibraryQuery $library,
        UserLibrarySummaryQuery $summaries,
        CatalogViewingActivityQuery $activity,
        CatalogViewingActivityService $activityActions,
        CatalogUserStateService $userState,
        CatalogTitleQuery $titles,
        PersonalTagLibraryQuery $personalTags,
        CatalogManualPlaybackService $manualPlayback,
        CatalogPersonalUpdateService $personalUpdates,
        PersonalLibrarySchema $personalLibrarySchema,
        PlaybackTimeFormatter $playbackTimes,
    ): void {
        $this->library = $library;
        $this->summaries = $summaries;
        $this->activity = $activity;
        $this->activityActions = $activityActions;
        $this->userState = $userState;
        $this->titles = $titles;
        $this->personalTags = $personalTags;
        $this->manualPlayback = $manualPlayback;
        $this->personalUpdates = $personalUpdates;
        $this->personalLibrarySchema = $personalLibrarySchema;
        $this->playbackTimes = $playbackTimes;
    }

    public function mount(string $section = 'watchlist', ?string $locale = null): void
    {
        if (! in_array($section, self::SECTIONS, true)) {
            if (request()->routeIs('library.section', 'localized.library.section')) {
                $this->redirectRoute('home');

                return;
            }

            abort(404);
        }

        $this->section = $section;
    }

    public function applyFilters(): void
    {
        if (! $this->filterable()) {
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
        $this->userState->setWatchlist($this->user(), $this->title($catalogTitleId), $inWatchlist);
        $this->resetLibraryPages();
        $this->status = __($inWatchlist ? 'library.notices.bookmark_added' : 'library.notices.bookmark_removed');
    }

    public function setRating(int $catalogTitleId, mixed $rating): void
    {
        $normalizedRating = $rating === null || $rating === ''
            ? null
            : filter_var($rating, FILTER_VALIDATE_INT);

        if ($normalizedRating === false) {
            $this->addError('rating', $this->userState->ratingValidationMessage());

            return;
        }

        $this->resetErrorBag('rating');
        $this->userState->setRating($this->user(), $this->title($catalogTitleId), $normalizedRating);
        $this->resetLibraryPages();
        $this->status = __($normalizedRating === null ? 'library.notices.rating_removed' : 'library.notices.rating_saved');
    }

    public function setWatchStatus(int $catalogTitleId, mixed $status): void
    {
        $watchStatus = $status === null || $status === ''
            ? null
            : (is_string($status) ? CatalogWatchStatus::tryFrom($status) : null);

        if ($watchStatus === null && $status !== null && $status !== '') {
            $this->addError('watchStatus', __('library.errors.invalid_status'));

            return;
        }

        $this->userState->setWatchStatus($this->user(), $this->title($catalogTitleId), $watchStatus);
        $this->resetLibraryPages();
        $this->status = __('library.notices.status_saved');
    }

    public function acknowledgeUpdates(int $catalogTitleId): void
    {
        $this->personalUpdates->acknowledge($this->user(), $this->title($catalogTitleId));
        $this->resetLibraryPages();
        $this->status = __('library.notices.updates_acknowledged');
    }

    public function deleteMarker(string $publicId): void
    {
        abort_unless(preg_match('/^[a-f0-9-]{36}$/iD', $publicId) === 1, 404);
        $this->manualPlayback->deleteMarker($this->user(), $publicId);
        $this->resetPage(pageName: 'markersPage');
        $this->status = __('library.notices.marker_deleted');
    }

    public function removeHistoryItem(int $progressId): void
    {
        $this->activityActions->removeOwned($this->user(), $progressId);
        $this->resetPage(pageName: 'historyPage');
        $this->status = __('library.notices.history_item_removed');
    }

    public function clearHistory(): void
    {
        $this->activityActions->clear($this->user());
        $this->resetPage(pageName: 'historyPage');
        $this->status = __('library.notices.history_cleared');
    }

    public function undoRecommendationFeedback(int $catalogTitleId): void
    {
        $this->userState->undoRecommendationFeedback($this->user(), $this->title($catalogTitleId));
        $this->resetLibraryPages();
        $this->status = __('recommendations.feedback.undone');
    }

    public function render(
        AccountSettingsService $settings,
        AccountDateTimeFormatter $dateTimes,
    ): View {
        $user = $this->user();
        $summary = $this->summaries->get($user);
        $accountSettings = $settings->resolve($user);
        $stateItems = null;
        $markers = null;
        $continueWatching = null;
        $history = null;
        $feedbackItems = null;
        $filters = $this->filters->toDto($this->section);

        if ($this->section === 'watchlist') {
            $stateItems = $this->library->watchlist($user, $filters, 'watchlistPage');
        } elseif ($this->section === 'ratings') {
            $stateItems = $this->library->ratings($user, $filters, 'ratingsPage');
        } elseif ($watchStatus = CatalogWatchStatus::tryFrom($this->section)) {
            $stateItems = $this->library->watchStatus($user, $watchStatus, $filters, 'statePage');
        } elseif ($this->section === 'with-updates' || $this->section === 'without-updates') {
            $stateItems = $this->library->updates(
                $user,
                $this->section === 'with-updates',
                $filters,
                'updatesPage',
            );
        } elseif ($this->section === 'markers') {
            $markers = $this->personalLibrarySchema->ready()
                ? $this->prepareMarkers($this->library->markers($user, $filters, 'markersPage'))
                : null;
        } elseif ($this->section === 'continue-watching') {
            $continueWatching = $this->activity->continueWatching($user, 24);
        } elseif ($this->section === 'history') {
            $history = $this->activity->history($user, 12, 'historyPage');
        } elseif ($this->section === 'not-interested' || $this->section === 'blacklisted') {
            $feedbackItems = $this->library->recommendationFeedbackByType(
                $user,
                $this->section === 'blacklisted'
                    ? CatalogRecommendationFeedback::Blacklisted
                    : CatalogRecommendationFeedback::NotInterested,
                'recommendationFeedbackPage',
            );
        } else {
            $feedbackItems = $this->library->recommendationFeedback($user, 'recommendationFeedbackPage');
        }

        return view('livewire.library.user-library-page', [
            'summary' => $summary,
            'tabs' => $this->tabs($summary->sectionCounts, $summary->watchlistCount, $summary->ratingsCount, $summary->continueWatchingCount, $summary->historyCount),
            'sectionTitle' => __('library.sections.'.$this->section.'.title'),
            'sectionDescription' => __('library.sections.'.$this->section.'.description'),
            'lastWatchedAtLabel' => $summary->lastWatchedAt !== null
                ? $dateTimes->value($summary->lastWatchedAt, $accountSettings->locale, $accountSettings->timezone)
                : null,
            'publicationTypes' => collect(CatalogPublicationType::cases())
                ->reject(fn (CatalogPublicationType $type): bool => $type === CatalogPublicationType::Unknown)
                ->map(fn (CatalogPublicationType $type): array => ['value' => $type->value, 'label' => $type->label()])
                ->values(),
            'sortOptions' => $this->sortOptions(),
            'watchStatusOptions' => collect(CatalogWatchStatus::cases())->map(fn (CatalogWatchStatus $status): array => [
                'value' => $status->value,
                'label' => __('recommendations.watch_status.'.$status->value),
            ]),
            'ratingOptions' => $this->userState->ratingOptions(),
            'canInteract' => $user->hasVerifiedEmail(),
            'filterable' => $this->filterable(),
            'personalTags' => $this->personalTags->active($user),
            'maximumYear' => now()->year + 1,
            'stateItems' => $stateItems,
            'markers' => $markers,
            'continueWatching' => $continueWatching,
            'history' => $history,
            'feedbackItems' => $feedbackItems,
        ])
            ->extends('layouts.app', [
                'title' => __('library.meta.title'),
                'seo' => [
                    'title' => __('library.meta.title'),
                    'description' => __('library.meta.description'),
                    'robots' => 'noindex, nofollow',
                    'canonical' => $this->sectionUrl($this->section),
                ],
            ])
            ->section('content');
    }

    /** @return array<int, array{section: string, label: string, countLabel: string|null, icon: string, url: string}> */
    private function tabs(array $counts, int $watchlist, int $ratings, int $continueWatching, int $history): array
    {
        $tabCounts = [
            'watchlist' => $watchlist,
            'ratings' => $ratings,
            'continue-watching' => $continueWatching,
            'history' => $history,
            ...$counts,
        ];
        $icons = [
            'watchlist' => 'fa-solid fa-bookmark',
            'planned' => 'fa-solid fa-calendar-plus',
            'watching' => 'fa-solid fa-circle-play',
            'completed' => 'fa-solid fa-circle-check',
            'paused' => 'fa-solid fa-pause',
            'dropped' => 'fa-solid fa-ban',
            'with-updates' => 'fa-solid fa-bell',
            'without-updates' => 'fa-solid fa-bell-slash',
            'markers' => 'fa-solid fa-location-dot',
            'not-interested' => 'fa-solid fa-eye-slash',
            'blacklisted' => 'fa-solid fa-shield-halved',
            'continue-watching' => 'fa-solid fa-forward',
            'history' => 'fa-solid fa-clock-rotate-left',
            'ratings' => 'fa-solid fa-star',
        ];
        $sections = array_keys($icons);
        $tabs = array_map(fn (string $section): array => [
            'section' => $section,
            'label' => __('library.tabs.'.$section),
            'countLabel' => Number::format($tabCounts[$section] ?? 0, locale: app()->getLocale()),
            'icon' => $icons[$section],
            'url' => $this->sectionUrl($section),
        ], $sections);
        $tabs[] = [
            'section' => 'collections',
            'label' => __('library.tabs.collections'),
            'countLabel' => null,
            'icon' => 'fa-solid fa-layer-group',
            'url' => route('collections.mine'),
        ];

        return $tabs;
    }

    /** @return Collection<int, array{value: string, label: string}> */
    private function sortOptions(): Collection
    {
        $values = ['updated', 'title', 'year'];

        if ($this->section === 'ratings') {
            $values[] = 'rating';
        }

        if (CatalogWatchStatus::tryFrom($this->section) !== null
            || in_array($this->section, ['with-updates', 'without-updates'], true)) {
            array_push($values, 'recently-watched', 'progress', 'status');
        }

        return collect($values)->map(fn (string $value): array => [
            'value' => $value,
            'label' => __('library.sort.'.$value),
        ]);
    }

    private function prepareMarkers(LengthAwarePaginator $markers): LengthAwarePaginator
    {
        $markers->getCollection()->each(function (EpisodePlaybackMarker $marker): void {
            $marker->setAttribute('position_label', $this->playbackTimes->compact($marker->position_seconds));
            $marker->setAttribute('resume_url', route('titles.show', [
                'catalogTitle' => $marker->catalogTitle,
                'season' => $marker->episode?->season_id,
                'episode' => $marker->episode_id,
                'marker' => $marker->public_id,
            ]).'#player');
        });

        return $markers;
    }

    private function sectionUrl(string $section): string
    {
        $locale = request()->route('locale');

        return is_string($locale)
            ? route('localized.library.section', ['locale' => $locale, 'section' => $section])
            : route('library.section', $section);
    }

    private function title(int $catalogTitleId): CatalogTitle
    {
        return $this->titles->visibleTo($this->user())->findOrFail($catalogTitleId);
    }

    private function filterable(): bool
    {
        return in_array($this->section, self::FILTERABLE_SECTIONS, true);
    }

    private function resetLibraryPages(): void
    {
        foreach (['watchlistPage', 'ratingsPage', 'statePage', 'updatesPage', 'markersPage', 'recommendationFeedbackPage'] as $pageName) {
            $this->resetPage(pageName: $pageName);
        }
    }

    private function user(): User
    {
        $user = auth()->user();

        abort_unless($user instanceof User, 403);

        return $user;
    }
}
