<?php

declare(strict_types=1);

namespace App\Livewire\ReleaseCalendar;

use App\Enums\ReleaseCalendarFeedScope;
use App\Models\CatalogCollection;
use App\Models\CatalogTitle;
use App\Models\ReleaseCalendarFeed;
use App\Models\User;
use App\Services\ReleaseCalendar\ReleaseCalendarFeedManagementQuery;
use App\Services\ReleaseCalendar\ReleaseCalendarFeedService;
use App\Services\ReleaseCalendar\ReleaseCalendarFeedUrl;
use App\Services\ReleaseCalendar\ReleaseCalendarSchema;
use App\Support\PlainText;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Component;

final class ReleaseCalendarFeedManager extends Component
{
    public string $scope = 'all';

    public string $collectionPublicId = '';

    public string $titleSearch = '';

    #[Locked]
    public ?int $selectedTitleId = null;

    public string $languageCode = '';

    public string $translationName = '';

    #[Locked]
    public string $locale = 'ru';

    public string $notice = '';

    public function mount(string $locale = 'ru'): void
    {
        $this->user();
        $this->locale = in_array($locale, (array) config('release-calendar.supported_locales', []), true)
            ? $locale
            : 'ru';
    }

    public function updatedScope(): void
    {
        $this->collectionPublicId = '';
        $this->titleSearch = '';
        $this->selectedTitleId = null;
        $this->languageCode = '';
        $this->translationName = '';
        $this->notice = '';
        $this->resetValidation();
    }

    public function updatedTitleSearch(): void
    {
        $this->titleSearch = PlainText::clean($this->titleSearch, 80);
        $this->selectedTitleId = null;
        $this->notice = '';
    }

    public function selectTitle(int $catalogTitleId, ReleaseCalendarFeedManagementQuery $query): void
    {
        $title = $query->title($this->user(), $catalogTitleId);
        $this->selectedTitleId = $title->id;
        $this->titleSearch = $title->display_title;
        $this->resetValidation('selectedTitleId');
    }

    public function clearTitle(): void
    {
        $this->selectedTitleId = null;
        $this->titleSearch = '';
    }

    public function createFeed(
        ReleaseCalendarFeedManagementQuery $query,
        ReleaseCalendarFeedService $feeds,
    ): void {
        $validated = $this->validate([
            'scope' => ['required', Rule::enum(ReleaseCalendarFeedScope::class)],
            'collectionPublicId' => ['nullable', 'uuid'],
            'selectedTitleId' => ['nullable', 'integer', 'min:1'],
            'languageCode' => ['nullable', 'string', 'max:16'],
            'translationName' => ['nullable', 'string', 'max:120'],
            'locale' => ['required', Rule::in((array) config('release-calendar.supported_locales', []))],
        ], [
            'scope.*' => __('calendar.feeds.errors.invalid_scope'),
            'collectionPublicId.*' => __('calendar.feeds.errors.collection'),
            'selectedTitleId.*' => __('calendar.feeds.errors.title'),
            'languageCode.*' => __('calendar.feeds.errors.language'),
            'translationName.*' => __('calendar.feeds.errors.translation'),
            'locale.*' => __('calendar.feeds.errors.locale'),
        ]);
        $user = $this->user();
        $this->limitMutation($user);
        $scope = ReleaseCalendarFeedScope::from($validated['scope']);
        $collection = $validated['collectionPublicId'] !== ''
            ? $query->collection($user, $validated['collectionPublicId'])
            : null;
        $title = $this->selectedTitleId !== null
            ? $query->title($user, $this->selectedTitleId)
            : null;

        $feeds->create(
            $user,
            $scope,
            $this->locale,
            $collection,
            $title,
            $validated['languageCode'],
            $validated['translationName'],
        );
        $this->resetForm();
        $this->notice = __('calendar.feeds.created');
    }

    public function regenerateFeed(string $publicId, ReleaseCalendarFeedService $feeds): void
    {
        $user = $this->user();
        $this->abortUnlessOwned($user, $publicId);
        $this->limitMutation($user);
        $feeds->regenerate($user, $publicId);
        $this->notice = __('calendar.feeds.regenerated');
    }

