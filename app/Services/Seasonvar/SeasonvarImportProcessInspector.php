<?php

namespace App\Services\Seasonvar;

use App\Models\SeasonvarImportRun;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Symfony\Component\Process\Process;
use Throwable;

class SeasonvarImportProcessInspector
{
    /**
     * @return array{pid: int|null, host: string|null, command: string|null, recorded_at: string}
     */
    public function currentProcess(): array
    {
        $pid = getmypid();
        $host = gethostname();

        return [
            'pid' => $pid === false ? null : $pid,
            'host' => $host === false ? null : $host,
            'command' => $this->currentCommand(),
            'recorded_at' => now()->toIso8601String(),
        ];
    }

    /**
     * @param  array<string, mixed>|null  $lockProcess
     * @param  Collection<int, SeasonvarImportRun>  $runningRuns
     * @return array{running: bool, verified: bool, pid: int|null, run_id: int|null, source: string|null, checks: array<int, string>}
     */
    public function inspect(?array $lockProcess, Collection $runningRuns): array
    {
        $checks = [];

        foreach ($this->processCandidates($lockProcess, $runningRuns) as $candidate) {
            $result = $this->inspectCandidate($candidate);
            $checks = [...$checks, ...$result['checks']];

            if ($result['running']) {
                return [
                    'running' => true,
                    'verified' => true,
                    'pid' => $candidate['pid'],
                    'run_id' => $candidate['run_id'],
                    'source' => $candidate['source'],
                    'checks' => $checks,
                ];
            }
        }

        $fallback = $this->inspectProcessLists();
        $checks = [...$checks, ...$fallback['checks']];

        if ($fallback['running']) {
            return [
                'running' => true,
                'verified' => true,
                'pid' => $fallback['pid'],
                'run_id' => null,
                'source' => 'process-list',
                'checks' => $checks,
            ];
        }

        return [
            'running' => false,
            'verified' => $fallback['verified'] || $checks !== [],
            'pid' => null,
            'run_id' => null,
            'source' => null,
            'checks' => $checks,
        ];
    }

    private function currentCommand(): ?string
    {
        $argv = $_SERVER['argv'] ?? null;

        if (! is_array($argv) || $argv === []) {
            return null;
        }

        return implode(' ', array_map(static fn (mixed $argument): string => (string) $argument, $argv));
    }

    /**
     * @param  array<string, mixed>|null  $lockProcess
     * @param  Collection<int, SeasonvarImportRun>  $runningRuns
     * @return array<int, array{pid: int, host: string|null, command: string|null, run_id: int|null, source: string, started_at: CarbonInterface|null}>
     */
    private function processCandidates(?array $lockProcess, Collection $runningRuns): array
    {
        $candidates = [];
        $lockCandidate = $this->candidateFromArray($lockProcess, 'cache-lock', null, null);

        if ($lockCandidate !== null) {
            $candidates[] = $lockCandidate;
        }

        foreach ($runningRuns as $run) {
            $candidate = $this->candidateFromArray([
                'pid' => $run->process_id,
                'host' => $run->process_host,
                'command' => $run->process_command,
            ], 'database-run', (int) $run->id, $run->started_at);

            if ($candidate !== null) {
                $candidates[] = $candidate;
            }
        }

        return $candidates;
    }

    /**
     * @param  array<string, mixed>|null  $process
     * @return array{pid: int, host: string|null, command: string|null, run_id: int|null, source: string, started_at: CarbonInterface|null}|null
     */
    private function candidateFromArray(?array $process, string $source, ?int $runId, ?CarbonInterface $startedAt): ?array
    {
        $pid = (int) ($process['pid'] ?? 0);

        if ($pid <= 0) {
            return null;
        }

        return [
            'pid' => $pid,
            'host' => isset($process['host']) ? (string) $process['host'] : null,
            'command' => isset($process['command']) ? (string) $process['command'] : null,
            'run_id' => $runId,
            'source' => $source,
            'started_at' => $startedAt,
        ];
    }

