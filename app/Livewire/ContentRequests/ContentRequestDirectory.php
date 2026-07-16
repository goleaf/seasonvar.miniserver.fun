<?php

declare(strict_types=1);

namespace App\Livewire\ContentRequests;

use App\Enums\ContentRequestSort;
use App\Enums\ContentRequestStatus;
use App\Enums\ContentRequestType;
use App\Services\ContentRequests\ContentRequestQuery;
use App\Services\ContentRequests\ContentRequestSchema;
use App\Services\ContentRequests\ContentRequestSeoPresenter;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Contracts\View\View;
use Illuminate\Pagination\LengthAwarePaginator as Paginator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;
use Throwable;

final class ContentRequestDirectory extends Component
{
    use WithPagination;

    #[Url(as: 'q', history: true, except: '')]
    public string $search = '';

    #[Url(history: true, except: '')]
    public string $type = '';

    #[Url(history: true, except: '')]
    public string $status = '';

    #[Url(history: true, except: 'most_voted')]
    public string $sort = 'most_voted';

    public bool $queryFailed = false;

    public function updated(string $property): void
    {
        if (in_array($property, ['search', 'type', 'status', 'sort'], true)) {
            $this->normalize();
            $this->resetPage(pageName: 'requestsPage');
        }
    }

    public function clearFilters(): void
    {
        $this->reset('search', 'type', 'status');
        $this->sort = 'most_voted';
        $this->resetPage(pageName: 'requestsPage');
    }

    public function render(ContentRequestQuery $query, ContentRequestSchema $schema, ContentRequestSeoPresenter $seo): View
    {
        $this->normalize();
        $requests = $this->emptyPaginator();
        $this->queryFailed = false;

        if ($schema->ready()) {
            try {
                $requests = $query->directory(
                    Auth::user(),
                    $this->search,
                    ContentRequestType::tryFrom($this->type),
                    ContentRequestStatus::tryFrom($this->status),
                    ContentRequestSort::tryFrom($this->sort) ?? ContentRequestSort::MostVoted,
                );
            } catch (Throwable $exception) {
                report($exception);
                $this->queryFailed = true;
            }
        }

        $locale = request()->route('locale');
        $localized = is_string($locale) ? $locale : null;

        return view('livewire.content-requests.directory', [
            'requests' => $requests,
            'schemaReady' => $schema->ready(),
            'typeOptions' => $this->enumOptions(ContentRequestType::cases()),
            'statusOptions' => $this->enumOptions(ContentRequestStatus::cases()),
            'sortOptions' => $this->enumOptions(ContentRequestSort::cases()),
            'createUrl' => Auth::check()
                ? ($localized !== null ? route('localized.requests.create', ['locale' => $localized]) : route('requests.create'))
                : route('login'),
            'mineUrl' => Auth::check()
                ? ($localized !== null ? route('localized.requests.mine', ['locale' => $localized]) : route('requests.mine'))
                : route('login'),
        ])->extends('layouts.app', [
            'title' => __('requests.directory.title'),
            'seo' => $seo->directory($this->search !== '' || $this->type !== '' || $this->status !== '' || $this->sort !== 'most_voted', $localized),
        ])->section('content');
    }

    private function normalize(): void
    {
        $this->search = Str::limit(Str::squish($this->search), 120, '');
        $this->type = ContentRequestType::tryFrom($this->type)?->value ?? '';
        $this->status = ContentRequestStatus::tryFrom($this->status)?->value ?? '';
        $this->sort = ContentRequestSort::tryFrom($this->sort)?->value ?? ContentRequestSort::MostVoted->value;
    }

    /** @param array<int, ContentRequestType|ContentRequestStatus|ContentRequestSort> $cases
     * @return list<array{value: string, label: string}>
     */
    private function enumOptions(array $cases): array
    {
        return array_map(static fn ($case): array => ['value' => $case->value, 'label' => $case->label()], $cases);
    }

    /** @return LengthAwarePaginator<int, mixed> */
    private function emptyPaginator(): LengthAwarePaginator
    {
        return new Paginator([], 0, max(1, (int) config('content-requests.per_page', 20)), pageName: 'requestsPage');
    }
}
