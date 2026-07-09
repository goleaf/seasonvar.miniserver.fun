<?php

namespace App\Console\Commands;

use App\Models\CatalogTitle;
use App\Services\Media\ExternalPlaylistImporter;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

#[Signature('media:import-playlist {url : Ссылка на внешний M3U/M3U8 плейлист} {--title= : Slug сериала, если весь плейлист относится к одной карточке} {--limit=500 : Максимум файлов за один запуск} {--dry-run : Только показать, что будет подключено}')]
#[Description('Подключает внешние файлы из M3U/M3U8 плейлиста к локальному плееру')]
class ImportExternalPlaylist extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(ExternalPlaylistImporter $importer): int
    {
        $titleOption = trim((string) $this->option('title'));
        $forcedTitle = null;

        if ($titleOption !== '') {
            $forcedTitle = CatalogTitle::query()
                ->where('slug', $titleOption)
                ->first();

            if ($forcedTitle === null) {
                $this->error("Сериал со slug {$titleOption} не найден.");

                return self::FAILURE;
            }
        }

        try {
            $result = $importer->importFromUrl(
                (string) $this->argument('url'),
                $forcedTitle,
                max(1, (int) $this->option('limit')),
                (bool) $this->option('dry-run'),
            );
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        foreach ($result['items'] as $item) {
            $status = match ($item['status']) {
                'imported' => 'подключено',
                'updated' => 'обновлено',
                'dry-run' => 'проверка',
                'unmatched' => 'не найден сериал',
                default => 'пропущено',
            };
            $target = isset($item['catalog_title'])
                ? ' -> '.$item['catalog_title']
                : '';

            $this->line("{$status}: {$item['title']}{$target}");
        }

        $this->info(sprintf(
            'Готово: файлов %d, подключено %d, обновлено %d, без сериала %d, пропущено %d.',
            $result['total'],
            $result['imported'],
            $result['updated'],
            $result['unmatched'],
            $result['skipped'],
        ));

        return self::SUCCESS;
    }
}
