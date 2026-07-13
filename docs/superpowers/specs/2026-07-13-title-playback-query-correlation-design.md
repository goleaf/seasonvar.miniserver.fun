# Оптимизация коррелированного запроса доступных серий

Дата: 13.07.2026

## Контекст

Страница публичного тайтла выбирает первую доступную серию и соседние серии через `CatalogTitlePlaybackQuery::watchableEpisodesForVisibleTitles()`. Внешний запрос уже ограничивает:

- видимые пользователю тайтлы;
- доступные пользователю сезоны;
- доступные пользователю серии;
- media с разрешённой публикацией и аудиторией;
- media с непустым playback location;
- media без известного недоступного состояния.

Однако коррелированный media-подзапрос дополнительно вызывает `LicensedMedia::forAvailableReleases()`. Этот scope снова проверяет родительский тайтл, сезон и серию вложенными коррелированными подзапросами для каждой рассматриваемой media row. На production-scale SQLite эти повторные проверки доминируют в cold-path страницы.

Контрольный профиль `/titles/veshhdok` до изменения:

- 20 HTTP-запросов: mean `1028,0 ms`, p50 `1004,8 ms`, p95 `1205,9 ms`, max `1365,3 ms`;
- размер ответа: `884195` bytes;
- один in-process HTTP-профиль: wall `979,4 ms`, SQL `376,32 ms`, `63` запроса;
- три самых дорогих запроса выбора первой/предыдущей/следующей серии: `135,85 ms`, `102,29 ms` и `101,20 ms`.

Проверка `EXPLAIN QUERY PLAN` и принудительный выбор существующих индексов не дали существенного ускорения: медиана разных index-вариантов осталась в диапазоне `114–143 ms`. Следовательно, добавление ещё одного похожего индекса не устраняет первопричину.

Проверка целостности текущей базы для `644604` media rows, связанных с сериями, не обнаружила отсутствующих серий, пустого `season_id`, несовпадения сезона или тайтла. Несмотря на это, оптимизированный запрос не будет полагаться только на состояние данных: все три связи будут проверяться явными column correlations.

## Цель

Убрать повторные проверки одной и той же release hierarchy из самого горячего playback-подзапроса, не ослабляя публикационные, audience, availability, health и ownership-границы.

Изменение должно:

1. материально сократить SQL-время выбора первой и соседних серий;
2. сохранить результаты и порядок навигации;
3. отклонять media, чьи `catalog_title_id`, `season_id` и `episode_id` не соответствуют внешней серии;
4. не увеличивать число SQL-запросов или Livewire payload;
5. не добавлять общий кеш авторизации, моделей или подписанных playback URL.

## Не входит в объём

- изменение публичного URL, player DTO или подписанного playback route;
- изменение entitlement-правил тайтла, сезона, серии или media;
- перестройка tuple-order навигации;
- добавление индексов без отдельного подтверждения планом и замером;
- кеширование пользовательских разрешений или signed URL;
- рефакторинг всех вызовов `forAvailableReleases()`;
- изменение импорта, схемы базы или существующих media rows.

## Согласованный подход

Изменяется только media `EXISTS` внутри `watchableEpisodesForVisibleTitles()`.

Подзапрос сохраняет scopes:

- `availableTo($user)` для собственных publication/audience/window/soft-delete полей media;
- `withPlaybackLocation()` для обязательного внешнего источника;
- `withoutKnownFailures()` для health boundary.

Из этого конкретного подзапроса удаляется `forAvailableReleases($user)`, потому что release hierarchy уже авторизована внешним запросом:

- `Episode::availableTo($user)` проверяет внешнюю серию;
- `Season::availableTo($user)` ограничивает внешний `season_id`;
- `CatalogTitleQuery::visibleTo($user)` ограничивает внешний `catalog_title_id`.

Media row связывается с уже проверенной внешней hierarchy тремя явными `whereColumn`:

1. `licensed_media.episode_id = episodes.id`;
2. `licensed_media.season_id = episodes.season_id`;
3. `licensed_media.catalog_title_id = seasons.catalog_title_id`.

Вторая корреляция является обязательной, даже если текущие данные целостны: media с правильным episode ID, но чужим season ID не должна сделать серию доступной. Третья корреляция аналогично не позволяет media другого тайтла пройти через совпавшую серию.

Внешние filters, selected columns и детерминированный порядок сезонов/серий не меняются. Применяется существующий Eloquent query boundary; raw пользовательский ввод и handwritten SQL для correlations не нужны.

## Граница безопасности

Оптимизация не переносит authorization в доверие к браузеру или базе.

- Пользователь и audience передаются в те же `availableTo()`/`visibleTo()` scopes.
- Тайтл, сезон и серия проверяются внешним запросом до признания серии доступной.
- Media отдельно проверяет собственные публикационные поля, playback location и health.
- Несогласованные foreign-key dimensions отклоняются явными correlations.
- Route model binding, Livewire locked ID и action authorization не меняются.
- `CatalogPlaybackSourceResolver` и прямой signed route продолжают повторно проверять полную parent hierarchy через `forAvailableReleases()` или существующую эквивалентную границу.
- Полный scope также сохраняется в `availableMedia()`, eager loading вариантов, season summaries и всех путях, где нет уже авторизованной внешней episode/season/title hierarchy.
- Raw external URL, токены, private state и authorization-derived output не попадают в общий кеш или HTML.

