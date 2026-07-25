<?php

declare(strict_types=1);

namespace App\Services\ReleaseCalendar;

use App\Enums\ReleaseDatePrecision;
use App\Enums\ReleaseScheduleStatus;
use App\Models\ReleaseCalendarFeed;
use App\Models\ReleaseScheduleEntry;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Lang;

final class ReleaseCalendarIcsRenderer
{
    /** @param Collection<int, ReleaseScheduleEntry> $entries */
    public function render(ReleaseCalendarFeed $feed, Collection $entries): string
    {
        $host = parse_url((string) config('app.url'), PHP_URL_HOST) ?: 'seasonvar.local';
        $lines = [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//Seasonvar//Private Release Calendar//RU',
            'CALSCALE:GREGORIAN',
            'METHOD:PUBLISH',
            'X-WR-CALNAME:'.$this->escape(
                Lang::get('calendar.feeds.calendar_name', ['scope' => $feed->scope->label()], $feed->locale),
            ),
            'REFRESH-INTERVAL;VALUE=DURATION:PT6H',
            'X-PUBLISHED-TTL:PT6H',
        ];

        foreach ($entries as $entry) {
            array_push($lines, ...$this->event($entry, $feed->locale, $host));
        }

        $lines[] = 'END:VCALENDAR';

        return implode("\r\n", array_map($this->fold(...), $lines))."\r\n";
    }

    /** @return list<string> */
    private function event(ReleaseScheduleEntry $entry, string $locale, string $host): array
    {
        $updatedAt = $entry->updated_at?->utc()->format('Ymd\THis\Z') ?? now('UTC')->format('Ymd\THis\Z');
        $context = array_filter([
            $entry->season_number !== null
                ? Lang::get('calendar.season_number', ['number' => $entry->season_number], $locale)
                : null,
            $entry->episode_number !== null
                ? Lang::get('calendar.episode_number', ['number' => $entry->episode_number], $locale)
                : null,
            $entry->translation_name,
            $entry->language_code,
        ], is_string(...));
        $summary = $entry->catalogTitle->display_title.' — '
            .Lang::get('calendar.types.'.$entry->entry_type->value, [], $locale);
        $lines = [
            'BEGIN:VEVENT',
            'UID:'.$entry->public_id.'@'.$host,
            'DTSTAMP:'.$updatedAt,
            'LAST-MODIFIED:'.$updatedAt,
            'SEQUENCE:'.$entry->revision,
            ...$this->dates($entry),
            'SUMMARY:'.$this->escape($summary),
            'DESCRIPTION:'.$this->escape(implode(' · ', $context)),
            'URL:'.$this->titleUrl($entry),
            'STATUS:'.$this->status($entry->status),
            'TRANSP:TRANSPARENT',
            'END:VEVENT',
        ];

        return $lines;
    }

    /** @return list<string> */
    private function dates(ReleaseScheduleEntry $entry): array
    {
        return match ($entry->precision) {
            ReleaseDatePrecision::ExactDateTime => [
                'DTSTART:'.$entry->starts_at->utc()->format('Ymd\THis\Z'),
            ],
            ReleaseDatePrecision::ExactDate => [
                'DTSTART;VALUE=DATE:'.$entry->date_value->format('Ymd'),
                'DTEND;VALUE=DATE:'.$entry->date_value->addDay()->format('Ymd'),
            ],
            ReleaseDatePrecision::DateRange => [
                'DTSTART;VALUE=DATE:'.$entry->date_value->format('Ymd'),
                'DTEND;VALUE=DATE:'.$entry->date_end->addDay()->format('Ymd'),
            ],
            default => [],
        };
    }

    private function titleUrl(ReleaseScheduleEntry $entry): string
    {
        return rtrim((string) config('app.url'), '/')
            .route('titles.show', ['catalogTitle' => $entry->catalogTitle->slug], absolute: false);
    }

    private function status(ReleaseScheduleStatus $status): string
    {
        return match ($status) {
            ReleaseScheduleStatus::Cancelled => 'CANCELLED',
            ReleaseScheduleStatus::Confirmed, ReleaseScheduleStatus::Released => 'CONFIRMED',
            default => 'TENTATIVE',
        };
    }

    private function escape(string $value): string
    {
        return str_replace(
            ['\\', "\r\n", "\n", "\r", ';', ','],
            ['\\\\', '\\n', '\\n', '\\n', '\\;', '\\,'],
            $value,
        );
    }

    private function fold(string $line): string
    {
        $folded = [];
        $buffer = '';
        $limit = 75;

        foreach (mb_str_split($line) as $character) {
            if ($buffer !== '' && strlen($buffer.$character) > $limit) {
                $folded[] = $buffer;
                $buffer = ' '.$character;
                $limit = 75;

                continue;
            }

            $buffer .= $character;
        }

        $folded[] = $buffer;

        return implode("\r\n", $folded);
    }
}
