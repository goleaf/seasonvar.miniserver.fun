<?php

declare(strict_types=1);

namespace App\Services\ReleaseCalendar;

use App\Enums\ReleaseCalendarFeedScope;
use App\Models\ReleaseCalendarFeed;
use App\Services\Auth\AccountAccessResolver;
use Illuminate\Http\Response;

final readonly class ReleaseCalendarFeedResponder
{
    public function __construct(
        private ReleaseCalendarSchema $schema,
        private ReleaseCalendarFeedToken $tokens,
        private ReleaseCalendarFeedQuery $query,
        private ReleaseCalendarIcsRenderer $renderer,
        private AccountAccessResolver $accounts,
    ) {}

    public function response(string $privateToken): Response
    {
        abort_unless($this->schema->feedsReady(), 404);

        $feed = ReleaseCalendarFeed::query()
            ->with([
                'user:id,name',
                'catalogCollection:id,owner_id,mode,smart_rules,smart_rules_version',
                'catalogTitle:id,slug,title,original_title',
            ])
            ->where('token_hash', $this->tokens->hash($privateToken))
            ->firstOrFail();

        abort_unless($this->accounts->canAuthenticate($feed->user), 404);
        abort_unless($this->targetIsAvailable($feed), 404);

        return response($this->renderer->render($feed, $this->query->entries($feed)), 200, [
            'Content-Type' => 'text/calendar; charset=utf-8',
            'Content-Disposition' => 'inline; filename="seasonvar-calendar.ics"',
            'Cache-Control' => 'private, no-store, max-age=0',
            'Pragma' => 'no-cache',
            'X-Robots-Tag' => 'noindex, nofollow, noarchive',
            'Referrer-Policy' => 'no-referrer',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private function targetIsAvailable(ReleaseCalendarFeed $feed): bool
    {
        return match ($feed->scope) {
            ReleaseCalendarFeedScope::Collection => $feed->catalogCollection !== null
                && $feed->catalogCollection->owner_id === $feed->user_id,
            ReleaseCalendarFeedScope::Title => $feed->catalogTitle !== null,
            ReleaseCalendarFeedScope::Translation,
            ReleaseCalendarFeedScope::Subtitles => $feed->catalog_title_id === null
                || $feed->catalogTitle !== null,
            default => true,
        };
    }
}
