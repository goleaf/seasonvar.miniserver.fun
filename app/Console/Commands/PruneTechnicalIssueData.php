<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\TechnicalIssueAttachment;
use App\Models\TechnicalIssueDiagnostic;
use App\Models\TechnicalIssueOccurrence;
use App\Services\TechnicalIssues\TechnicalIssueSchema;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Throwable;

#[Signature('technical-issues:prune-private-data {--limit=200 : Maximum diagnostics and attachments handled per category}')]
#[Description('Prunes expired optional technical-ticket diagnostics and closed-ticket screenshots in bounded batches')]
final class PruneTechnicalIssueData extends Command
{
    public function handle(TechnicalIssueSchema $schema): int
    {
        if (! $schema->ready()) {
            $this->components->info('Technical issue schema is not installed. Nothing was pruned.');

            return self::SUCCESS;
        }

        $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 500]]);
        $limit = is_int($limit) ? $limit : 200;
        $diagnosticCutoff = now()->subDays(max(1, (int) config('technical-issues.retention.diagnostics_days', 180)));
        $attachmentCutoff = now()->subDays(max(1, (int) config('technical-issues.retention.attachments_days_after_closed', 365)));
        $terminalStatuses = ['resolved', 'resolution_verified', 'closed', 'rejected', 'merged', 'withdrawn'];
        $diagnostics = TechnicalIssueDiagnostic::query()
            ->whereHas('technicalIssue', fn ($query) => $query->whereIn('status', $terminalStatuses)->where('updated_at', '<=', $diagnosticCutoff))
            ->oldest('id')
            ->limit($limit)
            ->pluck('id');
        $diagnosticsDeleted = TechnicalIssueDiagnostic::query()->whereKey($diagnostics)->delete();
        $occurrences = TechnicalIssueOccurrence::query()
            ->whereNull('diagnostics_pruned_at')
            ->whereHas('technicalIssue', fn ($query) => $query->whereIn('status', $terminalStatuses)->where('updated_at', '<=', $diagnosticCutoff))
            ->oldest('id')
            ->limit($limit)
            ->pluck('id');
        $occurrencesPruned = TechnicalIssueOccurrence::query()->whereKey($occurrences)->update([
            'browser_family' => null,
            'browser_major' => null,
            'operating_system' => null,
            'device_category' => null,
            'viewport_width' => null,
            'viewport_height' => null,
            'timezone' => null,
            'network_online' => null,
            'playback_position_seconds' => null,
            'public_error_code' => null,
            'source_health_code' => null,
            'diagnostics_pruned_at' => now(),
            'updated_at' => now(),
        ]);
        $attachments = TechnicalIssueAttachment::query()
            ->whereHas('technicalIssue', fn ($query) => $query->whereIn('status', ['closed', 'withdrawn'])->where('updated_at', '<=', $attachmentCutoff))
            ->oldest('id')
            ->limit($limit)
            ->get(['id', 'disk', 'path']);
        $attachmentsDeleted = 0;
        $attachmentsFailed = 0;

        foreach ($attachments as $attachment) {
            try {
                $disk = Storage::disk($attachment->disk);

                if ($disk->exists($attachment->path) && ! $disk->delete($attachment->path)) {
                    $attachmentsFailed++;

                    continue;
                }

                $attachmentsDeleted += TechnicalIssueAttachment::query()->whereKey($attachment->id)->delete();
            } catch (Throwable $exception) {
                report($exception);
                $attachmentsFailed++;
            }
        }

        $this->components->info("Diagnostics deleted: {$diagnosticsDeleted}.");
        $this->components->info("Duplicate occurrence diagnostics pruned: {$occurrencesPruned}.");
        $this->components->info("Closed-ticket screenshots deleted: {$attachmentsDeleted}; failed: {$attachmentsFailed}.");

        return $attachmentsFailed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
