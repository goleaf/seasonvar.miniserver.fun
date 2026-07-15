<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Operations\InfrastructureHealthCheck;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('app:health {--json : Вывести машинно-читаемый JSON}')]
#[Description('Проверяет БД, Redis workloads, Memcached, очередь и состояние прогрева')]
final class CheckInfrastructureHealth extends Command
{
    public function handle(InfrastructureHealthCheck $health): int
    {
        $result = $health->run();

        if ((bool) $this->option('json')) {
            $this->line(json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        } else {
            $this->info('Readiness: '.$result['status']);

            foreach ($result['components'] as $component => $state) {
                $this->line(sprintf('%-20s %s', $component, $state['status'] ?? 'unknown'));
            }
        }

        return $result['status'] === 'ok' ? self::SUCCESS : self::FAILURE;
    }
}