    /**
     * @param  array{pid: int, host: string|null, command: string|null, run_id: int|null, source: string, started_at: CarbonInterface|null}  $candidate
     * @return array{running: bool, checks: array<int, string>}
     */
    private function inspectCandidate(array $candidate): array
    {
        $pid = $candidate['pid'];
        $checks = [sprintf('%s:pid=%d', $candidate['source'], $pid)];
        $localHost = gethostname();

        if ($localHost !== false && $candidate['host'] !== null && $candidate['host'] !== $localHost) {
            $checks[] = sprintf('host:other:%s', $candidate['host']);

            return ['running' => false, 'checks' => $checks];
        }

        if ($this->isCurrentAttemptPid($pid)) {
            $checks[] = 'pid:current-attempt';

            return ['running' => false, 'checks' => $checks];
        }

        $alive = false;
        $commandMatches = false;
        $zombie = false;
        $processTooNew = false;

        $posixAlive = $this->posixProcessExists($pid);
        if ($posixAlive !== null) {
            $alive = $alive || $posixAlive;
            $checks[] = 'posix:'.($posixAlive ? 'alive' : 'missing');
        }

        $procDir = '/proc/'.$pid;
        if (is_dir($procDir)) {
            $alive = true;
            $checks[] = 'proc:exists';
        } else {
            $checks[] = 'proc:missing';
        }

        $procCommand = $this->readProcCommand($pid);
        if ($procCommand !== null) {
            $procMatches = $this->isSeasonvarImportCommand($procCommand);
            $commandMatches = $commandMatches || $procMatches;
            $checks[] = 'proc-cmdline:'.($procMatches ? 'match' : 'miss');
        } else {
            $checks[] = 'proc-cmdline:unavailable';
        }

        $psResult = $this->inspectPidWithPs($pid, $candidate['started_at']);
        $alive = $alive || $psResult['alive'];
        $commandMatches = $commandMatches || $psResult['command_matches'];
        $zombie = $zombie || $psResult['zombie'];
        $processTooNew = $processTooNew || $psResult['process_too_new'];
        $checks = [...$checks, ...$psResult['checks']];

        return [
            'running' => $alive && $commandMatches && ! $zombie && ! $processTooNew,
            'checks' => $checks,
        ];
    }

    private function posixProcessExists(int $pid): ?bool
    {
        if (! function_exists('posix_kill')) {
            return null;
        }

        if (posix_kill($pid, 0)) {
            return true;
        }

        if (! function_exists('posix_get_last_error')) {
            return false;
        }

        return posix_get_last_error() === 1;
    }

    private function readProcCommand(int $pid): ?string
    {
        $path = '/proc/'.$pid.'/cmdline';

        if (! is_readable($path)) {
            return null;
        }

        $contents = @file_get_contents($path);

        if (! is_string($contents) || $contents === '') {
            return null;
        }

        return trim(str_replace("\0", ' ', $contents));
    }

    /**
     * @return array{alive: bool, command_matches: bool, zombie: bool, process_too_new: bool, checks: array<int, string>}
     */
    private function inspectPidWithPs(int $pid, ?CarbonInterface $startedAt): array
    {
        $output = $this->runCommand(['ps', '-p', (string) $pid, '-o', 'pid=', '-o', 'stat=', '-o', 'etimes=', '-o', 'command=']);

        if ($output === null || trim($output) === '') {
            return [
                'alive' => false,
                'command_matches' => false,
                'zombie' => false,
                'process_too_new' => false,
                'checks' => ['ps-pid:missing'],
            ];
        }

        $parts = preg_split('/\s+/', trim($output), 4);
        $stat = isset($parts[1]) ? (string) $parts[1] : '';
        $elapsedSeconds = isset($parts[2]) ? max(0, (int) $parts[2]) : null;
        $command = isset($parts[3]) ? (string) $parts[3] : '';
        $commandMatches = $this->isSeasonvarImportCommand($command);
        $zombie = str_starts_with($stat, 'Z');
        $processTooNew = false;
        $checks = [
            'ps-pid:alive',
            'ps-cmd:'.($commandMatches ? 'match' : 'miss'),
        ];

        if ($zombie) {
            $checks[] = 'ps-stat:zombie';
        }

        if ($startedAt !== null && $elapsedSeconds !== null) {
            $runAgeSeconds = max(0, (int) $startedAt->diffInSeconds(now()));
            $processTooNew = $elapsedSeconds + 60 < $runAgeSeconds;
            $checks[] = 'ps-age:'.($processTooNew ? 'mismatch' : 'ok');
        }

        return [
            'alive' => true,
            'command_matches' => $commandMatches,
            'zombie' => $zombie,
            'process_too_new' => $processTooNew,
            'checks' => $checks,
        ];
    }

