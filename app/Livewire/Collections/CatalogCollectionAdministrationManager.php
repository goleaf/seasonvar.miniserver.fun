<?php

declare(strict_types=1);

namespace App\Livewire\Collections;

use App\Enums\CatalogCollectionModerationStatus;
use App\Enums\CatalogCollectionReportStatus;
use App\Models\CatalogCollectionReport;
use App\Models\User;
use App\Services\Collections\CatalogCollectionModerationService;
use App\Services\Collections\CatalogCollectionQuery;
use App\Services\Collections\CatalogCollectionResolver;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Str;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

final class CatalogCollectionAdministrationManager extends Component
{
    use WithPagination;

    #[Url(as: 'q', history: true, except: '')]
    public string $search = '';

    public ?string $notice = null;

    public function updatedSearch(): void
    {
        $this->search = Str::limit(Str::squish($this->search), 100, '');
        $this->resetPage(pageName: 'collectionAdminPage');
    }

    public function moderate(
        string $publicId,
        string $status,
        CatalogCollectionResolver $resolver,
        CatalogCollectionModerationService $moderation,
    ): void {
        $normalized = CatalogCollectionModerationStatus::tryFrom($status);
        abort_unless($normalized !== null, 404);
        $moderation->moderate($this->user(), $resolver->byPublicId($publicId, true), $normalized);
        $this->notice = __('collections.admin.saved');
    }

    public function feature(
        string $publicId,
        bool $featured,
        CatalogCollectionResolver $resolver,
        CatalogCollectionModerationService $moderation,
    ): void {
        $moderation->feature($this->user(), $resolver->byPublicId($publicId), $featured);
        $this->notice = __('collections.admin.saved');
    }

    public function resolveReports(
        string $publicId,
        CatalogCollectionResolver $resolver,
        CatalogCollectionModerationService $moderation,
    ): void {
        $collection = $resolver->byPublicId($publicId, true);
        $actor = $this->user();
        CatalogCollectionReport::query()
            ->whereBelongsTo($collection, 'collection')
            ->where('status', CatalogCollectionReportStatus::Open->value)
            ->orderBy('id')
            ->eachById(fn (CatalogCollectionReport $report) => $moderation->resolveReport(
                $actor,
                $report,
                CatalogCollectionReportStatus::Resolved,
            ), 100);
        $this->notice = __('collections.admin.saved');
    }

    public function render(CatalogCollectionQuery $collections): View
    {
        return view('livewire.collections.catalog-collection-administration-manager', [
            'collections' => $collections->moderationQueue($this->search),
        ])->extends('layouts.app', [
            'title' => __('collections.admin.title'),
            'seo' => [
                'title' => __('collections.admin.title'),
                'description' => __('collections.admin.description'),
                'robots' => 'noindex,nofollow',
                'canonical' => route('admin.collections'),
                'alternates' => [],
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
