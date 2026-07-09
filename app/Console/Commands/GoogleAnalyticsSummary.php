<?php

namespace App\Console\Commands;

use App\Services\Google\GoogleAnalyticsDataClient;
use App\Services\Google\GoogleIntegrationException;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

#[Signature('google:analytics:summary {--days=7 : Период отчета в днях} {--limit=10 : Максимум строк отчета}')]
#[Description('Показывает read-only GA4 сводку по страницам каталога')]
class GoogleAnalyticsSummary extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(GoogleAnalyticsDataClient $client): int
    {
        if (! $client->enabled()) {
            $this->warn('Google Analytics 4 выключен. Установите GOOGLE_ANALYTICS_ENABLED=true и настройте credentials вне Git.');

            return self::SUCCESS;
        }

        try {
            $rows = $client->topPages($this->positiveOption('days'), $this->positiveOption('limit'));
        } catch (GoogleIntegrationException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        } catch (Throwable $exception) {
            $this->error('Не удалось получить отчет GA4: '.$exception->getMessage());

            return self::FAILURE;
        }

        if ($rows === []) {
            $this->info('GA4 не вернул строки за выбранный период.');

            return self::SUCCESS;
        }

        $this->table(['Страница', 'Просмотры', 'Пользователи'], array_map(
            fn (array $row): array => [
                (string) ($row['dimensionValues'][0]['value'] ?? ''),
                (string) ($row['metricValues'][0]['value'] ?? 0),
                (string) ($row['metricValues'][1]['value'] ?? 0),
            ],
            $rows,
        ));

        return self::SUCCESS;
    }

    private function positiveOption(string $name): int
    {
        return max(1, (int) $this->option($name));
    }
}