    /**
     * @return array{running: bool, verified: bool, pid: int|null, checks: array<int, string>}
     */
    private function inspectProcessLists(): array
    {
        $pgrepResult = $this->inspectPgrep();

        if ($pgrepResult['running']) {
            return $pgrepResult;
        }

        $psResult = $this->inspectFullPsList();

        return [
            'running' => $psResult['running'],
            'verified' => $pgrepResult['verified'] || $psResult['verified'],
            'pid' => $psResult['pid'],
            'checks' => [...$pgrepResult['checks'], ...$psResult['checks']],
        ];
    }

    /**
     * @return array{running: bool, verified: bool, pid: int|null, checks: array<int, string>}
     */
    private function inspectPgrep(): array
    {
        $output = $this->runCommand(['pgrep', '-af', 'seasonvar:import']);

        if ($output === null || trim($output) === '') {
            return [
                'running' => false,
                'verified' => false,
                'pid' => null,
                'checks' => ['pgrep:unavailable-or-empty'],
            ];
        }

        foreach (explode("\n", trim($output)) as $line) {
            $parsed = $this->parseProcessListLine($line);

            if ($parsed === null || $this->isCurrentAttemptPid($parsed['pid'])) {
                continue;
            }

            if ($this->isSeasonvarImportCommand($parsed['command'])) {
                return [
                    'running' => true,
                    'verified' => true,
                    'pid' => $parsed['pid'],
                    'checks' => ['pgrep:match'],
                ];
            }
        }

        return [
            'running' => false,
            'verified' => true,
            'pid' => null,
            'checks' => ['pgrep:no-other-match'],
        ];
    }

    /**
     * @return array{running: bool, verified: bool, pid: int|null, checks: array<int, string>}
     */
    private function inspectFullPsList(): array
    {
        $output = $this->runCommand(['ps', '-eo', 'pid=', '-o', 'stat=', '-o', 'command=']);

        if ($output === null || trim($output) === '') {
            return [
                'running' => false,
                'verified' => false,
                'pid' => null,
                'checks' => ['ps-list:unavailable'],
            ];
        }

        foreach (explode("\n", trim($output)) as $line) {
            $parts = preg_split('/\s+/', trim($line), 3);

            if (count($parts) < 3) {
                continue;
            }

            $pid = (int) $parts[0];
            $stat = (string) $parts[1];
            $command = (string) $parts[2];

            if ($this->isCurrentAttemptPid($pid) || str_starts_with($stat, 'Z')) {
                continue;
            }

            if ($this->isSeasonvarImportCommand($command)) {
                return [
                    'running' => true,
                    'verified' => true,
                    'pid' => $pid,
                    'checks' => ['ps-list:match'],
                ];
            }
        }

        return [
            'running' => false,
            'verified' => true,
            'pid' => null,
            'checks' => ['ps-list:no-match'],
        ];
    }

    /**
     * @return array{pid: int, command: string}|null
     */
    private function parseProcessListLine(string $line): ?array
    {
        if (! preg_match('/^\s*(\d+)\s+(.+)$/', $line, $matches)) {
            return null;
        }

        return [
            'pid' => (int) $matches[1],
            'command' => (string) $matches[2],
        ];
    }

    private function isCurrentAttemptPid(int $pid): bool
    {
        $currentPid = getmypid();
        $parentPid = function_exists('posix_getppid') ? posix_getppid() : null;

        return $pid === $currentPid || ($parentPid !== null && $pid === $parentPid);
    }

    private function isSeasonvarImportCommand(string $command): bool
    {
        $normalized = mb_strtolower(str_replace("\0", ' ', $command));

        return str_contains($normalized, 'seasonvar:import')
            && (str_contains($normalized, 'artisan') || str_contains($normalized, 'php'));
    }

    private function runCommand(array $command): ?string
    {
        try {
            $process = new Process($command);
            $process->setTimeout(2);
            $process->run();
        } catch (Throwable) {
            return null;
        }

        $output = trim($process->getOutput());

        return $output === '' ? null : $output;
    }
}
