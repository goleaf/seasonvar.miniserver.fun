<?php

declare(strict_types=1);

namespace Tests\Unit;

use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\TestCase;

final class GitWorkspaceLeaseScriptTest extends TestCase
{
    private string $repositoryPath;

    private string $scriptPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->repositoryPath = sys_get_temp_dir().'/seasonvar workspace lease '.bin2hex(random_bytes(6));
        $this->scriptPath = base_path('scripts/task-workspace-lease.sh');

        File::makeDirectory($this->repositoryPath, recursive: true);
        File::put($this->repositoryPath.'/tracked.txt', "исходное состояние\n");

        $this->runGit('init', '-b', 'main');
        $this->runGit('config', 'user.name', 'Seasonvar Test');
        $this->runGit('config', 'user.email', 'seasonvar@example.com');
        $this->runGit('add', 'tracked.txt');
        $this->runGit('commit', '-m', 'Исходное состояние');
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->repositoryPath);

        parent::tearDown();
    }

    public function test_acquire_is_atomic_and_preserves_the_first_owner_metadata(): void
    {
        $first = $this->runLease('acquire', 'task-a');

        $this->assertTrue($first->isSuccessful(), $first->getErrorOutput());

        $token = $this->tokenFrom($first);
        $metadata = File::get($this->metadataPath());
        $second = $this->runLease('acquire', 'task-b');

        $this->assertFalse($second->isSuccessful());
        $this->assertSame($metadata, File::get($this->metadataPath()));
        $this->assertStringNotContainsString($token, $metadata);
        $this->assertStringContainsString('task_id=task-a', $metadata);
        $this->assertStringContainsString('owner_pid='.getmypid(), $metadata);
        $this->assertStringContainsString('token_sha256='.hash('sha256', $token), $metadata);
        $this->assertSame(1, substr_count($first->getOutput(), $token));
    }

    public function test_status_exposes_only_safe_owner_metadata(): void
    {
        $acquire = $this->runLease('acquire', 'status-task');
        $token = $this->tokenFrom($acquire);

        $status = $this->runLease('status');

        $this->assertTrue($status->isSuccessful(), $status->getErrorOutput());
        $this->assertStringContainsString('task_id=status-task', $status->getOutput());
        $this->assertStringContainsString('owner_pid='.getmypid(), $status->getOutput());
        $this->assertMatchesRegularExpression('/acquired_at=\\d{4}-\\d{2}-\\d{2}T\\d{2}:\\d{2}:\\d{2}Z/', $status->getOutput());
        $this->assertStringNotContainsString($token, $status->getOutput());
        $this->assertStringNotContainsString(hash('sha256', $token), $status->getOutput());
        $this->assertStringNotContainsString('token', $status->getOutput());
        $this->assertStringNotContainsString('digest', $status->getOutput());
    }

    public function test_only_the_matching_token_can_release_the_exact_lease(): void
    {
        $acquire = $this->runLease('acquire', 'release-task');
        $token = $this->tokenFrom($acquire);
        $metadata = File::get($this->metadataPath());

        $wrongRelease = $this->runLeaseWithToken(str_repeat('0', 64), 'release', 'release-task');

        $this->assertFalse($wrongRelease->isSuccessful());
        $this->assertSame($metadata, File::get($this->metadataPath()));

        $matchingRelease = $this->runLeaseWithToken($token, 'release', 'release-task');

        $this->assertTrue($matchingRelease->isSuccessful(), $matchingRelease->getErrorOutput());
        $this->assertDirectoryDoesNotExist($this->leasePath());
    }

    public function test_unexpected_files_block_release_without_destroying_metadata(): void
    {
        $acquire = $this->runLease('acquire', 'protected-release');
        $token = $this->tokenFrom($acquire);
        $metadata = File::get($this->metadataPath());
        $unexpectedPath = $this->leasePath().'/unexpected';
        File::put($unexpectedPath, "неизвестный файл\n");

        $blockedRelease = $this->runLeaseWithToken($token, 'release', 'protected-release');

        $this->assertFalse($blockedRelease->isSuccessful());
        $this->assertSame($metadata, File::get($this->metadataPath()));
        $this->assertFileExists($unexpectedPath);

        File::delete($unexpectedPath);
        $release = $this->runLeaseWithToken($token, 'release', 'protected-release');

        $this->assertTrue($release->isSuccessful(), $release->getErrorOutput());
        $this->assertDirectoryDoesNotExist($this->leasePath());
    }

    public function test_stale_recovery_is_explicit_and_refuses_a_live_owner(): void
    {
        $liveAcquire = $this->runLease('acquire', 'live-task');
        $liveToken = $this->tokenFrom($liveAcquire);

        $liveRecovery = $this->runLease('recover', 'live-task');

        $this->assertFalse($liveRecovery->isSuccessful());
        $this->assertFileExists($this->metadataPath());

        $this->runLeaseWithToken($liveToken, 'release', 'live-task');

        $staleAcquire = $this->runLeaseWithOwnerPid(2147483647, 'acquire', 'stale-task');
        $this->assertTrue($staleAcquire->isSuccessful(), $staleAcquire->getErrorOutput());

        $implicitRecovery = $this->runLease('acquire', 'other-task');
        $wrongTaskRecovery = $this->runLease('recover', 'other-task');

        $this->assertFalse($implicitRecovery->isSuccessful());
        $this->assertFalse($wrongTaskRecovery->isSuccessful());
        $this->assertFileExists($this->metadataPath());

        $explicitRecovery = $this->runLease('recover', 'stale-task');

        $this->assertTrue($explicitRecovery->isSuccessful(), $explicitRecovery->getErrorOutput());
        $this->assertDirectoryDoesNotExist($this->leasePath());
    }

    public function test_competing_acquires_in_a_path_with_spaces_have_one_winner(): void
    {
        $first = $this->newLeaseProcess(['acquire', 'concurrent-a']);
        $second = $this->newLeaseProcess(['acquire', 'concurrent-b']);

        $first->start();
        $second->start();
        $first->wait();
        $second->wait();

        $successful = array_values(array_filter(
            [$first, $second],
            static fn (Process $process): bool => $process->isSuccessful(),
        ));

        $this->assertCount(1, $successful);

        $winner = $successful[0];
        $token = $this->tokenFrom($winner);
        $taskId = str_contains($winner->getOutput(), 'task_id=concurrent-a')
            ? 'concurrent-a'
            : 'concurrent-b';

        $release = $this->runLeaseWithToken($token, 'release', $taskId);

        $this->assertTrue($release->isSuccessful(), $release->getErrorOutput());
    }

    public function test_commands_do_not_change_tracked_or_staged_files(): void
    {
        $statusBefore = $this->gitOutput('status', '--porcelain=v1');
        $stagedBefore = $this->gitOutput('diff', '--cached', '--binary');
        $trackedBefore = $this->gitOutput('diff', '--binary');

        $acquire = $this->runLease('acquire', 'clean-tree-task');
        $token = $this->tokenFrom($acquire);
        $this->runLease('status');
        $release = $this->runLeaseWithToken($token, 'release', 'clean-tree-task');

        $this->assertTrue($release->isSuccessful(), $release->getErrorOutput());
        $this->assertSame($statusBefore, $this->gitOutput('status', '--porcelain=v1'));
        $this->assertSame($stagedBefore, $this->gitOutput('diff', '--cached', '--binary'));
        $this->assertSame($trackedBefore, $this->gitOutput('diff', '--binary'));
    }

    #[DataProvider('invalidTaskIds')]
    public function test_task_id_is_rejected_before_any_lease_path_is_created(string $taskId): void
    {
        $process = $this->runLease('acquire', $taskId);

        $this->assertFalse($process->isSuccessful());
        $this->assertDirectoryDoesNotExist($this->leasePath());
    }

    /**
     * @return array<string, array{string}>
     */
    public static function invalidTaskIds(): array
    {
        return [
            'пустой' => [''],
            'переход к родителю' => ['../other'],
            'пробел' => ['task owner'],
            'управляющий символ' => ["task\nowner"],
            'слишком длинный' => [str_repeat('a', 65)],
        ];
    }

    private function runLease(string ...$arguments): Process
    {
        $process = $this->newLeaseProcess($arguments);
        $process->run();

        return $process;
    }

    private function runLeaseWithToken(string $token, string ...$arguments): Process
    {
        $process = $this->newLeaseProcess($arguments, [
            'SEASONVAR_TASK_LEASE_TOKEN' => $token,
        ]);
        $process->run();

        return $process;
    }

    private function runLeaseWithOwnerPid(int $ownerPid, string ...$arguments): Process
    {
        $process = $this->newLeaseProcess($arguments, [
            'SEASONVAR_TASK_LEASE_OWNER_PID' => (string) $ownerPid,
        ]);
        $process->run();

        return $process;
    }

    /**
     * @param  list<string>  $arguments
     * @param  array<string, string>  $environment
     */
    private function newLeaseProcess(array $arguments, array $environment = []): Process
    {
        return new Process(
            ['bash', $this->scriptPath, ...$arguments],
            $this->repositoryPath,
            [
                'SEASONVAR_TASK_LEASE_OWNER_PID' => (string) getmypid(),
                'SEASONVAR_TASK_LEASE_TOKEN' => false,
                ...$environment,
            ],
        );
    }

    private function tokenFrom(Process $process): string
    {
        $this->assertMatchesRegularExpression(
            '/^SEASONVAR_TASK_LEASE_TOKEN=([a-f0-9]{64})$/m',
            $process->getOutput(),
        );

        preg_match('/^SEASONVAR_TASK_LEASE_TOKEN=([a-f0-9]{64})$/m', $process->getOutput(), $matches);

        return $matches[1];
    }

    private function leasePath(): string
    {
        return $this->repositoryPath.'/.git/seasonvar-task-workspace-lease';
    }

    private function metadataPath(): string
    {
        return $this->leasePath().'/metadata';
    }

    private function runGit(string ...$arguments): void
    {
        $process = new Process(['git', ...$arguments], $this->repositoryPath);
        $process->mustRun();
    }

    private function gitOutput(string ...$arguments): string
    {
        $process = new Process(['git', ...$arguments], $this->repositoryPath);
        $process->mustRun();

        return $process->getOutput();
    }
}
