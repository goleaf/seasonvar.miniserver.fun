# Тихое пакетное обновление счётчиков импорта

## Цель

Использовать новый API Laravel 13.20 `incrementEachQuietly()` для синхронного обновления нескольких счётчиков `SeasonvarImportRun` одним атомарным SQL-запросом без событий модели. Изменение должно сохранить текущие итоговые данные импорта, обновление `summary` и временных меток, не ослабляя защиту конкурентного queue-importer.

Успех подтверждается следующими условиями:

- минимальная версия `laravel/framework` в `composer.json` — `^13.20`;
- синхронный `SeasonvarImportPipeline` увеличивает несколько счётчиков через `incrementEachQuietly()`;
- дополнительные данные `summary` сохраняются тем же обновлением;
- события `updating` и `updated` для пакетного обновления счётчиков не запускаются;
- существующий conditional update конкурентного `SeasonvarImportRunRecorder` остаётся без изменений;
- прежние тесты импорта и новый focused contract проходят.

## Проверенный baseline на 17.07.2026

- Установлены PHP 8.5 и Laravel 13.20.0; `composer.lock` уже фиксирует `laravel/framework` 13.20.0, но `composer.json` пока допускает `^13.8`.
- `SeasonvarImportPipeline::addRunCounters()` сначала обновляет модель через `refresh()`, затем вручную складывает значения в `fill()` и вызывает `save()`. Такой путь выполняет read-modify-write и запускает Eloquent model events.
- `SeasonvarImportRunRecorder::addCounters()` обслуживает конкурентные jobs одним условным `UPDATE`, ограниченным `id` и активными статусами `queued|running`. Query-builder update уже не запускает Eloquent model events.
- Laravel 13.20 предоставляет `incrementEachQuietly()` только на экземпляре Eloquent model. Перенос conditional recorder на instance API потребовал бы предварительного чтения и создал бы гонку между проверкой статуса и записью.

## Рассмотренные варианты

### 1. Точечное применение в синхронном pipeline — выбран

`addRunCounters()` использует `incrementEachQuietly()` на уже переданном `SeasonvarImportRun`. Счётчики обновляются атомарными выражениями `column + amount`, а объединённый `summary` передаётся как extra attributes. Это прямо использует новый API и не меняет конкурентную boundary.

### 2. Перевести также конкурентный recorder

Для instance API пришлось бы сначала найти активный run, а затем обновить его только по primary key. Статус может стать terminal между запросами, поэтому поздняя job смогла бы изменить завершённый запуск. Вариант отклонён.

### 3. Только повысить минимальную версию Laravel

Constraint стал бы точнее, но прикладной код не получил бы атомарное тихое обновление и продолжил бы запускать события. Вариант не достигает цели.

## Дизайн

`SeasonvarImportPipeline::addRunCounters()` сохраняет текущий вызов `refresh()`, поскольку он нужен для объединения свежего `summary`. После чтения метод формирует allowlisted массив приращений для существующих counter columns и передаёт его в `incrementEachQuietly()`.

Второй аргумент содержит только объединённый `summary`. Laravel Eloquent самостоятельно добавляет `updated_at`; форма summary, имена счётчиков и вызывающие методы не меняются. Значения остаются числовыми и берутся из внутреннего typed result pipeline, поэтому новая HTTP-валидация не требуется.

`SeasonvarImportRunRecorder`, routes, migrations, публичная команда `php artisan seasonvar:import`, очередь и UI не меняются. `decrementEachQuietly()` не применяется: текущий домен импортера не уменьшает эти накопительные счётчики.

## Поток данных и ошибки

1. Pipeline получает внутренние приращения и optional summary fragment.
2. Модель перечитывается, чтобы взять актуальный summary.
3. Новый fragment объединяется с текущим summary по прежнему правилу `array_merge()`.
4. `incrementEachQuietly()` выполняет один atomic update счётчиков, summary и `updated_at` без `updating`/`updated` events.
5. Исключение БД или нечисловое значение не скрывается и проходит по существующему error path импортера.

Нулевые значения допустимы и сохраняют прежнюю арифметику. Метод не получает произвольные имена колонок: список счётчиков остаётся явным в pipeline.

## Тестирование

Focused regression вызывает пакетное обновление через реальную pipeline boundary и проверяет:

- одновременное увеличение как минимум двух счётчиков;
- накопление поверх уже сохранённых значений;
- сохранение существующих и новых ключей `summary`;
- отсутствие `updating` и `updated` events именно во время counter update;
- обновление `updated_at`.

Затем выполняются focused importer tests, Pint, dependency validation, Larastan в затронутом профиле и полный PHPUnit suite в объёме, оправданном результатами focused checks.

## Документация

`CHANGELOG.md` получает отдельный русский пункт за 17.07.2026. `README.md` проверяется перед завершением: состояние проекта уже указывает Laravel 13.20, а внутренняя смена механизма счётчиков не добавляет посетителю новую возможность, поэтому фиктивная запись в пользовательской истории не создаётся.

Исторические audit и implementation plan документы с зафиксированным на дату Laravel 13.19 не переписываются. Управляемые блоки `project-docs` изменение не затрагивает.

## Вне области

- Изменение статусов, набора или семантики счётчиков импорта.
- Применение `decrementEachQuietly()` без существующего decrement use case.
- Перенос conditional queue updates на instance model API.
- Новые observers, events, migrations, routes, UI или production dependencies.
