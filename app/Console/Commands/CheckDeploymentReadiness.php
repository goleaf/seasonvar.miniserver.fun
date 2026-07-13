<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Operations\DeploymentReadinessChecker;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('app:deployment-check {--json : Вывести машинно-читаемый JSON}')]
#[Description('Выполняет read-only preflight production-конфигурации, SQLite, FTS, очереди и импортёра')]
final class CheckDeploymentReadiness extends Command
{
    public function handle(DeploymentReadinessChecker $checker): int
    {
        $checks = $checker->check();
        $ready = collect($checks)->doesntContain(
            fn ($check): bool => $check->failed(),
        );

        if ((bool) $this->option('json')) {
            $this->line(json_encode([
                'status' => $ready ? 'ready' : 'failed',
                'ready' => $ready,
                'checked_at' => now()->toIso8601String(),
                'checks' => array_map(
                    fn ($check): array => $check->toArray(),
                    $checks,
                ),
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        } else {
            $this->line($ready ? 'Deployment readiness: ready' : 'Deployment readiness: failed');

            foreach ($checks as $check) {
                $this->line(sprintf('%-24s %-8s %s', $check->name, $check->status, $check->message));
            }
        }

        return $ready ? self::SUCCESS : self::FAILURE;
    }
}
