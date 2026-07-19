<?php

declare(strict_types=1);

namespace App\Livewire\ReleaseCalendar;

use App\Enums\ReleaseDatePrecision;
use App\Enums\ReleaseScheduleEntryType;
use App\Enums\ReleaseScheduleSource;
use App\Enums\ReleaseScheduleStatus;
use App\Livewire\Concerns\InteractsWithPaginationIslands;
use App\Models\CatalogTitle;
use App\Models\Episode;
use App\Models\LicensedMedia;
use App\Models\ReleaseScheduleCorrection;
use App\Models\ReleaseScheduleEntry;
use App\Models\Season;
use App\Services\ReleaseCalendar\ReleaseCalendarSchema;
use App\Services\ReleaseCalendar\ReleaseScheduleService;
use App\Support\PlainText;
use App\ValueObjects\AccountTimezone;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Livewire\Component;
use Livewire\WithPagination;

final class ReleaseCalendarAdministrationManager extends Component
{
    use InteractsWithPaginationIslands;
    use WithPagination;

    public ?string $editingPublicId = null;

    public string $titleSearch = '';

    public ?int $catalogTitleId = null;

    public ?int $seasonId = null;

    public ?int $episodeId = null;

    public ?int $licensedMediaId = null;

    public string $entryType = 'episode_release';

    public string $status = 'scheduled';

    public string $precision = 'unknown';

    public string $source = 'editorial';

    public ?string $startsAt = null;

    public ?string $dateValue = null;

    public ?string $dateEnd = null;

    public ?int $releaseYear = null;

    public ?int $releaseMonth = null;

    public ?int $releaseQuarter = null;

    public string $originalTimezone = 'UTC';

    public ?string $languageCode = null;

    public ?string $translationName = null;

    public ?string $sourceReference = null;

    public ?string $reasonCode = null;

    public ?string $publicNote = null;

    public ?string $privateNote = null;

    public bool $isEstimated = false;

    public bool $isLocked = true;

    public bool $isPublic = true;

    public bool $notificationsEnabled = true;

    public string $notice = '';

    public function mount(): void
    {
        Gate::authorize('manage-release-calendar');
    }

    public function updatedCatalogTitleId(): void
    {
        $this->reset('seasonId', 'episodeId', 'licensedMediaId');
    }

    public function updatedSeasonId(): void
    {
        $this->reset('episodeId', 'licensedMediaId');
    }

    public function updatedEpisodeId(): void
    {
        $this->reset('licensedMediaId');
    }

