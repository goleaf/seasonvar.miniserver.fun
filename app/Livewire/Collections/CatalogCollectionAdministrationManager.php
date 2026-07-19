<?php

declare(strict_types=1);

namespace App\Livewire\Collections;

use App\Enums\CatalogCollectionModerationStatus;
use App\Enums\CatalogCollectionReportStatus;
use App\Enums\CatalogCollectionType;
use App\Enums\CatalogCollectionVisibility;
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

    #[Url(as: 'collection_admin_q', history: true, except: '')]
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
        $limit = max(1, min(500, (int) config('catalog-collections.report_resolution_batch_size', 100)));
        $reports = CatalogCollectionReport::query()
            ->whereBelongsTo($collection, 'collection')
            ->where('status', CatalogCollectionReportStatus::Open->value)
            ->orderBy('id')
            ->limit($limit)
            ->get();

        $reports->each(fn (CatalogCollectionReport $report) => $moderation->resolveReport(
            $actor,
            $report,
            CatalogCollectionReportStatus::Resolved,
        ));
        $hasMore = CatalogCollectionReport::query()
            ->whereBelongsTo($collection, 'collection')
            ->where('status', CatalogCollectionReportStatus::Open->value)
            ->exists();
        $this->notice = $hasMore
            ? __('collections.admin.report_batch_saved', ['count' => $limit])
            : __('collections.admin.saved');
    }

    public function render(CatalogCollectionQuery $collections): View
    {
        $paginator = $collections->moderationQueue($this->search);
        $sourceSyncSummary = $collections->latestSourceSyncSummary();

        if ($sourceSyncSummary !== null) {
            $sourceSyncSummary['status_label'] = __('collections.sync.status.'.$sourceSyncSummary['status']);
            $sourceSyncSummary['status_variant'] = match ($sourceSyncSummary['status']) {
                'completed' => 'success',
                'partial' => 'warning',
                default => 'muted',
            };
            $sourceSyncSummary['metrics'] = collect($sourceSyncSummary['counters'])
                ->map(fn (int $value, string $key): array => [
                    'label' => __('collections.sync.metrics.'.$key),
                    'value' => $value,
                ])
                ->values()
                ->all();
        }

        foreach ($paginator->getCollection() as $collection) {
            $totalItems = (int) ($collection->total_items_count ?? 0);
            $openReports = (int) ($collection->open_reports_count ?? 0);
            $collection->setAttribute('presentation_type_label', $collection->type->label());
            $collection->setAttribute('presentation_visibility_label', $collection->visibility->label());
            $collection->setAttribute('presentation_moderation_label', $collection->moderation_status->label());
            $collection->setAttribute(
                'presentation_owner_label',
                $collection->owner?->name ?: __('collections.admin.system_owner'),
            );
            $collection->setAttribute('presentation_items_label', trans_choice(
                'collections.page.items',
                $totalItems,
                ['count' => $totalItems],
            ));
            $collection->setAttribute(
                'presentation_open_reports_label',
                __('collections.admin.open_reports', ['count' => $openReports]),
            );
            $collection->setAttribute('presentation_deleted', $collection->trashed());
            $collection->setAttribute('presentation_has_open_reports', $openReports > 0);
            $collection->setAttribute(
                'presentation_can_feature',
                $collection->type === CatalogCollectionType::Editorial
                    && $collection->visibility === CatalogCollectionVisibility::Public
                    && $collection->moderation_status === CatalogCollectionModerationStatus::Approved,
            );
            $collection->setAttribute('presentation_feature_next', $collection->is_featured ? 'false' : 'true');
            $collection->setAttribute(
                'presentation_feature_label',
                $collection->is_featured ? __('collections.admin.unfeature') : __('collections.admin.feature'),
            );
        }

        return view('livewire.collections.catalog-collection-administration-manager', [
            'collections' => $paginator,
            'sourceSyncSummary' => $sourceSyncSummary,
        ]);
    }

    private function user(): User
    {
        $user = auth()->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
