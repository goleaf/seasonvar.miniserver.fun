<?php

namespace App\Console\Commands;

use App\Services\ProjectDocumentation\ProjectDocumentationRefresher;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('project:docs-refresh {--check : Проверить документацию без записи изменений}')]
#[Description('Обновляет управляемые разделы документации проекта')]
class RefreshProjectDocs extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(ProjectDocumentationRefresher $refresher): int
    {
        $check = (bool) $this->option('check');
        $result = $refresher->refresh($check);

        foreach ($result->missingFiles as $relativePath) {
            $this->warn('Файл документации не найден: '.$relativePath);
        }

        foreach ($result->brokenLinks as $brokenLink) {
            $this->error('Некорректная ссылка Markdown: '.$brokenLink);
        }

        if ($result->hasBrokenLinks()) {
            return self::FAILURE;
        }

        if (! $result->hasChanges()) {
            $this->info('Документация уже актуальна.');

            return self::SUCCESS;
        }

        if ($check) {
            $this->error('Документация требует обновления: '.implode(', ', $result->changedFiles));

            return self::FAILURE;
        }

        $this->info('Документация обновлена: '.implode(', ', $result->changedFiles));

        return self::SUCCESS;
    }
}
