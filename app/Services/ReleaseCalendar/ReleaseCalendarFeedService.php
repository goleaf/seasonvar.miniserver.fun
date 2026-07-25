<?php

declare(strict_types=1);

namespace App\Services\ReleaseCalendar;

use App\Enums\ReleaseCalendarFeedScope;
use App\Models\CatalogCollection;
use App\Models\CatalogTitle;
use App\Models\ReleaseCalendarFeed;
use App\Models\User;
use App\Support\PlainText;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final readonly class ReleaseCalendarFeedService
{
    public function __construct(private ReleaseCalendarFeedToken $tokens) {}

    public function create(
        User $user,
        ReleaseCalendarFeedScope $scope,
        string $locale,
        ?CatalogCollection $collection = null,
        ?CatalogTitle $title = null,
        ?string $languageCode = null,
        ?string $translationName = null,
    ): ReleaseCalendarFeed {
        Gate::forUser($user)->authorize('create', ReleaseCalendarFeed::class);

        if (! in_array($locale, (array) config('release-calendar.supported_locales', []), true)) {
            throw ValidationException::withMessages(['feedLocale' => [__('calendar.feeds.errors.locale')]]);
        }

        $languageCode = $this->languageCode($languageCode);
        $translationName = $this->translationName($translationName);
        $this->assertScope($user, $scope, $collection, $title, $languageCode, $translationName);

        return DB::transaction(function () use (
            $user,
            $scope,
            $locale,
            $collection,
            $title,
            $languageCode,
            $translationName,
        ): ReleaseCalendarFeed {
            User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();

            if (ReleaseCalendarFeed::query()->where('user_id', $user->id)->count()
                >= max(1, (int) config('release-calendar.feeds.max_per_user', 10))) {
                throw ValidationException::withMessages(['feedScope' => [__('calendar.feeds.errors.limit')]]);
            }

            $secret = $this->tokens->generate();

            return ReleaseCalendarFeed::query()->create([
                'user_id' => $user->id,
                'catalog_collection_id' => $collection?->id,
                'catalog_title_id' => $title?->id,
                'scope' => $scope,
                'token_hash' => $this->tokens->hash($secret),
                'token_secret' => $secret,
                'language_code' => $languageCode,
                'translation_name' => $translationName,
                'locale' => $locale,
                'token_rotated_at' => now(),
            ]);
        }, attempts: 3);
    }

    public function regenerate(User $user, string $publicId): ReleaseCalendarFeed
    {
        return DB::transaction(function () use ($user, $publicId): ReleaseCalendarFeed {
            $feed = ReleaseCalendarFeed::query()
                ->where('user_id', $user->id)
                ->where('public_id', $publicId)
                ->lockForUpdate()
                ->firstOrFail();
            Gate::forUser($user)->authorize('update', $feed);
            $secret = $this->tokens->generate();
            $feed->forceFill([
                'token_hash' => $this->tokens->hash($secret),
                'token_secret' => $secret,
                'version' => $feed->version + 1,
                'token_rotated_at' => now(),
            ])->save();

            return $feed->refresh();
        }, attempts: 3);
    }

    public function delete(User $user, string $publicId): void
    {
        DB::transaction(function () use ($user, $publicId): void {
            $feed = ReleaseCalendarFeed::query()
                ->where('user_id', $user->id)
                ->where('public_id', $publicId)
                ->lockForUpdate()
                ->firstOrFail();
            Gate::forUser($user)->authorize('delete', $feed);
            $feed->delete();
        }, attempts: 3);
    }

    private function languageCode(?string $languageCode): ?string
    {
        $normalized = Str::lower(PlainText::clean($languageCode, 16));

        if ($normalized === '') {
            return null;
        }

        if (preg_match('/^[a-z]{2,3}(?:-[a-z0-9]{2,8})*$/', $normalized) !== 1) {
            throw ValidationException::withMessages([
                'feedLanguageCode' => [__('calendar.feeds.errors.language')],
            ]);
        }

        return $normalized;
    }

    private function translationName(?string $translationName): ?string
    {
        $normalized = PlainText::clean($translationName, 120);

        return $normalized !== '' ? $normalized : null;
    }

    private function assertScope(
        User $user,
        ReleaseCalendarFeedScope $scope,
        ?CatalogCollection $collection,
        ?CatalogTitle $title,
        ?string $languageCode,
        ?string $translationName,
    ): void {
        $valid = match ($scope) {
            ReleaseCalendarFeedScope::All,
            ReleaseCalendarFeedScope::NewEpisodes,
            ReleaseCalendarFeedScope::SeasonPremieres => $collection === null
                && $title === null
                && $languageCode === null
                && $translationName === null,
            ReleaseCalendarFeedScope::Collection => $collection !== null
                && $collection->owner_id === $user->id
                && ! $collection->trashed()
                && $title === null
                && $languageCode === null
                && $translationName === null,
            ReleaseCalendarFeedScope::Title => $collection === null
                && $title !== null
                && $this->titleIsAvailable($user, $title)
                && $languageCode === null
                && $translationName === null,
            ReleaseCalendarFeedScope::Translation => $collection === null
                && $translationName !== null
                && ($title === null || $this->titleIsAvailable($user, $title)),
            ReleaseCalendarFeedScope::Subtitles => $collection === null
                && $languageCode !== null
                && $translationName === null
                && ($title === null || $this->titleIsAvailable($user, $title)),
        };

        if (! $valid) {
            throw ValidationException::withMessages([
                $scope === ReleaseCalendarFeedScope::Collection ? 'feedCollection' : 'feedScope' => [
                    __('calendar.feeds.errors.invalid_scope'),
                ],
            ]);
        }
    }

    private function titleIsAvailable(User $user, CatalogTitle $title): bool
    {
        return CatalogTitle::query()
            ->availableTo($user)
            ->whereKey($title->id)
            ->exists();
    }
}