## Поток данных

1. Сервер получает trusted `CatalogTitle` и текущего пользователя.
2. Внешний query выбирает только серии доступных сезонов видимых тайтлов.
3. Коррелированный `EXISTS` ищет хотя бы одну собственно доступную и рабочую media row.
4. Три column correlations доказывают принадлежность media выбранной episode/season/title hierarchy.
5. Первая или соседняя серия выбирается существующим tuple-order.
6. Конкретный источник воспроизведения разрешается отдельным полным security-path.

## Обработка ошибок и деградация

Изменение не добавляет внешний сервис, кеш или новую точку отказа. При отсутствии согласованной playable media серия не попадает в результат. Ошибка базы обрабатывается существующим HTTP/Livewire exception boundary; она не маскируется stale authorization state.

Схема базы и данные не меняются, поэтому rollback состоит из возврата прежней формы подзапроса. Cache invalidation, warming и deployment cache-version bump не требуются.

## Тестирование

Реализация выполняется test-first в новом `tests/Feature/CatalogTitlePlaybackQueryTest.php`. Существующие route-level границы дополнительно проверяются ближайшими тестами в `CatalogPageTest` и `SecurityHardeningTest`, но новая query-семантика не растворяется в этих больших классах.

Обязательные сценарии:

1. опубликованные согласованные title/season/episode/media возвращают серию;
2. скрытый или недоступный тайтл не возвращает серию;
3. скрытый или недоступный сезон не возвращает серию;
4. скрытая или недоступная серия не возвращается;
5. скрытая, будущая, истёкшая, удалённая или health-failed media не делает серию доступной;
6. media без playback location не делает серию доступной;
7. media с несовпадающим `season_id` отклоняется;
8. media с несовпадающим `catalog_title_id` отклоняется;
9. первая, предыдущая и следующая серии сохраняют текущий stable tuple-order и lane semantics;
10. прямой signed playback route по-прежнему повторно проверяет parent availability;
11. query-shape regression test нормализует quotes/whitespace результата `toSql()`, проверяет три обязательные column correlations и отсутствие вложенных parent-release `EXISTS` внутри media-подзапроса; весь SQL string целиком не фиксируется.

После focused тестов запускаются связанные catalog/security/Livewire tests, Pint и полный PHP test suite. Frontend build нужен только если параллельная работа или фактическая реализация затронет Blade, CSS или JavaScript; данная оптимизация сама по себе frontend assets не меняет.

## Проверка производительности

Замеры выполняются на том же production-scale read-only состоянии базы и для того же slug после прогрева compiled views. Сравниваются:

- минимум 20 последовательных HTTP-запросов к `/titles/veshhdok`: mean, p50, p95, max и response bytes;
- минимум пять in-process профилей до изменения и пять после: wall time, суммарное SQL-время и query count;
- длительность трёх запросов first/previous/next;
- `EXPLAIN QUERY PLAN` до и после;
- корректность ответа и отсутствие новых ошибок в browser/Livewire flow.

Критерии приёмки:

- query count не превышает исходные `63`;
- response payload не увеличивается по причине изменения;
- медианное суммарное SQL-время трёх playback selection/navigation запросов уменьшается не менее чем на `20%` относительно пяти pre-change профилей; одиночное исходное наблюдение `339,34 ms` сохраняется как диагностическое свидетельство, но не подменяет медиану;
- HTTP p95 не ухудшается более чем на `5%`, а ускорение страницы заявляется только при воспроизводимом снижении p50/p95 вне обычного шума;
- cold-cache и cache-outage корректность не зависят от нового кеша, потому что кеш в этом изменении не вводится.

Если SQL-форма стала проще, но измерения не показывают ожидаемого эффекта, изменение не объявляется performance improvement: сначала повторяется профилирование и отдельно проектируется следующий bottleneck.

## Документация и поставка

После подтверждённой реализации обновляются только затронутые владельцы документации:

- `docs/performance.md` — новая query boundary и измерения до/после;
- `docs/maintenance-log.md` — выполненные проверки и operational evidence;
- `CHANGELOG.md` — пользовательский эффект без неподтверждённых заявлений.

Изменение поставляется отдельным логическим коммитом после тестов. Перед commit проверяются `main`, staged/unstaged/untracked файлы и принадлежность каждого файла текущей работе. Параллельные изменения импортера не включаются.

## Рассмотренные альтернативы

### Join с `DISTINCT` или grouped media relation

Прямой join может сократить вложенность, но размножает episode rows, влияет на tuple navigation и требует более широкого переписывания select/order. Для локальной причины это неоправданно высокий regression risk.

### Новый индекс без изменения query shape

Существующие принудительные index-варианты дали близкую медиану. Индекс не устраняет повторные correlated release checks и увеличивает стоимость большого importer write-path.

### Кеширование результата навигации

Кеш добавил бы user/audience/availability dimensions, сложную немедленную invalidation и риск stale authorization. Он также не исправляет cold path. Для этой задачи корректнее сначала убрать избыточную работу базы.