    public function save(ReleaseScheduleService $schedules): void
    {
        Gate::authorize('manage-release-calendar');
        $rateKey = 'release-calendar-administration:'.Auth::id();

        if (! RateLimiter::attempt($rateKey, max(1, (int) config('release-calendar.rate_limits.administration_per_minute', 60)), fn (): bool => true, 60)) {
            throw ValidationException::withMessages(['entryType' => [__('calendar.errors.rate_limited')]]);
        }

        $validated = $this->validate([
            'catalogTitleId' => ['required', 'integer', 'exists:catalog_titles,id'],
            'seasonId' => ['nullable', 'integer', 'exists:seasons,id'],
            'episodeId' => ['nullable', 'integer', 'exists:episodes,id'],
            'licensedMediaId' => ['nullable', 'integer', 'exists:licensed_media,id'],
            'entryType' => ['required', Rule::enum(ReleaseScheduleEntryType::class)],
            'status' => ['required', Rule::enum(ReleaseScheduleStatus::class)],
            'precision' => ['required', Rule::enum(ReleaseDatePrecision::class)],
            'source' => ['required', Rule::enum(ReleaseScheduleSource::class)],
            'startsAt' => ['nullable', 'date'],
            'dateValue' => ['nullable', 'date_format:Y-m-d'],
            'dateEnd' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:dateValue'],
            'releaseYear' => ['nullable', 'integer', 'between:1900,2200'],
            'releaseMonth' => ['nullable', 'integer', 'between:1,12'],
            'releaseQuarter' => ['nullable', 'integer', 'between:1,4'],
            'originalTimezone' => ['required', Rule::in(AccountTimezone::identifiers())],
            'languageCode' => ['nullable', 'string', 'max:16', 'regex:/\A[a-zA-Z]{2,3}(?:-[a-zA-Z0-9]{2,8})*\z/'],
            'translationName' => ['nullable', 'string', 'max:120'],
            'sourceReference' => ['nullable', 'string', 'max:191'],
            'reasonCode' => ['nullable', 'string', 'max:48', 'regex:/\A[a-z0-9][a-z0-9_:-]*\z/'],
            'publicNote' => ['nullable', 'string', 'max:2000'],
            'privateNote' => ['nullable', 'string', 'max:4000'],
            'isEstimated' => ['boolean'],
            'isLocked' => ['boolean'],
            'isPublic' => ['boolean'],
            'notificationsEnabled' => ['boolean'],
        ]);

        $entry = $this->editingPublicId !== null
            ? ReleaseScheduleEntry::query()->where('public_id', $this->editingPublicId)->firstOrFail()
            : null;

        $actor = Auth::user();

        if ($actor === null) {
            abort(403);
        }

        try {
            $schedules->save($entry, [
                'catalog_title_id' => $validated['catalogTitleId'],
                'season_id' => $validated['seasonId'],
                'episode_id' => $validated['episodeId'],
                'licensed_media_id' => $validated['licensedMediaId'],
                'entry_type' => $validated['entryType'],
                'status' => $validated['status'],
                'precision' => $validated['precision'],
                'source' => $validated['source'],
                'starts_at' => $validated['startsAt'],
                'date_value' => $validated['dateValue'],
                'date_end' => $validated['dateEnd'],
                'release_year' => $validated['releaseYear'],
                'release_month' => $validated['releaseMonth'],
                'release_quarter' => $validated['releaseQuarter'],
                'original_timezone' => $validated['originalTimezone'],
                'language_code' => $validated['languageCode'],
                'translation_name' => $validated['translationName'],
                'source_reference' => $validated['sourceReference'],
                'reason_code' => $validated['reasonCode'],
                'public_note' => $validated['publicNote'],
                'private_note' => $validated['privateNote'],
                'is_estimated' => $validated['isEstimated'],
                'is_locked' => $validated['isLocked'],
                'is_public' => $validated['isPublic'],
                'notifications_enabled' => $validated['notificationsEnabled'],
            ], $actor);
        } catch (InvalidArgumentException) {
            $this->addError('dateValue', __('calendar.errors.invalid_date_representation'));

            return;
        }

        $this->resetForm();
        $this->notice = __('calendar.admin.saved');
        $this->resetPage(pageName: 'adminCalendarPage');
    }

    public function edit(string $publicId): void
    {
        Gate::authorize('manage-release-calendar');
        $entry = ReleaseScheduleEntry::query()->where('public_id', $publicId)->firstOrFail();

        $this->editingPublicId = $entry->public_id;
        $this->catalogTitleId = $entry->catalog_title_id;
        $this->seasonId = $entry->season_id;
        $this->episodeId = $entry->episode_id;
        $this->licensedMediaId = $entry->licensed_media_id;
        $this->entryType = $entry->entry_type->value;
        $this->status = $entry->status->value;
        $this->precision = $entry->precision->value;
        $this->source = $entry->source->value;
        $this->originalTimezone = $entry->original_timezone;
        $this->startsAt = $entry->starts_at?->setTimezone($entry->original_timezone)->format('Y-m-d\\TH:i');
        $this->dateValue = $entry->date_value?->toDateString();
        $this->dateEnd = $entry->date_end?->toDateString();
        $this->releaseYear = $entry->release_year;
        $this->releaseMonth = $entry->release_month;
        $this->releaseQuarter = $entry->release_quarter;
        $this->languageCode = $entry->language_code;
        $this->translationName = $entry->translation_name;
        $this->sourceReference = $entry->source_reference;
        $this->isEstimated = $entry->is_estimated;
        $this->isLocked = $entry->is_locked;
        $this->isPublic = $entry->is_public;
        $this->notificationsEnabled = $entry->notifications_enabled;
        $this->notice = '';
    }

    public function cancelEdit(): void
    {
        $this->resetForm();
    }

