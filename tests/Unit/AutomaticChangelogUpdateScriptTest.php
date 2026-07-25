<?php

declare(strict_types=1);

namespace Tests\Unit;

use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;
use Tests\TestCase;

final class AutomaticChangelogUpdateScriptTest extends TestCase
{
    private string $repositoryPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->repositoryPath = sys_get_temp_dir().'/seasonvar-automatic-changelog-'.bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->repositoryPath);

        parent::tearDown();
    }

    public function test_it_does_nothing_for_documentation_only_changes(): void
    {
        $initialChangelog = $this->initializeRepository();
        $this->writeAndStage('docs/note.md', "# Заметка\n");

        $process = $this->runUpdater();

        $this->assertTrue($process->isSuccessful(), $process->getErrorOutput());
        $this->assertSame($initialChangelog, File::get($this->repositoryPath.'/CHANGELOG.md'));
        $this->assertSame("docs/note.md\n", $this->gitOutput('diff', '--cached', '--name-only'));
    }

    public function test_it_creates_a_new_dated_section_for_staged_code(): void
    {
        $this->initializeRepository();
        $this->writeAndStage('app/Feature.php', "<?php\n");

        $process = $this->runUpdater();
        $changelog = File::get($this->repositoryPath.'/CHANGELOG.md');

        $this->assertTrue($process->isSuccessful(), $process->getErrorOutput());
        $this->assertStringContainsString(
            "## 2026-07-25\n\n- Автоматически зафиксировано обновление кода. Области: серверная логика. Количество изменённых файлов: 1.",
            $changelog,
        );
        $this->assertStringContainsString(
            "CHANGELOG.md\n",
            $this->gitOutput('diff', '--cached', '--name-only'),
        );
        $this->assertStringContainsString(
            "## 2026-07-24\n\n- Исходная запись.\n",
            $changelog,
        );
        $this->assertStringEndsWith("\n", $changelog);
    }

    public function test_it_inserts_into_an_existing_dated_section(): void
    {
        $this->initializeRepository(
            "# Журнал изменений\n\n## 2026-07-25\n\n- Ручная запись текущей даты.\n\n## 2026-07-24\n\n- Исходная запись.\n",
        );
        $this->writeAndStage('routes/web.php', "<?php\n");

        $process = $this->runUpdater();
        $changelog = File::get($this->repositoryPath.'/CHANGELOG.md');

        $this->assertTrue($process->isSuccessful(), $process->getErrorOutput());
        $this->assertStringContainsString(
            "## 2026-07-25\n\n- Автоматически зафиксировано обновление кода. Области: маршруты. Количество изменённых файлов: 1.\n- Ручная запись текущей даты.",
            $changelog,
        );
    }

    public function test_it_classifies_code_paths_and_counts_only_relevant_files(): void
    {
        $this->initializeRepository();
        $this->writeAndStage('app/Feature.php', "<?php\n");
        $this->writeAndStage('routes/web.php', "<?php\n");
        $this->writeAndStage('tests/Unit/FeatureTest.php', "<?php\n");
        $this->writeAndStage('docs/note.md', "# Заметка\n");

        $process = $this->runUpdater();
        $changelog = File::get($this->repositoryPath.'/CHANGELOG.md');

        $this->assertTrue($process->isSuccessful(), $process->getErrorOutput());
        $this->assertStringContainsString(
            'Области: серверная логика, маршруты и инструменты разработки и проверки. Количество изменённых файлов: 3.',
            $changelog,
        );
    }

    public function test_it_preserves_manual_staged_changelog_changes(): void
    {
        $this->initializeRepository();
        $manualChangelog = "# Журнал изменений\n\n## 2026-07-25\n\n- Добавлена содержательная ручная запись.\n\n## 2026-07-24\n\n- Исходная запись.\n";
        File::put($this->repositoryPath.'/CHANGELOG.md', $manualChangelog);
        $this->writeAndStage('app/Feature.php', "<?php\n");
        $this->runGit('add', '--', 'CHANGELOG.md');

        $process = $this->runUpdater();

        $this->assertTrue($process->isSuccessful(), $process->getErrorOutput());
        $this->assertSame($manualChangelog, File::get($this->repositoryPath.'/CHANGELOG.md'));
        $this->assertStringNotContainsString(
            'Автоматически зафиксировано обновление кода',
            $manualChangelog,
        );
    }

    public function test_it_is_idempotent_after_automatic_staging(): void
    {
        $this->initializeRepository();
        $this->writeAndStage('app/Feature.php', "<?php\n");

        $firstRun = $this->runUpdater();
        $afterFirstRun = File::get($this->repositoryPath.'/CHANGELOG.md');
        $secondRun = $this->runUpdater();
        $afterSecondRun = File::get($this->repositoryPath.'/CHANGELOG.md');

        $this->assertTrue($firstRun->isSuccessful(), $firstRun->getErrorOutput());
        $this->assertTrue($secondRun->isSuccessful(), $secondRun->getErrorOutput());
        $this->assertSame($afterFirstRun, $afterSecondRun);
        $this->assertSame(1, substr_count($afterSecondRun, 'Автоматически зафиксировано обновление кода'));
    }

    public function test_it_rejects_unstaged_changelog_changes(): void
    {
        $this->initializeRepository();
        $this->writeAndStage('app/Feature.php', "<?php\n");
        File::append($this->repositoryPath.'/CHANGELOG.md', "\nНезаписанное изменение.\n");

        $process = $this->runUpdater();

        $this->assertFalse($process->isSuccessful());
        $this->assertStringContainsString(
            'CHANGELOG.md содержит изменения вне индекса',
            $process->getErrorOutput(),
        );
        $this->assertStringNotContainsString(
            'Автоматически зафиксировано обновление кода',
            File::get($this->repositoryPath.'/CHANGELOG.md'),
        );
    }

    public function test_it_supports_staged_renames_and_deletions(): void
    {
        $this->initializeRepository(
            changelog: "# Журнал изменений\n\n## 2026-07-24\n\n- Исходная запись.\n",
            trackedFiles: [
                'app/OldFeature.php' => "<?php\n",
                'routes/old.php' => "<?php\n",
            ],
        );

        File::move(
            $this->repositoryPath.'/app/OldFeature.php',
            $this->repositoryPath.'/app/NewFeature.php',
        );
        File::delete($this->repositoryPath.'/routes/old.php');
        $this->runGit('add', '-A', '--', 'app', 'routes');

        $process = $this->runUpdater();
        $changelog = File::get($this->repositoryPath.'/CHANGELOG.md');

        $this->assertTrue($process->isSuccessful(), $process->getErrorOutput());
        $this->assertStringContainsString(
            'Области: серверная логика и маршруты. Количество изменённых файлов: 2.',
            $changelog,
        );
    }

    public function test_generated_entry_passes_the_russian_policy(): void
    {
        $this->initializeRepository();
        $this->writeAndStage('config/feature.php', "<?php\n");

        $updateProcess = $this->runUpdater();
        $policyProcess = new Process([
            'bash',
            base_path('scripts/check-changelog-policy.sh'),
            $this->repositoryPath.'/CHANGELOG.md',
        ]);
        $policyProcess->run();

        $this->assertTrue($updateProcess->isSuccessful(), $updateProcess->getErrorOutput());
        $this->assertTrue($policyProcess->isSuccessful(), $policyProcess->getErrorOutput());
    }

    /**
     * @param  array<string, string>  $trackedFiles
     */
    private function initializeRepository(
        string $changelog = "# Журнал изменений\n\n## 2026-07-24\n\n- Исходная запись.\n",
        array $trackedFiles = [],
    ): string {
        File::makeDirectory($this->repositoryPath, recursive: true);
        File::put($this->repositoryPath.'/CHANGELOG.md', $changelog);

        foreach ($trackedFiles as $path => $contents) {
            $this->writeFile($path, $contents);
        }

        $this->runGit('init', '-b', 'main');
        $this->runGit('config', 'user.name', 'Seasonvar Test');
        $this->runGit('config', 'user.email', 'seasonvar@example.com');
        $this->runGit('add', '--', '.');
        $this->runGit('commit', '-m', 'Исходное состояние');

        return $changelog;
    }

    private function writeAndStage(string $path, string $contents): void
    {
        $this->writeFile($path, $contents);
        $this->runGit('add', '--', $path);
    }

    private function writeFile(string $path, string $contents): void
    {
        $absolutePath = $this->repositoryPath.'/'.$path;
        File::ensureDirectoryExists(dirname($absolutePath));
        File::put($absolutePath, $contents);
    }

    private function runUpdater(): Process
    {
        $process = new Process([
            'bash',
            base_path('scripts/update-changelog-for-staged-code.sh'),
        ]);
        $process->setWorkingDirectory($this->repositoryPath);
        $process->setEnv([
            'PATH' => (string) getenv('PATH'),
            'SEASONVAR_CHANGELOG_DATE' => '2026-07-25',
        ]);
        $process->run();

        return $process;
    }

    private function runGit(string ...$arguments): void
    {
        $process = new Process(['git', ...$arguments]);
        $process->setWorkingDirectory($this->repositoryPath);
        $process->mustRun();
    }

    private function gitOutput(string ...$arguments): string
    {
        $process = new Process(['git', ...$arguments]);
        $process->setWorkingDirectory($this->repositoryPath);
        $process->mustRun();

        return $process->getOutput();
    }
}
