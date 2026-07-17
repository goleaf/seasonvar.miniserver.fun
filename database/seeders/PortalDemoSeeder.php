<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Services\DemoData\DemoDataOrchestrator;
use Illuminate\Database\Seeder;

final class PortalDemoSeeder extends Seeder
{
    public function run(DemoDataOrchestrator $orchestrator): void
    {
        $report = $orchestrator->run(function (string $stage, int $current, int $total): void {
            if ($current === 1 || $current === $total || $current % 25 === 0) {
                $this->command?->line(sprintf('%s: %d/%d', $stage, $current, $total));
            }
        });

        $this->command?->info(sprintf(
            'Демонстрационное наполнение завершено: %d пользователей, %d состояний каталога, нарушений нет.',
            $report->counters['demo_users'],
            $report->counters['user_title_states'],
        ));
    }
}
