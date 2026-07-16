<?php

declare(strict_types=1);

namespace Tests\Unit;

use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\TestCase;

final class ChangelogPolicyScriptTest extends TestCase
{
    private string $changelogPath;

    private ?string $repositoryPath = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->changelogPath = sys_get_temp_dir().'/seasonvar-changelog-policy-'.bin2hex(random_bytes(6)).'.md';
    }

    protected function tearDown(): void
    {
        File::delete($this->changelogPath);

        if ($this->repositoryPath !== null) {
            File::deleteDirectory($this->repositoryPath);
        }

        parent::tearDown();
    }

    public function test_it_accepts_russian_prose_with_exact_technical_identifiers(): void
    {
        File::put($this->changelogPath, <<<'MARKDOWN'
# Журнал изменений

## 2026-07-16

- Laravel запускает `php artisan test`, проверяет `GET /titles` и сохраняет JSON через HTTPS.
MARKDOWN);

        $process = $this->runPolicyCheck($this->changelogPath);

        $this->assertTrue($process->isSuccessful(), $process->getErrorOutput());
    }

    #[DataProvider('invalidChangelogs')]
    public function test_it_rejects_english_prose(string $contents, string $expectedMessage): void
    {
        File::put($this->changelogPath, $contents);

        $process = $this->runPolicyCheck($this->changelogPath);

        $this->assertFalse($process->isSuccessful());
        $this->assertStringContainsString($expectedMessage, $process->getErrorOutput());
    }

    public static function invalidChangelogs(): array
    {
        return [
            'полностью английская строка' => [
                "# Журнал изменений\n\n- Added a complete search workflow.\n",
                'Строка 3 должна содержать русский текст',
            ],
            'английское предложение после русского начала' => [
                "# Журнал изменений\n\n- Добавлен поиск. The old behavior remains unchanged.\n",
                'Строка 3 содержит английский обычный текст',
            ],
        ];
    }

    public function test_staged_mode_reads_the_git_index_instead_of_the_working_file(): void
    {
        $this->repositoryPath = sys_get_temp_dir().'/seasonvar-changelog-repository-'.bin2hex(random_bytes(6));
        File::makeDirectory($this->repositoryPath, recursive: true);
        File::put($this->repositoryPath.'/CHANGELOG.md', "# Журнал изменений\n\n- Добавлен поиск.\n");

        $this->runGit('init', '-b', 'main');
        $this->runGit('config', 'user.name', 'Seasonvar Test');
        $this->runGit('config', 'user.email', 'seasonvar@example.com');
        $this->runGit('add', 'CHANGELOG.md');

        File::put($this->repositoryPath.'/CHANGELOG.md', "# Changelog\n\n- Added search.\n");

        $process = new Process(['bash', base_path('scripts/check-changelog-policy.sh'), '--staged']);
        $process->setWorkingDirectory($this->repositoryPath);
        $process->run();

        $this->assertTrue($process->isSuccessful(), $process->getErrorOutput());
    }

    private function runPolicyCheck(string ...$arguments): Process
    {
        $process = new Process(['bash', base_path('scripts/check-changelog-policy.sh'), ...$arguments]);
        $process->run();

        return $process;
    }

    private function runGit(string ...$arguments): void
    {
        $process = new Process(['git', ...$arguments]);
        $process->setWorkingDirectory($this->repositoryPath);
        $process->mustRun();
    }
}
