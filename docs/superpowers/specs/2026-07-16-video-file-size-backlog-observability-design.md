# Дизайн наблюдаемости backlog размеров видео

**Дата:** 2026-07-16
**Статус:** одобрено прямым указанием пользователя продолжать реализацию без дополнительных вопросов

## Контекст

Production-каталог содержит около 873 тысяч активных прямых media-записей, а подавляющее большинство legacy rows ещё не имеет terminal file-size metadata. Существующие `SeasonvarImportRun` counters показывают результат отдельного запуска, но не отвечают на операционный вопрос, сколько всего файлов ожидают первичной или повторной проверки. `seasonvar:import --status` показывал только Redis queue/run lifecycle, а административная Livewire-страница — только per-run counters и media health.

Прямой aggregate по `licensed_media` использует полный scan SQLite. Административная страница опрашивается каждые пять секунд во время активного импорта, поэтому вычислять global backlog непосредственно в каждом render нельзя.

## Решение

Добавляется focused service `LicensedMediaFileSizeBacklog`, который владеет единым eligibility/freshness query contract:

- eligible media имеет разрешённый direct-file format или direct-file suffix в доверенном URL/path;
- effective location использует HTTP(S);
- normal backlog включает отсутствующий status/timestamp, `pending`, а также просроченные `known`, `unknown` и `failed` согласно существующим config TTL;
- force mode возвращает все eligible direct-file media;
- HLS и другие stream-only manifest записи не входят в direct-file backlog.

Pipeline использует этот query вместо собственной копии условий. Тот же service строит один typed global snapshot с eligible, checked, pending, due, known, unknown, unsupported, failed и суммой известных bytes.

Snapshot сохраняется через существующий `TieredCache` в operational domain. Fresh TTL настраивается как `seasonvar.media_file_size.status_cache_seconds` и по умолчанию равен 900 секундам: это соответствует медленному scheduled batch и ограничивает тяжёлый production SQLite scan. Stale snapshot может обслуживаться ограниченное время при временном cache/DB сбое. Per-media import writes не bump-ят global cache version: максимум пятнадцати минут eventual consistency намеренно предотвращает scan storm во время интенсивного backfill; точный capture time виден оператору.

## Интерфейсы

`LicensedMediaFileSizeBacklogStatusData` — immutable DTO. Он валидирует неотрицательные counters/bytes, хранит момент capture и детерминированно вычисляет процент terminal coverage.

`LicensedMediaFileSizeBacklog` предоставляет:

1. `query(bool $force = false): Builder` — stable reusable Eloquent query с SoftDeletes scope;
2. `status(): LicensedMediaFileSizeBacklogStatusData` — cached typed global snapshot;
3. private aggregate builder — один SQL statement без загрузки media rows в PHP.

`seasonvar:import --status` выводит второй console table с глобальными размерами и временем снимка. Existing admin service превращает DTO в полностью подготовленный presentation array; Blade только отображает translated labels, icons и values.

## Отказы и безопасность

- Snapshot не содержит playback/source URLs, signed query, cookies или error details.
- Cache failure использует существующий TieredCache fallback; DB exception сохраняет стандартное console/admin error behavior и не меняет importer data.
- Snapshot read-only и не инициирует network inspection.
- Pipeline по-прежнему ограничивает обработку `lazyById`, chunk size и hard-capped limit.
- Новый экран и новый public endpoint не создаются; authorization существующей admin route не меняется.
- Exact bytes остаются в БД и console diagnostics; UI форматирует их через общий `HumanFileSizeFormatter`.

## Проверка

Automated tests запрещены исходным заданием. Выполняются PHP lint, targeted Pint, targeted PHPStan, read-only console status cold/warm timing, Blade compilation, Tailwind production build, translation-key validation, `git diff --check`, forbidden-pattern search и task-only diff review.
