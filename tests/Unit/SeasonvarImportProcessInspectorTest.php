<?php

namespace Tests\Unit;

use App\Services\Seasonvar\SeasonvarImportProcessInspector;
use PHPUnit\Framework\TestCase;

class SeasonvarImportProcessInspectorTest extends TestCase
{
    public function test_it_matches_only_processes_that_execute_the_synchronous_import(): void
    {
        $inspector = new SeasonvarImportProcessInspector;

        $this->assertTrue($inspector->isImportExecutionCommand('/usr/bin/php artisan seasonvar:import --force'));
        $this->assertTrue($inspector->isImportExecutionCommand('php8.5 /srv/app/artisan seasonvar:import --no-discovery'));
        $this->assertFalse($inspector->isImportExecutionCommand('watch php artisan seasonvar:import --status'));
        $this->assertFalse($inspector->isImportExecutionCommand('/usr/bin/php artisan seasonvar:import --status'));
        $this->assertFalse($inspector->isImportExecutionCommand('/usr/bin/php artisan seasonvar:import --queued'));
        $this->assertFalse($inspector->isImportExecutionCommand('/usr/bin/php artisan seasonvar:import --help'));
        $this->assertFalse($inspector->isImportExecutionCommand('codex user asked for php artisan seasonvar:import'));
    }
}
