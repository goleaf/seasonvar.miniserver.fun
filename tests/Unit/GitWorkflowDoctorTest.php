<?php

declare(strict_types=1);

namespace Tests\Unit;

use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;
use Tests\TestCase;

final class GitWorkflowDoctorTest extends TestCase
{
    private ?string $repositoryPath = null;

    protected function tearDown(): void
    {
        if ($this->repositoryPath !== null) {
            File::deleteDirectory($this->repositoryPath);
        }

        parent::tearDown();
    }

    public function test_clean_main_repository_with_versioned_hooks_passes_local_diagnostics(): void
    {
        $this->makeRepository();

        $process = $this->runDoctor();

        $this->assertTrue($process->isSuccessful(), $process->getErrorOutput());
        $this->assertStringContainsString('[OK] Текущая ветка: main.', $process->getOutput());
        $this->assertStringContainsString('[OK] core.hooksPath=.githooks.', $process->getOutput());
        $this->assertStringContainsString('[OK] origin использует SSH.', $process->getOutput());
        $this->assertStringContainsString(
            '[WARN] Локальный origin/main отсутствует; ahead/behind не вычислен.',
            $process->getOutput(),
        );
    }

    public function test_missing_hook_and_wrong_hooks_path_are_blockers(): void
    {
        $this->makeRepository();
        File::delete($this->repositoryPath.'/.githooks/pre-push');
        $this->runGit('config', 'core.hooksPath', '.git/hooks');

        $process = $this->runDoctor();

        $this->assertSame(1, $process->getExitCode());
        $this->assertStringContainsString('[FAIL] core.hooksPath должен быть .githooks.', $process->getOutput());
        $this->assertStringContainsString('[FAIL] Отсутствует hook: .githooks/pre-push.', $process->getOutput());
    }

    public function test_branch_other_than_main_is_a_blocker(): void
    {
        $this->makeRepository('temporary');

        $process = $this->runDoctor();

        $this->assertSame(1, $process->getExitCode());
        $this->assertStringContainsString(
            '[FAIL] Работа разрешена только в main; текущая ветка: temporary.',
            $process->getOutput(),
        );
    }

    public function test_partial_dirty_state_reports_counts_without_disclosing_contents(): void
    {
        $this->makeRepository();
        File::put($this->repositoryPath.'/staged-secret-name.txt', "подготовлено\n");
        $this->runGit('add', '--', 'staged-secret-name.txt');
        File::append($this->repositoryPath.'/tracked.txt', "не в индексе\n");
        File::put($this->repositoryPath.'/untracked-secret-name.txt', "не отслеживается\n");

        $process = $this->runDoctor();

        $this->assertTrue($process->isSuccessful(), $process->getErrorOutput());
        $this->assertStringContainsString(
            '[WARN] Рабочее дерево: staged=1, unstaged=1, untracked=1.',
            $process->getOutput(),
        );
        $this->assertStringNotContainsString('staged-secret-name.txt', $process->getOutput());
        $this->assertStringNotContainsString('untracked-secret-name.txt', $process->getOutput());
    }

    public function test_https_remote_with_embedded_credentials_is_rejected_without_echoing_them(): void
    {
        $this->makeRepository();
        $this->runGit(
            'remote',
            'set-url',
            'origin',
            'https://user:super-secret-token@github.com/goleaf/seasonvar.miniserver.fun.git',
        );

        $process = $this->runDoctor();

        $this->assertSame(1, $process->getExitCode());
        $this->assertStringContainsString(
            '[FAIL] HTTPS origin содержит запрещённые embedded credentials.',
            $process->getOutput(),
        );
        $this->assertStringNotContainsString('super-secret-token', $process->getOutput());
        $this->assertStringNotContainsString('user:', $process->getOutput());
    }

    public function test_ssh_remote_for_another_repository_is_rejected_without_echoing_it(): void
    {
        $this->makeRepository();
        $this->runGit(
            'remote',
            'set-url',
            'origin',
            'git@github.com:goleaf/private-secret-repository.git',
        );

        $process = $this->runDoctor();

        $this->assertSame(1, $process->getExitCode());
        $this->assertStringContainsString(
            '[FAIL] origin не соответствует каноническому repository.',
            $process->getOutput(),
        );
        $this->assertStringNotContainsString(
            'private-secret-repository',
            $process->getOutput(),
        );
    }

