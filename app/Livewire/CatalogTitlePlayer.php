<?php

declare(strict_types=1);

namespace App\Livewire;

use App\DTOs\CatalogEpisodeNavigation;
use App\DTOs\CatalogPrimaryAction;
use App\DTOs\PlaybackPreferencesData;
use App\Enums\ReleaseKind;
use App\Models\CatalogTitle;
use App\Models\Episode;
use App\Models\LicensedMedia;
use App\Models\Season;
use App\Models\User;
use App\Services\Catalog\CatalogPlaybackProgressSession;
use App\Services\Catalog\CatalogPlaybackSourceResolver;
use App\Services\Catalog\CatalogPrimaryActionResolver;
use App\Services\Catalog\CatalogTitlePlaybackQuery;
use App\Services\Catalog\CatalogUserStateService;
use App\Services\Media\ExternalMediaMetadata;
use App\View\ViewModels\CatalogShowViewModel;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Attributes\Renderless;
use Livewire\Attributes\Url;
use Livewire\Component;

class CatalogTitlePlayer extends Component
{
    #[Locked]
    public int $catalogTitleId;

    #[Url(except: '')]
    public string|int|null $season = '';

    #[Url(except: '')]
    public string|int|null $episode = '';

    #[Url(except: '')]
    public string|int|null $media = '';

    #[Url(except: '')]
    public ?string $variant = '';

    #[Url(except: '')]
    public ?string $quality = '';

    #[Url(except: '')]
    public ?string $format = '';

    protected CatalogTitlePlaybackQuery $playback;

    protected CatalogPrimaryActionResolver $primaryActions;

    protected CatalogPlaybackSourceResolver $sources;

    protected CatalogPlaybackProgressSession $progressSessions;

    protected CatalogUserStateService $userState;

    protected ExternalMediaMetadata $mediaMetadata;

    protected ?CatalogTitle $resolvedTitle = null;

    protected ?Episode $resolvedEpisode = null;

    /** @var Collection<int, Season>|null */
    protected ?Collection $resolvedSeasons = null;

    public function boot(
        CatalogTitlePlaybackQuery $playback,
        CatalogPrimaryActionResolver $primaryActions,
        CatalogPlaybackSourceResolver $sources,
        CatalogPlaybackProgressSession $progressSessions,
        CatalogUserStateService $userState,
        ExternalMediaMetadata $mediaMetadata,
    ): void {
        $this->playback = $playback;
        $this->primaryActions = $primaryActions;
        $this->sources = $sources;
        $this->progressSessions = $progressSessions;
        $this->userState = $userState;
        $this->mediaMetadata = $mediaMetadata;
    }

    public function mount(int $catalogTitleId): void
    {
        $this->catalogTitleId = $catalogTitleId;
        $this->normalizeInitialSelection();
    }

    #[On('catalog-title-refreshed')]
    public function refreshCatalogData(int $catalogTitleId): void
    {
        if ($catalogTitleId !== $this->catalogTitleId) {
            return;
        }

        $this->resolvedTitle = null;
        $this->resolvedEpisode = null;
        $this->resolvedSeasons = null;
    }

    public function playPrimary(): void
    {
        $action = $this->primaryActions->resolve($this->title(), $this->user());

        if (! $action->isPlayable()) {
            return;
        }

        $this->applyPrimaryAction($action);
    }

    public function selectSeason(mixed $seasonId): void
    {
        $seasonId = $this->positiveId($seasonId);

        if ($seasonId === null) {
            $this->resetSelection();

            return;
        }

        $title = $this->title();
        $season = $this->seasonSummaries($title, $this->user())->firstWhere('id', $seasonId);

        if ($season === null) {
            $this->resetSelection();

            return;
        }

        $this->season = (string) $season->id;
        $this->episode = '';
        $this->media = '';
        $this->resolvedEpisode = null;
    }

