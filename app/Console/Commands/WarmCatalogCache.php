<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\WarmCatalogCaches;
use App\Services\Catalog\CatalogCacheWarmer;
use App\Services\Catalog\CatalogCacheWarmRequestStore;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('cache:warm-catalog {--queue : Поставить прогрев в Redis-очередь вместо синхронного выполнения} {--refresh : Пересобрать текущие warmable namespaces без удаления читаемого snapshot}')]
#[Description('Прогревает ограниченный набор критических публичных кэшей каталога')]
final class WarmCatalogCache extends Command
{
    public function handle(CatalogCacheWarmer $warmer, CatalogCacheWarmRequestStore $requests): int
    {
        $refresh = (bool) $this->option('refresh');

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