    public function test_ssh_remote_requires_repository_local_identity_command(): void
    {
        $this->makeRepository();
        $this->runGit('config', '--unset', 'core.sshCommand');

        $process = $this->runDoctor();

        $this->assertSame(1, $process->getExitCode());
        $this->assertStringContainsString(
            '[FAIL] SSH origin требует repository-local exact identity.',
            $process->getOutput(),
        );
    }

    public function test_incomplete_ssh_identity_command_is_rejected_without_echoing_it(): void
    {
        $this->makeRepository();
        $this->runGit(
            'config',
            'core.sshCommand',
            'ssh -i /tmp/private-secret-key -o IdentitiesOnly=yes',
        );

        $process = $this->runDoctor();

        $this->assertSame(1, $process->getExitCode());
        $this->assertStringContainsString(
            '[FAIL] SSH identity должна явно задавать ключ, IdentitiesOnly=yes и BatchMode=yes.',
            $process->getOutput(),
        );
        $this->assertStringNotContainsString('/tmp/private-secret-key', $process->getOutput());
    }

    public function test_unknown_argument_returns_usage_error(): void
    {
        $this->makeRepository();

        $process = $this->runDoctor('--unknown');

        $this->assertSame(2, $process->getExitCode());
        $this->assertStringContainsString(
            'Использование: bash scripts/git-doctor.sh [--remote]',
            $process->getErrorOutput(),
        );
    }

    public function test_default_mode_does_not_contact_remote(): void
    {
        $this->makeRepository();

        $process = $this->runDoctor();

        $this->assertTrue($process->isSuccessful(), $process->getErrorOutput());
        $this->assertStringContainsString('[OK] origin использует SSH.', $process->getOutput());
        $this->assertStringNotContainsString('Remote main доступен', $process->getOutput());
    }

    private function makeRepository(string $branch = 'main'): string
    {
        $this->repositoryPath = sys_get_temp_dir().'/seasonvar-git-doctor-'.bin2hex(random_bytes(6));

        File::ensureDirectoryExists($this->repositoryPath);
        $this->runGit('init', '-b', $branch);
        $this->runGit('config', 'user.name', 'Seasonvar Test');
        $this->runGit('config', 'user.email', 'seasonvar@example.com');

        File::put($this->repositoryPath.'/tracked.txt', "исходное состояние\n");
        $this->runGit('add', '--', 'tracked.txt');
        $this->runGit('commit', '-m', 'Исходное состояние');

        foreach ([
            '.githooks/pre-commit',
            '.githooks/pre-push',
            '.githooks/post-commit',
            '.githooks/lib/git-guard.sh',
        ] as $hook) {
            File::ensureDirectoryExists(dirname($this->repositoryPath.'/'.$hook));
            File::copy(base_path($hook), $this->repositoryPath.'/'.$hook);
            chmod($this->repositoryPath.'/'.$hook, 0755);
        }

        $this->runGit('add', '--', '.githooks');
        $this->runGit('commit', '-m', 'Добавлены hooks');
        $this->runGit('config', 'core.hooksPath', '.githooks');
        $this->runGit(
            'remote',
            'add',
            'origin',
            'git@github.com:goleaf/seasonvar.miniserver.fun.git',
        );
        $this->runGit(
            'config',
            'core.sshCommand',
            'ssh -i /tmp/seasonvar-test-deploy -o IdentitiesOnly=yes -o BatchMode=yes',
        );

        return $this->repositoryPath;
    }

    private function runDoctor(string ...$arguments): Process
    {
        $process = new Process(
            ['bash', base_path('scripts/git-doctor.sh'), ...$arguments],
            $this->repositoryPath,
        );
        $process->setEnv([
            'GIT_CONFIG_GLOBAL' => '/dev/null',
            'GIT_CONFIG_SYSTEM' => '/dev/null',
            'LC_ALL' => 'C.UTF-8',
        ]);
        $process->run();

        return $process;
    }

    private function runGit(string ...$arguments): void
    {
        $process = new Process(['git', ...$arguments], $this->repositoryPath);
        $process->mustRun();
    }
}
