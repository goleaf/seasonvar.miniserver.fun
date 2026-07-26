<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Operations\PlayerReleaseReadiness;
use Illuminate\Console\Command;

final class CheckPlayerRelease extends Command
{
    protected $signature = 'player:release-check {--json : Вывести машинно-читаемый результат}';

    protected $description = 'Проверить единую версию player-кода и Vite-ресурсов';

    public function handle(PlayerReleaseReadiness $readiness): int
    {
        $result = $readiness->check();
        $output = json_encode(
            $result,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );

        if ($this->option('json')) {
            $this->line($output);
        } elseif ($result['ready']) {
            $this->info(sprintf(
                'Player release согласован: %d source-файлов, %d assets.',
                $result['source_count'],
                $result['asset_count'],
            ));
        } else {
            $this->error('Player release не согласован: '.implode(', ', $result['errors']));
        }

        return $result['ready'] ? self::SUCCESS : self::FAILURE;
    }
}
