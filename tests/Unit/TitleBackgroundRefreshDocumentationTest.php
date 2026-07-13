<?php

declare(strict_types=1);

namespace Tests\Unit;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

class TitleBackgroundRefreshDocumentationTest extends TestCase
{
    public function test_single_thread_forever_import_has_a_persistent_systemd_profile(): void
    {
        $service = File::get(base_path('deploy/systemd/seasonvar-import-forever.service'));

        $this->assertStringContainsString(
            'ExecStart=/usr/bin/php -d memory_limit=256M artisan seasonvar:import --forever',
            $service,
        );
        $this->assertStringContainsString('Restart=always', $service);
        $this->assertStringNotContainsString('queue:work', $service);
        $this->assertStringContainsString(
            'seasonvar-import-forever.service',
            File::get(base_path('docs/deployment.md')),
        );
        $this->assertStringContainsString(
            'взаимоисключ',
            File::get(base_path('docs/queues.md')),
        );
    }

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
