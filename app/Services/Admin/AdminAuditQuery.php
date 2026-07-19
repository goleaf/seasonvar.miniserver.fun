<?php

declare(strict_types=1);

namespace App\Services\Admin;

use App\DTOs\Administration\AdminAuditEventData;
use App\Enums\AdminAuditAction;
use App\Models\AdminAuditEvent;
use App\Support\Administration\AdminTableState;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

final class AdminAuditQuery
{
    /** @return LengthAwarePaginator<int, AdminAuditEventData> */
    public function paginate(AdminTableState $state): LengthAwarePaginator
    {
        return $this->base($state)
            ->paginate($state->perPage, pageName: 'auditPage', page: $state->page)
            ->through(fn (AdminAuditEvent $event): AdminAuditEventData => $this->data($event));
    }

    /** @return list<AdminAuditEventData> */
    public function export(AdminTableState $state): array
    {
        return $this->base($state)
            ->limit(1000)
            ->get()
            ->map(fn (AdminAuditEvent $event): AdminAuditEventData => $this->data($event))
            ->all();
    }

    /** @return Builder<AdminAuditEvent> */
    private function base(AdminTableState $state): Builder
    {
        $query = AdminAuditEvent::query()
            ->select(['id', 'public_id', 'actor_id', 'action', 'resource_type', 'resource_public_id', 'correlation_id', 'changed_fields', 'occurred_at'])
            ->with('actor:id,public_id,name');

        if (isset($state->filters['action']) && AdminAuditAction::tryFrom($state->filters['action']) !== null) {
            $query->where('action', $state->filters['action']);
        }

        if (isset($state->filters['resource']) && preg_match('/^[a-z][a-z0-9_]{2,63}$/D', $state->filters['resource']) === 1) {
            $query->where('resource_type', $state->filters['resource']);
        }

        $from = isset($state->filters['from']) ? $this->date($state->filters['from'])?->startOfDay() : null;
        $to = isset($state->filters['to']) ? $this->date($state->filters['to'])?->endOfDay() : null;

        if ($from !== null) {
            $minimum = now()->subDays(90)->startOfDay();
            $query->where('occurred_at', '>=', $from->lessThan($minimum) ? $minimum : $from);
        }

        if ($to !== null) {
            $maximum = now()->endOfDay();
            $query->where('occurred_at', '<=', $to->greaterThan($maximum) ? $maximum : $to);
        }

        return $query->orderBy($state->sortColumn(), $state->direction)->orderBy('id', $state->direction);
    }

    private function data(AdminAuditEvent $event): AdminAuditEventData
    {
        return new AdminAuditEventData(
            publicId: (string) $event->public_id,
            actionCode: $event->action->value,
            actionLabel: $event->action->label(),
            resourceType: $event->resource_type,
            resourceLabel: __("administration.audit.resources.{$event->resource_type}"),
            resourcePublicId: (string) $event->resource_public_id,
            actorName: (string) $event->actor->name,
            actorPublicId: (string) $event->actor->public_id,
            changedFieldLabels: collect($event->changed_fields)
                ->filter(fn (mixed $field): bool => is_string($field))
                ->map(fn (string $field): string => __('administration.audit.fields.'.str_replace('.', '_', $field)))
                ->values()
                ->all(),
            occurredAtIso: $event->occurred_at->toIso8601String(),
            occurredAtLabel: $event->occurred_at->translatedFormat('d.m.Y H:i:s'),
            correlationId: is_string($event->correlation_id) ? $event->correlation_id : null,
        );
    }

    private function date(string $value): ?Carbon
    {
        try {
            $date = Carbon::createFromFormat('!Y-m-d', $value);
        } catch (\Throwable) {
            return null;
        }

        return $date !== false && $date->format('Y-m-d') === $value ? $date : null;
    }
}