    public function selectEpisode(mixed $episodeId): void
    {
        $episodeId = $this->positiveId($episodeId);

        if ($episodeId === null) {
            $this->episode = '';
            $this->media = '';
            $this->resolvedEpisode = null;

            return;
        }

        $title = $this->title();
        $episode = $this->playback->watchableEpisode($title, $this->user(), $episodeId);

        if ($episode === null) {
            $this->episode = '';
            $this->media = '';
            $this->resolvedEpisode = null;

            return;
        }

        $media = $this->playback->bestMediaForEpisode(
            $title,
            $this->user(),
            $episode,
            $this->normalizedVariant(),
            $this->normalizedQuality(),
            $this->normalizedFormat(),
        );
        $this->season = (string) $episode->season_id;
        $this->episode = (string) $episode->id;
        $this->media = $media !== null ? (string) $media->id : '';
        $this->resolvedEpisode = $episode;

        if ($media !== null) {
            $this->syncMediaProfile($media);
        }
    }

    public function selectMedia(mixed $mediaId): void
    {
        $mediaId = $this->positiveId($mediaId);

        if ($mediaId === null) {
            $this->media = '';

            return;
        }

        $title = $this->title();
        $media = $this->playback->findAvailableMedia($title, $this->user(), $mediaId);

        if ($media === null) {
            $this->media = '';

            return;
        }

        $this->media = (string) $media->id;
        $this->season = $media->season_id !== null ? (string) $media->season_id : '';
        $this->episode = $media->episode_id !== null ? (string) $media->episode_id : '';
        $this->syncMediaProfile($media);
    }

    public function setWatchlist(mixed $inWatchlist): void
    {
        $user = $this->authenticatedUser();
        $inWatchlist = filter_var($inWatchlist, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);

        if ($inWatchlist === null) {
            return;
        }

        $this->userState->setWatchlist($user, $this->title(), $inWatchlist);
    }

    public function setRating(mixed $rating): void
    {
        $user = $this->authenticatedUser();
        if ($rating === null || $rating === '') {
            $this->resetErrorBag('rating');
            $this->userState->setRating($user, $this->title(), null);

            return;
        }

        $rating = filter_var($rating, FILTER_VALIDATE_INT);

        if ($rating === false) {
            $this->addError('rating', $this->userState->ratingValidationMessage());

            return;
        }

        $this->resetErrorBag('rating');
        $this->userState->setRating($user, $this->title(), $rating);
    }

    #[Renderless]
    public function recordProgress(
        mixed $episodeId,
        mixed $playbackSessionToken,
        mixed $eventSequence,
        mixed $positionSeconds,
        mixed $reportedDurationSeconds,
        mixed $ended = false,
    ): void {
        $user = $this->authenticatedUser();
        $episodeId = $this->positiveId($episodeId);
        $eventSequence = $this->nonNegativeInteger($eventSequence, minimum: 1);
        $positionSeconds = $this->nonNegativeInteger($positionSeconds);
        $reportedDurationSeconds = $this->nonNegativeInteger($reportedDurationSeconds);
        $ended = filter_var($ended, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);

        if ($episodeId === null
            || ! is_string($playbackSessionToken)
            || mb_strlen($playbackSessionToken) > 2048
            || $eventSequence === null
            || $positionSeconds === null
            || $reportedDurationSeconds === null
            || $ended === null) {
            return;
        }

        $this->userState->recordProgress(
            $user,
            $this->title(),
            $episodeId,
            $playbackSessionToken,
            $eventSequence,
            $positionSeconds,
            $reportedDurationSeconds,
            $ended,
        );
    }

