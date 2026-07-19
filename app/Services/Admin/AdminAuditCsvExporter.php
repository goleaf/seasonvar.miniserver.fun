<?php

declare(strict_types=1);

namespace App\Services\Admin;

use App\DTOs\Administration\AdminAuditEventData;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class AdminAuditCsvExporter
{
    public function safeCell(string $value): string
    {
        return preg_match('/^[=+\-@\t\r]/u', $value) === 1 ? "'".$value : $value;
    }

    /** @param iterable<AdminAuditEventData> $events */
    public function response(iterable $events): StreamedResponse
    {
        return response()->streamDownload(function () use ($events): void {
            $output = fopen('php://output', 'wb');

            if ($output === false) {
                return;
            }

            fwrite($output, "\xEF\xBB\xBF");
            fputcsv($output, ['event_public_id', 'occurred_at', 'action', 'resource_type', 'resource_public_id', 'actor_public_id', 'changed_fields', 'correlation_id']);

            foreach ($events as $event) {
                fputcsv($output, array_map($this->safeCell(...), [
                    $event->publicId,
                    $event->occurredAtIso,
                    $event->actionCode,
                    $event->resourceType,
                    $event->resourcePublicId,
                    $event->actorPublicId,
                    implode('|', $event->changedFieldLabels),
                    $event->correlationId ?? '',
                ]));
            }

            fclose($output);
        }, 'admin-audit-'.now()->format('Y-m-d').'.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Cache-Control' => 'private, no-store, max-age=0',
            'X-Content-Type-Options' => 'nosniff',
            'X-Robots-Tag' => 'noindex, nofollow',
        ]);
    }
}
