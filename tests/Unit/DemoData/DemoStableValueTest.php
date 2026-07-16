<?php

declare(strict_types=1);

namespace Tests\Unit\DemoData;

use App\DTOs\DemoData\DemoAuditReport;
use App\DTOs\DemoData\DemoDataOptions;
use App\DTOs\DemoData\DemoStageReport;
use App\Services\DemoData\DemoStableValue;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use InvalidArgumentException;
use LogicException;
use Tests\TestCase;

final class DemoStableValueTest extends TestCase
{
    public function test_stable_values_uuid_and_dates_are_repeatable_and_bounded(): void
    {
        $values = new DemoStableValue('seasonvar-demo-v1');
        $from = CarbonImmutable::parse('2025-01-01 00:00:00');
        $to = CarbonImmutable::parse('2026-01-01 00:00:00');

        $this->assertSame(
            $values->integer('user:1', 1, 10),
            $values->integer('user:1', 1, 10),
        );
        $this->assertNotSame($values->hash('user:1'), $values->hash('user:2'));
        $this->assertTrue(Str::isUuid($values->uuid('comment:user:1:title:1')));
        $this->assertContains($values->pick('profile:1:city', ['Казань', 'Тула']), ['Казань', 'Тула']);
        $this->assertSame(
            $values->boolean('profile:1:newsletter', 37),
            $values->boolean('profile:1:newsletter', 37),
        );

        $date = $values->date('profile:1:joined', $from, $to);

        $this->assertTrue($date->betweenIncluded($from, $to));
        $this->assertTrue($date->equalTo($values->date('profile:1:joined', $from, $to)));
    }

    public function test_invalid_ranges_and_percentages_are_rejected(): void
    {
        $values = new DemoStableValue('seasonvar-demo-v1');

        $this->expectException(InvalidArgumentException::class);
        $values->integer('invalid', 2, 1);
    }

    public function test_options_validate_environment_and_selected_title_count(): void
    {
        config([
            'demo-data.user_count' => 4,
            'demo-data.coverage_numerator' => 1,
            'demo-data.coverage_denominator' => 2,
            'demo-data.chunk_size' => 100,
        ]);

        $options = DemoDataOptions::fromConfig();

        $options->assertEnvironment('testing');
        $this->assertSame(4, $options->userCount);
        $this->assertSame(8, $options->selectedTitleCount(17));

        $this->expectException(LogicException::class);
        $options->assertEnvironment('production');
    }

    public function test_stage_and_audit_reports_keep_typed_counters(): void
    {
        $stage = new DemoStageReport('accounts', ['users' => 100], 1.25);
        $audit = new DemoAuditReport(['users' => 100], []);

        $this->assertSame(100, $stage->counters['users']);
        $this->assertSame(1.25, $stage->elapsedSeconds);
        $this->assertTrue($audit->passed());
        $this->assertSame(100, $audit->counters['users']);
    }
}
