<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Admin\AdminAuditCsvExporter;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class AdminAuditCsvExporterTest extends TestCase
{
    #[Test]
    public function spreadsheet_formula_prefixes_are_neutralized(): void
    {
        $exporter = new AdminAuditCsvExporter;

        self::assertSame("'=SUM(A1:A2)", $exporter->safeCell('=SUM(A1:A2)'));
        self::assertSame("'+command", $exporter->safeCell('+command'));
        self::assertSame("'-10", $exporter->safeCell('-10'));
        self::assertSame("'@payload", $exporter->safeCell('@payload'));
        self::assertSame("'\ttab", $exporter->safeCell("\ttab"));
        self::assertSame('safe', $exporter->safeCell('safe'));
    }
}
