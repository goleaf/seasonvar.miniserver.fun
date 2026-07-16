<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Models\User;
use App\Services\Seasonvar\SeasonvarImportAdminService;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Livewire\Component;

final class SeasonvarImportManager extends Component
{
    public bool $force = false;

    public bool $discover = true;

    public ?string $notice = null;

    protected SeasonvarImportAdminService $imports;

    public function boot(SeasonvarImportAdminService $imports): void
    {
        $this->imports = $imports;
    }

    public function mount(): void
    {
        Gate::authorize('manage-seasonvar-imports');
    }

    public function startImport(): void
    {
        $validated = $this->validate([
            'force' => ['required', 'boolean'],
            'discover' => ['required', 'boolean'],
        ]);
        $result = $this->imports->start(
            $this->user(),
            force: (bool) $validated['force'],
            discover: (bool) $validated['discover'],
        );
        $this->notice = $result->created
            ? __('catalog.importer.queued', ['id' => $result->run->id])
            : __('catalog.importer.already_active', ['id' => $result->run->id]);
    }

    public function retryImport(mixed $runId): void
    {
        $runId = $this->positiveId($runId);

        if ($runId === null) {
            return;
        }

        $result = $this->imports->retry($this->user(), $runId);
        $this->notice = $result->created
            ? __('catalog.importer.retry_queued', ['id' => $result->run->id])
            : __('catalog.importer.already_active', ['id' => $result->run->id]);
    }

    public function cancelImport(mixed $runId): void
    {
        $runId = $this->positiveId($runId);

        if ($runId === null) {
            return;
        }

        $run = $this->imports->cancel($this->user(), $runId);
        $this->notice = $run->status === 'cancelled'
            ? __('catalog.importer.cancelled', ['id' => $run->id])
            : __('catalog.importer.already_finished', ['id' => $run->id]);
    }

    public function recoverStaleImports(): void
    {
        Gate::forUser($this->user())->authorize('manage-seasonvar-imports');
        $count = $this->imports->recoverStale();
        $this->notice = $count > 0
            ? __('catalog.importer.stale_recovered', ['count' => $count])
            : __('catalog.importer.stale_missing');
    }

    public function refreshRuns(): void
    {
        Gate::forUser($this->user())->authorize('manage-seasonvar-imports');
    }

    public function render(): View
    {
        Gate::forUser($this->user())->authorize('manage-seasonvar-imports');
        $dashboard = $this->imports->dashboard();

        return view('livewire.seasonvar-import-manager', [
            'runs' => $dashboard['runs'],
            'hasActiveRun' => $dashboard['has_active_run'],
            'staleCount' => $dashboard['stale_count'],
            'mediaHealth' => $dashboard['media_health'],
            'mediaDueCount' => $dashboard['media_due_count'],
            'mediaSizeBacklog' => $dashboard['media_size_backlog'],
        ])->extends('layouts.app', [
            'title' => __('catalog.importer.title'),
            'seo' => [
                'title' => __('catalog.importer.title'),
                'description' => __('catalog.importer.seo_description'),
                'robots' => 'noindex, nofollow',
                'canonical' => route('admin.imports'),
            ],
        ])->section('content');
    }

    private function user(): User
    {
        $user = auth()->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }

    private function positiveId(mixed $value): ?int
    {
        if (! is_int($value) && (! is_string($value) || ! ctype_digit($value))) {
            return null;
        }

        $value = (int) $value;

        return $value > 0 ? $value : null;
    }
}