    public function render(): View
    {
        $user = $this->user();
        $title = $this->title();
        $primaryAction = $this->primaryActions->resolve($title, $user);
        $seasons = $this->seasonSummaries($title, $user);
        $requestedEpisode = $this->positiveId($this->episode);
        $requestedEpisodeModel = $requestedEpisode !== null
            ? $this->resolvedWatchableEpisode($title, $user, $requestedEpisode)
            : null;
        $requestedSeason = $this->positiveId($this->season);

        if ($this->hasUrlValue($this->season) && ($requestedSeason === null || $seasons->firstWhere('id', $requestedSeason) === null)) {
            $this->season = '';
        }

        if ($this->hasUrlValue($this->episode) && $requestedEpisodeModel === null) {
            $this->episode = '';
            $this->media = '';
        }

        $activeSeason = $this->activeSeason($seasons, $requestedEpisodeModel, $primaryAction);
        $episodes = $activeSeason !== null
            ? $this->playback->episodesForSeason($title, $activeSeason, $user)
            : collect();
        $selectedEpisode = $this->selectedEpisode($episodes, $requestedEpisodeModel, $primaryAction, $activeSeason);
        $episodeNavigation = $selectedEpisode !== null && $activeSeason !== null
            ? $this->playback->episodeNavigation(
                $title,
                $activeSeason,
                $user,
                $selectedEpisode,
                $episodes,
                $seasons,
            )
            : new CatalogEpisodeNavigation;
        $mediaItems = $episodes
            ->flatMap(fn (Episode $episode): Collection => $episode->licensedMedia)
            ->values();
        $selectedMedia = $this->selectedMedia($mediaItems, $selectedEpisode, $primaryAction, $title, $user);
        $requestedMedia = $this->positiveId($this->media);

        if ($this->hasUrlValue($this->media) && ($requestedMedia === null || $selectedMedia?->id !== $requestedMedia)) {
            $this->media = '';
        }

        if ($mediaItems->isEmpty() && $selectedMedia !== null) {
            $selectedMedia->setRelation('catalogTitle', $title);
            $mediaItems = collect([$selectedMedia]);
        }

        $exactMediaId = $requestedMedia !== null && $selectedMedia?->id === $requestedMedia
            ? $requestedMedia
            : null;

        $playbackSource = $this->sources->resolve(
            $title,
            $user,
            $selectedEpisode,
            $exactMediaId,
            new PlaybackPreferencesData(
                variant: $this->normalizedVariant(),
                quality: $this->normalizedQuality(),
                format: $this->normalizedFormat(),
            ),
        );
        $selectedMedia = $playbackSource->mediaId !== null
            ? ($mediaItems->firstWhere('id', $playbackSource->mediaId) ?? $selectedMedia)
            : $selectedMedia;
        $playerSessionKey = $selectedMedia !== null && $playbackSource->isPlayable()
            ? implode(':', [$title->id, $selectedEpisode?->id ?? 0, $selectedMedia->id])
            : '';
        $progressSessionToken = $user !== null
            && $selectedEpisode !== null
            && $selectedMedia !== null
            && $playbackSource->isPlayable()
                ? $this->progressSessions->issue($user, $title, $selectedEpisode, $selectedMedia)
                : '';

        $showView = new CatalogShowViewModel(
            title: $title,
            taxonomiesByType: collect(),
            seasons: $seasons,
            mediaItems: $mediaItems,
            selectedEpisode: $selectedEpisode,
            selectedMedia: $selectedMedia,
            mediaMetadata: $this->mediaMetadata,
            playbackSource: $playbackSource,
            episodeCount: $seasons->sum('available_episodes_count'),
            parsedSeasonCount: $seasons->filter(fn (Season $season): bool => (int) $season->available_episodes_count > 0)->count(),
            mediaCount: $seasons->sum('available_media_count'),
        );
        $state = $user !== null ? $this->userState->state($user, $title) : null;
        $stateSummary = $this->userState->summary($title);
        $ratingRange = $this->userState->ratingRange();

        return view('livewire.catalog-title-player', [
            'title' => $title,
            'primaryAction' => $primaryAction,
            'seasons' => $seasons,
            'activeSeason' => $activeSeason,
            'episodes' => $episodes,
            'selectedEpisode' => $selectedEpisode,
            'episodeNavigation' => $episodeNavigation,
            'selectedMedia' => $selectedMedia,
            'playbackSource' => $playbackSource,
            'playerSessionKey' => $playerSessionKey,
            'progressSessionToken' => $progressSessionToken,
            'mediaItems' => $mediaItems,
            'showView' => $showView,
            'inWatchlist' => (bool) ($state?->in_watchlist ?? false),
            'userRating' => $state?->rating,
            'userStateSummary' => $stateSummary,
            'ratingOptions' => $this->userState->ratingOptions(),
            'ratingMaximum' => $ratingRange['maximum'],
            'isAuthenticated' => $user !== null,
            'canInteract' => $user?->hasVerifiedEmail() === true,
        ]);
    }

