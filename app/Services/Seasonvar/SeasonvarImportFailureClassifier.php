<?php

declare(strict_types=1);

namespace App\Services\Seasonvar;

use App\Enums\SeasonvarImportFailureType;
use App\Exceptions\Seasonvar\SeasonvarSourceRequestException;
use Illuminate\Http\Client\ConnectionException;
use Throwable;

class SeasonvarImportFailureClassifier
{
    public function classify(Throwable $exception): SeasonvarImportFailureType
    {
        do {
            if ($exception instanceof SeasonvarSourceRequestException) {
                return $this->httpStatusIsRetryable($exception->status)
                    ? SeasonvarImportFailureType::Transient
                    : SeasonvarImportFailureType::Permanent;
            }

            if ($exception instanceof ConnectionException || $this->isDatabaseLocked($exception)) {
                return SeasonvarImportFailureType::Transient;
            }

            $exception = $exception->getPrevious();
        } while ($exception instanceof Throwable);

        return SeasonvarImportFailureType::Permanent;
    }

    private function httpStatusIsRetryable(int $status): bool
    {
        return in_array($status, [408, 425, 429], true) || $status >= 500;
    }

    private function isDatabaseLocked(Throwable $exception): bool
    {
        $message = mb_strtolower($exception->getMessage());

        return str_contains($message, 'database is locked')
            || str_contains($message, 'database table is locked')
            || str_contains($message, 'a table in the database is locked');
    }
}
