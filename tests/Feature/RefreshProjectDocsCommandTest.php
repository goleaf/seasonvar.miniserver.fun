<?php

namespace Tests\Feature;

use App\Services\ProjectDocumentation\ProjectDocumentationRefresher;
use App\Services\ProjectDocumentation\ProjectDocumentationRefreshResult;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Tests\TestCase;

class RefreshProjectDocsCommandTest extends TestCase
{
    private string $fixtureRoot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fixtureRoot = storage_path('framework/testing/docs-refresh-'.Str::random(12));
        File::ensureDirectoryExists($this->fixtureRoot.'/docs');
        File::ensureDirectoryExists($this->fixtureRoot.'/database/migrations');
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->fixtureRoot);

        parent::tearDown();
    }

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

    public function test_check_mode_fails_with_the_owning_file_and_broken_relative_path(): void
    {
        $this->mock(ProjectDocumentationRefresher::class, function ($mock): void {
            $mock
                ->shouldReceive('refresh')
                ->once()
                ->with(true)
                ->andReturn(new ProjectDocumentationRefreshResult(
                    [],
                    [],
                    ['docs/guide.md:7 -> missing.md'],
                ));
        });

        $this->artisan('project:docs-refresh --check')
            ->expectsOutputToContain('Некорректная ссылка Markdown: docs/guide.md:7 -> missing.md')
            ->assertExitCode(1);
    }

    public function test_relative_markdown_link_validation_ignores_external_anchors_and_fenced_code(): void
    {
        File::put($this->fixtureRoot.'/README.md', <<<'MARKDOWN'
# Fixture

[Файл](docs/guide.md#существующий-раздел)
[Внешняя](https://example.com/not-fetched)
[Почта](mailto:ops@example.com)
[Якорь](#fixture)

```md
[Пример не проверяется](docs/example-only.md)
```
MARKDOWN);
        File::put($this->fixtureRoot.'/docs/guide.md', "# Существующий раздел\n");

        $result = (new ProjectDocumentationRefresher($this->fixtureRoot))->refresh(check: true);

        $this->assertSame([], $result->brokenLinks);
    }

    public function test_relative_markdown_link_validation_reports_missing_and_escaping_paths_in_stable_order(): void
    {
        File::put($this->fixtureRoot.'/README.md', <<<'MARKDOWN'
# Fixture

[Missing](docs/z-missing.md)
[Escape](../outside.md)
[Also missing](docs/a-missing.md#section)
MARKDOWN);

        $result = (new ProjectDocumentationRefresher($this->fixtureRoot))->refresh(check: true);

        $this->assertSame([
            'README.md:4 -> ../outside.md',
            'README.md:5 -> docs/a-missing.md#section',
            'README.md:3 -> docs/z-missing.md',
        ], $result->brokenLinks);
    }

    public function test_managed_migration_inventory_is_sorted_and_idempotent(): void
    {
        File::put($this->fixtureRoot.'/database/migrations/2026_07_13_220000_second.php', '<?php');
        File::put($this->fixtureRoot.'/database/migrations/2026_07_13_210000_first.php', '<?php');
        $contents = <<<'MARKDOWN'
# Maintenance

Ручной текст вне markers.

<!-- project-docs:start -->
старое содержимое
<!-- project-docs:end -->
MARKDOWN;
        $refresher = new ProjectDocumentationRefresher($this->fixtureRoot);

        $first = $refresher->refreshContents('docs/MAINTENANCE_LOG.md', $contents);
        $second = $refresher->refreshContents('docs/MAINTENANCE_LOG.md', $first);

        $this->assertSame($first, $second);
        $this->assertStringContainsString('Ручной текст вне markers.', $first);
        $this->assertLessThan(
            strpos($first, '2026_07_13_220000_second.php'),
            strpos($first, '2026_07_13_210000_first.php'),
        );
        $this->assertSame(1, substr_count($first, '2026_07_13_210000_first.php'));
        $this->assertSame(1, substr_count($first, '2026_07_13_220000_second.php'));
    }

    public function test_existing_source_parity_snapshot_is_preserved_without_inventory_storage(): void
    {
        $contents = <<<'MARKDOWN'
# Source parity

Обновлено: 01.01.2000

<!-- project-docs:start -->
Последний подтверждённый production inventory.
<!-- project-docs:end -->
MARKDOWN;

        $refreshed = (new ProjectDocumentationRefresher($this->fixtureRoot))->refreshContents(
            'docs/SOURCE_PARITY.md',
            $contents,
        );

        $this->assertSame($contents, $refreshed);
    }
}
