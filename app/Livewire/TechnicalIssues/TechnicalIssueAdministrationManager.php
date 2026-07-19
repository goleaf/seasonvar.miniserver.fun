<?php

declare(strict_types=1);

namespace App\Livewire\TechnicalIssues;

use App\Actions\TechnicalIssues\TechnicalIssueWorkflow;
use App\Enums\AdminPermission;
use App\Enums\TechnicalIssuePriority;
use App\Enums\TechnicalIssueSeverity;
use App\Enums\TechnicalIssueSort;
use App\Enums\TechnicalIssueStatus;
use App\Enums\TechnicalIssueTargetType;
use App\Enums\TechnicalIssueType;
use App\Exceptions\TechnicalIssues\TechnicalIssueActionException;
use App\Livewire\Concerns\InteractsWithPaginationIslands;
use App\Models\TechnicalIssue;
use App\Models\User;
use App\Services\Admin\AdminEligibleUserQuery;
use App\Services\TechnicalIssues\TechnicalIssueQuery;
use App\Services\TechnicalIssues\TechnicalIssueSchema;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Contracts\View\View;
use Illuminate\Pagination\LengthAwarePaginator as Paginator;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;
use Throwable;

final class TechnicalIssueAdministrationManager extends Component
{
    use InteractsWithPaginationIslands;
    use WithPagination;

    #[Url(as: 'q', history: true, except: '')]
    public string $search = '';

    #[Locked]
    public string $issueLocale = 'ru';

    #[Url(history: true, except: '')]
    public string $status = '';

    #[Url(history: true, except: '')]
    public string $type = '';

    #[Url(history: true, except: '')]
    public string $severity = '';

    #[Url(history: true, except: '')]
    public string $priority = '';

    #[Url(history: true, except: '')]
    public string $team = '';

    #[Url(history: true, except: '')]
    public string $targetType = '';

    #[Url(history: true, except: '')]
    public string $assignment = '';

    #[Url(history: true, except: '')]
    public string $sourceHealth = '';

    #[Url(history: true, except: 'priority')]
    public string $sort = 'priority';

    /** @var list<int> */
    public array $selectedIssues = [];

    /** @var list<int> */
    #[Locked]
    public array $visibleIssueIds = [];

    public string $bulkPriority = 'normal';

    public string $bulkTeam = 'support';

    public string $bulkAssigneeId = '';

    public bool $bulkConfirmed = false;

    public ?string $statusMessage = null;

    public ?string $actionError = null;

    public function mount(): void
    {
        Gate::authorize('manage-technical-issues');
        $this->issueLocale = app()->getLocale();
    }

