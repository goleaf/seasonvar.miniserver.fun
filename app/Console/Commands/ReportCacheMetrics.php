<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\Cache\CacheMetricsSnapshot;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('cache:metrics {--date= : Дата UTC в формате YYYY-MM-DD} {--json : Вывести JSON}')]
#[Description('Показывает низкокардинальные метрики прикладного cache layer')]
final class ReportCacheMetrics extends Command
{
    public function handle(CacheMetricsSnapshot $metrics): int
    {
        $date = $this->option('date');
        $date = is_string($date) && $date !== '' ? $date : null;

        if ($date !== null && ! $this->validDate($date)) {
            $this->error('Дата должна быть указана в формате YYYY-MM-DD.');

            return self::FAILURE;
        }

        $snapshot = $metrics->forDate($date);

        if ((bool) $this->option('json')) {
            $this->line(json_encode($snapshot, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

            return self::SUCCESS;
        }

        $this->info('Cache metrics: '.$snapshot['date']);
        $this->table(
            ['Домен', 'Hit ratio', 'Hot hits', 'Redis hits', 'Stale', 'Rebuilds', 'Invalidations', 'Warm failures', 'Failures'],
            collect($snapshot['domains'])->map(fn (array $domain, string $name): array => [
                $name,
                number_format((float) $domain['hit-ratio'] * 100, 2).'%',
                $domain['hot-hit'],
                $domain['shared-hit'],
                $domain['stale-served'],
                $domain['rebuild-count'],
                $domain['invalidation'],
                $domain['warming-failure'] + $domain['warming-dispatch-failure'],
                $domain['failure'],
            ])->values()->all(),
        );

        return self::SUCCESS;
    }

    private function validDate(string $date): bool
    {
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);

        return $parsed !== false && $parsed->format('Y-m-d') === $date;
    }
}
