<?php

declare(strict_types=1);

namespace App\Services\Operations;

use Carbon\CarbonImmutable;

final class FailedJobMetadataClassifier
{
    public const EXCEPTION_PREFIX_BYTES = 256;

    private const JOB_LABELS = [
        'App\\Jobs\\FinalizeSeasonvarImportTitleGroup' => 'finalize_title_group',
        'App\\Jobs\\FinalizeSeasonvarQueuedImport' => 'finalize_import',
        'App\\Jobs\\ImportSeasonvarSourcePage' => 'import_source_page',
        'App\\Jobs\\PrepareSeasonvarImportTitlePage' => 'prepare_title_page',
        'App\\Jobs\\ProcessSeasonvarImportPage' => 'import_page',
        'App\\Jobs\\RefreshSeasonvarCatalogTitle' => 'refresh_catalog_title',
        'App\\Jobs\\RunSeasonvarImport' => 'run_import',
        'App\\Jobs\\StartSeasonvarQueuedImport' => 'start_import',
        'App\\Jobs\\WakeSeasonvarImportFinalizers' => 'wake_finalizers',
        'App\\Jobs\\WarmCatalogCaches' => 'warm_catalog_cache',
    ];

    private const REASON_LABELS = [
        'Illuminate\\Queue\\MaxAttemptsExceededException' => 'attempts_exhausted',
        'Illuminate\\Queue\\TimeoutExceededException' => 'timeout',
        'Symfony\\Component\\Process\\Exception\\ProcessTimedOutException' => 'timeout',
        'Illuminate\\Database\\QueryException' => 'database',
        'PDOException' => 'database',
        'Illuminate\\Http\\Client\\ConnectionException' => 'provider_connection',
        'GuzzleHttp\\Exception\\ConnectException' => 'provider_connection',
        'RuntimeException' => 'runtime',
    ];

    public function jobLabel(?string $displayName): string
    {
        return $displayName !== null
            ? (self::JOB_LABELS[$displayName] ?? 'other')
            : 'other';
    }

    public function reasonLabel(?string $exceptionPrefix): string
    {
        if ($exceptionPrefix === null) {
            return 'other';
        }

        foreach (self::REASON_LABELS as $exceptionClass => $label) {
            if ($exceptionPrefix === $exceptionClass
                || str_starts_with($exceptionPrefix, $exceptionClass.':')
                || str_starts_with($exceptionPrefix, $exceptionClass.' ')
            ) {
                return $label;
            }
        }

        return 'other';
    }

    public function ageLabel(string $failedAt, CarbonImmutable $now): string
    {
        $hours = CarbonImmutable::parse($failedAt)->diffInHours($now);

        return match (true) {
            $hours < 1 => 'under_1_hour',
            $hours < 24 => '1_to_24_hours',
            $hours < 168 => '1_to_7_days',
            default => 'over_7_days',
        };
    }
}
