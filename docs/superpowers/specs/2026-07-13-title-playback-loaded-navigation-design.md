# Навигация по уже загруженным сериям активного сезона

Дата: 13.07.2026

## Контекст и доказательства

Первый этап исправил hierarchy defect в `CatalogTitlePlaybackQuery::watchableEpisodesForVisibleTitles()`: media с чужим `season_id` больше не делает серию доступной, а внутренний `EXISTS` не повторяет parent-release scopes. Focused, navigation и direct signed playback tests прошли.

Однако одинаковые pre/post профили не подтвердили требуемое ускорение. Парный A/B старой и новой SQL-формы в одном процессе показал медиану `122,3 ms` против `120,9 ms`, то есть около `1,1%`. `EXPLAIN QUERY PLAN` подтвердил, что удалённые parent checks были дешёвыми indexed primary-key lookups. Основная стоимость остаётся в трёх независимых проходах watchable query:

1. `CatalogPrimaryActionResolver` выбирает первую серию;
2. `episodeNavigation()` отдельно ищет предыдущую серию;
3. `episodeNavigation()` отдельно ищет следующую серию.

Контрольный тайтл `veshhdok` содержит один сезон, `329` серий и `329` media rows. К моменту вычисления навигации `CatalogTitlePlayer::render()` уже загрузил все доступные серии активного сезона через `episodesForSeason()` и все season summaries через `seasonSummaries()`. Несмотря на это, текущий `episodeNavigation()` игнорирует обе trusted collections и повторно обращается к базе два раза.

## Цель

Вычислять previous/next из уже загруженной и авторизованной коллекции активного сезона. Выполнять существующий SQL adjacent lookup только когда сосед действительно может находиться в другом видимом сезоне той же release lane.

Изменение должно:

- убрать оба navigation SQL scans для обычного перехода внутри активного сезона;
- убрать бессмысленный boundary scan, если предыдущего или следующего сезона нет;
- выполнять не более одного adjacent query на реально пересекаемую границу сезона;
- сохранить пару lane `(season.kind, episode.kind)`, tuple-order и все publication/audience/window/media-health ограничения;
- не загружать дополнительные коллекции и не увеличивать Livewire snapshot;
- не добавлять общий кеш, static mutable state или signed URL storage.

## Не входит в объём

- изменение выбора primary action;
- изменение `episodesForSeason()` или `seasonSummaries()` SQL;
- загрузка всех серий всех сезонов;
- SQLite-specific window functions или raw CTE;
- новый индекс, migration, cache key или TTL;
- изменение URL-state, player source, progress, watchlist или rating;
- перенос query/business logic в Blade.

## Согласованный подход

`CatalogTitlePlaybackQuery::episodeNavigation()` получает две уже построенные server-side collections:

- `Collection<int, Episode> $activeSeasonEpisodes` из `episodesForSeason()`;
- `Collection<int, Season> $seasonSummaries` из `seasonSummaries()`.

Сигнатура закрепляется в таком порядке:

```php
public function episodeNavigation(
    CatalogTitle $catalogTitle,
    Season $season,
    ?User $user,
    Episode $episode,
    Collection $activeSeasonEpisodes,
    Collection $seasonSummaries,
): CatalogEpisodeNavigation
```

Метод остаётся владельцем navigation semantics. Livewire только передаёт результаты существующих query services и получает `CatalogEpisodeNavigation`; компонент не сортирует модели и не строит SQL.

### Навигация внутри сезона

Метод повторно проверяет, что:

- season принадлежит title;
- selected episode принадлежит season;
- selected episode присутствует в `activeSeasonEpisodes`;
- все кандидаты для локального previous/next принадлежат тому же season;
- candidate `episode.kind` совпадает с selected `episode.kind`.

Коллекция `episodesForSeason()` уже имеет canonical order `kind`, `sort_order`, `number`, `id`. После фильтрации по текущему `episode.kind` соседние элементы в этой коллекции являются точными previous/next внутри сезона. Новая сортировка и PHP-side normalization не выполняются.

### Переход между сезонами

Если локального previous или next нет, метод использует ordered `seasonSummaries` только для ответа на вопрос, существует ли до или после текущего сезона другой доступный сезон того же `season.kind` с `available_episodes_count > 0`.

- Если такого сезона с нужной стороны нет, результат остаётся `null` без SQL.
- Если потенциальный сезон существует, вызывается существующий `adjacentEpisode()` только для этой стороны.
- `adjacentEpisode()` сохраняет текущий database-authoritative поиск по полной watchable boundary и `(season.kind, episode.kind)` lane. Поэтому summary count не считается доказательством наличия конкретного episode kind и не используется для выбора ID.

На середине сезона navigation выполняет `0` queries. На первой серии самого первого сезона previous не вызывает query, а next берётся из коллекции. На последней серии перед следующим сезоном выполняется только один next query. Максимум два query остаётся возможен только для активного сезона без локальных соседей, расположенного между двумя другими сезонами; correctness важнее искусственного объединения двух направлений.

## Границы компонентов

### `CatalogTitlePlaybackQuery`

Владеет:

- проверкой hierarchy входных моделей и collections;
- фильтрацией текущей episode lane;
- выбором локальных соседей;
- определением необходимости cross-season lookup;
- существующим SQL fallback через `adjacentEpisode()`.

Не кеширует результаты между запросами и не хранит collections в service properties.

### `CatalogTitlePlayer`

После существующих вызовов `seasonSummaries()` и `episodesForSeason()` передаёт `$episodes` и `$seasons` в `episodeNavigation()`. Публичные Livewire properties не меняются. Eloquent collections остаются локальными render variables и не сериализуются в snapshot.

### `CatalogEpisodeNavigation`

DTO не меняется: `previous` и `next` остаются nullable `Episode`. Blade и player controls получают тот же контракт.

## Безопасность и целостность

- Collections создаются только server-side существующими user-aware query services.
- Browser не передаёт collection, порядок, kind или candidate IDs.
- Locked `catalogTitleId` и повторная проверка URL episode сохраняются.
- Несовпадающие title/season/episode inputs возвращают пустую навигацию без fallback query.
- Episode, отсутствующая в trusted active-season collection, возвращает пустую навигацию.
- Cross-season fallback использует полную `watchableEpisodes()` boundary и не доверяет summary count как authorization result.
- Private user state, permissions, raw media URLs и signed playback URLs не кешируются.
- Новая логика не делает запросов из Blade и не добавляет PHP implementation в Blade.

## Ошибки и деградация

Неполная или несогласованная collection приводит к безопасному `CatalogEpisodeNavigation` без ссылок, а не к поиску по произвольным browser IDs. Database failure возможен только в существующем cross-season fallback и обрабатывается текущим Livewire exception boundary.

Если importer обновил releases между `seasonSummaries()` и `episodesForSeason()` в одном render, отсутствие selected episode в active collection даёт пустую навигацию до следующего render. Компонент не показывает потенциально устаревшую ссылку.

Migration, cache invalidation, warming и deployment cache-version bump не требуются. Rollback возвращает прежний вызов `episodeNavigation()` без collections.

## Тестирование

Реализация выполняется test-first.

Новый focused service test покрывает:

1. middle episode получает previous/next из active collection с `0` navigation SQL queries;
2. first episode первого сезона получает локальный next и `null` previous с `0` queries;
3. last episode последнего сезона получает локальный previous и `null` next с `0` queries;
4. last episode перед следующим regular season выполняет ровно один query и получает first episode следующего сезона;
5. first episode после предыдущего regular season выполняет ровно один query и получает last episode предыдущего сезона;
6. special episode не смешивается с regular episode внутри того же сезона;
7. special season не смешивается с regular season;
8. скрытые, истёкшие, source-less и health-failed cross-season candidates остаются исключёнными существующей fallback boundary;
9. mismatched title/season/episode и episode вне active collection возвращают пустую навигацию без SQL.

Существующий Livewire navigation regression подтверждает URL-state, кнопки, переходы между сезонами и release lanes. Direct signed playback security test подтверждает независимую полную parent recheck.

## Измерения и критерии приёмки

Используются сохранённые pre-change evidence:

- in-process: `60` queries, response `881900` bytes, median суммарного playback SQL `493,7 ms`;
- localhost HTTP: `20/20` status 200, mean `1181,5 ms`, p50 `1139,0 ms`, p95 `1638,8 ms`, payload `884195` bytes;
- paired media correlation A/B: `122,3 → 120,9 ms`, недостаточные `1,1%`.

После изменения повторяются те же пять in-process и двадцать HTTP observations при отсутствии параллельного PHPUnit/load-test процесса.

Приёмка требует:

- guest title render query count не выше `58` для контрольного one-season title;
- navigation SQL query count равен `0` для first/interior/last episode без соседнего сезона;
- response payload не увеличивается;
- median суммарного playback SQL снижается минимум на `20%` от `493,7 ms`;
- HTTP p95 не ухудшается более чем на `5%`; ускорение HTTP заявляется только при воспроизводимом снижении вне шума;
- все focused, CatalogPage, SecurityHardening и полные tests проходят.

Если порог SQL снова не достигнут, документация не получает performance claim: профиль повторяется без параллельной нагрузки, затем отдельно проектируется primary-action path.

## Документация и поставка

После доказанного результата обновляются `docs/performance.md`, `docs/MAINTENANCE_LOG.md` и `CHANGELOG.md`. Код/test и benchmark documentation поставляются логическими commits только из объявленных файлов. Параллельные importer/search изменения не включаются.

## Рассмотренные альтернативы

### Один SQL window/CTE для двух соседей

Уменьшает round trips, но сортирует release set, добавляет SQLite-specific сложность и игнорирует уже загруженный активный сезон.

### Request-local materialization всех серий тайтла

Убирает повторные queries, но создаёт новый неограниченный memory payload для сериалов с большим числом сезонов. Это противоречит bounded Livewire/query architecture.

### Ещё один media index или set-based `WHERE IN`

Парные измерения set-based и correlated вариантов остались около `124 ms`; новый индекс не устраняет два повторных navigation calls и увеличивает importer write amplification.
