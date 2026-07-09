<?php

namespace Tests\Feature;

use App\Services\ProjectDocumentation\ProjectDocumentationRefresher;
use App\Services\ProjectDocumentation\ProjectDocumentationRefreshResult;
use Tests\TestCase;

class RefreshProjectDocsCommandTest extends TestCase
{
    public function test_check_mode_reports_stale_documentation_from_refresher(): void
    {
        $this->mock(ProjectDocumentationRefresher::class, function ($mock): void {
            $mock
                ->shouldReceive('refresh')
                ->once()
                ->with(true)
                ->andReturn(new ProjectDocumentationRefreshResult(['README.md'], []));
        });

        $this->artisan('project:docs-refresh --check')
            ->expectsOutputToContain('Документация требует обновления: README.md')
            ->assertExitCode(1);
    }
}
