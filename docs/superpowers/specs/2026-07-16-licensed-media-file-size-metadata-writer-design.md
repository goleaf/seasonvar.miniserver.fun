# Centralized Licensed Media File Size Metadata Writer Design

**Дата:** 16.07.2026

**Статус:** одобрено общим разрешением пользователя продолжать рекомендуемые production follow-up изменения без дополнительных вопросов

## Контекст

`InspectLicensedMediaFileSize` и `StreamLicensedMediaDownload` независимо выполняют одинаковый optimistic update `licensed_media`: проверяют ID, `catalog_title_id`, `playback_url`, `path` и `format`, записывают шесть file-size полей, обновляют загруженную модель и инвалидируют cache тайтла. Дублирование появилось после закрытия source-race, но теперь два delivery boundary могут незаметно разойтись по guard, cache или правилам material change.

## Рассмотренные подходы

1. Оставить две реализации. Это минимальный diff, но будущая правка source identity или size schema потребует синхронного изменения двух security-sensitive путей.
2. Вынести только Eloquent query helper. Это убирает часть SQL-дублирования, но оставляет разными сравнение metadata, `forceFill` и cache invalidation.
3. Ввести один focused metadata writer с typed source snapshot и typed write status. Этот вариант выбран: он формирует одну mutation boundary без repository-слоя и не забирает у importer/download их orchestration.

## Архитектура

Readonly `LicensedMediaFileSizeSourceData` снимается до внешнего HTTP-запроса и содержит только стабильную identity записи: media ID, title ID, `playback_url`, `path`, `format`. Он не содержит пользовательских данных, cookies или отдельного remote URL вне уже сохранённых model attributes и никогда не логируется.

`LicensedMediaFileSizeMetadataWriter` принимает модель, snapshot и typed `ExternalMediaFileSizeResultData`, затем сам нормализует полный набор size attributes. Сервис выполняет один conditional Eloquent update под стандартным SoftDeletes scope. Результат имеет один из трёх typed states через `MediaFileSizeMetadataWriteStatus`:

- `Changed` — source совпал, metadata сохранена и material size fields изменились;
- `Unchanged` — source совпал, запись выполнена, но изменился только служебный timestamp или значения совпали;
- `SourceChanged` — строка удалена или source identity изменилась, поэтому stale результат не записан.

Writer синхронизирует уже загруженный model instance только после успешного update. Только `Changed` вызывает `CatalogCacheInvalidator::importedTitleChanged()` и только для положительного `catalog_title_id`.

## Интеграция

Importer action снимает snapshot непосредственно перед inspector. После HTTP он делегирует writer и сохраняет прежнее progress-поведение: `SourceChanged` становится безопасным skipped event, `Changed` возвращает `true`, `Unchanged` возвращает `false`. Исключения по-прежнему не откатывают playable catalog import.

Download service снимает snapshot после успешной eligibility проверки, но до открытия upstream stream. Best-effort correction делегирует тому же writer; любая metadata/cache ошибка подавляется внутри существующей download repair boundary и не прерывает авторизованную передачу bytes.

## Безопасность и производительность

- Клиент не передаёт source snapshot или attributes.
- Conditional update сохраняет защиту от stale HEAD/Range/download response и soft-deleted media.
- Никакой DB transaction не охватывает внешний HTTP или streaming loop.
- Writer выполняет ровно один update и только targeted title cache invalidation; дополнительных reads, N+1 или global cache flush нет.
- Video body, upstream headers и signed URL не сохраняются и не логируются.

## Совместимость и проверка

Schema, routes, configuration, translations, UI и public API не меняются. Проверка выполняется без создания или запуска tests согласно прямому ограничению задачи: PHP lint, targeted Pint, targeted PHPStan, `git diff --check`, forbidden-pattern scan и ручная проверка полного task-only diff.
