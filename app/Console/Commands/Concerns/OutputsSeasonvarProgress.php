<?php

namespace App\Console\Commands\Concerns;

use BackedEnum;
use DateTimeInterface;
use Illuminate\Console\Command;

/**
 * @mixin Command
 */
trait OutputsSeasonvarProgress
{
    /**
     * @return callable(string, array<string, mixed>): void
     */
    private function seasonvarProgress(): callable
    {
        return fn (string $event, array $context = []) => $this->writeSeasonvarProgress($event, $context);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function writeSeasonvarProgress(string $event, array $context = []): void
    {
        $details = $this->formatSeasonvarContext($context);
        $message = '['.now()->format('H:i:s').'] '.$this->formatSeasonvarEvent($event);

        if ($details !== '') {
            $message .= ': '.$details;
        }

        if (str_contains($event, 'failed') || str_contains($event, 'blocked') || str_contains($event, 'invalid')) {
            $this->warn($message);

            return;
        }

        if (str_contains($event, 'complete') || str_contains($event, 'created') || str_contains($event, 'stored')) {
            $this->info($message);

            return;
        }

        $this->line($message);
    }

    private function formatSeasonvarEvent(string $event): string
    {
        return self::eventLabels()[$event] ?? str_replace('-', ' ', $event);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function formatSeasonvarContext(array $context): string
    {
        $items = [];

        foreach ($context as $key => $value) {
            $items[] = $this->formatSeasonvarKey((string) $key).'='.$this->formatSeasonvarValue($value);
        }

        return implode(' | ', $items);
    }

    private function formatSeasonvarValue(mixed $value): string
    {
        if ($value === null) {
            return 'пусто';
        }

        if ($value === true) {
            return 'да';
        }

        if ($value === false) {
            return 'нет';
        }

        if ($value instanceof BackedEnum) {
            return (string) $value->value;
        }

        if ($value instanceof DateTimeInterface) {
            return $value->format(DATE_ATOM);
        }

        if (is_string($value)) {
            return self::valueLabels()[$value] ?? $value;
        }

        if (is_array($value)) {
            return $this->formatSeasonvarArray($value);
        }

        if (is_float($value)) {
            return number_format($value, 3, '.', '');
        }

        return (string) $value;
    }

    /**
     * @param  array<array-key, mixed>  $value
     */
    private function formatSeasonvarArray(array $value): string
    {
        if ($value === []) {
            return '[]';
        }

        if (array_is_list($value)) {
            return '['.implode(', ', array_map(
                fn (mixed $item): string => $this->formatSeasonvarValue($item),
                $value,
            )).']';
        }

        $items = [];

        foreach ($value as $key => $item) {
            $items[] = $this->formatSeasonvarKey((string) $key).'='.$this->formatSeasonvarValue($item);
        }

        return '{'.implode(', ', $items).'}';
    }

    private function formatSeasonvarKey(string $key): string
    {
        return self::contextLabels()[$key] ?? self::valueLabels()[$key] ?? $key;
    }

    /**
     * @return array<string, string>
     */
    private static function eventLabels(): array
    {
        return [
            'catalog-title-created' => 'Запись каталога создана',
            'catalog-title-slug-prepared' => 'Адрес записи подготовлен',
            'catalog-title-updated' => 'Запись каталога обновлена',
            'catalog-title-upsert-started' => 'Сохранение записи каталога началось',
            'catalog-url-discovered' => 'Ссылка каталога найдена',
            'catalog-url-duplicate' => 'Ссылка каталога уже была найдена',
            'catalog-url-skipped' => 'Ссылка каталога пропущена',
            'child-sitemap-queued' => 'Вложенная карта сайта добавлена в очередь',
            'child-sitemap-skipped' => 'Вложенная карта сайта пропущена',
            'crawl-delay-not-needed' => 'Пауза обхода не нужна',
            'crawl-delay-skipped' => 'Пауза обхода пропущена',
            'crawl-delay-wait-complete' => 'Пауза обхода завершена',
            'crawl-delay-wait-started' => 'Пауза обхода началась',
            'episode-created' => 'Серия создана',
            'episode-sync-complete' => 'Синхронизация серий завершена',
            'episode-sync-skipped' => 'Серия пропущена',
            'episode-sync-started' => 'Синхронизация серий началась',
            'episode-updated' => 'Серия обновлена',
            'full-sync-cycle-complete' => 'Цикл синхронизации завершен',
            'full-sync-cycle-failed' => 'Цикл синхронизации завершился ошибкой',
            'full-sync-cycle-started' => 'Цикл синхронизации начался',
            'full-sync-sleep-started' => 'Пауза перед следующим циклом',
            'full-sync-started' => 'Синхронизация запущена',
            'full-sync-stop-requested' => 'Получен сигнал остановки',
            'full-sync-stopped' => 'Синхронизация остановлена',
            'html-parse-complete' => 'Разбор страницы завершен',
            'html-parse-started' => 'Разбор страницы начался',
            'http-request-complete' => 'HTTP-запрос завершен',
            'http-request-started' => 'HTTP-запрос начался',
            'licensed-media-attached' => 'Медиа подключено',
            'licensed-media-auto-attach-skipped' => 'Подключение медиа пропущено',
            'page-parse-complete' => 'Страница обработана',
            'page-parse-failed' => 'Страница завершилась ошибкой',
            'page-parse-started' => 'Обработка страницы началась',
            'page-response-received' => 'Ответ страницы получен',
            'page-selection-complete' => 'Выбор страниц завершен',
            'page-selection-started' => 'Выбор страниц начался',
            'parse-batch-complete' => 'Пакет обработки завершен',
            'parse-batch-item-complete' => 'Страница в пакете обработана',
            'parse-batch-item-failed' => 'Страница в пакете завершилась ошибкой',
            'parse-batch-item-started' => 'Обработка страницы в пакете началась',
            'parse-batch-started' => 'Пакет обработки начался',
            'pending-pages-query-complete' => 'Поиск страниц в очереди завершен',
            'pending-pages-query-started' => 'Поиск страниц в очереди начался',
            'season-created' => 'Сезон создан',
            'season-sync-complete' => 'Синхронизация сезонов завершена',
            'season-sync-started' => 'Синхронизация сезонов началась',
            'season-updated' => 'Сезон обновлен',
            'sitemap-already-visited' => 'Карта сайта уже посещена',
            'sitemap-discovery-blocked' => 'Поиск по карте сайта заблокирован',
            'sitemap-discovery-complete' => 'Поиск по карте сайта завершен',
            'sitemap-discovery-limit-reached' => 'Лимит поиска по карте сайта достигнут',
            'sitemap-discovery-started' => 'Поиск по карте сайта начался',
            'sitemap-fetch-complete' => 'Загрузка карты сайта завершена',
            'sitemap-fetch-failed' => 'Загрузка карты сайта завершилась ошибкой',
            'sitemap-fetch-started' => 'Загрузка карты сайта началась',
            'sitemap-mirror-archive-ready' => 'Архив карты сайта готов',
            'sitemap-mirror-complete' => 'Зеркало карты сайта готово',
            'sitemap-mirror-index-ready' => 'Индекс карты сайта готов',
            'sitemap-xml-decompressed' => 'XML карты сайта распакован',
            'sitemap-xml-failed' => 'XML карты сайта завершился ошибкой',
            'sitemap-xml-gzip-detected' => 'Обнаружен сжатый XML карты сайта',
            'sitemap-xml-parsed' => 'XML карты сайта разобран',
            'source-page-created' => 'Страница источника создана',
            'source-page-crawl-metadata-updated' => 'Данные обхода страницы обновлены',
            'source-page-selected' => 'Страница источника выбрана',
            'source-page-updated' => 'Страница источника обновлена',
            'source-ready' => 'Источник готов',
            'store-discovered-urls-complete' => 'Сохранение найденных ссылок завершено',
            'store-discovered-urls-started' => 'Сохранение найденных ссылок началось',
            'taxonomy-created' => 'Справочник создан',
            'taxonomy-sync-complete' => 'Синхронизация справочников завершена',
            'taxonomy-sync-started' => 'Синхронизация справочников началась',
            'taxonomy-updated' => 'Справочник обновлен',
            'url-blocked' => 'Ссылка заблокирована',
            'url-invalid' => 'Ссылка некорректна',
            'url-normalized' => 'Ссылка нормализована',
        ];
    }

    /**
     * @return array<string, string>
     */
    private static function contextLabels(): array
    {
        return [
            'archive_count' => 'архивов',
            'archive_path' => 'путь_архива',
            'archives' => 'архивы',
            'all_urls_path' => 'путь_ссылок',
            'argument' => 'аргумент',
            'base_url' => 'базовая_ссылка',
            'body_bytes' => 'байты',
            'catalog_title_id' => 'запись_каталога',
            'child_sitemaps' => 'вложенных_карт',
            'code' => 'код',
            'compressed_bytes' => 'сжатые_байты',
            'connect_timeout_seconds' => 'тайм-аут_подключения',
            'content_changed' => 'содержимое_изменилось',
            'content_hash' => 'хеш_содержимого',
            'counts' => 'количество',
            'counts_path' => 'путь_количества',
            'crawl_delay_seconds' => 'пауза_обхода',
            'cycles' => 'циклы',
            'cycle' => 'цикл',
            'delay_seconds' => 'пауза_секунд',
            'discovered' => 'найдено',
            'discover_limit' => 'лимит_поиска',
            'duration_seconds' => 'длительность',
            'elapsed_since_last_request_seconds' => 'прошло_с_прошлого_запроса',
            'episode_id' => 'серия',
            'episode_number' => 'номер_серии',
            'etag' => 'etag',
            'exception' => 'исключение',
            'existing' => 'уже_было',
            'external_id' => 'внешний_id',
            'failed' => 'ошибок',
            'http_status' => 'код_http',
            'index' => 'номер',
            'index_path' => 'путь_индекса',
            'index_url' => 'ссылка_индекса',
            'indexed_at' => 'проиндексировано',
            'last_changed_at' => 'изменено',
            'last_crawled_at' => 'обойдено',
            'last_modified' => 'последнее_изменение',
            'limit' => 'лимит',
            'media_attached' => 'медиа_подключено',
            'message' => 'сообщение',
            'mode' => 'режим',
            'name' => 'название',
            'number' => 'номер',
            'once' => 'один_цикл',
            'original_title' => 'оригинальное_название',
            'page_type' => 'тип_страницы',
            'parse_limit' => 'лимит_разбора',
            'parse_status' => 'статус_разбора',
            'parsed' => 'разобрано',
            'playback_url' => 'ссылка_медиа',
            'poster_url' => 'постер',
            'queue_remaining' => 'очередь_осталась',
            'queue_size' => 'размер_очереди',
            'queued_sitemaps_remaining' => 'карт_осталось_в_очереди',
            'raw_url' => 'исходная_ссылка',
            'reason' => 'причина',
            'remaining_seconds' => 'осталось_секунд',
            'seconds' => 'секунды',
            'season_id' => 'сезон',
            'season_number' => 'номер_сезона',
            'selected' => 'выбрано',
            'selected_for_parse' => 'выбрано_для_разбора',
            'signal' => 'сигнал',
            'sitemap_url' => 'ссылка_карты',
            'sleep_seconds' => 'пауза',
            'slug' => 'адрес',
            'source_id' => 'источник',
            'source_page_id' => 'страница_источника',
            'source_url' => 'ссылка_источника',
            'source_url_hash' => 'хеш_ссылки',
            'stored' => 'сохранено',
            'stored_urls_this_cycle' => 'ссылок_в_цикле',
            'successful' => 'успешно',
            'synced' => 'синхронизировано',
            'taxonomy_id' => 'справочник',
            'timeout_seconds' => 'тайм-аут',
            'title' => 'название',
            'total' => 'всего',
            'total_urls' => 'всего_ссылок',
            'type' => 'тип',
            'unique_urls' => 'уникальных_ссылок',
            'updated' => 'обновлено',
            'url' => 'ссылка',
            'url_count' => 'ссылок',
            'url_hash' => 'хеш_ссылки',
            'url_locations' => 'ссылок_в_xml',
            'visited' => 'посещено',
            'visited_sitemaps' => 'посещено_карт',
            'waited_seconds' => 'ждали_секунд',
            'xml_bytes' => 'байты_xml',
            'xml_path' => 'путь_xml',
            'year' => 'год',
        ];
    }

    /**
     * @return array<string, string>
     */
    private static function valueLabels(): array
    {
        return [
            'actor' => 'актер',
            'country' => 'страна',
            'director' => 'режиссер',
            'failed' => 'ошибка',
            'genre' => 'жанр',
            'id' => 'номер',
            'pending' => 'в очереди',
            'parsed' => 'разобрано',
            'published' => 'опубликовано',
            'remote' => 'удаленное',
            'search' => 'поиск',
            'serial' => 'сериал',
            'sitemap' => 'карта сайта',
            'static' => 'служебная',
            'unknown' => 'неизвестно',
            'url-argument' => 'ссылка из аргумента',
        ];
    }
}
