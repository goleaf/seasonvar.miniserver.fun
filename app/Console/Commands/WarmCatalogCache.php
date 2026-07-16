<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\WarmCatalogCaches;
use App\Jobs\WarmPublicCatalogCaches;
use App\Services\Catalog\CatalogCacheWarmer;
use App\Services\Catalog\CatalogCacheWarmRequestStore;
use App\Services\Catalog\PublicCatalogWarmStateStore;
use App\Services\Catalog\PublicCatalogWarmTargetSource;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('cache:warm-catalog
    {--queue : Поставить прогрев в Redis-очередь вместо синхронного выполнения}
    {--refresh : Пересобрать текущие warmable namespaces без удаления читаемого snapshot}
    {--scope=critical : Область прогрева: critical или all-public}
    {--dry-run : Только подсчитать безопасные цели полного прогрева}
    {--resume : Продолжить последнее незавершённое поколение all-public}')]
#[Description('Прогревает критические кэши или весь безопасный публичный каталог')]
final class WarmCatalogCache extends Command
{
    public function handle(
        CatalogCacheWarmer $warmer,
        CatalogCacheWarmRequestStore $requests,
        PublicCatalogWarmTargetSource $fullTargets,
        PublicCatalogWarmStateStore $fullStates,
    ): int {
        $scope = (string) $this->option('scope');

        if (! in_array($scope, ['critical', 'all-public'], true)) {
            $this->error('Область прогрева должна быть critical или all-public.');

            return self::FAILURE;
        }

        if ($scope === 'critical' && (bool) $this->option('resume')) {
            $this->error('Параметр --resume доступен только для --scope=all-public.');

            return self::FAILURE;
        }

        if ($scope === 'all-public'
            && ! (bool) $this->option('queue')
            && ! (bool) $this->option('dry-run')) {
            $this->error('Полный публичный прогрев выполняется только через Redis-очередь. Добавьте --queue.');

            return self::FAILURE;
        }

        $refresh = (bool) $this->option('refresh');

        if ($scope === 'all-public') {
            if ((bool) $this->option('dry-run')) {
                $estimate = $fullTargets->estimate();
                $this->info('Безопасных публичных целей: '.$estimate['targets'].'.');

                foreach ($estimate['by_source'] as $source => $count) {
                    $this->line("{$source}: {$count}");
                }

                return self::SUCCESS;
            }

            $state = (bool) $this->option('resume')
                ? $fullStates->resume()
                : $fullStates->start($refresh, $fullTargets->estimate()['targets']);

            if ($state === null) {
                $this->error('Нет незавершённого поколения полного публичного прогрева.');

                return self::FAILURE;
            }

            $queue = (string) config('cache-architecture.warming.queue', 'cache-warm-v2');

            if ($refresh && ! (bool) $this->option('resume')) {
                $requests->request(refresh: true);
                WarmCatalogCaches::dispatch(true)
                    ->onConnection((string) config('cache-architecture.warming.connection', 'redis'))
                    ->onQueue($queue);
            }

            WarmPublicCatalogCaches::dispatch(
                (string) $state['generation'],
                (bool) $state['refresh'],
            )->onConnection((string) config('cache-architecture.warming.connection', 'redis'))
                ->onQueue($queue);
            $this->info("Полный публичный прогрев поставлен в очередь {$queue}. Поколение: {$state['generation']}.");

            return self::SUCCESS;
        }

        if ((bool) $this->option('queue')) {
            $queue = (string) config('cache-architecture.warming.queue', 'cache-warm-v2');
            $requests->request(refresh: $refresh);
            WarmCatalogCaches::dispatch($refresh)
                ->onConnection((string) config('cache-architecture.warming.connection', 'redis'))
                ->onQueue($queue);
            $this->info("Прогрев поставлен в очередь {$queue}.");

            return self::SUCCESS;
        }

        $result = $warmer->warmCritical($refresh);
        $this->info("Критические кэши прогреты за {$result['duration_ms']} мс.");

        return self::SUCCESS;
    }
}
