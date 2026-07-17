<?php

declare(strict_types=1);

namespace App\Services\ReleaseCalendar;

use App\DTOs\ReleaseCalendar\ReleaseScheduleCardData;
use App\Enums\ReleaseCalendarView;

final class ReleaseCalendarSeoPresenter
{
    /** @param iterable<int, ReleaseScheduleCardData> $items
     * @return array<string, mixed>
     */
    public function page(ReleaseCalendarView $view, ?string $period, bool $filtered, ?string $locale, iterable $items = []): array
    {
        $eligible = $view === ReleaseCalendarView::Upcoming && ! $filtered;
        $canonical = $this->url($view, $period, $locale);
        $locales = (array) config('release-calendar.supported_locales', ['ru']);
        $itemList = [];
        $seenUrls = [];

        if ($eligible) {
            foreach ($items as $item) {
                if (isset($seenUrls[$item->url])) {
                    continue;
                }

                $seenUrls[$item->url] = true;
                $itemList[] = [
                    '@type' => 'ListItem',
                    'position' => count($itemList) + 1,
                    'url' => $item->url,
                    'name' => $item->title,
                ];

                if (count($itemList) >= 20) {
                    break;
                }
            }
        }

        $indexable = $eligible && $itemList !== [];

        return [
            'title' => __('calendar.seo.title'),
            'description' => __('calendar.seo.description'),
            'canonical' => $canonical,
            'robots' => $indexable
                ? 'index, follow'
                : ($view === ReleaseCalendarView::Personal ? 'noindex, nofollow, noarchive' : 'noindex, follow'),
            'alternates' => $view === ReleaseCalendarView::Personal ? [] : collect($locales)->mapWithKeys(fn (string $value): array => [
                $value => $this->url($view, $period, $value),
            ])->all(),
            'jsonLd' => $itemList === [] ? [] : [[
                '@context' => 'https://schema.org',
                '@type' => 'ItemList',
                'name' => __('calendar.title'),
                'url' => $canonical,
                'itemListElement' => $itemList,
            ]],
        ];
    }

    private function url(ReleaseCalendarView $view, ?string $period, ?string $locale): string
    {
        $name = match ($view) {
            ReleaseCalendarView::Upcoming => 'calendar.upcoming',
            ReleaseCalendarView::Day => 'calendar.day',
            ReleaseCalendarView::Week => 'calendar.week',
            ReleaseCalendarView::Month => 'calendar.month',
            ReleaseCalendarView::Recent => 'calendar.recent',
            ReleaseCalendarView::Personal => 'calendar.mine',
        };
        $parameters = $period !== null ? ['period' => $period] : [];

        if ($locale !== null) {
            $parameters['locale'] = $locale;

            return route('localized.'.$name, $parameters);
        }

        return route($name, $parameters);
    }
}
