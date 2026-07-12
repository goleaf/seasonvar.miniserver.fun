<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Enums\SeasonvarImportFailureType;
use App\Exceptions\Seasonvar\SeasonvarSourceRequestException;
use App\Services\Seasonvar\SeasonvarImportFailureClassifier;
use Illuminate\Database\QueryException;
use Illuminate\Http\Client\ConnectionException;
use PDOException;
use RuntimeException;
use Tests\TestCase;

class SeasonvarImportFailureClassifierTest extends TestCase
{
    public function test_it_classifies_retryable_source_failures_as_transient(): void
    {
        $this->assertTrue(class_exists(SeasonvarImportFailureClassifier::class));

        $classifier = app(SeasonvarImportFailureClassifier::class);

        foreach ([408, 425, 429, 500, 503] as $status) {
            $this->assertSame(
                SeasonvarImportFailureType::Transient,
                $classifier->classify(SeasonvarSourceRequestException::forStatus($status)),
            );
        }

        $this->assertSame(
            SeasonvarImportFailureType::Transient,
            $classifier->classify(new ConnectionException('Connection timed out')),
        );
        $this->assertSame(
            SeasonvarImportFailureType::Transient,
            $classifier->classify($this->lockedException()),
        );
    }

    public function test_it_classifies_permanent_source_failures_without_retry(): void
    {
        $this->assertTrue(class_exists(SeasonvarImportFailureClassifier::class));

        $classifier = app(SeasonvarImportFailureClassifier::class);

        foreach ([400, 404, 410, 422] as $status) {
            $this->assertSame(
                SeasonvarImportFailureType::Permanent,
                $classifier->classify(SeasonvarSourceRequestException::forStatus($status)),
            );
        }

        $this->assertSame(
            SeasonvarImportFailureType::Permanent,
            $classifier->classify(new RuntimeException('Некорректная разметка страницы.')),
        );
    }

    private function lockedException(): QueryException
    {
        return new QueryException(
            'sqlite',
            'update source_pages set parse_status = ?',
            ['failed'],
            new PDOException('SQLSTATE[HY000]: General error: 5 database is locked', 5),
        );
    }
}
