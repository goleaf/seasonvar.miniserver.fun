<?php

declare(strict_types=1);

namespace App\Livewire\Profile;

use App\Models\User;
use App\Services\Profiles\UserProfileModerationService;
use Illuminate\Contracts\View\View;
use Illuminate\Database\QueryException;
use Livewire\Component;
use Livewire\WithPagination;
use Throwable;

final class UserProfileAdministrationManager extends Component
{
    use WithPagination;

    public string $privateNote = '';

    public ?string $notice = null;

    public ?string $actionError = null;

    public function moderate(string $reportPublicId, string $action, UserProfileModerationService $moderation): void
    {
        $this->validate(['privateNote' => ['nullable', 'string', 'max:2000']]);

        try {
            $moderation->apply($this->user(), $reportPublicId, $action, $this->privateNote);
            $this->reset('privateNote');
            $this->notice = __('profiles.admin.updated');
            $this->actionError = null;
            $this->resetPage(pageName: 'profileReportsPage');
        } catch (Throwable $exception) {
            report($exception);
            $this->actionError = __('profiles.errors.action_failed');
        }
    }

    public function render(UserProfileModerationService $moderation): View
    {
        try {
            $reports = $moderation->queue($this->user());
        } catch (QueryException $exception) {
            report($exception);
            $reports = null;
        }

        return view('livewire.profile.administration-manager', [
            'reports' => $reports,
        ])->extends('layouts.app', [
            'title' => __('profiles.admin.title'),
            'seo' => [
                'title' => __('profiles.admin.title'),
                'description' => __('profiles.admin.description'),
                'robots' => 'noindex, nofollow',
                'canonical' => route('admin.profiles'),
                'social' => false,
                'alternates' => [],
                'jsonLd' => [],
            ],
        ])->section('content');
    }

    private function user(): User
    {
        $user = auth()->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