    public function episodeCountLabel(int $count): string
    {
        return trans_choice('catalog.counts.available_episodes', $count);
    }

    public function episodeDisplayLabel(Episode $episode): string
    {
        if ($episode->kind === ReleaseKind::Special) {
            return $episode->number !== null
                ? __('catalog.release.special_episode', ['number' => $episode->number])
                : __('catalog.release.special_episode_without_number');
        }

        return $episode->number !== null
            ? __('catalog.release.episode', ['number' => $episode->number])
            : __('catalog.release.episode_without_number');
    }

    public function selectedEpisodeLabel(Episode $episode): string
    {
        return __(
            $episode->kind === ReleaseKind::Special ? 'catalog.release.selected_special' : 'catalog.release.selected_episode',
            ['episode' => $this->episodeDisplayLabel($episode)],
        );
    }

    public function seasonDisplayLabel(Season $season): string
    {
        if ($season->kind === ReleaseKind::Special) {
            return $season->number !== null
                ? __('catalog.release.special_season', ['number' => $season->number])
                : __('catalog.release.special_season_without_number');
        }

        return $season->number !== null
            ? __('catalog.release.season', ['number' => $season->number])
            : __('catalog.release.season_without_number');
    }

    /** @param Collection<int, Season> $seasons */
    private function activeSeason(Collection $seasons, ?Episode $requestedEpisode, CatalogPrimaryAction $primaryAction): ?Season
    {
        $requestedSeasonId = $this->positiveId($this->season);
        $seasonId = $requestedEpisode?->season_id
            ?? $requestedSeasonId
            ?? $primaryAction->seasonId;

        return ($seasonId !== null ? $seasons->firstWhere('id', $seasonId) : null)
            ?? $seasons->first();
    }

    /** @param Collection<int, Episode> $episodes */
    private function selectedEpisode(
        Collection $episodes,
        ?Episode $requestedEpisode,
        CatalogPrimaryAction $primaryAction,
        ?Season $activeSeason,
    ): ?Episode {
        if ($requestedEpisode !== null && $requestedEpisode->season_id === $activeSeason?->id) {
            return $episodes->firstWhere('id', $requestedEpisode->id);
        }

        if ($primaryAction->episodeId !== null && $primaryAction->seasonId === $activeSeason?->id) {
            return $episodes->firstWhere('id', $primaryAction->episodeId) ?? $episodes->first();
        }

        return $episodes->first();
    }

    /**
     * @param  Collection<int, LicensedMedia>  $mediaItems
     */
    private function selectedMedia(
        Collection $mediaItems,
        ?Episode $selectedEpisode,
        CatalogPrimaryAction $primaryAction,
        CatalogTitle $title,
        ?User $user,
    ): ?LicensedMedia {
        $requestedMediaId = $this->positiveId($this->media);
        $requestedMedia = $requestedMediaId !== null ? $mediaItems->firstWhere('id', $requestedMediaId) : null;

        if ($requestedMedia !== null && ($selectedEpisode === null || $requestedMedia->episode_id === $selectedEpisode->id)) {
            return $requestedMedia;
        }

        if ($selectedEpisode !== null) {
            $requestedProfileMedia = $this->playback->preferredMedia(
                $selectedEpisode->licensedMedia,
                $this->normalizedVariant(),
                $this->normalizedQuality(),
                $this->normalizedFormat(),
            );

            return $selectedEpisode->licensedMedia->firstWhere('id', $requestedProfileMedia?->id)
                ?? $selectedEpisode->licensedMedia->firstWhere('id', $primaryAction->mediaId)
                ?? $selectedEpisode->licensedMedia->first();
        }

        if ($primaryAction->mediaId !== null) {
            return $this->playback->findAvailableMedia($title, $user, $primaryAction->mediaId);
        }

        return null;
    }

    private function applyPrimaryAction(CatalogPrimaryAction $action): void
    {
        $this->season = $action->seasonId !== null ? (string) $action->seasonId : '';
        $this->episode = $action->episodeId !== null ? (string) $action->episodeId : '';
        $this->media = $action->mediaId !== null ? (string) $action->mediaId : '';
    }

