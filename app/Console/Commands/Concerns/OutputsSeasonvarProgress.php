<?php

namespace App\Console\Commands\Concerns;

use App\Enums\SeasonvarPageType;
use BackedEnum;
use DateTimeInterface;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use App\Support\HumanFileSizeFormatter;

/**
 * @mixin Command
 */
trait OutputsSeasonvarProgress
{
    private const EUROPEAN_DATE_TIME_FORMAT = 'd.m.Y H:i';

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
        $message = '['.now()->format(self::EUROPEAN_DATE_TIME_FORMAT).'] '.$this->formatSeasonvarEvent($event);

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
            $items[] = $this->formatSeasonvarKey((string) $key).'='.$this->formatSeasonvarValue($value, (string) $key);
        }

        return implode(' | ', $items);
    }

    private function formatSeasonvarValue(mixed $value, ?string $key = null): string
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
            return $value->format(self::EUROPEAN_DATE_TIME_FORMAT);
        }

        if (is_string($value)) {
            return self::valueLabels()[$value] ?? $this->formatSeasonvarString($value);
        }

        if (is_array($value)) {
            return $this->formatSeasonvarArray($value);
        }

        if (is_float($value)) {
            return number_format($value, 3, '.', '');
        }

        if ($key === 'file_size_bytes' && is_int($value)) {
            $human = app(HumanFileSizeFormatter::class)->format($value, 'ru');

            return $human === null ? (string) $value : $human.' ('.$value.')';
        }

        return (string) $value;
    }

    private function formatSeasonvarString(string $value): string
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1) {
            return Carbon::parse($value)->format('d.m.Y');
        }

        if (preg_match('/^\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}(?::\d{2})?(?:\.\d+)?(?:Z|[+-]\d{2}:?\d{2})?$/', $value) === 1) {
            return Carbon::parse($value)->format(self::EUROPEAN_DATE_TIME_FORMAT);
        }

        return $value;
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
            'catalog-title-aliases-synced' => 'Дополнительные названия сохранены',
            'catalog-title-created' => 'Запись каталога создана',
            'catalog-title-ratings-synced' => 'Рейтинги сохранены',
            'catalog-title-recommendation-signals-synced' => 'Сигналы рекомендаций сохранены',
            'catalog-title-reviews-synced' => 'Отзывы сохранены',
            'catalog-title-slug-prepared' => 'Адрес записи подготовлен',
            'catalog-title-updated' => 'Запись каталога обновлена',
            'catalog-title-upsert-started' => 'Сохранение записи каталога началось',
            'catalog-relations-cleanup-complete' => 'Проверка связей каталога завершена',
            'catalog-relations-cleanup-started' => 'Проверка связей каталога началась',
            'catalog-relations-cleanup-type-complete' => 'Тип связей каталога очищен',
            'catalog-relations-legacy-cleanup-complete' => 'Архивные связи каталога очищены',
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
            'html-parse-complete' => 'Разбор страницы завершен',
            'html-parse-started' => 'Разбор страницы начался',
            'http-request-complete' => 'HTTP-запрос завершен',
            'http-request-started' => 'HTTP-запрос начался',
            'licensed-media-attached' => 'Медиа подключено',
            'licensed-media-playlist-import-complete' => 'Плейлист медиа обработан',
            'page-parse-complete' => 'Страница обработана',
            'page-parse-failed' => 'Страница завершилась ошибкой',
            'page-parse-skipped-unchanged' => 'Страница не изменилась, разбор пропущен',
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
            'seasonvar-media-attached' => 'Медиа из страницы подключено',
            'seasonvar-media-backlog-complete' => 'Допроверка старых медиа завершена',
            'seasonvar-media-backlog-started' => 'Допроверка старых медиа началась',
            'seasonvar-media-size-backlog-complete' => 'Допроверка размеров старых медиа завершена',
            'seasonvar-media-size-backlog-started' => 'Допроверка размеров старых медиа началась',
            'seasonvar-media-size-check-failed' => 'Проверка размера видео завершилась ошибкой',
            'seasonvar-media-size-check-skipped' => 'Проверка размера видео пропущена',
            'seasonvar-media-size-check-started' => 'Проверка размера видео началась',
            'seasonvar-media-size-known' => 'Размер видео определён',
            'seasonvar-media-size-unknown' => 'Размер видео не определён',
            'seasonvar-media-size-unsupported' => 'Размер видео не поддерживается для этого формата',
            'seasonvar-media-metadata-backlog-complete' => 'Дополнение данных медиа завершено',
            'seasonvar-media-metadata-backlog-started' => 'Дополнение данных медиа началось',
            'seasonvar-media-metadata-updated' => 'Данные медиа дополнены',
            'seasonvar-media-source-key-backlog-complete' => 'Ключи старых медиа дополнены',
            'seasonvar-media-source-key-backlog-started' => 'Дополнение ключей старых медиа началось',
            'seasonvar-media-source-key-updated' => 'Ключ медиа дополнен',
            'seasonvar-media-playlist-import-complete' => 'Плейлист из страницы обработан',
            'seasonvar-media-playlist-import-failed' => 'Плейлист из страницы завершился ошибкой',
            'seasonvar-media-skipped' => 'Медиа из страницы пропущено',
            'seasonvar-media-sync-complete' => 'Сохранение медиа из страницы завершено',
            'seasonvar-media-sync-started' => 'Сохранение медиа из страницы началось',
            'seasonvar-media-url-check-failed' => 'Проверка ссылки медиа завершилась ошибкой',
            'seasonvar-media-url-checked' => 'Ссылка медиа проверена',
            'seasonvar-media-updated' => 'Медиа из страницы обновлено',
            'seasonvar-metadata-backfill-complete' => 'Локальное дополнение метаданных завершено',
            'seasonvar-metadata-backfill-started' => 'Локальное дополнение метаданных началось',
            'seasonvar-metadata-page-complete' => 'Метаданные страницы дополнены',
            'seasonvar-metadata-page-failed' => 'Дополнение метаданных страницы завершилось ошибкой',
            'seasonvar-metadata-page-rejected' => 'Снимок страницы не прошёл проверку метаданных',
            'seasonvar-metadata-title-complete' => 'Связи тайтла дополнены',
            'seasonvar-metadata-title-failed' => 'Дополнение связей тайтла завершилось ошибкой',
            'seasonvar-title-merge-complete' => 'Объединение сериалов завершено',
            'seasonvar-title-merged' => 'Страницы сезонов объединены',
            'seasonvar-import-complete' => 'Обновление Seasonvar завершено',
            'seasonvar-import-cycle-complete' => 'Цикл обновления завершен',
            'seasonvar-import-cycle-started' => 'Цикл обновления начался',
            'seasonvar-import-failed' => 'Обновление Seasonvar завершилось ошибкой',
            'seasonvar-import-season-url-failed' => 'Страница сезона завершилась ошибкой',
            'seasonvar-import-season-urls-selected' => 'Страницы сезонов выбраны',
            'seasonvar-import-sleep-started' => 'Пауза перед следующим циклом',
            'seasonvar-import-started' => 'Обновление Seasonvar запущено',
            'seasonvar-import-stop-requested' => 'Получен сигнал остановки',
            'seasonvar-import-url-failed' => 'Страница по ссылке завершилась ошибкой',
            'seasonvar-source-inventory-complete' => 'Инвентаризация страниц источника завершена',
            'seasonvar-source-inventory-failed' => 'Инвентаризация страниц источника завершилась ошибкой',
            'seasonvar-source-inventory-started' => 'Инвентаризация страниц источника началась',
            'sitemap-already-visited' => 'Карта сайта уже посещена',
            'sitemap-discovery-blocked' => 'Поиск по карте сайта заблокирован',
            'sitemap-discovery-complete' => 'Поиск по карте сайта завершен',
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
            'source-pages-status-backfill-complete' => 'Статусы старых страниц обновлены',
            'source-pages-status-backfill-started' => 'Обновление статусов старых страниц началось',
            'source-pages-malformed-cleaned' => 'Некорректные ссылки источника отключены',
            'source-ready' => 'Источник готов',
            'store-discovered-urls-chunk-complete' => 'Пакет найденных ссылок сохранен',
            'store-discovered-urls-complete' => 'Сохранение найденных ссылок завершено',
            'store-discovered-urls-started' => 'Сохранение найденных ссылок началось',
            'taxonomy-created' => 'Справочник создан',
            'taxonomy-sync-complete' => 'Синхронизация справочников завершена',
            'taxonomy-sync-started' => 'Синхронизация справочников началась',
            'taxonomy-type-synced' => 'Тип справочника синхронизирован',
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
            'aliases' => 'дополнительных_названий',
            'archive_count' => 'архивов',
            'archive_path' => 'путь_архива',
            'archives' => 'архивы',
            'attached' => 'подключено',
            'all_urls_path' => 'путь_ссылок',
            'argument' => 'аргумент',
            'base_url' => 'базовая_ссылка',
            'body_bytes' => 'байты',
            'backfilled' => 'дополнено',
            'catalog_title_id' => 'запись_каталога',
            'catalog_title' => 'сериал',
            'candidates' => 'кандидатов',
            'child_sitemaps' => 'вложенных_карт',
            'check_status' => 'статус_проверки',
            'chunk' => 'пакет',
            'code' => 'код',
            'collisions' => 'конфликтов',
            'compressed_bytes' => 'сжатые_байты',
            'connect_timeout_seconds' => 'тайм-аут_подключения',
            'content_changed' => 'содержимое_изменилось',
            'content_hash' => 'хеш_содержимого',
            'counts' => 'количество',
            'counts_path' => 'путь_количества',
            'cleaned' => 'отключено',
            'links_removed' => 'связей_удалено',
            'crawl_delay_seconds' => 'пауза_обхода',
            'cycles' => 'циклы',
            'cycle' => 'цикл',
            'delay_seconds' => 'пауза_секунд',
            'discovered' => 'найдено',
            'duration_seconds' => 'длительность',
            'elapsed_since_last_request_seconds' => 'прошло_с_прошлого_запроса',
            'episodes' => 'серий',
            'episode_id' => 'серия',
            'episode_number' => 'номер_серии',
            'etag' => 'etag',
            'exception' => 'исключение',
            'existing' => 'уже_было',
            'external_id' => 'внешний_id',
            'failed' => 'ошибок',
            'force' => 'принудительно',
            'forever' => 'постоянно',
            'format' => 'формат',
            'file_size_bytes' => 'размер_байт',
            'file_size_human' => 'размер',
            'file_size_source' => 'источник_размера',
            'groups' => 'групп',
            'http_status' => 'код_http',
            'index' => 'номер',
            'index_path' => 'путь_индекса',
            'index_url' => 'ссылка_индекса',
            'indexed_at' => 'проиндексировано',
            'imported' => 'импортировано',
            'import_status' => 'статус_обновления',
            'last_changed_at' => 'изменено',
            'last_crawled_at' => 'обойдено',
            'last_modified' => 'последнее_изменение',
            'legacy_links_removed' => 'архивных_связей_удалено',
            'legacy_records_removed' => 'архивных_записей_удалено',
            'licensed_media_id' => 'медиа',
            'media_attached' => 'медиа_подключено',
            'media_available' => 'медиа_доступно',
            'media_candidates' => 'медиа_кандидатов',
            'media_check_available' => 'проверено_доступных',
            'media_check_unavailable' => 'проверено_недоступных',
            'media_checked' => 'медиа_проверено',
            'metadata_pages_checked' => 'страниц_метаданных_проверено',
            'metadata_pages_updated' => 'страниц_метаданных_обновлено',
            'metadata_titles_checked' => 'тайтлов_метаданных_проверено',
            'metadata_titles_updated' => 'тайтлов_метаданных_обновлено',
            'metadata_relations_attached' => 'связей_метаданных_подключено',
            'metadata_failed' => 'ошибок_метаданных',
            'media_failed' => 'медиа_ошибок',
            'media_metadata_checked' => 'данные_медиа_проверено',
            'media_metadata_updated' => 'данные_медиа_обновлено',
            'media_skipped' => 'медиа_пропущено',
            'media_source_keys_checked' => 'ключей_медиа_проверено',
            'media_source_keys_updated' => 'ключей_медиа_обновлено',
            'media_unavailable' => 'медиа_недоступно',
            'media_updated' => 'медиа_обновлено',
            'media_sizes_selected' => 'размеров_выбрано',
            'media_sizes_processed' => 'размеров_проверено',
            'media_sizes_changed' => 'размеров_обновлено',
            'max_per_title' => 'максимум_на_карточку',
            'message' => 'сообщение',
            'merged_episodes' => 'серий_объединено',
            'merged_seasons' => 'сезонов_объединено',
            'merged_titles' => 'карточек_объединено',
            'mode' => 'режим',
            'name' => 'название',
            'number' => 'номер',
            'once' => 'один_цикл',
            'original_title' => 'оригинальное_название',
            'page_type' => 'тип_страницы',
            'parse_status' => 'статус_разбора',
            'parsed' => 'разобрано',
            'playback_url' => 'ссылка_медиа',
            'poster_url' => 'постер',
            'quality' => 'качество',
            'queue_remaining' => 'очередь_осталась',
            'queue_size' => 'размер_очереди',
            'queued_sitemaps_remaining' => 'карт_осталось_в_очереди',
            'ratings' => 'рейтингов',
            'raw_url' => 'исходная_ссылка',
            'reason' => 'причина',
            'records' => 'записей',
            'records_removed' => 'записей_удалено',
            'remaining_seconds' => 'осталось_секунд',
            'relation' => 'связь',
            'relation_links_removed' => 'связей_каталога_удалено',
            'relation_records_removed' => 'записей_справочника_удалено',
            'relations_checked' => 'связей_проверено',
            'reviews' => 'отзывов',
            'seconds' => 'секунды',
            'season_id' => 'сезон',
            'season_number' => 'номер_сезона',
            'seasons' => 'сезонов',
            'selected' => 'выбрано',
            'selected_for_parse' => 'выбрано_для_разбора',
            'signal' => 'сигнал',
            'sitemap_url' => 'ссылка_карты',
            'sitemap_count' => 'карт_сайта',
            'sleep_seconds' => 'пауза',
            'slug' => 'адрес',
            'source_id' => 'источник',
            'source_page_id' => 'страница_источника',
            'source_media_key' => 'ключ_медиа',
            'source_status_backfilled' => 'статусов_страниц_дополнено',
            'source_url' => 'ссылка_источника',
            'translation_name' => 'перевод',
            'source_url_hash' => 'хеш_ссылки',
            'skipped' => 'пропущено',
            'stored' => 'сохранено',
            'stored_url_count' => 'новых_ссылок',
            'stored_urls_this_cycle' => 'ссылок_в_цикле',
            'successful' => 'успешно',
            'synced' => 'синхронизировано',
            'taxonomy_id' => 'справочник',
            'taxonomy_ids' => 'справочники',
            'taxonomies' => 'справочников',
            'timeout_seconds' => 'тайм-аут',
            'title' => 'название',
            'titles' => 'карточек',
            'total' => 'всего',
            'total_urls' => 'всего_ссылок',
            'total_url_count' => 'всего_нормализованных_ссылок',
            'type' => 'тип',
            'unmatched' => 'не_найдено',
            'unknown_url_count' => 'неизвестных_ссылок',
            'malformed_url_count' => 'некорректных_ссылок',
            'blocked_url_count' => 'заблокированных_ссылок',
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
        $labels = [
            'age_rating' => 'возраст',
            'ageRatings' => 'возрастные ограничения',
            'actors' => 'актеры',
            'available' => 'доступно',
            'countries' => 'страны',
            'directors' => 'режиссеры',
            'failed' => 'ошибка',
            'genres' => 'жанры',
            'gone' => 'страница недоступна',
            'id' => 'номер',
            'invalid_url' => 'некорректная ссылка',
            'missing_data' => 'данные дополняются',
            'not_checked' => 'не проверено',
            'pending' => 'в очереди',
            'parsed' => 'разобрано',
            'published' => 'опубликовано',
            'remote' => 'удаленное',
            'unavailable' => 'недоступно',
            'statuses' => 'статусы',
            'studios' => 'студии',
            'tags' => 'метки',
            'translations' => 'переводы',
            'url' => 'ссылка',
            'url-argument' => 'ссылка из аргумента',
        ];

        foreach (SeasonvarPageType::cases() as $type) {
            $labels[$type->value] = $type->label();
        }

        return $labels;
    }
}
