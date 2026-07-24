<?php

declare(strict_types=1);

namespace Tests\Unit;

use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\TestCase;

final class CurrentPlanPolicyScriptTest extends TestCase
{
    private string $fixtureDirectory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fixtureDirectory = sys_get_temp_dir().'/seasonvar-current-plan-policy-'.bin2hex(random_bytes(6));

        File::makeDirectory($this->fixtureDirectory.'/archive', recursive: true);
        File::put(
            $this->fixtureDirectory.'/archive/2026-07-24-system-evidence.md',
            "# Архив evidence\n\nПодтверждённое состояние.\n",
        );
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->fixtureDirectory);

        parent::tearDown();
    }

    public function test_it_accepts_one_active_registry_with_existing_archive_evidence(): void
    {
        $path = $this->writePlan($this->validPlan());

        $process = $this->runPolicyCheck($path);

        $this->assertTrue($process->isSuccessful(), $process->getErrorOutput());
        $this->assertSame('', $process->getOutput());
        $this->assertSame('', $process->getErrorOutput());
    }

    #[DataProvider('invalidTopLevelHeadings')]
    public function test_it_rejects_duplicate_or_embedded_top_level_plan_bodies(
        string $extraHeading,
        string $expectedMessage,
    ): void {
        $path = $this->writePlan($this->validPlan()."\n".$extraHeading."\n");

        $process = $this->runPolicyCheck($path);

        $this->assertFalse($process->isSuccessful());
        $this->assertStringContainsString($expectedMessage, $process->getErrorOutput());
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function invalidTopLevelHeadings(): array
    {
        return [
            'вторая текущая задача' => [
                '# Текущая задача — второй владелец',
                'должен быть ровно один H1',
            ],
            'завершённое тело' => [
                '# Завершённая задача — историческое тело',
                'посторонний H1',
            ],
            'скопированный параллельный план' => [
                '# Параллельный план — импортёр',
                'посторонний H1',
            ],
        ];
    }

    public function test_it_rejects_a_missing_required_registry_section(): void
    {
        $contents = str_replace(
            "## Реестр blocked/unresolved\n\n| Workstream | Status | Evidence |\n| --- | --- | --- |\n\n",
            '',
            $this->validPlan(),
        );
        $path = $this->writePlan($contents);

        $process = $this->runPolicyCheck($path);

        $this->assertFalse($process->isSuccessful());
        $this->assertStringContainsString(
            'раздел «Реестр blocked/unresolved» должен встречаться ровно один раз',
            $process->getErrorOutput(),
        );
    }

    public function test_it_rejects_a_missing_archive_target(): void
    {
        $path = $this->writePlan(str_replace(
            'archive/2026-07-24-system-evidence.md',
            'archive/missing.md',
            $this->validPlan(),
        ));

        $process = $this->runPolicyCheck($path);

        $this->assertFalse($process->isSuccessful());
        $this->assertStringContainsString('архивный файл не найден', $process->getErrorOutput());
    }

    public function test_it_rejects_an_archive_link_that_escapes_the_archive_directory(): void
    {
        File::put($this->fixtureDirectory.'/outside.md', "# Внешний файл\n");

        $path = $this->writePlan(str_replace(
            'archive/2026-07-24-system-evidence.md',
            'archive/../outside.md',
            $this->validPlan(),
        ));

        $process = $this->runPolicyCheck($path);

        $this->assertFalse($process->isSuccessful());
        $this->assertStringContainsString('выходит за каталог archive', $process->getErrorOutput());
    }

    public function test_it_rejects_an_unknown_machine_status(): void
    {
        $path = $this->writePlan(str_replace(
            '`in_progress: standalone parser`',
            '`implementation_complete`',
            $this->validPlan(),
        ));

        $process = $this->runPolicyCheck($path);

        $this->assertFalse($process->isSuccessful());
        $this->assertStringContainsString('неподдерживаемый status', $process->getErrorOutput());
    }

    public function test_it_rejects_unresolved_registry_state_without_evidence(): void
    {
        $contents = str_replace(
            "| --- | --- | --- |\n\n## Task-specific compliance matrix",
            "| --- | --- | --- |\n| Remote delivery | `unresolved: authentication` | |\n\n## Task-specific compliance matrix",
            $this->validPlan(),
        );
        $path = $this->writePlan($contents);

        $process = $this->runPolicyCheck($path);

        $this->assertFalse($process->isSuccessful());
        $this->assertStringContainsString('требует непустое evidence', $process->getErrorOutput());
    }

    public function test_it_has_no_maximum_task_identifier(): void
    {
        $path = $this->writePlan(str_replace(
            'System Task 33',
            'System Task 999999999999999999999999999999',
            $this->validPlan(),
        ));

        $process = $this->runPolicyCheck($path);

        $this->assertTrue($process->isSuccessful(), $process->getErrorOutput());
    }

    public function test_it_has_no_line_count_ceiling_for_structurally_valid_evidence(): void
    {
        $largeEvidence = implode(
            "\n",
            array_fill(0, 6000, '- Подтверждённая строка evidence без изменения структуры.'),
        );
        $path = $this->writePlan($this->validPlan()."\n".$largeEvidence."\n");

        $process = $this->runPolicyCheck($path);

        $this->assertTrue($process->isSuccessful(), $process->getErrorOutput());
    }

    public function test_it_ignores_markdown_headings_and_links_inside_fenced_examples(): void
    {
        $contents = $this->validPlan().<<<'MARKDOWN'

```markdown
# Завершённая задача — пример
[Несуществующий пример](archive/missing-example.md)
```
MARKDOWN;
        $path = $this->writePlan($contents);

        $process = $this->runPolicyCheck($path);

        $this->assertTrue($process->isSuccessful(), $process->getErrorOutput());
    }

    public function test_it_ignores_archive_link_examples_inside_inline_code(): void
    {
        $path = $this->writePlan(
            $this->validPlan()."\nПример: `[Архив](archive/missing-example.md)`.\n",
        );

        $process = $this->runPolicyCheck($path);

        $this->assertTrue($process->isSuccessful(), $process->getErrorOutput());
    }

    public function test_it_rejects_encoded_control_characters_without_a_php_error(): void
    {
        $path = $this->writePlan(str_replace(
            'archive/2026-07-24-system-evidence.md',
            'archive/%00private.md',
            $this->validPlan(),
        ));

        $process = $this->runPolicyCheck($path);

        $this->assertSame(1, $process->getExitCode());
        $this->assertStringContainsString('недопустимые символы', $process->getErrorOutput());
        $this->assertStringNotContainsString('Fatal error', $process->getErrorOutput());
        $this->assertStringNotContainsString($this->fixtureDirectory, $process->getErrorOutput());
    }

    public function test_it_never_modifies_the_plan_or_archive_evidence(): void
    {
        $path = $this->writePlan($this->validPlan());
        $archivePath = $this->fixtureDirectory.'/archive/2026-07-24-system-evidence.md';
        $before = [
            'plan' => hash_file('sha256', $path),
            'archive' => hash_file('sha256', $archivePath),
        ];

        $process = $this->runPolicyCheck($path);

        $this->assertTrue($process->isSuccessful(), $process->getErrorOutput());
        $this->assertSame($before['plan'], hash_file('sha256', $path));
        $this->assertSame($before['archive'], hash_file('sha256', $archivePath));
    }

    public function test_diagnostics_do_not_disclose_content_or_absolute_paths(): void
    {
        $secretMarker = 'private-token-marker-should-not-be-printed';
        $path = $this->writePlan(str_replace(
            '`in_progress: standalone parser`',
            "`invalid_status: {$secretMarker}`",
            $this->validPlan(),
        ));

        $process = $this->runPolicyCheck($path);

        $this->assertFalse($process->isSuccessful());
        $this->assertStringContainsString('строка', $process->getErrorOutput());
        $this->assertStringNotContainsString($secretMarker, $process->getErrorOutput());
        $this->assertStringNotContainsString($this->fixtureDirectory, $process->getErrorOutput());
    }

    public function test_the_unmigrated_repository_current_plan_is_not_accepted_early(): void
    {
        $process = $this->runPolicyCheck(base_path('docs/plans/current-task-plan.md'));

        $this->assertFalse($process->isSuccessful());
        $this->assertStringContainsString('H1', $process->getErrorOutput());
    }

    private function runPolicyCheck(string $path): Process
    {
        $process = new Process([
            'bash',
            base_path('scripts/check-current-plan-policy.sh'),
            $path,
        ]);
        $process->setWorkingDirectory($this->fixtureDirectory);
        $process->run();

        return $process;
    }

    private function writePlan(string $contents): string
    {
        $path = $this->fixtureDirectory.'/current-task-plan.md';

        File::put($path, $contents);

        return $path;
    }

    private function validPlan(): string
    {
        return <<<'MARKDOWN'
# Текущая задача — единый реестр

## Реестр активных workstreams

| Workstream | Status | Evidence |
| --- | --- | --- |
| System Task 33 | `in_progress: standalone parser` | [Подтверждение](archive/2026-07-24-system-evidence.md) |

## Реестр blocked/unresolved

| Workstream | Status | Evidence |
| --- | --- | --- |

## Task-specific compliance matrix

| Requirement | Status | Evidence |
| --- | --- | --- |
| Security | `already_compliant` | [Проверка](archive/2026-07-24-system-evidence.md) |
| Production activation | `not_applicable` | Standalone parser не меняет runtime |

## Последнее подтверждённое evidence

- [Архив Task 33](archive/2026-07-24-system-evidence.md)
MARKDOWN;
    }
}
