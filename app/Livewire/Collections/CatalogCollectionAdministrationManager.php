<?php

declare(strict_types=1);

namespace App\Livewire\Collections;

use App\Enums\AdminPermission;
use App\Enums\CatalogCollectionModerationStatus;
use App\Enums\CatalogCollectionReadinessReason;
use App\Enums\CatalogCollectionReportStatus;
use App\Livewire\Concerns\InteractsWithPaginationIslands;
use App\Models\CatalogCollectionQualityIssue;
use App\Models\CatalogCollectionReport;
use App\Models\User;
use App\Services\Collections\CatalogCollectionModerationService;
use App\Services\Collections\CatalogCollectionPublicationReadiness;
use App\Services\Collections\CatalogCollectionQuery;
use App\Services\Collections\CatalogCollectionResolver;
use App\Services\Collections\CatalogCollectionSchema;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Number;
use Illuminate\Support\Str;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

final class CatalogCollectionAdministrationManager extends Component
{
    use InteractsWithPaginationIslands;
    use WithPagination;

    /** @var array<string, int> */
    private const QUALITY_COMPONENT_MAXIMUMS = [
        'metadata' => 25,
        'structure' => 25,
        'theme' => 30,
        'trust' => 20,
    ];

    /** @var list<string> */
    private const QUALITY_SIGNAL_KEYS = [
        'saves',
        'completions',
        'returns',
        'reports',
    ];

    #[Url(as: 'collection_admin_q', history: true, except: '')]
    public string $search = '';

    #[Url(as: 'collection_quality', history: true, except: 'all')]
    public string $qualityFilter = 'all';

    public ?string $notice = null;

    public function updatedSearch(): void
    {
        $this->search = Str::limit(Str::squish($this->search), 100, '');
        $this->resetPage(pageName: 'collectionAdminPage');
    }

    public function updatedQualityFilter(): void
    {
        if (! array_key_exists($this->qualityFilter, $this->qualityFilterOptions())) {
            $this->qualityFilter = 'all';
        }

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

    public function verifyQuality(
        string $publicId,
        bool $verified,
        CatalogCollectionResolver $resolver,
        CatalogCollectionModerationService $moderation,
    ): void {
        $moderation->verifyQuality(
            $this->user(),
            $resolver->byPublicId($publicId),
            $verified,
        );
        $this->notice = $verified
            ? __('collections.admin.quality_verified')
            : __('collections.admin.quality_verification_removed');
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

        $reports->each(function (CatalogCollectionReport $report) use ($moderation, $actor): void {
            $moderation->resolveReport(
                $actor,
                $report,
                CatalogCollectionReportStatus::Resolved,
            );
        });
        $hasMore = CatalogCollectionReport::query()
            ->whereBelongsTo($collection, 'collection')
            ->where('status', CatalogCollectionReportStatus::Open->value)
            ->exists();
        $this->notice = $hasMore
            ? __('collections.admin.report_batch_saved', ['count' => $limit])
            : __('collections.admin.saved');
    }

    public function render(
        CatalogCollectionQuery $collections,
        CatalogCollectionPublicationReadiness $readiness,
        CatalogCollectionSchema $schema,
    ): View {
        $qualityAvailable = $schema->qualityAvailable();
        $paginator = $collections->moderationQueue($this->search, $this->qualityFilter);
        $sourceSyncSummary = $collections->latestSourceSyncSummary();
        $readinessByCollection = $readiness->evaluateMany($paginator->getCollection());

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
            $sourceSyncSummary['health_metrics'] = [
                [
                    'label' => __('collections.sync.health.empty_collections'),
                    'value' => $sourceSyncSummary['diagnostics']['empty_collections'],
                ],
                [
                    'label' => __('collections.sync.health.actionable_empty_collections'),
                    'value' => $sourceSyncSummary['diagnostics']['actionable_empty_collections'],
                ],
                [
                    'label' => __('collections.sync.health.unsupported_empty_collections'),
                    'value' => $sourceSyncSummary['diagnostics']['unsupported_empty_collections'],
                ],
                [
                    'label' => __('collections.sync.health.match_coverage'),
                    'value' => Number::percentage(
                        $sourceSyncSummary['diagnostics']['match_coverage_percent'],
                        maxPrecision: 2,
                        locale: app()->currentLocale(),
                    ),
                ],
            ];
            $sourceSyncSummary['scope_metrics'] = collect($sourceSyncSummary['diagnostics']['source_scopes'])
                ->map(fn (int $value, string $key): array => [
                    'label' => __('collections.sync.source_scopes.'.$key),
                    'value' => $value,
                ])
                ->values()
                ->all();
            $sourceSyncSummary['match_metrics'] = collect($sourceSyncSummary['diagnostics']['match_methods'])
                ->filter(fn (int $value): bool => $value > 0)
                ->map(fn (int $value, string $key): array => [
                    'label' => __('collections.sync.match_methods.'.$key),
                    'value' => $value,
                ])
                ->values()
                ->all();
        }

        foreach ($paginator->getCollection() as $collection) {
            $collectionReadiness = $readinessByCollection[(int) $collection->getKey()];
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
            $collection->setAttribute(
                'presentation_quality_label',
                ! $qualityAvailable
                    ? __('collections.admin.quality_unavailable')
                    : ($collection->hasCurrentQuality()
                    ? __('collections.admin.quality_score_value', [
                        'score' => $collection->quality_score,
                    ])
                    : ($collection->quality_score !== null
                        || $collection->quality_content_version !== null
                        || $collection->quality_evaluated_at !== null
                            ? __('collections.admin.quality_stale')
                            : __('collections.admin.quality_not_assessed'))),
            );
            $collection->setAttribute(
                'presentation_quality_components',
                $this->qualityComponents($collection->quality_details),
            );
            $collection->setAttribute(
                'presentation_quality_signals',
                $this->qualitySignals($collection->quality_details),
            );
            $qualityVerified = $collection->editorially_verified_at !== null
                && $collection->editorially_verified_content_version === $collection->content_version;
            $collection->setAttribute('presentation_quality_verified', $qualityVerified);
            $collection->setAttribute(
                'presentation_can_verify_quality',
                $qualityAvailable && $collection->meetsPublicQualityThreshold(),
            );
            $collection->setAttribute(
                'presentation_quality_verification_next',
                $qualityVerified ? 'false' : 'true',
            );
            $collection->setAttribute(
                'presentation_quality_verification_label',
                $qualityVerified
                    ? __('collections.admin.remove_quality_verification')
                    : __('collections.admin.verify_quality'),
            );
            $collection->setAttribute(
                'presentation_quality_issues',
                $collection->qualityIssues
                    ->map(fn (CatalogCollectionQualityIssue $issue): string => __(
                        'collections.admin.quality_issues.'.$issue->code,
                    ))
                    ->all(),
            );
            $collection->setAttribute('presentation_deleted', $collection->trashed());
            $collection->setAttribute('presentation_has_open_reports', $openReports > 0);
            $collection->setAttribute(
                'presentation_readiness_state',
                $collectionReadiness['ready'] ? 'ready' : 'not-ready',
            );
            $collection->setAttribute(
                'presentation_readiness_label',
                $collectionReadiness['ready']
                    ? __('collections.admin.readiness_ready')
                    : __('collections.admin.readiness_not_ready'),
            );
            $collection->setAttribute(
                'presentation_readiness_count_label',
                __('collections.admin.readiness_count', [
                    'visible' => $collectionReadiness['visible_items'],
                    'total' => $collectionReadiness['total_items'],
                    'required' => $collectionReadiness['required_items'],
                ]),
            );
            $collection->setAttribute(
                'presentation_readiness_reasons',
                collect($collectionReadiness['reason_codes'])
                    ->map(fn (string $code): string => CatalogCollectionReadinessReason::tryFrom($code)?->label()
                        ?? __('collections.admin.readiness_unknown_reason'))
                    ->all(),
            );
            $collection->setAttribute(
                'presentation_can_feature',
                $collection->is_featured || $collectionReadiness['ready'],
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
            'canModerateCollections' => Gate::allows(AdminPermission::CollectionsModerate->value),
            'qualityAvailable' => $qualityAvailable,
            'qualityFilterOptions' => $this->qualityFilterOptions(),
        ]);
    }

