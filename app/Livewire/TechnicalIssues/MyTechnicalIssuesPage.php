<?php

declare(strict_types=1);

namespace App\Livewire\TechnicalIssues;

use App\Enums\TechnicalIssueSort;
use App\Enums\TechnicalIssueStatus;
use App\Enums\TechnicalIssueType;
use App\Models\User;
use App\Services\TechnicalIssues\TechnicalIssueQuery;
use App\Services\TechnicalIssues\TechnicalIssueSchema;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Contracts\View\View;
use Illuminate\Pagination\LengthAwarePaginator as Paginator;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Str;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;
use Throwable;

final class MyTechnicalIssuesPage extends Component
{
    use WithPagination;

    #[Locked]
    public string $issueLocale = 'ru';

    #[Url(as: 'q', history: true, except: '')]
    public string $search = '';

    #[Url(history: true, except: 'created')]
    public string $scope = 'created';

    #[Url(history: true, except: '')]
    public string $status = '';

    #[Url(history: true, except: '')]
    public string $type = '';

    #[Url(history: true, except: 'recently_updated')]
    public string $sort = 'recently_updated';

    public function mount(): void
    {
        $this->issueLocale = App::getLocale();
    }

    public function hydrate(): void
    {
        if (in_array($this->issueLocale, config('technical-issues.supported_locales', []), true)) {
            App::setLocale($this->issueLocale);
        }
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['search', 'scope', 'status', 'type', 'sort'], true)) {
            $this->normalize();
            $this->resetPage(pageName: 'issuePage');
        }
    }

    public function clearFilters(): void
    {
        $this->reset('search', 'status', 'type');
        $this->scope = 'created';
        $this->sort = TechnicalIssueSort::RecentlyUpdated->value;
        $this->resetPage(pageName: 'issuePage');
    }

    public function render(TechnicalIssueQuery $query, TechnicalIssueSchema $schema): View
    {
        $this->normalize();
        $user = auth()->user();
        abort_unless($user instanceof User, 403);
        $issues = $this->emptyPaginator();
        $counts = ['created' => 0, 'waiting' => 0, 'followed' => 0, 'confirmed' => 0];

        if ($schema->ready()) {
            try {
                $issues = $query->mine(
                    $user,
                    $this->scope,
                    TechnicalIssueStatus::tryFrom($this->status),
                    TechnicalIssueType::tryFrom($this->type),
                    $this->search,
                    TechnicalIssueSort::tryFrom($this->sort) ?? TechnicalIssueSort::RecentlyUpdated,
                );
                $counts = $query->mineCounts($user);
            } catch (Throwable $exception) {
                report($exception);
                session()->now('technical_issue_query_error', __('issues.errors.query_failed'));
            }
        }

        return view('livewire.technical-issues.mine-page', [
            'issues' => $issues,
            'schemaReady' => $schema->ready(),
            'scopeOptions' => collect(['created', 'waiting', 'followed', 'confirmed'])->map(fn (string $value): array => ['value' => $value, 'label' => __('issues.scopes.'.$value), 'count' => $counts[$value]])->all(),
            'statusOptions' => collect(TechnicalIssueStatus::cases())->map(fn (TechnicalIssueStatus $status): array => ['value' => $status->value, 'label' => $status->label()])->all(),
            'typeOptions' => collect(TechnicalIssueType::cases())->map(fn (TechnicalIssueType $type): array => ['value' => $type->value, 'label' => $type->label()])->all(),
            'sortOptions' => collect(TechnicalIssueSort::cases())->reject(fn (TechnicalIssueSort $sort): bool => in_array($sort, [TechnicalIssueSort::Priority, TechnicalIssueSort::Severity], true))->map(fn (TechnicalIssueSort $sort): array => ['value' => $sort->value, 'label' => $sort->label()])->values()->all(),
            'createUrl' => $this->createUrl(),
        ])->extends('layouts.app', [
            'title' => __('issues.mine.title'),
            'seo' => [
                'title' => __('issues.mine.title'),
                'description' => __('issues.mine.description'),
                'robots' => 'noindex, nofollow, noarchive',
                'canonical' => $this->mineUrl(),
                'social' => false,
                'alternates' => [],
                'jsonLd' => [],
            ],
        ])->section('content');
    }

    private function normalize(): void
    {
        $this->search = Str::limit(Str::squish($this->search), 120, '');
        $this->scope = in_array($this->scope, ['created', 'waiting', 'followed', 'confirmed'], true) ? $this->scope : 'created';
        $this->status = in_array($this->status, TechnicalIssueStatus::values(), true) ? $this->status : '';
        $this->type = in_array($this->type, TechnicalIssueType::values(), true) ? $this->type : '';
        $sort = TechnicalIssueSort::tryFrom($this->sort);
        $this->sort = $sort !== null && ! in_array($sort, [TechnicalIssueSort::Priority, TechnicalIssueSort::Severity], true)
            ? $sort->value
            : TechnicalIssueSort::RecentlyUpdated->value;
    }

    /** @return LengthAwarePaginator<int, mixed> */
    private function emptyPaginator(): LengthAwarePaginator
    {
        return new Paginator([], 0, max(1, (int) config('technical-issues.per_page', 12)), max(1, Paginator::resolveCurrentPage('issuePage')), [
            'path' => request()->url(), 'query' => request()->query(), 'pageName' => 'issuePage',
        ]);
    }

    private function createUrl(): string
    {
        return in_array(App::getLocale(), config('technical-issues.supported_locales', []), true)
            ? route('localized.issues.create', ['locale' => App::getLocale()])
            : route('issues.create');
    }

    private function mineUrl(): string
    {
        return in_array(App::getLocale(), config('technical-issues.supported_locales', []), true)
            ? route('localized.issues.mine', ['locale' => App::getLocale()])
            : route('issues.mine');
    }
}
