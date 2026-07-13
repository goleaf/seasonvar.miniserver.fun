<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Models\User;
use App\Services\Seasonvar\SeasonvarImportAdminService;
use App\Services\Security\SensitiveActionRateLimiter;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Livewire\Component;

final class SeasonvarImportManager extends Component
{
    public bool $force = false;

    public bool $discover = true;

    public ?string $notice = null;

    protected SeasonvarImportAdminService $imports;

    protected SensitiveActionRateLimiter $rateLimits;

    public function boot(SeasonvarImportAdminService $imports, SensitiveActionRateLimiter $rateLimits): void
    {
        $this->imports = $imports;
        $this->rateLimits = $rateLimits;
    }

    public function mount(): void
    {
        Gate::authorize('manage-seasonvar-imports');
    }

    public function startImport(): void
    {
        $this->rateLimits->enforce('import_admin', $this->user());
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
            ? 'Запуск #'.$result->run->id.' поставлен в очередь.'
            : 'Активный запуск #'.$result->run->id.' уже существует.';
    }

    public function retryImport(mixed $runId): void
    {
        $runId = $this->positiveId($runId);

        if ($runId === null) {
            return;
        }

        $this->rateLimits->enforce('import_admin', $this->user(), $runId);
        $result = $this->imports->retry($this->user(), $runId);
        $this->notice = $result->created
            ? 'Повторный запуск #'.$result->run->id.' поставлен в очередь.'
            : 'Активный запуск #'.$result->run->id.' уже существует.';
    }

    public function cancelImport(mixed $runId): void
    {
        $runId = $this->positiveId($runId);

        if ($runId === null) {
            return;
        }

        $this->rateLimits->enforce('import_admin', $this->user(), $runId);
        $run = $this->imports->cancel($this->user(), $runId);
        $this->notice = $run->status === 'cancelled'
            ? 'Запуск #'.$run->id.' отменён.'
            : 'Запуск #'.$run->id.' уже завершён.';
    }

    public function recoverStaleImports(): void
    {
        $this->rateLimits->enforce('import_admin', $this->user());
        Gate::forUser($this->user())->authorize('manage-seasonvar-imports');
        $count = $this->imports->recoverStale();
        $this->notice = $count > 0
            ? 'Зависших запусков закрыто: '.$count.'.'
            : 'Зависшие запуски не найдены.';
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
        ])->extends('layouts.app', [
            'title' => 'Импорт Seasonvar',
            'seo' => [
                'title' => 'Импорт Seasonvar',
                'description' => 'Служебное управление очередью обновления каталога.',
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
