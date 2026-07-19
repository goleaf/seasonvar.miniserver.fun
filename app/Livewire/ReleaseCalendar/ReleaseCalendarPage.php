<?php

declare(strict_types=1);

namespace App\Livewire\ReleaseCalendar;

use App\Actions\ReleaseCalendar\SetReleaseCalendarSubscription;
use App\Enums\ReleaseCalendarSort;
use App\Enums\ReleaseCalendarView;
use App\Enums\ReleaseScheduleEntryType;
use App\Enums\ReleaseScheduleStatus;
use App\Models\CatalogTitle;
use App\Services\Auth\AccountSettingsService;
use App\Services\ReleaseCalendar\ReleaseCalendarPeriod;
use App\Services\ReleaseCalendar\ReleaseCalendarQuery;
use App\Services\ReleaseCalendar\ReleaseCalendarSchema;
use App\Services\ReleaseCalendar\ReleaseCalendarSeoPresenter;
use App\Services\ReleaseCalendar\ReleaseCalendarTimezone;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Contracts\View\View;
use Illuminate\Pagination\LengthAwarePaginator as Paginator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;
use Throwable;

final class ReleaseCalendarPage extends Component
{
    use WithPagination;

    #[Locked]
    public string $view = 'upcoming';

    #[Locked]
    public ?string $period = null;

    #[Locked]
    public ?string $locale = null;

    #[Url(history: true, except: '')]
    public string $type = '';

    #[Url(history: true, except: '')]
    public string $status = '';

    #[Url(history: true, except: 'earliest')]
    public string $sort = 'earliest';

    #[Url(as: 'title', history: true, except: '')]
    public string $catalogTitle = '';

    public bool $queryFailed = false;

    public string $notice = '';

