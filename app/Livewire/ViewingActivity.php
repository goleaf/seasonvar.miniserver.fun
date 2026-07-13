<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Models\User;
use App\Services\Catalog\CatalogViewingActivityQuery;
use App\Services\Catalog\CatalogViewingActivityService;
use Illuminate\Contracts\View\View;
use Livewire\Component;
use Livewire\WithPagination;

final class ViewingActivity extends Component
{
    use WithPagination;

    protected CatalogViewingActivityQuery $activity;

    protected CatalogViewingActivityService $actions;

    public function boot(
        CatalogViewingActivityQuery $activity,
        CatalogViewingActivityService $actions,
    ): void {
        $this->activity = $activity;
        $this->actions = $actions;
    }

    public function mount(): void
    {
        abort_unless(auth()->check(), 403);
    }

    public function removeHistoryItem(int $progressId): void
    {
        $this->actions->remove($this->user(), $progressId);
        $this->resetPage(pageName: 'historyPage');
    }

    public function clearHistory(): void
    {
        $this->actions->clear($this->user());
        $this->resetPage(pageName: 'historyPage');
    }

    public function render(): View
    {
        $user = $this->user();

        return view('livewire.viewing-activity', [
            'continueWatching' => $this->activity->continueWatching($user),
            'history' => $this->activity->history($user),
        ])->extends('layouts.app', [
            'title' => 'Мои просмотры',
            'seo' => [
                'title' => 'Мои просмотры',
                'description' => 'Личная история просмотра сериалов.',
                'robots' => 'noindex, nofollow',
                'canonical' => route('viewing-activity'),
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
