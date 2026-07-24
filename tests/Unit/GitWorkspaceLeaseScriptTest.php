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
        $this->assertStringContainsString('paths_declared=no', $status->getOutput());
    }

    public function test_path_declaration_requires_the_matching_active_lease_and_preserves_previous_state_on_failure(): void
    {
        $withoutLease = $this->declarePaths(str_repeat('1', 64), 'paths-task', ['tracked.txt']);

        $this->assertFalse($withoutLease->isSuccessful());

        $acquire = $this->runLease('acquire', 'paths-task');
        $token = $this->tokenFrom($acquire);
        $declaration = $this->declarePaths($token, 'paths-task', ['tracked.txt']);

        $this->assertTrue($declaration->isSuccessful(), $declaration->getErrorOutput());

        $manifest = File::get($this->declaredPathsPath());
        $metadata = File::get($this->declaredPathsMetadataPath());
        File::put($this->repositoryPath.'/tracked.txt', "проверенная версия\n");
        $this->runGit('add', 'tracked.txt');
        $approval = $this->runLeaseWithToken($token, 'approve-index', 'paths-task');

        $this->assertTrue($approval->isSuccessful(), $approval->getErrorOutput());

        $wrongToken = $this->declarePaths(str_repeat('2', 64), 'paths-task', ['other.txt']);
        $wrongTask = $this->declarePaths($token, 'other-task', ['other.txt']);

        $this->assertFalse($wrongToken->isSuccessful());
        $this->assertFalse($wrongTask->isSuccessful());
        $this->assertSame($manifest, File::get($this->declaredPathsPath()));
        $this->assertSame($metadata, File::get($this->declaredPathsMetadataPath()));
        $this->assertFileExists($this->approvedIndexPath());
    }

    #[DataProvider('invalidDeclaredPathInputs')]
    public function test_path_declaration_rejects_malformed_or_unsafe_nul_input(string $input): void
    {
        $acquire = $this->runLease('acquire', 'invalid-paths');
        $token = $this->tokenFrom($acquire);
        $declaration = $this->runLeaseWithTokenAndInput(
            $token,
            $input,
            'declare-paths',
            'invalid-paths',
        );

        $this->assertFalse($declaration->isSuccessful());
        $this->assertFileDoesNotExist($this->declaredPathsPath());
        $this->assertFileDoesNotExist($this->declaredPathsMetadataPath());
    }

    /**
     * @return array<string, array{string}>
     */
    public static function invalidDeclaredPathInputs(): array
    {
        return [
            'пустой input' => [''],
            'нет завершающего NUL' => ['tracked.txt'],
            'пустая запись' => ["tracked.txt\0\0"],
            'дубликат' => ["tracked.txt\0tracked.txt\0"],
            'абсолютный путь' => ["/tmp/file.txt\0"],
            'dot component' => ["dir/./file.txt\0"],
            'parent component' => ["dir/../file.txt\0"],
            'Git directory' => [".git\0"],
            'Git descendant' => [".git/config\0"],
        ];
    }

    public function test_path_manifest_is_binary_safe_deterministic_private_and_can_be_declared_before_staging(): void
    {
        $acquire = $this->runLease('acquire', 'binary-paths');
        $token = $this->tokenFrom($acquire);
        $paths = [
            "new path\nwith newline.txt",
            'tracked.txt',
            'deleted file.txt',
        ];

        File::put($this->repositoryPath.'/deleted file.txt', "для удаления\n");
        $this->runGit('add', 'deleted file.txt');
        $this->runGit('commit', '-m', 'Добавлен удаляемый файл');
        File::put($this->repositoryPath.'/'.$paths[0], "новый файл\n");
        File::delete($this->repositoryPath.'/deleted file.txt');

        $declaration = $this->declarePaths($token, 'binary-paths', $paths);

        $this->assertTrue($declaration->isSuccessful(), $declaration->getErrorOutput());
        $this->assertSame("declared_task_id=binary-paths\n", $declaration->getOutput());
        $this->assertSame('0600', substr(sprintf('%o', fileperms($this->declaredPathsPath())), -4));
        $this->assertSame('0600', substr(sprintf('%o', fileperms($this->declaredPathsMetadataPath())), -4));

        $expectedPaths = $paths;
        sort($expectedPaths, SORT_STRING);
        $expectedManifest = implode("\0", $expectedPaths)."\0";
        $manifest = File::get($this->declaredPathsPath());
        $metadata = File::get($this->declaredPathsMetadataPath());

        $this->assertSame($expectedManifest, $manifest);
        $this->assertMatchesRegularExpression(
            '/^task_id=binary-paths\\ndeclared_at=\\d{4}-\\d{2}-\\d{2}T\\d{2}:\\d{2}:\\d{2}Z\\npaths_sha256=[a-f0-9]{64}\\n$/',
            $metadata,
        );
        $this->assertStringContainsString('paths_sha256='.hash('sha256', $manifest), $metadata);
        $this->assertStringNotContainsString($token, $metadata);
        $this->assertStringNotContainsString($paths[0], $metadata);
        $this->assertStringNotContainsString($paths[0], $declaration->getOutput());

        $status = $this->runLease('status');

        $this->assertTrue($status->isSuccessful(), $status->getErrorOutput());
        $this->assertStringContainsString('paths_declared=yes', $status->getOutput());
        $this->assertStringNotContainsString($paths[0], $status->getOutput());
        $this->assertStringNotContainsString(hash('sha256', $manifest), $status->getOutput());
        $this->assertStringNotContainsString('paths_sha256', $status->getOutput());
        $this->assertStringNotContainsString('declared_at', $status->getOutput());
    }

    #[DataProvider('declaredPathScenarios')]
    public function test_path_verification_accepts_exact_add_edit_delete_rename_and_mode_sets(string $scenario): void
    {
        $acquire = $this->runLease('acquire', 'exact-paths');
        $token = $this->tokenFrom($acquire);
        $paths = match ($scenario) {
            'add' => ['added.txt'],
            'edit', 'delete', 'mode' => ['tracked.txt'],
            'rename' => ['renamed.txt', 'tracked.txt'],
            default => self::fail('Неизвестный declared-path scenario: '.$scenario),
        };

        $declaration = $this->declarePaths($token, 'exact-paths', $paths);
        $this->assertTrue($declaration->isSuccessful(), $declaration->getErrorOutput());

        match ($scenario) {
            'add' => $this->stageAddedFile(),
            'edit' => $this->stageEditedFile(),
            'delete' => $this->runGit('rm', '-f', 'tracked.txt'),
            'rename' => $this->runGit('mv', 'tracked.txt', 'renamed.txt'),
            'mode' => $this->stageModeChange(),
            default => null,
        };

        $verification = $this->runLeaseWithToken($token, 'verify-paths', 'exact-paths');

        $this->assertTrue($verification->isSuccessful(), $verification->getErrorOutput());
        $this->assertSame("verified_paths_task_id=exact-paths\n", $verification->getOutput());
    }

    /**
     * @return array<string, array{string}>
     */
    public static function declaredPathScenarios(): array
    {
        return [
            'добавление' => ['add'],
            'редактирование' => ['edit'],
            'удаление' => ['delete'],
            'переименование' => ['rename'],
            'смена mode' => ['mode'],
        ];
    }

    public function test_path_verification_and_approval_reject_missing_or_additional_staged_paths(): void
    {
        $acquire = $this->runLease('acquire', 'mismatch-paths');
        $token = $this->tokenFrom($acquire);
        $declaration = $this->declarePaths($token, 'mismatch-paths', ['tracked.txt', 'missing.txt']);

        $this->assertTrue($declaration->isSuccessful(), $declaration->getErrorOutput());

        File::put($this->repositoryPath.'/tracked.txt', "проверенная версия\n");
        $this->runGit('add', 'tracked.txt');

        $missing = $this->runLeaseWithToken($token, 'verify-paths', 'mismatch-paths');
        $approval = $this->runLeaseWithToken($token, 'approve-index', 'mismatch-paths');

        $this->assertFalse($missing->isSuccessful());
        $this->assertFalse($approval->isSuccessful());
        $this->assertFileDoesNotExist($this->approvedIndexPath());

        $replacement = $this->declarePaths($token, 'mismatch-paths', ['tracked.txt']);
        $this->assertTrue($replacement->isSuccessful(), $replacement->getErrorOutput());
        $this->stageAddedFile();

        $additional = $this->runLeaseWithToken($token, 'verify-paths', 'mismatch-paths');

        $this->assertFalse($additional->isSuccessful());
    }

    public function test_successful_redeclaration_invalidates_approval_but_failed_redeclaration_preserves_it(): void
    {
        $acquire = $this->runLease('acquire', 'redeclare-paths');
        $token = $this->tokenFrom($acquire);
        $declaration = $this->declarePaths($token, 'redeclare-paths', ['tracked.txt']);

        $this->assertTrue($declaration->isSuccessful(), $declaration->getErrorOutput());

        File::put($this->repositoryPath.'/tracked.txt', "проверенная версия\n");
        $this->runGit('add', 'tracked.txt');
        $approval = $this->runLeaseWithToken($token, 'approve-index', 'redeclare-paths');

        $this->assertTrue($approval->isSuccessful(), $approval->getErrorOutput());

        $failed = $this->runLeaseWithTokenAndInput(
            $token,
            "tracked.txt\0tracked.txt\0",
            'declare-paths',
            'redeclare-paths',
        );

        $this->assertFalse($failed->isSuccessful());
        $this->assertFileExists($this->approvedIndexPath());

        $replacement = $this->declarePaths($token, 'redeclare-paths', ['other.txt']);

        $this->assertTrue($replacement->isSuccessful(), $replacement->getErrorOutput());
        $this->assertFileDoesNotExist($this->approvedIndexPath());
        $this->assertFalse(
            $this->runLeaseWithToken($token, 'verify-index', 'redeclare-paths')->isSuccessful(),
        );
    }

    public function test_index_approval_requires_a_matching_active_lease_and_non_empty_staged_diff(): void
    {
        $withoutLease = $this->runLeaseWithToken(str_repeat('1', 64), 'approve-index', 'approval-task');

        $this->assertFalse($withoutLease->isSuccessful());

        $acquire = $this->runLease('acquire', 'approval-task');
        $token = $this->tokenFrom($acquire);
        $emptyIndex = $this->runLeaseWithToken($token, 'approve-index', 'approval-task');

        $this->assertFalse($emptyIndex->isSuccessful());
        $this->assertFileDoesNotExist($this->approvedIndexPath());

        File::put($this->repositoryPath.'/tracked.txt', "проверенная версия\n");
        $this->runGit('add', 'tracked.txt');
        $declaration = $this->declarePaths($token, 'approval-task', ['tracked.txt']);

        $this->assertTrue($declaration->isSuccessful(), $declaration->getErrorOutput());

        $wrongToken = $this->runLeaseWithToken(str_repeat('2', 64), 'approve-index', 'approval-task');
        $wrongTask = $this->runLeaseWithToken($token, 'approve-index', 'other-task');

        $this->assertFalse($wrongToken->isSuccessful());
        $this->assertFalse($wrongTask->isSuccessful());
        $this->assertFileDoesNotExist($this->approvedIndexPath());
    }

    public function test_index_approval_refuses_unmerged_entries(): void
    {
        $acquire = $this->runLease('acquire', 'conflict-task');
        $token = $this->tokenFrom($acquire);
        $declaration = $this->declarePaths($token, 'conflict-task', ['tracked.txt']);

        $this->assertTrue($declaration->isSuccessful(), $declaration->getErrorOutput());
        $this->runGit('switch', '-c', 'conflict-source');
        File::put($this->repositoryPath.'/tracked.txt', "изменение source\n");
        $this->runGit('add', 'tracked.txt');
        $this->runGit('commit', '-m', 'Изменение source');
        $this->runGit('switch', 'main');
        File::put($this->repositoryPath.'/tracked.txt', "изменение main\n");
        $this->runGit('add', 'tracked.txt');
        $this->runGit('commit', '-m', 'Изменение main');

        $merge = $this->runGitProcess('merge', 'conflict-source');

        $this->assertFalse($merge->isSuccessful());
        $this->assertNotSame('', $this->gitOutput('ls-files', '--unmerged'));

        $approval = $this->runLeaseWithToken($token, 'approve-index', 'conflict-task');

        $this->assertFalse($approval->isSuccessful());
        $this->assertFileDoesNotExist($this->approvedIndexPath());
    }

    public function test_approval_metadata_status_and_binary_safe_digest_are_safe(): void
    {
        $acquire = $this->runLease('acquire', 'metadata-task');
        $token = $this->tokenFrom($acquire);
        $statusBefore = $this->runLease('status');

        $this->assertTrue($statusBefore->isSuccessful(), $statusBefore->getErrorOutput());
        $this->assertStringContainsString('index_approved=no', $statusBefore->getOutput());

        $path = "path with spaces\nand newline.txt";
        File::put($this->repositoryPath.'/'.$path, "binary-safe content\n");
        $declaration = $this->declarePaths($token, 'metadata-task', [$path]);
        $this->runGit('add', '--', $path);

        $this->assertTrue($declaration->isSuccessful(), $declaration->getErrorOutput());
        $approval = $this->runLeaseWithToken($token, 'approve-index', 'metadata-task');

        $this->assertTrue($approval->isSuccessful(), $approval->getErrorOutput());
        $this->assertSame("approved_task_id=metadata-task\n", $approval->getOutput());
        $this->assertFileExists($this->approvedIndexPath());
        $this->assertSame('0600', substr(sprintf('%o', fileperms($this->approvedIndexPath())), -4));

        $approvalMetadata = File::get($this->approvedIndexPath());
        $expectedDigest = hash('sha256', $this->gitOutput('ls-files', '--stage', '-z', '--'));

        $this->assertMatchesRegularExpression(
            '/^task_id=metadata-task\\napproved_at=\\d{4}-\\d{2}-\\d{2}T\\d{2}:\\d{2}:\\d{2}Z\\nindex_sha256=[a-f0-9]{64}\\n$/',
            $approvalMetadata,
        );
        $this->assertStringContainsString('index_sha256='.$expectedDigest, $approvalMetadata);
        $this->assertStringNotContainsString($token, $approvalMetadata);
        $this->assertStringNotContainsString(hash('sha256', $token), $approvalMetadata);
        $this->assertStringNotContainsString($path, $approvalMetadata);
        $this->assertStringNotContainsString('binary-safe content', $approvalMetadata);

        $status = $this->runLease('status');

        $this->assertTrue($status->isSuccessful(), $status->getErrorOutput());
        $this->assertStringContainsString('index_approved=yes', $status->getOutput());
        $this->assertStringNotContainsString($expectedDigest, $status->getOutput());
        $this->assertStringNotContainsString('index_sha256', $status->getOutput());
        $this->assertStringNotContainsString('approved_at', $status->getOutput());
        $this->assertStringNotContainsString($path, $status->getOutput());
    }

    public function test_verify_accepts_the_reviewed_index_and_reapproval_accepts_a_new_snapshot(): void
    {
        $acquire = $this->runLease('acquire', 'verify-task');
        $token = $this->tokenFrom($acquire);

        File::put($this->repositoryPath.'/tracked.txt', "первая проверенная версия\n");
        $declaration = $this->declarePaths($token, 'verify-task', ['tracked.txt']);
        $this->runGit('add', 'tracked.txt');

        $this->assertTrue($declaration->isSuccessful(), $declaration->getErrorOutput());
        $firstApproval = $this->runLeaseWithToken($token, 'approve-index', 'verify-task');
        $firstVerify = $this->runLeaseWithToken($token, 'verify-index', 'verify-task');

        $this->assertTrue($firstApproval->isSuccessful(), $firstApproval->getErrorOutput());
        $this->assertTrue($firstVerify->isSuccessful(), $firstVerify->getErrorOutput());
        $this->assertSame("verified_task_id=verify-task\n", $firstVerify->getOutput());

        File::put($this->repositoryPath.'/tracked.txt', "вторая проверенная версия\n");
        $this->runGit('add', 'tracked.txt');

        $staleVerify = $this->runLeaseWithToken($token, 'verify-index', 'verify-task');

        $this->assertFalse($staleVerify->isSuccessful());

        $secondApproval = $this->runLeaseWithToken($token, 'approve-index', 'verify-task');
        $secondVerify = $this->runLeaseWithToken($token, 'verify-index', 'verify-task');

        $this->assertTrue($secondApproval->isSuccessful(), $secondApproval->getErrorOutput());
        $this->assertTrue($secondVerify->isSuccessful(), $secondVerify->getErrorOutput());
    }

    #[DataProvider('indexMutations')]
    public function test_final_index_mutations_invalidate_approval(string $mutation): void
    {
        $acquire = $this->runLease('acquire', 'mutation-task');
        $token = $this->tokenFrom($acquire);

        File::put($this->repositoryPath.'/tracked.txt', "проверенная версия\n");
        $declaration = $this->declarePaths($token, 'mutation-task', ['tracked.txt']);
        $this->runGit('add', 'tracked.txt');

        $this->assertTrue($declaration->isSuccessful(), $declaration->getErrorOutput());
        $approval = $this->runLeaseWithToken($token, 'approve-index', 'mutation-task');

        $this->assertTrue($approval->isSuccessful(), $approval->getErrorOutput());

        match ($mutation) {
            'add' => $this->stageAddedFile(),
            'edit' => $this->stageEditedFile(),
            'delete' => $this->runGit('rm', '-f', 'tracked.txt'),
            'rename' => $this->runGit('mv', 'tracked.txt', 'renamed.txt'),
            'mode' => $this->stageModeChange(),
            'unstage' => $this->runGit('restore', '--staged', 'tracked.txt'),
            default => self::fail('Неизвестная тестовая mutation: '.$mutation),
        };

        $verify = $this->runLeaseWithToken($token, 'verify-index', 'mutation-task');

        $this->assertFalse($verify->isSuccessful());
    }

    /**
     * @return array<string, array{string}>
     */
    public static function indexMutations(): array
    {
        return [
            'добавление' => ['add'],
            'редактирование' => ['edit'],
            'удаление' => ['delete'],
            'переименование' => ['rename'],
            'смена mode' => ['mode'],
            'удаление из index' => ['unstage'],
        ];
    }

    public function test_release_removes_exact_approval_and_metadata_only(): void
    {
        $acquire = $this->runLease('acquire', 'approval-release');
        $token = $this->tokenFrom($acquire);
        File::put($this->repositoryPath.'/tracked.txt', "проверенная версия\n");
        $declaration = $this->declarePaths($token, 'approval-release', ['tracked.txt']);
        $this->runGit('add', 'tracked.txt');

        $this->assertTrue($declaration->isSuccessful(), $declaration->getErrorOutput());
        $approval = $this->runLeaseWithToken($token, 'approve-index', 'approval-release');

        $this->assertTrue($approval->isSuccessful(), $approval->getErrorOutput());

        $unexpectedPath = $this->leasePath().'/unexpected';
        File::put($unexpectedPath, "неизвестный файл\n");

        $blockedRelease = $this->runLeaseWithToken($token, 'release', 'approval-release');

        $this->assertFalse($blockedRelease->isSuccessful());
        $this->assertFileExists($this->metadataPath());
        $this->assertFileExists($this->approvedIndexPath());
        $this->assertFileExists($unexpectedPath);

        File::delete($unexpectedPath);

        $release = $this->runLeaseWithToken($token, 'release', 'approval-release');

        $this->assertTrue($release->isSuccessful(), $release->getErrorOutput());
        $this->assertDirectoryDoesNotExist($this->leasePath());
    }

    public function test_malformed_declaration_metadata_blocks_status_and_release_without_deleting_the_lease(): void
    {
        $acquire = $this->runLease('acquire', 'malformed-paths');
        $token = $this->tokenFrom($acquire);
        $declaration = $this->declarePaths($token, 'malformed-paths', ['tracked.txt']);

        $this->assertTrue($declaration->isSuccessful(), $declaration->getErrorOutput());

        File::append($this->declaredPathsMetadataPath(), "unknown=value\n");

        $status = $this->runLease('status');
        $release = $this->runLeaseWithToken($token, 'release', 'malformed-paths');

        $this->assertFalse($status->isSuccessful());
        $this->assertFalse($release->isSuccessful());
        $this->assertFileExists($this->metadataPath());
        $this->assertFileExists($this->declaredPathsPath());
        $this->assertFileExists($this->declaredPathsMetadataPath());
    }

    public function test_symlinked_declaration_manifest_blocks_status_and_release_without_following_the_link(): void
    {
        $acquire = $this->runLease('acquire', 'symlink-paths');
        $token = $this->tokenFrom($acquire);
        $declaration = $this->declarePaths($token, 'symlink-paths', ['tracked.txt']);

        $this->assertTrue($declaration->isSuccessful(), $declaration->getErrorOutput());

        File::delete($this->declaredPathsPath());
        symlink($this->repositoryPath.'/tracked.txt', $this->declaredPathsPath());

        $status = $this->runLease('status');
        $release = $this->runLeaseWithToken($token, 'release', 'symlink-paths');

        $this->assertFalse($status->isSuccessful());
        $this->assertFalse($release->isSuccessful());
        $this->assertTrue(is_link($this->declaredPathsPath()));
        $this->assertFileExists($this->metadataPath());
        $this->assertFileExists($this->declaredPathsMetadataPath());
        $this->assertSame("исходное состояние\n", File::get($this->repositoryPath.'/tracked.txt'));
    }

    public function test_stale_recovery_removes_the_exact_manifest_approval_and_lease_files(): void
    {
        $acquire = $this->runLeaseWithOwnerPid(2147483647, 'acquire', 'stale-paths');
        $token = $this->tokenFrom($acquire);
        $declaration = $this->declarePaths($token, 'stale-paths', ['tracked.txt']);

        $this->assertTrue($declaration->isSuccessful(), $declaration->getErrorOutput());

        File::put($this->repositoryPath.'/tracked.txt', "проверенная версия\n");
        $this->runGit('add', 'tracked.txt');
        $approval = $this->runLeaseWithToken($token, 'approve-index', 'stale-paths');

        $this->assertTrue($approval->isSuccessful(), $approval->getErrorOutput());
        $this->assertFileExists($this->declaredPathsPath());
        $this->assertFileExists($this->declaredPathsMetadataPath());
        $this->assertFileExists($this->approvedIndexPath());

        $recovery = $this->runLease('recover', 'stale-paths');

        $this->assertTrue($recovery->isSuccessful(), $recovery->getErrorOutput());
        $this->assertDirectoryDoesNotExist($this->leasePath());
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
        File::put($this->repositoryPath.'/tracked.txt', "проверенная версия\n");
        $this->runGit('add', 'tracked.txt');

        $statusBefore = $this->gitOutput('status', '--porcelain=v1');
        $stagedBefore = $this->gitOutput('diff', '--cached', '--binary');
        $trackedBefore = $this->gitOutput('diff', '--binary');

        $acquire = $this->runLease('acquire', 'clean-tree-task');
        $token = $this->tokenFrom($acquire);
        $declaration = $this->declarePaths($token, 'clean-tree-task', ['tracked.txt']);
        $this->runLease('status');
        $approval = $this->runLeaseWithToken($token, 'approve-index', 'clean-tree-task');
        $pathsVerification = $this->runLeaseWithToken($token, 'verify-paths', 'clean-tree-task');
        $verify = $this->runLeaseWithToken($token, 'verify-index', 'clean-tree-task');
        $release = $this->runLeaseWithToken($token, 'release', 'clean-tree-task');

        $this->assertTrue($declaration->isSuccessful(), $declaration->getErrorOutput());
        $this->assertTrue($approval->isSuccessful(), $approval->getErrorOutput());
        $this->assertTrue($pathsVerification->isSuccessful(), $pathsVerification->getErrorOutput());
        $this->assertTrue($verify->isSuccessful(), $verify->getErrorOutput());
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

    /**
     * @param  list<string>  $paths
     */
    private function declarePaths(string $token, string $taskId, array $paths): Process
    {
        $input = $paths === [] ? '' : implode("\0", $paths)."\0";

        return $this->runLeaseWithTokenAndInput(
            $token,
            $input,
            'declare-paths',
            $taskId,
        );
    }

    private function runLeaseWithTokenAndInput(
        string $token,
        string $input,
        string ...$arguments,
    ): Process {
        $process = $this->newLeaseProcess($arguments, [
            'SEASONVAR_TASK_LEASE_TOKEN' => $token,
        ]);
        $process->setInput($input);
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

    private function approvedIndexPath(): string
    {
        return $this->leasePath().'/approved-index';
    }

    private function declaredPathsPath(): string
    {
        return $this->leasePath().'/declared-paths';
    }

    private function declaredPathsMetadataPath(): string
    {
        return $this->leasePath().'/declared-paths.meta';
    }

    private function stageAddedFile(): void
    {
        File::put($this->repositoryPath.'/added.txt', "добавление\n");
        $this->runGit('add', 'added.txt');
    }

    private function stageEditedFile(): void
    {
        File::put($this->repositoryPath.'/tracked.txt', "другая версия\n");
        $this->runGit('add', 'tracked.txt');
    }

    private function stageModeChange(): void
    {
        chmod($this->repositoryPath.'/tracked.txt', 0755);
        $this->runGit('add', 'tracked.txt');
    }

    private function runGit(string ...$arguments): void
    {
        $process = new Process(['git', ...$arguments], $this->repositoryPath);
        $process->mustRun();
    }

    private function runGitProcess(string ...$arguments): Process
    {
        $process = new Process(['git', ...$arguments], $this->repositoryPath);
        $process->run();

        return $process;
    }

    private function gitOutput(string ...$arguments): string
    {
        $process = new Process(['git', ...$arguments], $this->repositoryPath);
        $process->mustRun();

        return $process->getOutput();
    }
}
