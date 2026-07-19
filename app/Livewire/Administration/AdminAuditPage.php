<?php

declare(strict_types=1);

namespace App\Livewire\Administration;

use App\Enums\AdminAuditAction;
use App\Enums\AdminPermission;
use App\Livewire\Concerns\InteractsWithPaginationIslands;
use App\Services\Admin\AdminAccessResolver;
use App\Services\Admin\AdminAuditCsvExporter;
use App\Services\Admin\AdminAuditQuery;
use App\Support\Administration\AdminTableState;
use Illuminate\Contracts\View\View;
use Illuminate\Pagination\LengthAwarePaginator as Paginator;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

final class AdminAuditPage extends Component
{
    use InteractsWithPaginationIslands;
    use WithPagination;

    #[Url(history: true)]
    public string $action = '';

    #[Url(history: true)]
    public string $resource = '';

    #[Url(history: true)]
    public string $from = '';

    #[Url(history: true)]
    public string $to = '';

    #[Url(history: true)]
    public string $direction = 'desc';

    #[Url(history: true)]
    public int $perPage = 25;

    public function mount(): void
    {
        Gate::authorize(AdminPermission::AuditView->value);
    }

    public function updated(): void
    {
        $this->resetPage('auditPage');
    }

    public function export(AdminAuditQuery $query, AdminAuditCsvExporter $exporter): StreamedResponse
    {
        Gate::authorize(AdminPermission::AuditExport->value);

        return $exporter->response($query->export($this->state()));
    }

    public function render(AdminAuditQuery $query, AdminAccessResolver $access): View
    {
        Gate::authorize(AdminPermission::AuditView->value);
        $user = request()->user();
        abort_if($user === null, 401);

        $state = $this->state();
        $queryFailed = false;

        try {
            $events = $query->paginate($state);
        } catch (Throwable $exception) {
            report($exception);
            $queryFailed = true;
            $events = new Paginator([], 0, $state->perPage, $state->page, [
                'path' => request()->url(),
                'query' => request()->query(),
                'pageName' => 'auditPage',
            ]);
        }

        return view('livewire.administration.audit', [
            'events' => $events,
            'queryFailed' => $queryFailed,
            'actions' => collect(AdminAuditAction::cases())->mapWithKeys(fn (AdminAuditAction $action): array => [$action->value => $action->label()])->all(),
            'canExport' => $access->allows($user, AdminPermission::AuditExport),
            'activeFilterCount' => count(array_filter([$this->action, $this->resource, $this->from, $this->to])),
        ])->extends('layouts.app', [
            'title' => __('administration.audit.title'),
            'seo' => [
                'title' => __('administration.audit.title'),
                'description' => __('administration.audit.description'),
                'robots' => 'noindex,nofollow',
                'canonical' => route('admin.audit'),
                'alternates' => [],
                'social' => false,
                'jsonLd' => [],
            ],
        ])->section('content');
    }

    private function state(): AdminTableState
    {
        return AdminTableState::from(
            input: [
                'direction' => $this->direction,
                'page' => $this->getPage('auditPage'),
                'per_page' => $this->perPage,
                'filters' => ['action' => $this->action, 'resource' => $this->resource, 'from' => $this->from, 'to' => $this->to],
            ],
            sortColumns: ['occurred' => 'occurred_at'],
            defaultSort: 'occurred',
            filterCodes: ['action', 'resource', 'from', 'to'],
        );
    }
}
