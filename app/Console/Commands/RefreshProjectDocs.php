<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use RuntimeException;

#[Signature('project:docs-refresh {--check : Проверить Markdown без записи изменений}')]
#[Description('Обновляет управляемые Markdown-разделы проекта')]
class RefreshProjectDocs extends Command
{
    private const START_MARKER = '<!-- project-docs:start -->';

    private const END_MARKER = '<!-- project-docs:end -->';

    private const MANAGED_FILES = [
        'README.md',
        'AGENTS.md',
        'docs/CODE_STANDARDS.md',
        'docs/UI_STANDARDS.md',
        'docs/DATA_RELATIONS.md',
        'docs/MAINTENANCE_LOG.md',
    ];

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $check = (bool) $this->option('check');
        $changedFiles = [];

        foreach (self::MANAGED_FILES as $relativePath) {
            $path = base_path($relativePath);

            if (! is_file($path)) {
                $this->warn('Файл документации не найден: '.$relativePath);

                continue;
            }

            $contents = file_get_contents($path);

            if (! is_string($contents)) {
                throw new RuntimeException('Не удалось прочитать файл документации: '.$relativePath);
            }

            $nextContents = $this->refreshUpdatedDate($relativePath, $contents);
            $nextContents = $this->replaceManagedSection($nextContents, $this->managedSection($relativePath));

            if ($nextContents === $contents) {
                continue;
            }

            $changedFiles[] = $relativePath;

            if (! $check) {
                file_put_contents($path, $nextContents);
            }
        }

        if ($changedFiles === []) {
            $this->info('Документация уже актуальна.');

            return self::SUCCESS;
        }

        if ($check) {
            $this->error('Документация требует обновления: '.implode(', ', $changedFiles));

            return self::FAILURE;
        }

        $this->info('Документация обновлена: '.implode(', ', $changedFiles));

