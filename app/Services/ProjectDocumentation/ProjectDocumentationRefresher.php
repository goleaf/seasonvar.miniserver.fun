<?php

namespace App\Services\ProjectDocumentation;

use App\Enums\SeasonvarPageType;
use App\Models\SeasonvarImportRun;
use App\Services\Seasonvar\SeasonvarSourceParityRegistry;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;

class ProjectDocumentationRefresher
{
    private const START_MARKER = '<!-- project-docs:start -->';

    private const END_MARKER = '<!-- project-docs:end -->';

    private const MANAGED_FILES = [
        'README.md',
        'AGENTS.md',
        'docs/CODE_STANDARDS.md',
        'docs/UI_STANDARDS.md',
        'docs/DATA_RELATIONS.md',
        'docs/SOURCE_PARITY.md',
        'docs/MAINTENANCE_LOG.md',
    ];

    private string $basePath;

    private SeasonvarSourceParityRegistry $sourceParity;

    public function __construct(
        ?string $basePath = null,
        ?SeasonvarSourceParityRegistry $sourceParity = null,
    ) {
        $this->basePath = $basePath ?? base_path();
        $this->sourceParity = $sourceParity ?? app(SeasonvarSourceParityRegistry::class);
    }

    public function refresh(bool $check = false): ProjectDocumentationRefreshResult
    {
        $changedFiles = [];
        $missingFiles = [];

        foreach (self::MANAGED_FILES as $relativePath) {
            $path = $this->path($relativePath);

            if (! is_file($path)) {
                $missingFiles[] = $relativePath;

                continue;
            }

            $contents = file_get_contents($path);

            if (! is_string($contents)) {
                throw new RuntimeException('Не удалось прочитать файл документации: '.$relativePath);
            }

            $nextContents = $this->refreshContents($relativePath, $contents);

            if ($nextContents === $contents) {
                continue;
            }

            $changedFiles[] = $relativePath;

            if (! $check) {
                file_put_contents($path, $nextContents);
            }
        }

        return new ProjectDocumentationRefreshResult($changedFiles, $missingFiles);
    }

    public function refreshContents(string $relativePath, string $contents): string
    {
        $contents = $this->refreshUpdatedDate($relativePath, $contents);

        return $this->replaceManagedSection($contents, $this->managedSection($relativePath));
    }

