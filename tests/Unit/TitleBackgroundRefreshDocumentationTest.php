<?php

declare(strict_types=1);

namespace Tests\Unit;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

class TitleBackgroundRefreshDocumentationTest extends TestCase
{
    public function test_project_docs_describe_the_title_background_refresh_contract(): void
    {
        $this->assertStringContainsString('15 минут', File::get(base_path('docs/importer.md')));
        $this->assertStringContainsString('wire:poll.3s.visible', File::get(base_path('docs/frontend.md')));
        $this->assertStringContainsString('RefreshSeasonvarCatalogTitle', File::get(base_path('docs/queues.md')));
        $this->assertStringContainsString(
            '--queue=seasonvar-import',
            File::get(base_path('deploy/systemd/seasonvar-import-worker@.service')),
        );
        $titleWorker = File::get(base_path('deploy/systemd/seasonvar-title-refresh-worker@.service'));
        $this->assertStringContainsString('--queue=seasonvar-title-refresh', $titleWorker);
        $this->assertStringNotContainsString('seasonvar-import', $titleWorker);
        $this->assertStringContainsString('без application-level limit', File::get(base_path('docs/queues.md')));
        $this->assertArrayNotHasKey('concurrency_limit', config('seasonvar.title_refresh'));
        $this->assertArrayNotHasKey('max_pages', config('seasonvar.title_refresh'));
    }
}
