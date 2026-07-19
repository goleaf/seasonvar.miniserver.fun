<?php

declare(strict_types=1);

namespace App\Livewire\ContentRequests;

use App\Enums\ContentRequestSort;
use App\Enums\ContentRequestStatus;
use App\Livewire\Concerns\InteractsWithPaginationIslands;
use App\Models\User;
use App\Services\ContentRequests\ContentRequestQuery;
use App\Services\ContentRequests\ContentRequestSchema;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Contracts\View\View;
use Illuminate\Pagination\LengthAwarePaginator as Paginator;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

final class MyContentRequestsPage extends Component
{
    use InteractsWithPaginationIslands;
    use WithPagination;

    #[Url(history: true, except: 'created')]
    public string $scope = 'created';

    #[Url(history: true, except: '')]
    public string $status = '';

    #[Url(history: true, except: 'recently_updated')]
    public string $sort = 'recently_updated';

    public function updated(string $property): void
    {
        if (in_array($property, ['scope', 'status', 'sort'], true)) {
            $this->normalize();
            $this->resetPage(pageName: 'myRequestsPage');
        }
    }

    public function render(ContentRequestQuery $query, ContentRequestSchema $schema): View
    {
        $this->normalize();
        $user = auth()->user();
        abort_unless($user instanceof User, 403);
        $requests = $schema->ready()
            ? $query->mine($user, $this->scope, ContentRequestStatus::tryFrom($this->status), ContentRequestSort::tryFrom($this->sort) ?? ContentRequestSort::RecentlyUpdated)
            : $this->emptyPaginator();

        return view('livewire.content-requests.mine-page', [
            'requests' => $requests,
            'schemaReady' => $schema->ready(),
            'scopeOptions' => collect(['created', 'voted', 'followed'])->map(fn (string $value): array => ['value' => $value, 'label' => __('requests.mine.scopes.'.$value)])->all(),
            'statusOptions' => collect(ContentRequestStatus::cases())->map(fn (ContentRequestStatus $status): array => ['value' => $status->value, 'label' => $status->label()])->all(),
            'sortOptions' => collect(ContentRequestSort::cases())->map(fn (ContentRequestSort $sort): array => ['value' => $sort->value, 'label' => $sort->label()])->all(),
            'createUrl' => route('requests.create'),
        ])->extends('layouts.app', [
            'title' => __('requests.mine.title'),
            'seo' => ['title' => __('requests.mine.title'), 'description' => __('requests.mine.description'), 'robots' => 'noindex, nofollow', 'canonical' => route('requests.mine')],
        ])->section('content');
    }

    private function normalize(): void
    {
        $this->scope = in_array($this->scope, ['created', 'voted', 'followed'], true) ? $this->scope : 'created';
        $this->status = ContentRequestStatus::tryFrom($this->status)?->value ?? '';
        $this->sort = ContentRequestSort::tryFrom($this->sort)?->value ?? ContentRequestSort::RecentlyUpdated->value;
    }

    /** @return LengthAwarePaginator<int, mixed> */
    private function emptyPaginator(): LengthAwarePaginator
    {
        return new Paginator(
            [],
            0,
            max(1, (int) config('content-requests.per_page', 20)),
            max(1, Paginator::resolveCurrentPage('myRequestsPage')),
            ['path' => request()->url(), 'query' => request()->query(), 'pageName' => 'myRequestsPage'],
        );
    }
}