    /** @return array<string, string> */
    private function qualityFilterOptions(): array
    {
        return [
            'all' => __('collections.admin.quality_filters.all'),
            'critical' => __('collections.admin.quality_filters.critical'),
            'warning' => __('collections.admin.quality_filters.warning'),
            'low' => __('collections.admin.quality_filters.low'),
            'stale' => __('collections.admin.quality_filters.stale'),
            'unassessed' => __('collections.admin.quality_filters.unassessed'),
            'verified' => __('collections.admin.quality_filters.verified'),
            'duplicate' => __('collections.admin.quality_filters.duplicate'),
            'similar' => __('collections.admin.quality_filters.similar'),
            'template' => __('collections.admin.quality_filters.template'),
            'theme' => __('collections.admin.quality_filters.theme'),
            'structure' => __('collections.admin.quality_filters.structure'),
            'reported' => __('collections.admin.quality_filters.reported'),
        ];
    }

    /**
     * @param  array<string, mixed>|null  $details
     * @return list<array{label: string, value: string}>
     */
    private function qualityComponents(?array $details): array
    {
        $components = is_array($details['components'] ?? null)
            ? $details['components']
            : [];

        if ($components === []) {
            return [];
        }

        $presentation = [];

        foreach (self::QUALITY_COMPONENT_MAXIMUMS as $key => $maximum) {
            $value = is_numeric($components[$key] ?? null)
                ? min($maximum, max(0, (int) $components[$key]))
                : 0;
            $presentation[] = [
                'label' => __('collections.admin.quality_components.'.$key),
                'value' => "{$value}/{$maximum}",
            ];
        }

        return $presentation;
    }

    /**
     * @param  array<string, mixed>|null  $details
     * @return list<array{label: string, value: string}>
     */
    private function qualitySignals(?array $details): array
    {
        $signals = is_array($details['engagement'] ?? null)
            ? $details['engagement']
            : [];

        if ($signals === []) {
            return [];
        }

        $presentation = [];

        foreach (self::QUALITY_SIGNAL_KEYS as $key) {
            $value = is_numeric($signals[$key] ?? null)
                ? max(0, (int) $signals[$key])
                : 0;
            $presentation[] = [
                'label' => __('collections.admin.quality_signals.'.$key),
                'value' => Number::format($value, locale: app()->currentLocale()),
            ];
        }

        return $presentation;
    }

    private function user(): User
    {
        $user = auth()->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