    private function path(string $relativePath): string
    {
        return $this->basePath.DIRECTORY_SEPARATOR.$relativePath;
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
            'docs/SOURCE_PARITY.md' => $this->sourceParitySection(),
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

    private function sourceParitySection(): string
    {
        $capabilities = $this->sourceParity->capabilities();
        $latestAttempt = null;
        $latestSuccessful = null;

        if (Schema::hasTable('seasonvar_import_runs')) {
            $latestAttempt = SeasonvarImportRun::query()
                ->where('mode', 'inventory')
                ->latest('id')
                ->first();
            $latestSuccessful = SeasonvarImportRun::query()
                ->where('mode', 'inventory')
                ->where('status', 'completed')
                ->latest('id')
                ->first();
        }

        $inventory = is_array(data_get($latestSuccessful?->summary, 'source_inventory'))
            ? data_get($latestSuccessful?->summary, 'source_inventory')
            : [];
        $counts = is_array($inventory['counts_by_page_type'] ?? null)
            ? $inventory['counts_by_page_type']
            : [];
        $samples = is_array($inventory['sample_urls_by_page_type'] ?? null)
            ? $inventory['sample_urls_by_page_type']
            : [];
        $timestamp = $this->inventoryTimestamp($latestSuccessful, $inventory);
        $attemptStatus = match ($latestAttempt?->status) {
            'completed' => 'успешно',
            'failed' => 'ошибка; новый успешный снимок не создан',
            'running' => 'выполняется',
            default => 'не выполнялась',
        };

        $lines = [
            '## Управляемый снимок source parity',
            '',
            '- Последняя попытка: '.$attemptStatus.'.',
            '- Последний подтверждённый снимок: '.($timestamp ?? 'нет').'.',
            '- Команда: `php artisan seasonvar:import --inventory-only`.',
            '- Карт сайта: '.($inventory['sitemap_count'] ?? '—').'.',
            '- Нормализованных URL: '.($inventory['total_url_count'] ?? '—').'.',
            '- Неизвестных URL: '.($inventory['unknown_url_count'] ?? '—').'; некорректных: '.($inventory['malformed_url_count'] ?? '—').'; заблокированных: '.($inventory['blocked_url_count'] ?? '—').'.',
            '',
            '| Тип источника | Найдено | Parser | Публичный route | SourcePage | В локальном sitemap | Parser class | Route name |',
            '| --- | ---: | --- | --- | --- | --- | --- | --- |',
        ];

        $discoveredTypes = collect(SeasonvarPageType::cases())
            ->filter(fn (SeasonvarPageType $type): bool => (int) ($counts[$type->value] ?? 0) > 0)
            ->values();

        if ($discoveredTypes->isEmpty()) {
            $lines[] = '| _Подтверждённых типов пока нет_ | — | — | — | — | — | — | — |';
        } else {
            foreach ($discoveredTypes as $type) {
                $capability = $capabilities[$type->value];
                $lines[] = sprintf(
                    '| `%s` | %d | %s | %s | %s | %s | %s | %s |',
                    $type->value,
                    (int) $counts[$type->value],
                    $this->yesNo($capability['can_parse']),
                    $this->yesNo($capability['can_publish_local_page']),
                    $this->yesNo($capability['can_store_source_page']),
                    $this->yesNo($capability['can_add_to_sitemap']),
                    $capability['parser_class'] === null ? '—' : '`'.$capability['parser_class'].'`',
                    $capability['local_route_name'] === null ? '—' : '`'.$capability['local_route_name'].'`',
                );
            }
        }

        $missingParsers = collect($counts)
            ->filter(fn (mixed $count, string $type): bool => (int) $count > 0 && ! ($capabilities[$type]['can_parse'] ?? false))
            ->keys()
            ->values()
            ->all();
        $missingPages = collect($counts)
            ->filter(fn (mixed $count, string $type): bool => (int) $count > 0 && ! ($capabilities[$type]['can_publish_local_page'] ?? false))
            ->keys()
            ->values()
            ->all();

        $lines[] = '';
        $lines[] = '- Нет parser support: '.($missingParsers === [] ? 'нет' : implode(', ', $missingParsers)).'.';
        $lines[] = '- Нет локальной публичной страницы: '.($missingPages === [] ? 'нет' : implode(', ', $missingPages)).'.';

        if ($samples !== []) {
            $lines[] = '- Репрезентативные пути: '.collect($samples)
                ->map(fn (mixed $paths, string $type): string => '`'.$type.'` '.collect(is_array($paths) ? $paths : [])->take(3)->map(fn (mixed $path): string => '`'.$this->markdownCell((string) $path).'`')->implode(', '))
                ->filter()
                ->implode('; ').'.';
        }

        $lines[] = '- Подтверждённым считается только тип с ненулевым счётчиком последнего успешного inventory. Остальные категории реестра являются возможностями классификатора, а не заявлением о наличии страницы у источника.';
        $lines[] = '- Инвентаризация хранит только разрешённые публичные metadata URL. Видео, player/playlist URL, cookies, credentials, закрытые ответы и защищённый контент в отчёт не входят; публикация локальной страницы требует отдельного подтверждения прав.';

        return implode("\n", $lines);
    }

    /** @param array<string, mixed> $inventory */
    private function inventoryTimestamp(?SeasonvarImportRun $run, array $inventory): ?string
    {
        $value = $inventory['completed_at'] ?? $run?->finished_at;

        if ($value === null) {
            return null;
        }

        try {
            return Carbon::parse($value)->format('d.m.Y H:i:s');
        } catch (\Throwable) {
            return null;
        }
    }

    private function yesNo(bool $value): string
    {
        return $value ? 'да' : 'нет';
    }

    private function markdownCell(string $value): string
    {
        return str_replace(['|', "\r", "\n"], ['\\|', '', ' '], $value);
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