    private function syncMediaProfile(LicensedMedia $media): void
    {
        $profile = $this->playback->mediaProfile($media);
        $this->variant = $profile['variant'];
        $this->quality = $profile['quality'];
        $this->format = $profile['format'];
    }

    private function normalizedProfileValue(?string $value, int $maxLength): ?string
    {
        $value = trim((string) $value);

        return $value !== '' && mb_strlen($value) <= $maxLength ? $value : null;
    }

    private function normalizedVariant(): ?string
    {
        $value = $this->normalizedProfileValue($this->variant, 160);

        return $value !== null && preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $value) === 1
            ? $value
            : null;
    }

    private function normalizedQuality(): ?string
    {
        $value = $this->normalizedProfileValue($this->quality, 32);

        return $value !== null && in_array($value, (array) config('playback.supported_qualities', []), true)
            ? $value
            : null;
    }

    private function normalizedFormat(): ?string
    {
        $value = $this->normalizedProfileValue($this->format, 32);

        return $value !== null && in_array($value, (array) config('playback.allowed_formats', []), true)
            ? $value
            : null;
    }

    private function resetSelection(): void
    {
        $this->season = '';
        $this->episode = '';
        $this->media = '';
    }

    private function normalizeInitialSelection(): void
    {
        $user = $this->user();
        $title = $this->title();
        $seasons = $this->seasonSummaries($title, $user);
        $seasonId = $this->positiveId($this->season);

        if ($this->hasUrlValue($this->season) && ($seasonId === null || $seasons->firstWhere('id', $seasonId) === null)) {
            $this->season = '';
            $seasonId = null;
        }

        $episodeId = $this->positiveId($this->episode);
        $episode = $episodeId !== null ? $this->playback->watchableEpisode($title, $user, $episodeId) : null;
        $this->resolvedEpisode = $episode;

        if ($this->hasUrlValue($this->episode) && $episode === null) {
            $this->episode = '';
            $this->media = '';

            return;
        }

        if (! $this->hasUrlValue($this->media)) {
            return;
        }

        $mediaId = $this->positiveId($this->media);
        $media = $mediaId !== null ? $this->playback->findAvailableMedia($title, $user, $mediaId) : null;
        $matchesEpisode = $episode === null || $media?->episode_id === $episode->id;
        $matchesSeason = $seasonId === null || $media?->season_id === $seasonId;

        if ($media === null || ! $matchesEpisode || ! $matchesSeason) {
            $this->media = '';
        }
    }

    /** @return Collection<int, Season> */
    private function seasonSummaries(CatalogTitle $title, ?User $user): Collection
    {
        return $this->resolvedSeasons ??= $this->playback->seasonSummaries($title, $user);
    }

    private function title(): CatalogTitle
    {
        return $this->resolvedTitle ??= $this->playback->visibleTitle($this->catalogTitleId, $this->user());
    }

    private function resolvedWatchableEpisode(CatalogTitle $title, ?User $user, int $episodeId): ?Episode
    {
        if ($this->resolvedEpisode?->id === $episodeId) {
            return $this->resolvedEpisode;
        }

        return $this->resolvedEpisode = $this->playback->watchableEpisode($title, $user, $episodeId);
    }

    private function authenticatedUser(): User
    {
        $user = $this->user();

        abort_unless($user instanceof User, 403);

        return $user;
    }

    private function user(): ?User
    {
        $user = Auth::user();

        return $user instanceof User ? $user : null;
    }

    private function positiveId(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value > 0 ? $value : null;
        }

        return is_string($value) && ctype_digit($value) && (int) $value > 0 ? (int) $value : null;
    }

    private function nonNegativeInteger(mixed $value, int $minimum = 0): ?int
    {
        if (! is_int($value) && (! is_string($value) || ! ctype_digit($value))) {
            return null;
        }

        $value = (int) $value;

        return $value >= $minimum ? $value : null;
    }

    private function hasUrlValue(string|int|null $value): bool
    {
        return $value !== null && $value !== '';
    }
}
