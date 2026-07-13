<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

final class CacheMetricsCommandTest extends TestCase
{
    public function test_metrics_command_rejects_an_invalid_date_without_a_stack_trace(): void
    {
        $this->artisan('cache:metrics', ['--date' => '../../private-input'])
            ->expectsOutput('Дата должна быть указана в формате YYYY-MM-DD.')
            ->assertFailed();
    }
}
