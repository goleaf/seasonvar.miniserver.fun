<?php

declare(strict_types=1);

namespace App\Actions\Seasonvar;

use App\Enums\SeasonvarImportFailureType;
use App\Exceptions\Seasonvar\SeasonvarSourceRequestException;
use App\Models\SourcePage;
use App\Services\Seasonvar\SeasonvarImportErrorSanitizer;
use App\Services\Seasonvar\SeasonvarImportFailureClassifier;
use Throwable;

class RecordSeasonvarPageFailure
{
    public function __construct(
        private readonly SeasonvarImportFailureClassifier $classifier,
        private readonly SeasonvarImportErrorSanitizer $errors,
    ) {}

    public function handle(
        SourcePage $page,
        Throwable $exception,
        ?int $runId,
    ): SeasonvarImportFailureType {
        $type = $this->classifier->classify($exception);
        $httpStatus = $exception instanceof SeasonvarSourceRequestException
            ? $exception->status
            : null;
        $failureCount = max(0, (int) $page->failure_count);
        $attributes = [
            'parse_status' => 'failed',
            'import_status' => $httpStatus === 404 ? 'gone' : 'failed',
            'error_message' => $this->errors->fromException($exception),
            'last_crawled_at' => now(),
            'retry_after_at' => $httpStatus === 404
                ? now()->addDays(7)
                : now()->addMinutes($this->retryDelayMinutes($failureCount)),
            'failure_count' => $failureCount + 1,
            'last_import_run_id' => $runId,
        ];

        if ($httpStatus !== null) {
            $attributes['http_status'] = $httpStatus;
        }

        $page->update($attributes);

        return $type;
    }

    private function retryDelayMinutes(int $failureCount): int
    {
        return min(1440, 15 * (2 ** min($failureCount, 6)));
    }
}
