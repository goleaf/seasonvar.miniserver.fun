<?php

namespace App\Console\Commands;

use App\Services\Integrations\IntegrationDoctor;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('integrations:doctor {--json : Вывести результат в JSON} {--strict : Вернуть ошибку, если обязательные проверки не прошли}')]
#[Description('Проверяет локальную готовность MCP, Google и внешних интеграций без раскрытия секретов')]
class CheckIntegrations extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(IntegrationDoctor $doctor): int
    {
        $checks = $doctor->checks();
        $requiredFailures = collect($checks)
            ->filter(fn (array $check): bool => $check['required'] && $check['status'] !== IntegrationDoctor::STATUS_OK)
            ->values();

        if ((bool) $this->option('json')) {
            $this->line((string) json_encode([
                'summary' => $doctor->summary(),
                'checks' => $checks,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            return (bool) $this->option('strict') && $requiredFailures->isNotEmpty()
                ? self::FAILURE
                : self::SUCCESS;
        }

        $this->info('Диагностика интеграций Seasonvar');

        foreach ($checks as $check) {
            $this->line(sprintf(
                '%s %s: %s',
                $this->statusLabel($check['status']),
                $check['title'],
                $check['message'],
            ));
        }

        if ($requiredFailures->isNotEmpty()) {
            $this->warn('Обязательные проверки требуют внимания: '.$requiredFailures->pluck('title')->implode(', '));
        }

        $summary = $doctor->summary();
        $this->line(sprintf(
            'Итог: ok %d, warning %d, missing %d.',
            $summary['ok'],
            $summary['warning'],
            $summary['missing'],
        ));

        return (bool) $this->option('strict') && $requiredFailures->isNotEmpty()
            ? self::FAILURE
            : self::SUCCESS;
    }

    private function statusLabel(string $status): string
    {
        return match ($status) {
            IntegrationDoctor::STATUS_OK => '[OK]',
            IntegrationDoctor::STATUS_MISSING => '[MISSING]',
            default => '[WARN]',
        };
    }
}