        return self::SUCCESS;
    }

    private function refreshUpdatedDate(string $relativePath, string $contents): string
    {
        if (! Str::startsWith($relativePath, 'docs/')) {
            return $contents;
        }

        if (! Str::contains($contents, 'Обновлено:')) {
            return $contents;
        }

        return preg_replace('/^Обновлено:\s*.+$/mu', 'Обновлено: '.$this->today(), $contents) ?? $contents;
    }

    private function replaceManagedSection(string $contents, string $section): string
    {
        $replacement = self::START_MARKER."\n".$section."\n".self::END_MARKER;
        $pattern = '/'.preg_quote(self::START_MARKER, '/').'.*?'.preg_quote(self::END_MARKER, '/').'/s';

        if (preg_match($pattern, $contents) === 1) {
            return preg_replace($pattern, $replacement, $contents) ?? $contents;
        }

        return rtrim($contents)."\n\n".$replacement."\n";
    }

    private function managedSection(string $relativePath): string
    {
        return match ($relativePath) {
            'README.md' => $this->readmeSection(),
            'AGENTS.md' => $this->agentsSection(),
            'docs/CODE_STANDARDS.md' => $this->codeStandardsSection(),
            'docs/UI_STANDARDS.md' => $this->uiStandardsSection(),
            'docs/DATA_RELATIONS.md' => $this->dataRelationsSection(),
            'docs/MAINTENANCE_LOG.md' => $this->maintenanceSection(),
            default => '## Автоматически обновляемое состояние'."\n\n".'Обновлено: '.$this->today().'.',
        };
    }

    private function readmeSection(): string
    {
        return $this->section('Автоматически обновляемое состояние', [
            'Обновлено командой `php artisan project:docs-refresh`: '.$this->today().'.',
            'Основная карта сайта портала: `https://seasonvar.miniserver.fun/sitemap-index.xml`.',
            'Совместимый адрес `/sitemap.xml` отдает индекс карты сайта, чтобы поисковые системы получали все разделы карты.',
            '`public/robots.txt` объявляет стабильный индекс карты сайта без ручного перечисления страниц `sitemap-titles-*` и `sitemap-videos-*`.',
            'Git-хук `.githooks/post-commit` запускает обновление файлов документации и при изменениях делает отдельный коммит документации; автоматическая отправка в Git включается только через `SEASONVAR_DOCS_AUTO_PUSH=1`.',
        ]);
    }

    private function agentsSection(): string
    {
        return $this->section('Автоматизация документации', [
            'Команда `php artisan project:docs-refresh` поддерживает управляемые блоки документации в актуальном состоянии.',
            'Git-хук должен работать через `core.hooksPath=.githooks`, не должен коммитить посторонние изменения вне управляемых файлов документации и должен отправлять текущую ветку в Git только при `SEASONVAR_DOCS_AUTO_PUSH=1`.',
            'Карта сайта и `robots.txt` считаются частью технической документации проекта и должны отражаться в `README.md`, `docs/CODE_STANDARDS.md`, `docs/DATA_RELATIONS.md` и журнале обслуживания.',
        ]);
    }

    private function codeStandardsSection(): string
    {
        return $this->section('Автоматизация документации, карты сайта и robots', array_merge([
            'После изменений маршрутов, карты сайта, robots или команд нужно запускать `php artisan project:docs-refresh`.',
            'После PHP-правок по-прежнему обязателен `vendor/bin/pint --dirty --format agent`.',
            'Совместимый `/sitemap.xml` должен оставаться адресом индекса карты сайта, а не монолитной картой всех URL.',
            'Карта видео должна включать только опубликованные медиа с абсолютной внешней ссылкой `http://` или `https://`.',
        ], $this->sitemapRouteLines()));
    }

    private function uiStandardsSection(): string
    {
        return $this->section('Документация интерфейса', [
            'Автоматическое обновление документации не должно добавлять видимые тексты на публичные страницы каталога.',
            'Изменения sitemap, robots и hook не меняют светлую тему, Blade-компоненты и русскоязычные правила интерфейса.',
            'Если будущая правка меняет видимые блоки, этот файл нужно обновить вручную и затем запустить `php artisan project:docs-refresh`.',
        ]);
    }

    private function dataRelationsSection(): string
    {
        return $this->section('Публичная индексация', array_merge([
            'Индекс карты сайта собирает статические страницы, годы, активные справочники, программные посадочные страницы, карточки тайтлов и карту видео.',
            'Карточки тайтлов попадают в `sitemap-titles-{page}.xml` только при `is_published=true` и заполненном `slug`.',
            'Карта видео строится по `licensed_media` со статусом `published` и абсолютной внешней ссылкой в `playback_url` или `path`.',
            '`robots.txt` объявляет только индекс карты сайта, потому что количество страниц `sitemap-titles-*` и `sitemap-videos-*` зависит от базы.',
        ], $this->sitemapRouteLines()));
    }

    private function maintenanceSection(): string
    {
        return $this->section('Автоматически обновляемое состояние документации', [
            'Последнее автоматическое обновление блоков документации: '.$this->today().'.',
            'Команда обновления: `php artisan project:docs-refresh`.',
            'Хук автокоммита: `.githooks/post-commit` через `scripts/docs-autocommit-push.sh`; отправка в Git включается только через `SEASONVAR_DOCS_AUTO_PUSH=1`.',
            'Основной sitemap для robots и поисковых систем: `https://seasonvar.miniserver.fun/sitemap-index.xml`.',
        ]);
    }

    /**
     * @param  list<string>  $items
     */
    private function section(string $title, array $items): string
    {
        return '## '.$title."\n\n".collect($items)
            ->map(fn (string $item): string => '- '.$item)
            ->implode("\n");
    }

    /**
     * @return list<string>
     */
    private function sitemapRouteLines(): array
    {
        return collect([
            'sitemap',
            'sitemap.index',
            'sitemap.static',
            'sitemap.taxonomies',
            'sitemap.landings',
            'sitemap.titles',
            'sitemap.videos',
        ])
            ->map(fn (string $name): string => '`'.$this->routePath($name).'` (`'.$name.'`)')
            ->all();
    }

    private function routePath(string $name): string
    {
        $route = Route::getRoutes()->getByName($name);

        if ($route === null) {
            return $name;
        }

        return '/'.ltrim($route->uri(), '/');
    }

    private function today(): string
    {
        return now()->format('d.m.Y');
    }
}
