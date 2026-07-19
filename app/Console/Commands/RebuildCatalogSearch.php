<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\CatalogSearchIndexStatus;
use App\Models\CatalogSearchIndexState;
use App\Services\Catalog\Search\CatalogSearchIndexer;
use App\Services\Seasonvar\SeasonvarImportActivity;
use App\Services\Seasonvar\SeasonvarImportErrorSanitizer;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use RuntimeException;
use Throwable;

#[Signature('catalog:search-rebuild {--chunk=200 : Количество тайтлов в одном пакете}')]
#[Description('Перестраивает локальный полнотекстовый индекс каталога без импорта Seasonvar')]
class RebuildCatalogSearch extends Command
{
    public function handle(
        CatalogSearchIndexer $indexer,
        SeasonvarImportActivity $imports,
        SeasonvarImportErrorSanitizer $errors,
    ): int {
        if ($imports->active()) {
            $this->error('Пересборка поиска остановлена: активен импорт Seasonvar. Дождитесь его завершения.');

            return self::FAILURE;
        }

        $chunkSize = max(1, min(1000, (int) $this->option('chunk')));
        $state = CatalogSearchIndexState::query()->findOrFail(CatalogSearchIndexState::SINGLETON_ID);
        $resuming = $state->version === CatalogSearchIndexer::INDEX_VERSION
            && $state->checkpoint_id > 0
            && in_array($state->status, [
                CatalogSearchIndexStatus::Building,
                CatalogSearchIndexStatus::Failed,
                CatalogSearchIndexStatus::Stale,
            ], true);
        $checkpointId = $resuming ? $state->checkpoint_id : 0;
        $sourceCount = $indexer->sourceCount();

        $state->update([
            'version' => CatalogSearchIndexer::INDEX_VERSION,
            'status' => CatalogSearchIndexStatus::Building,
            'source_count' => $sourceCount,
            'document_count' => $indexer->documentCount(),
            'checkpoint_id' => $checkpointId,
            'build_started_at' => $resuming ? ($state->build_started_at ?? now()) : now(),
            'completed_at' => null,
            'failed_at' => null,
            'last_error' => null,
        ]);

        $this->info($resuming
            ? "Пересборка поиска возобновлена после тайтла #{$checkpointId}."
            : 'Пересборка поиска начата.');

        try {
            $indexer->pruneOutOfScopeDocuments();
            $lastId = $indexer->rebuildFromCheckpoint(
                $checkpointId,
                $chunkSize,
                function (int $processedId, array $_result) use ($state): void {
                    $state->update([
                        'checkpoint_id' => $processedId,
                    ]);
                },
            );
            $documentCount = $indexer->documentCount();

            if ($documentCount !== $sourceCount) {
                throw new RuntimeException("Количество поисковых документов не совпало с каталогом: {$documentCount} из {$sourceCount}.");
            }

            if (! $indexer->integrityCheck()) {
                throw new RuntimeException('Полнотекстовый индекс не прошёл проверку целостности.');
            }

            $state->update([
                'status' => CatalogSearchIndexStatus::Ready,
                'source_count' => $sourceCount,
                'document_count' => $documentCount,
                'checkpoint_id' => $lastId,
                'completed_at' => now(),
                'failed_at' => null,
                'last_error' => null,
            ]);

            $this->info("Поисковый индекс готов: {$documentCount} документов.");

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $message = $errors->fromException($exception);
            $state->update([
                'status' => CatalogSearchIndexStatus::Failed,
                'document_count' => $indexer->documentCount(),
                'completed_at' => null,
                'failed_at' => now(),
                'last_error' => $message,
            ]);
            $this->error($message);

            return self::FAILURE;
        }
    }
}
