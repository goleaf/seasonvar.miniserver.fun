<?php

declare(strict_types=1);

namespace Tests\Unit\DemoData;

use App\Services\DemoData\DemoLexicalFingerprint;
use InvalidArgumentException;
use Tests\TestCase;

final class DemoLexicalFingerprintTest extends TestCase
{
    public function test_fifty_thousand_fingerprints_are_unique_natural_and_have_no_numeric_suffixes(): void
    {
        $generator = new DemoLexicalFingerprint;
        $clauses = [];

        foreach (range(0, 49_999) as $ordinal) {
            $clause = $generator->clause('review', $ordinal);

            $this->assertMatchesRegularExpression('/[А-Яа-яЁё]/u', $clause);
            $this->assertDoesNotMatchRegularExpression('/\b\d+\b/u', $clause);
            $clauses[] = $clause;
        }

        $this->assertCount(50_000, array_unique($clauses));
        $this->assertGreaterThanOrEqual(25_000_000, $generator->capacity());
    }

    public function test_negative_or_out_of_capacity_ordinals_are_rejected(): void
    {
        $generator = new DemoLexicalFingerprint;

        $this->expectException(InvalidArgumentException::class);
        $generator->clause('comment', $generator->capacity());
    }
}