    public function deleteFeed(string $publicId, ReleaseCalendarFeedService $feeds): void
    {
        $user = $this->user();
        $this->abortUnlessOwned($user, $publicId);
        $this->limitMutation($user);
        $feeds->delete($user, $publicId);
        $this->notice = __('calendar.feeds.deleted');
    }

    public function render(
        ReleaseCalendarSchema $schema,
        ReleaseCalendarFeedManagementQuery $query,
        ReleaseCalendarFeedUrl $urls,
    ): View {
        $user = $this->user();
        $ready = $schema->feedsReady();
        $feeds = $ready ? $query->feeds($user) : new Collection;
        $selectedTitle = $this->selectedTitleId !== null
            ? $query->title($user, $this->selectedTitleId)
            : null;

        return view('livewire.release-calendar.release-calendar-feed-manager', [
            'feedsReady' => $ready,
            'scopeOptions' => array_map(
                static fn (ReleaseCalendarFeedScope $scope): array => [
                    'value' => $scope->value,
                    'label' => $scope->label(),
                ],
                ReleaseCalendarFeedScope::cases(),
            ),
            'collections' => $ready ? $query->collections($user) : new Collection,
            'titleSuggestions' => $ready && $this->selectedTitleId === null
                ? $query->titles($user, $this->titleSearch)
                : new Collection,
            'selectedTitle' => $selectedTitle,
            'feedRows' => $feeds->map(fn (ReleaseCalendarFeed $feed): array => [
                'publicId' => $feed->public_id,
                'label' => $this->feedLabel($feed),
                'details' => $this->feedDetails($feed),
                'privateUrl' => $urls->private($feed),
                'appleUrl' => $urls->apple($feed),
                'googleUrl' => $urls->google(),
                'rotatedAt' => $feed->token_rotated_at
                    ->locale($this->locale)
                    ->isoFormat('LLL'),
            ])->all(),
        ]);
    }

    private function resetForm(): void
    {
        $this->scope = ReleaseCalendarFeedScope::All->value;
        $this->collectionPublicId = '';
        $this->titleSearch = '';
        $this->selectedTitleId = null;
        $this->languageCode = '';
        $this->translationName = '';
        $this->resetValidation();
    }

    private function user(): User
    {
        $user = auth()->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }

    private function limitMutation(User $user): void
    {
        if (! RateLimiter::attempt(
            'release-calendar-feed-mutation:'.$user->id,
            max(1, (int) config('release-calendar.rate_limits.feed_mutations_per_minute', 15)),
            static fn (): bool => true,
            60,
        )) {
            throw ValidationException::withMessages([
                'scope' => [__('calendar.feeds.errors.rate_limited')],
            ]);
        }
    }

    private function abortUnlessOwned(User $user, string $publicId): void
    {
        abort_unless(
            ReleaseCalendarFeed::query()
                ->whereBelongsTo($user)
                ->where('public_id', $publicId)
                ->exists(),
            404,
        );
    }

    private function feedLabel(ReleaseCalendarFeed $feed): string
    {
        return match ($feed->scope) {
            ReleaseCalendarFeedScope::Collection => __('calendar.feeds.labels.collection', [
                'name' => $this->collectionName($feed),
            ]),
            ReleaseCalendarFeedScope::Title => __('calendar.feeds.labels.title', [
                'title' => $this->titleName($feed),
            ]),
            default => $feed->scope->label(),
        };
    }

    private function feedDetails(ReleaseCalendarFeed $feed): string
    {
        $parts = array_filter([
            $feed->catalogTitle?->display_title,
            $feed->translation_name,
            $feed->language_code,
        ], static fn (mixed $value): bool => is_string($value) && $value !== '');

        return implode(' · ', $parts);
    }

    private function collectionName(ReleaseCalendarFeed $feed): string
    {
        $collection = $feed->getRelation('catalogCollection');

        return $collection instanceof CatalogCollection
            ? $collection->display_name
            : __('calendar.feeds.missing_target');
    }

    private function titleName(ReleaseCalendarFeed $feed): string
    {
        $title = $feed->getRelation('catalogTitle');

        return $title instanceof CatalogTitle
            ? $title->display_title
            : __('calendar.feeds.missing_target');
    }
}
