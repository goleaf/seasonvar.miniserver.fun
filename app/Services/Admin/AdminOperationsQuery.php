<?php

declare(strict_types=1);

namespace App\Services\Admin;

use App\DTOs\Administration\AdminCapabilityData;
use App\Models\AdminOperationalEvent;
use App\Models\CatalogSearchIndexState;
use App\Services\Operations\InfrastructureHealthCheck;
use Illuminate\Support\Facades\Schema;
use Throwable;

final readonly class AdminOperationsQuery
{
    public function __construct(
        private AdminCapabilityRegistry $capabilities,
        private InfrastructureHealthCheck $health,
    ) {}

    /** @return array{capabilities: list<AdminCapabilityData>, capabilities_error: bool, health: array<string, mixed>, search: array<string, mixed>|null, search_error: bool, events: list<array<string, string>>, events_error: bool} */
    public function summary(): array
    {
        try {
            $health = $this->health->readiness();
        } catch (Throwable $exception) {
            report($exception);
            $health = ['status' => 'unavailable', 'ready' => false, 'checked_at' => now()->toIso8601String()];
        }

        $capabilities = [];
        $capabilitiesError = false;

        try {
            $capabilities = $this->capabilities->all();
        } catch (Throwable $exception) {
            report($exception);
            $capabilitiesError = true;
        }

        $search = null;
        $searchError = false;

        try {
            if (Schema::hasTable('catalog_search_index_states')) {
                $state = CatalogSearchIndexState::query()
                    ->select(['id', 'version', 'status', 'source_count', 'document_count', 'checkpoint_id', 'completed_at', 'failed_at'])
                    ->find(CatalogSearchIndexState::SINGLETON_ID);
                $search = $state === null ? null : [
                    'version' => (string) $state->version,
                    'status' => $state->statusValue()->value,
                    'status_label' => __("administration.operations.search_status.{$state->statusValue()->value}"),
                    'source_count' => (string) $state->source_count,
                    'document_count' => (string) $state->document_count,
                    'checkpoint' => (string) $state->checkpoint_id,
                    'completed_at' => $state->completed_at?->translatedFormat('d.m.Y H:i') ?? '—',
                ];
            }
        } catch (Throwable $exception) {
            report($exception);
            $searchError = true;
        }

        $events = [];
        $eventsError = false;

        try {
            if (Schema::hasTable('admin_operational_events')) {
                $events = AdminOperationalEvent::query()
                    ->select(['public_id', 'action_code', 'target_code', 'status', 'occurred_at'])
                    ->latest('occurred_at')
                    ->latest('id')
                    ->limit(20)
                    ->get()
                    ->map(fn (AdminOperationalEvent $event): array => [
                        'public_id' => $event->public_id,
                        'action' => __('administration.operations.actions.'.str_replace('.', '_', $event->action_code)),
                        'target' => $event->target_code,
                        'status' => __("administration.operations.event_status.{$event->status}"),
                        'time' => $event->occurred_at->translatedFormat('d.m.Y H:i:s'),
                    ])->all();
            }
        } catch (Throwable $exception) {
            report($exception);
            $eventsError = true;
        }

        return [
            'capabilities' => $capabilities,
            'capabilities_error' => $capabilitiesError,
            'health' => [
                'status' => (string) ($health['status'] ?? 'unavailable'),
                'status_label' => __('administration.operations.health_status.'.($health['status'] ?? 'unavailable')),
                'ready' => (bool) ($health['ready'] ?? false),
                'checked_at' => (string) ($health['checked_at'] ?? now()->toIso8601String()),
            ],
            'search' => $search,
            'search_error' => $searchError,
            'events' => $events,
            'events_error' => $eventsError,
        ];
    }
}