    public function render(ReleaseCalendarSchema $schema): View
    {
        Gate::authorize('manage-release-calendar');
        $titleSearch = str_replace(['%', '_'], '', PlainText::clean($this->titleSearch, 120));
        $titles = CatalogTitle::query()
            ->when($titleSearch !== '', fn ($query) => $query->where(fn ($query) => $query->where('title', 'like', '%'.$titleSearch.'%')->orWhere('original_title', 'like', '%'.$titleSearch.'%')))
            ->orderBy('title')->limit(30)->get(['id', 'title', 'original_title']);
        $seasons = $this->catalogTitleId !== null
            ? Season::query()->where('catalog_title_id', $this->catalogTitleId)->orderBy('sort_order')->orderBy('number')->get(['id', 'catalog_title_id', 'number', 'kind', 'title'])
            : collect();
        $episodes = $this->seasonId !== null
            ? Episode::query()->where('season_id', $this->seasonId)->orderBy('sort_order')->orderBy('number')->limit(500)->get(['id', 'season_id', 'number', 'kind', 'title'])
            : collect();
        $media = $this->catalogTitleId !== null
            ? LicensedMedia::query()->where('catalog_title_id', $this->catalogTitleId)
                ->when($this->seasonId !== null, fn ($query) => $query->where('season_id', $this->seasonId))
                ->when($this->episodeId !== null, fn ($query) => $query->where('episode_id', $this->episodeId))
                ->latest('id')->limit(100)->get(['id', 'title', 'quality', 'translation_name'])
            : collect();
        $entries = $schema->ready()
            ? ReleaseScheduleEntry::query()->with('catalogTitle:id,title,original_title')->latest('updated_at')->latest('id')->paginate(20, pageName: 'adminCalendarPage')
            : null;
        $corrections = $schema->ready() && $this->editingPublicId !== null
            ? ReleaseScheduleEntry::query()->where('public_id', $this->editingPublicId)->first()?->corrections()
                ->with('actor:id,name')->latest('revision')->limit(50)->get()
            : collect();

        return view('livewire.release-calendar.release-calendar-administration-manager', [
            'schemaReady' => $schema->ready(),
            'titles' => $titles,
            'seasons' => $seasons,
            'episodes' => $episodes,
            'mediaOptions' => $media,
            'entries' => $entries,
            'corrections' => $corrections,
            'typeOptions' => $this->enumOptions(ReleaseScheduleEntryType::cases()),
            'statusOptions' => $this->enumOptions(ReleaseScheduleStatus::cases()),
            'precisionOptions' => $this->enumOptions(ReleaseDatePrecision::cases()),
            'sourceOptions' => $this->enumOptions(ReleaseScheduleSource::cases()),
            'correctionPresentation' => $corrections
                ->mapWithKeys(fn (ReleaseScheduleCorrection $correction): array => [
                    $correction->id => [
                        'statusLabel' => $correction->new_status->label(),
                        'precisionLabel' => $correction->new_precision->label(),
                        'createdAt' => $correction->created_at?->toAtomString() ?? '',
                    ],
                ])->all(),
            'entryPresentation' => $entries?->getCollection()
                ->mapWithKeys(fn (ReleaseScheduleEntry $entry): array => [
                    $entry->id => [
                        'typeLabel' => $entry->entry_type->label(),
                        'statusLabel' => $entry->status->label(),
                        'precisionLabel' => $entry->precision->label(),
                    ],
                ])->all() ?? [],
        ])->extends('layouts.app', [
            'title' => __('calendar.admin.title'),
            'seo' => ['title' => __('calendar.admin.title'), 'description' => __('calendar.admin.description'), 'canonical' => route('admin.calendar'), 'robots' => 'noindex, nofollow'],
        ])->section('content');
    }

    private function resetForm(): void
    {
        $this->reset([
            'editingPublicId', 'titleSearch', 'catalogTitleId', 'seasonId', 'episodeId', 'licensedMediaId',
            'startsAt', 'dateValue', 'dateEnd', 'releaseYear', 'releaseMonth', 'releaseQuarter', 'languageCode',
            'translationName', 'sourceReference', 'reasonCode', 'publicNote', 'privateNote', 'notice',
        ]);
        $this->entryType = ReleaseScheduleEntryType::EpisodeRelease->value;
        $this->status = ReleaseScheduleStatus::Scheduled->value;
        $this->precision = ReleaseDatePrecision::Unknown->value;
        $this->source = ReleaseScheduleSource::Editorial->value;
        $this->originalTimezone = 'UTC';
        $this->isEstimated = false;
        $this->isLocked = true;
        $this->isPublic = true;
        $this->notificationsEnabled = true;
        $this->resetValidation();
    }

    /**
     * @param  array<int, ReleaseScheduleEntryType|ReleaseScheduleStatus|ReleaseDatePrecision|ReleaseScheduleSource>  $cases
     * @return list<array{value: string, label: string}>
     */
    private function enumOptions(array $cases): array
    {
        return array_map(static fn ($case): array => [
            'value' => $case->value,
            'label' => $case->label(),
        ], $cases);
    }
}