    public function hydrate(): void
    {
        if (in_array($this->issueLocale, config('technical-issues.supported_locales', []), true)) {
            app()->setLocale($this->issueLocale);
        }
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['search', 'status', 'type', 'severity', 'priority', 'team', 'targetType', 'assignment', 'sourceHealth', 'sort'], true)) {
            $this->normalize();
            $this->selectedIssues = [];
            $this->bulkConfirmed = false;
            $this->resetPage(pageName: 'supportIssuePage');
        }
    }

    public function clearFilters(): void
    {
        $this->reset('search', 'status', 'type', 'severity', 'priority', 'team', 'targetType', 'assignment', 'sourceHealth');
        $this->sort = TechnicalIssueSort::Priority->value;
        $this->selectedIssues = [];
        $this->resetPage(pageName: 'supportIssuePage');
    }

    public function applyBulkPriority(TechnicalIssueWorkflow $workflow): void
    {
        $priority = TechnicalIssuePriority::tryFrom($this->bulkPriority);

        if ($priority === null) {
            $this->actionError = __('issues.errors.invalid_classification');

            return;
        }

        $this->bulk(function (User $user, TechnicalIssue $issue) use ($workflow, $priority): void {
            $workflow->classify($user, $issue, $issue->severity, $priority);
        });
    }

    public function applyBulkAssignment(TechnicalIssueWorkflow $workflow): void
    {
        $assignee = filter_var($this->bulkAssigneeId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $this->bulk(function (User $user, TechnicalIssue $issue) use ($workflow, $assignee): void {
            $workflow->assign($user, $issue, is_int($assignee) ? $assignee : null, $this->bulkTeam);
        });
    }

    public function render(TechnicalIssueQuery $query, TechnicalIssueSchema $schema, AdminEligibleUserQuery $eligibleAdministrators): View
    {
        Gate::authorize('manage-technical-issues');
        $this->normalize();
        $user = auth()->user();
        abort_unless($user instanceof User, 403);
        $issues = $this->emptyPaginator();
        $counts = [];

        if ($schema->ready()) {
            try {
                $issues = $query->support(
                    $user,
                    TechnicalIssueStatus::tryFrom($this->status),
                    TechnicalIssueType::tryFrom($this->type),
                    $this->severity,
                    $this->priority,
                    $this->team,
                    TechnicalIssueTargetType::tryFrom($this->targetType),
                    $this->assignment,
                    $this->sourceHealth,
                    $this->search,
                    TechnicalIssueSort::tryFrom($this->sort) ?? TechnicalIssueSort::Priority,
                );
                $counts = $query->supportCounts($user);
            } catch (Throwable $exception) {
                report($exception);
                $this->actionError = __('issues.errors.query_failed');
            }
        }

        $visibleIds = collect($issues->items())->map(fn ($issue): int => $issue->id)->all();
        $this->visibleIssueIds = $visibleIds;
        $this->selectedIssues = array_values(array_intersect($this->selectedIssues, $visibleIds));
        $assignees = $eligibleAdministrators->forPermission(AdminPermission::TicketsSupport)->orderBy('name')->get(['id', 'name']);

        return view('livewire.technical-issues.administration-manager', [
            'issues' => $issues,
            'schemaReady' => $schema->ready(),
            'counts' => $counts,
            'statusOptions' => $this->options(TechnicalIssueStatus::cases()),
            'typeOptions' => $this->options(TechnicalIssueType::cases()),
            'severityOptions' => $this->options(TechnicalIssueSeverity::cases()),
            'priorityOptions' => $this->options(TechnicalIssuePriority::cases()),
            'targetOptions' => $this->options(TechnicalIssueTargetType::cases()),
            'sortOptions' => $this->options(TechnicalIssueSort::cases()),
            'teamOptions' => collect((array) config('technical-issues.support_teams', []))->map(fn (string $team): array => ['value' => $team, 'label' => __('issues.support_teams.'.$team)])->all(),
            'assigneeOptions' => $assignees->map(fn (User $agent): array => ['value' => $agent->id, 'label' => $agent->name])->all(),
            'sourceHealthOptions' => collect(['active', 'degraded', 'unavailable', 'disabled'])->map(fn (string $state): array => ['value' => $state, 'label' => __('issues.source_health.'.$state)])->all(),
        ])->extends('layouts.app', [
            'title' => __('issues.support_queue'),
            'seo' => ['title' => __('issues.support_queue'), 'description' => __('issues.admin.description'), 'robots' => 'noindex, nofollow, noarchive', 'canonical' => route('admin.issues'), 'social' => false, 'alternates' => [], 'jsonLd' => []],
        ])->section('content');
    }

    private function bulk(callable $operation): void
    {
        $user = auth()->user();
        abort_unless($user instanceof User, 403);
        Gate::forUser($user)->authorize('manage-technical-issues');
        $ids = array_values(array_intersect(
            array_unique(array_filter(array_map('intval', $this->selectedIssues), static fn (int $id): bool => $id > 0)),
            $this->visibleIssueIds,
        ));

        if (! $this->bulkConfirmed || $ids === [] || count($ids) > 10) {
            $this->actionError = __('issues.errors.bulk_confirmation');

            return;
        }

        $succeeded = 0;
        $failed = 0;

        foreach (TechnicalIssue::query()->whereKey($ids)->orderBy('id')->get() as $issue) {
            try {
                $operation($user, $issue);
                $succeeded++;
            } catch (TechnicalIssueActionException|AuthorizationException) {
                $failed++;
            } catch (Throwable $exception) {
                report($exception);
                $failed++;
            }
        }

        $this->selectedIssues = [];
        $this->bulkConfirmed = false;
        $this->statusMessage = __('issues.admin.bulk_result', ['succeeded' => $succeeded, 'failed' => $failed]);
        $this->actionError = null;
    }

    private function normalize(): void
    {
        $this->search = Str::limit(Str::squish($this->search), 120, '');
        $this->status = in_array($this->status, TechnicalIssueStatus::values(), true) ? $this->status : '';
        $this->type = in_array($this->type, TechnicalIssueType::values(), true) ? $this->type : '';
        $this->severity = in_array($this->severity, array_column(TechnicalIssueSeverity::cases(), 'value'), true) ? $this->severity : '';
        $this->priority = in_array($this->priority, array_column(TechnicalIssuePriority::cases(), 'value'), true) ? $this->priority : '';
        $this->team = in_array($this->team, config('technical-issues.support_teams', []), true) ? $this->team : '';
        $this->targetType = in_array($this->targetType, array_column(TechnicalIssueTargetType::cases(), 'value'), true) ? $this->targetType : '';
        $this->assignment = in_array($this->assignment, ['', 'unassigned', 'mine'], true) ? $this->assignment : '';
        $this->sourceHealth = in_array($this->sourceHealth, ['', 'active', 'degraded', 'unavailable', 'disabled'], true) ? $this->sourceHealth : '';
        $this->sort = in_array($this->sort, array_column(TechnicalIssueSort::cases(), 'value'), true)
            ? $this->sort
            : TechnicalIssueSort::Priority->value;
    }

    /**
     * @param  array<int, mixed>  $values
     * @return list<array{value: string, label: string}>
     */
    private function options(array $values): array
    {
        return array_map(static fn ($value): array => ['value' => $value->value, 'label' => $value->label()], $values);
    }

    /** @return LengthAwarePaginator<int, mixed> */
    private function emptyPaginator(): LengthAwarePaginator
    {
        return new Paginator([], 0, max(1, (int) config('technical-issues.support_per_page', 20)), max(1, Paginator::resolveCurrentPage('supportIssuePage')), ['path' => request()->url(), 'query' => request()->query(), 'pageName' => 'supportIssuePage']);
    }
}
