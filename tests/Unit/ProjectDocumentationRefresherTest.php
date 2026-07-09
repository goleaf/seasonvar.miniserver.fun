<?php

namespace Tests\Unit;

use App\Services\ProjectDocumentation\ProjectDocumentationRefresher;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class ProjectDocumentationRefresherTest extends TestCase
{
    private ?string $tempPath = null;

    protected function tearDown(): void
    {
        if ($this->tempPath !== null) {
            File::deleteDirectory($this->tempPath);
        }

        parent::tearDown();
    }

    public function test_refresher_updates_managed_sections_and_dates(): void
    {
        $basePath = $this->makeDocumentationFixture();
        $refresher = new ProjectDocumentationRefresher($basePath);

        $result = $refresher->refresh();

        $this->assertSame([], $result->missingFiles);
        $this->assertSame([
            'README.md',
            'AGENTS.md',
            'docs/CODE_STANDARDS.md',
            'docs/UI_STANDARDS.md',
            'docs/DATA_RELATIONS.md',
            'docs/MAINTENANCE_LOG.md',
        ], $result->changedFiles);
        $this->assertStringContainsString('<!-- project-docs:start -->', File::get($basePath.'/README.md'));
        $this->assertStringContainsString('`/sitemap-index.xml` (`sitemap.index`)', File::get($basePath.'/docs/CODE_STANDARDS.md'));
        $this->assertStringContainsString('Обновлено: '.now()->format('d.m.Y'), File::get($basePath.'/docs/UI_STANDARDS.md'));
    }

    public function test_check_mode_reports_changes_without_writing_files(): void
    {
        $basePath = $this->makeDocumentationFixture();
        $originalReadme = File::get($basePath.'/README.md');
        $refresher = new ProjectDocumentationRefresher($basePath);

        $result = $refresher->refresh(check: true);

        $this->assertTrue($result->hasChanges());
        $this->assertSame($originalReadme, File::get($basePath.'/README.md'));
        $this->assertStringNotContainsString('<!-- project-docs:start -->', File::get($basePath.'/README.md'));
    }

    private function makeDocumentationFixture(): string
    {
        $this->tempPath = sys_get_temp_dir().'/seasonvar-docs-'.bin2hex(random_bytes(6));

        File::makeDirectory($this->tempPath.'/docs', recursive: true);

        foreach ([
            'README.md' => "# Test\n",
            'AGENTS.md' => "# Agents\n",
            'docs/CODE_STANDARDS.md' => "# Code\n\nОбновлено: 01.01.2000\n",
            'docs/UI_STANDARDS.md' => "# UI\n\nОбновлено: 01.01.2000\n",
            'docs/DATA_RELATIONS.md' => "# Data\n\nОбновлено: 01.01.2000\n",
            'docs/MAINTENANCE_LOG.md' => "# Maintenance\n",
        ] as $relativePath => $contents) {
            File::put($this->tempPath.'/'.$relativePath, $contents);
        }

        return $this->tempPath;
    }
}
