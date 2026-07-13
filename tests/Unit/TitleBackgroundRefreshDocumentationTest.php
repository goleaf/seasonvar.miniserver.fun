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
    }
}