    public function mount(string $view = 'upcoming', ?string $period = null, ?string $locale = null): void
    {
        $this->view = ReleaseCalendarView::tryFrom($view)?->value ?? ReleaseCalendarView::Upcoming->value;
        $this->period = $period;
        $this->locale = is_string($locale) && in_array($locale, (array) config('release-calendar.supported_locales', []), true)
            ? $locale
            : null;

        if ($this->view === ReleaseCalendarView::Personal->value && ! Auth::check()) {
            abort(403);
        }
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['type', 'status', 'sort', 'catalogTitle'], true)) {
            $this->normalize();
            $this->resetPage(pageName: 'calendarPage');
        }
    }

    public function clearFilters(): void
    {
        $this->reset('type', 'status', 'catalogTitle');
        $this->sort = ReleaseCalendarSort::Earliest->value;
        $this->resetPage(pageName: 'calendarPage');
    }

    public function toggleSubscription(int $catalogTitleId, bool $enabled, SetReleaseCalendarSubscription $subscriptions): void
    {
        $user = Auth::user();

        if ($user === null) {
            abort(403);
        }

        if (! RateLimiter::attempt(
            'release-calendar-subscription:'.$user->id,
            max(1, (int) config('release-calendar.rate_limits.subscription_per_minute', 30)),
            fn (): bool => true,
            60,
        )) {
            throw ValidationException::withMessages(['subscription' => [__('calendar.errors.rate_limited')]]);
        }

        $title = CatalogTitle::query()->availableTo($user)->findOrFail($catalogTitleId);
        $subscriptions->handle($user, $title, $enabled);
        $this->notice = $enabled ? __('calendar.follow.enabled') : __('calendar.follow.disabled');
    }

    public function render(
        ReleaseCalendarQuery $query,
        ReleaseCalendarSchema $schema,
        ReleaseCalendarSeoPresenter $seo,
        ReleaseCalendarTimezone $calendarTimezone,
        AccountSettingsService $settings,
    ): View {
        $this->normalize();
        $calendarView = ReleaseCalendarView::from($this->view);
        $user = Auth::user();
        $account = $settings->resolve($user);
        $timezone = $user === null ? $calendarTimezone->public() : $account->timezone;
        $locale = $this->locale ?? $account->locale;
        $entries = $this->emptyPaginator();
        $monthGrid = null;
        $this->queryFailed = false;

        try {
            $period = ReleaseCalendarPeriod::resolve($calendarView, $this->period, $timezone);

            if ($schema->ready()) {
                $entries = $query->entries(
                    $user,
                    $calendarView,
                    $period,
                    ReleaseScheduleEntryType::tryFrom($this->type),
                    ReleaseScheduleStatus::tryFrom($this->status),
                    ReleaseCalendarSort::from($this->sort),
                    $locale,
                    $timezone,
                    $this->selectedCatalogTitleId(),
                );

                if ($calendarView === ReleaseCalendarView::Month) {
                    $monthGrid = $this->monthGrid(
                        $period,
                        $query->monthCounts(
                            $user,
                            $period,
                            $timezone,
                            ReleaseScheduleEntryType::tryFrom($this->type),
                            ReleaseScheduleStatus::tryFrom($this->status),
                            $this->selectedCatalogTitleId(),
                        ),
                        $locale,
                        $timezone,
                    );
                }
            }
        } catch (ValidationException) {
            abort(404);
        } catch (Throwable $exception) {
            report($exception);
            $this->queryFailed = true;
            $period = ReleaseCalendarPeriod::resolve(ReleaseCalendarView::Upcoming, null, $timezone);
        }

        return view('livewire.release-calendar.release-calendar-page', [
            'entries' => $entries,
            'entryGroups' => $entries->getCollection()->groupBy(fn ($entry): string => $entry->groupLabel),
            'schemaReady' => $schema->ready(),
            'calendarView' => $calendarView,
            'timezone' => $timezone,
            'periodLabel' => $this->periodLabel($calendarView, $period, $locale, $timezone),
            'typeOptions' => $this->enumOptions(ReleaseScheduleEntryType::cases()),
            'statusOptions' => $this->enumOptions(ReleaseScheduleStatus::cases()),
            'sortOptions' => $this->enumOptions(ReleaseCalendarSort::cases()),
            'viewUrls' => $this->viewUrls($timezone),
            'previousUrl' => $this->adjacentUrl($calendarView, $period, -1),
            'nextUrl' => $this->adjacentUrl($calendarView, $period, 1),
            'todayUrl' => $this->calendarUrl(ReleaseCalendarView::Day, CarbonImmutable::now($timezone)->format('Y-m-d')),
            'settingsUrl' => Auth::check() ? route('settings.index', ['section' => 'notifications']) : null,
            'monthGrid' => $monthGrid,
        ])->extends('layouts.app', [
            'title' => __('calendar.title'),
            'seo' => $seo->page(
                $calendarView,
                $this->period,
                request()->query() !== []
                    || $this->type !== ''
                    || $this->status !== ''
                    || $this->sort !== 'earliest'
                    || $this->catalogTitle !== ''
                    || $entries->currentPage() > 1,
                $this->locale,
                $entries->getCollection(),
            ),
        ])->section('content');
    }

    private function normalize(): void
    {
        $this->type = ReleaseScheduleEntryType::tryFrom($this->type)?->value ?? '';
        $this->status = ReleaseScheduleStatus::tryFrom($this->status)?->value ?? '';
        $this->sort = ReleaseCalendarSort::tryFrom($this->sort)?->value ?? ReleaseCalendarSort::Earliest->value;
        $this->catalogTitle = ctype_digit($this->catalogTitle) && (int) $this->catalogTitle > 0
            ? (string) (int) $this->catalogTitle
            : '';
    }

    private function selectedCatalogTitleId(): ?int
    {
        return $this->catalogTitle !== '' ? (int) $this->catalogTitle : null;
    }

    /** @param array<int, ReleaseScheduleEntryType|ReleaseScheduleStatus|ReleaseCalendarSort> $cases
     * @return list<array{value: string, label: string}>
     */
    private function enumOptions(array $cases): array
    {
        return array_map(static fn ($case): array => ['value' => $case->value, 'label' => $case->label()], $cases);
    }

    /** @return array<string, string> */
    private function viewUrls(string $timezone): array
    {
        $now = CarbonImmutable::now($timezone);

        return [
            'upcoming' => $this->calendarUrl(ReleaseCalendarView::Upcoming),
            'day' => $this->calendarUrl(ReleaseCalendarView::Day, $now->format('Y-m-d')),
            'week' => $this->calendarUrl(ReleaseCalendarView::Week, $now->format('o-\\WW')),
            'month' => $this->calendarUrl(ReleaseCalendarView::Month, $now->format('Y-m')),
            'recent' => $this->calendarUrl(ReleaseCalendarView::Recent),
            'personal' => Auth::check() ? $this->calendarUrl(ReleaseCalendarView::Personal) : route('login'),
        ];
    }

    private function adjacentUrl(ReleaseCalendarView $view, ReleaseCalendarPeriod $period, int $direction): ?string
    {
        $value = match ($view) {
            ReleaseCalendarView::Day => $period->start->addDays($direction)->format('Y-m-d'),
            ReleaseCalendarView::Week => $period->start->addWeeks($direction)->format('o-\\WW'),
            ReleaseCalendarView::Month => $period->start->addMonthsNoOverflow($direction)->format('Y-m'),
            default => null,
        };

        return $value !== null ? $this->calendarUrl($view, $value) : null;
    }

    private function calendarUrl(ReleaseCalendarView $view, ?string $period = null): string
    {
        $baseName = match ($view) {
            ReleaseCalendarView::Upcoming => 'calendar.upcoming',
            ReleaseCalendarView::Day => 'calendar.day',
            ReleaseCalendarView::Week => 'calendar.week',
            ReleaseCalendarView::Month => 'calendar.month',
            ReleaseCalendarView::Recent => 'calendar.index',
            ReleaseCalendarView::Personal => 'calendar.mine',
        };
        $parameters = $period !== null ? ['period' => $period] : [];

        if ($this->locale !== null) {
            $parameters['locale'] = $this->locale;

            return route('localized.'.$baseName, $parameters);
        }

        return route($baseName, $parameters);
    }

    private function periodLabel(ReleaseCalendarView $view, ReleaseCalendarPeriod $period, string $locale, string $timezone): string
    {
        return match ($view) {
            ReleaseCalendarView::Day => $period->start->locale($locale)->isoFormat('LL'),
            ReleaseCalendarView::Week => __('calendar.period.week', [
                'from' => $period->start->locale($locale)->isoFormat('D MMMM'),
                'to' => $period->end->locale($locale)->isoFormat('D MMMM YYYY'),
            ]),
            ReleaseCalendarView::Month => $period->start->locale($locale)->isoFormat('MMMM YYYY'),
            default => $view->label(),
        };
    }

    /** @param array<string, int> $counts
     * @return array{weekdays: list<string>, weeks: list<list<array{date: string, number: int, label: string, url: string, count: int, current: bool, today: bool}>>}
     */
    private function monthGrid(ReleaseCalendarPeriod $period, array $counts, string $locale, string $timezone): array
    {
        $weekStart = min(6, max(0, (int) config('release-calendar.week_start', 1)));
        $gridStart = $period->start;

        while ($gridStart->dayOfWeek !== $weekStart) {
            $gridStart = $gridStart->subDay();
        }

        $weekEnd = ($weekStart + 6) % 7;
        $gridEnd = $period->end;

        while ($gridEnd->dayOfWeek !== $weekEnd) {
            $gridEnd = $gridEnd->addDay();
        }

        $weekdays = [];
        $cursor = $gridStart;

        for ($day = 0; $day < 7; $day++) {
            $weekdays[] = $cursor->locale($locale)->isoFormat('dd');
            $cursor = $cursor->addDay();
        }

        $weeks = [];
        $week = [];
        $today = CarbonImmutable::now($timezone)->toDateString();

        for ($date = $gridStart; $date->lessThanOrEqualTo($gridEnd); $date = $date->addDay()) {
            $key = $date->toDateString();
            $week[] = [
                'date' => $key,
                'number' => $date->day,
                'label' => $date->locale($locale)->isoFormat('D MMMM YYYY'),
                'url' => $this->calendarUrl(ReleaseCalendarView::Day, $key),
                'count' => $counts[$key] ?? 0,
                'current' => $date->month === $period->start->month,
                'today' => $key === $today,
            ];

            if (count($week) === 7) {
                $weeks[] = $week;
                $week = [];
            }
        }

        return ['weekdays' => $weekdays, 'weeks' => $weeks];
    }

    /** @return LengthAwarePaginator<int, mixed> */
    private function emptyPaginator(): LengthAwarePaginator
    {
        return new Paginator([], 0, max(1, (int) config('release-calendar.per_page', 24)), max(1, Paginator::resolveCurrentPage('calendarPage')), [
            'path' => request()->url(), 'query' => request()->query(), 'pageName' => 'calendarPage',
        ]);
    }
}
