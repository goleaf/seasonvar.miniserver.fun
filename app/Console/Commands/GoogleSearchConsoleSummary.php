<?php

namespace App\Console\Commands;

use App\Services\Google\GoogleIntegrationException;
use App\Services\Google\GoogleSearchConsoleClient;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

#[Signature('google:search-console:summary {--days=7 : Период отчета в днях} {--limit=10 : Максимум строк отчета}')]
#[Description('Показывает read-only сводку Search Console по страницам каталога')]
class GoogleSearchConsoleSummary extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(GoogleSearchConsoleClient $client): int
    {
        if (! $client->enabled()) {
            $this->warn('Google Search Console выключен. Установите GOOGLE_SEARCH_CONSOLE_ENABLED=true и настройте credentials вне Git.');

            return self::SUCCESS;
        }

        try {
            $rows = $client->topPages($this->positiveOption('days'), $this->positiveOption('limit'));
        } catch (GoogleIntegrationException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        } catch (Throwable $exception) {
            $this->error('Не удалось получить отчет Search Console: '.$exception->getMessage());

            return self::FAILURE;
        }

        if ($rows === []) {
            $this->info('Search Console не вернул строки за выбранный период.');

            return self::SUCCESS;
        }

        $this->table(['Страница', 'Клики', 'Показы', 'CTR', 'Позиция'], array_map(
            fn (array $row): array => [
                (string) ($row['keys'][0] ?? ''),
                (string) ($row['clicks'] ?? 0),
                (string) ($row['impressions'] ?? 0),
                isset($row['ctr']) ? sprintf('%.2f%%', ((float) $row['ctr']) * 100) : '0.00%',
                isset($row['position']) ? sprintf('%.1f', (float) $row['position']) : '0.0',
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
