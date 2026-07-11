# План Playwright QA и рекомендаций

Обновлено: 10.07.2026

## Источники

- OpenAI Codex skills: <https://learn.chatgpt.com/codex/build-skills>
- OpenAI Codex MCP configuration: <https://learn.chatgpt.com/codex/extend/mcp>
- Playwright MCP snapshots: <https://playwright.dev/mcp/snapshots>
- Playwright MCP screenshots: <https://playwright.dev/mcp/tools/screenshots>
- Model Context Protocol overview: <https://modelcontextprotocol.io/docs/getting-started/intro>

## Текущее состояние

- Проектный MCP: безопасно включен только Laravel Boost в `.codex/config.toml`.
- User-level MCP: `openaiDeveloperDocs` зарегистрирован; Google/Cloudflare/Figma/Linear/Notion endpoints есть, но не авторизованы в текущей сессии.
- `gh` не найден в PATH; GitHub app connector доступен частично через Codex apps.
- `npx`, `codex`, `php` доступны.
- Playwright CLI daemon требует Chrome channel в `/opt/google/chrome/chrome`; на Rocky Linux стандартный `npx playwright install chrome` не сработал.
- Managed Chromium установлен в Playwright cache и был использован через Node fallback для фактического аудита.

## Playwright-аудит 09.07.2026

Проверены `http://127.0.0.1:8013/`, `/titles`, `/titles/fors-mazorysuits` в desktop `1440x1200` и mobile `390x844`.

Результат:

- Все проверенные страницы вернули HTTP 200.
- Горизонтального overflow не найдено.
- JavaScript page errors не найдены.
- На странице сериала есть внешний media metadata request с `net::ERR_ABORTED`; это внешний видео URL, а не локальный asset.
- На странице `Форс-мажоры/Suits` блок `Советуем посмотреть` показывает пустое состояние: `Похожие сериалы пока не подобраны.`
- Сразу после него показывается fallback `Похожие сериалы` по жанрам/году. Пользователь видит два разных блока, один из которых пустой.
- В локальной SQLite базе pending migrations: `add_score_breakdown_to_catalog_title_recommendations_table` и `create_catalog_title_recommendation_signals_table`.
- В локальной базе нет построенных precomputed recommendations для проверенного тайтла.
- После локального rebuild без лимита обнаружена проблема: builder мог сохранить сотни рекомендаций на один тайтл. Нужен жесткий `max_per_title`.
- В код добавлен лимит `SEASONVAR_RECOMMENDATION_MAX_PER_TITLE=12`. Текущую локальную таблицу нужно пересобрать после завершения уже запущенного `php artisan seasonvar:import`, потому что он держит SQLite lock.

Артефакты:

- `output/playwright/audit-report.json`
- `output/playwright/home-desktop.png`
- `output/playwright/home-mobile.png`
- `output/playwright/titles-desktop.png`
- `output/playwright/titles-mobile.png`
- `output/playwright/title-suits-desktop.png`
- `output/playwright/title-suits-mobile.png`

## Статус на 10.07.2026

Закрыто:

- Старые recommendation migrations применены: `add_score_breakdown_to_catalog_title_recommendations_table` и `create_catalog_title_recommendation_signals_table`.
- Кодовый лимит `SEASONVAR_RECOMMENDATION_MAX_PER_TITLE=12` есть в builder.
- Import pipeline вызывает rebuild рекомендаций в конце существующего `php artisan seasonvar:import`.
- UX пустого блока исправлен: fallback показывается внутри одного блока `Советуем посмотреть`, без отдельного пустого блока перед полезными рекомендациями.
- Focused tests прошли:
  - `php artisan test --filter=CatalogPageTest`
  - `php artisan test --filter=CatalogTitleRecommendationBuilderTest`
  - `php artisan test --filter=SeasonvarCatalogParserTest`
  - `php artisan test --filter=SeasonvarParsePageCommandTest`
- Skill validation прошла для `seasonvar-playwright-qa`, `seasonvar-recommendations`, `seasonvar-skill-authoring`.
- Project MCP по-прежнему безопасный: в `.codex/config.toml` включен только Laravel Boost.

Не закрыто:

- Идет активный процесс `php artisan seasonvar:import`; на момент проверки run `#43` был `running`.
- В `seasonvar_import_runs.summary` пока нет ни одного `last_recommendations`.
- Локальная таблица рекомендаций еще не пересобрана после лимита: `656726` rows, максимум `1087` рекомендаций на один тайтл, `1251` тайтл имеет больше 12 рекомендаций.
- Полная Playwright regression matrix после финального rebuild еще не пройдена.
- Полный `php artisan test` и `npm run build` после финального состояния еще не запускались.
- Есть новая pending migration `2026_07_09_205915_add_feed_query_index_to_catalog_titles_table`; она относится к feed index, а не к recommendations, но должна быть учтена перед финальным широким QA.

Промежуточная работа 10.07.2026:

- Исправлен crash в сборке статистического snapshot: `CatalogStatsPageBuilder::statsIssueRows()` больше не вызывает Eloquent `merge()` для array rows, когда первый issue bucket пустой.
- Добавлен regression test `test_stats_issue_rows_merge_multiple_issue_categories`.
- Проверки прошли:
  - `php artisan test --filter=test_stats_issue_rows_merge_multiple_issue_categories`
  - `php artisan test --filter=CatalogPageTest`
  - `php artisan test --filter=SeasonvarParsePageCommandTest`
  - `php artisan test --filter=SeasonvarImportMaintenanceTest`
  - `./vendor/bin/pint --dirty --format agent`
  - `php artisan test`
  - `npm run build`
- Активный `php artisan seasonvar:import` продолжает идти как длинный backlog run: на 16:37 EEST run `#43` был `running`, `selected=300`, `parsed=300`, `failed=0`, `media_updated=1482`. DB-write шаги плана отложены, чтобы не конкурировать с этим процессом.

## Безлимитный план закрытия

План без лимита: выполнять до прохождения всех критериев ниже, без ограничения по числу итераций, повторных проверок или перезапусков read-only QA. Не запускать destructive команды вроде `migrate:fresh`, `db:wipe`, `queue:clear`, `cache:clear`, `git reset --hard`.

### 1. Дождаться текущего importer cycle

- [ ] Проверить, что `php artisan seasonvar:import` еще работает или завершился:

```bash
ps -ef | rg 'php artisan seasonvar:import|artisan seasonvar:import' || true
```

- [ ] Если процесс завершился, проверить последний run:

```bash
sqlite3 database/database.sqlite <<'SQL'
.headers on
.mode column
SELECT id, status, started_at, finished_at,
       json_extract(summary, '$.last_recommendations.algorithm_version') AS rec_version,
       json_extract(summary, '$.last_recommendations.titles_with_recommendations') AS titles_with_rec,
       json_extract(summary, '$.last_recommendations.stored') AS stored,
       json_extract(summary, '$.last_recommendations.average_recommendations') AS avg_rec,
       json_extract(summary, '$.last_recommendations.max_per_title') AS max_per_title,
       json_extract(summary, '$.last_recommendations.duration_ms') AS duration_ms
FROM seasonvar_import_runs
ORDER BY id DESC
LIMIT 5;
SQL
```

- [ ] Если процесс завис или держит SQLite lock слишком долго, не убивать его молча: сначала зафиксировать `ps`, последний `seasonvar_import_runs` и симптомы lock, потом отдельно решить, можно ли останавливать процесс.

### 2. Довести схему базы до ожидаемого состояния

- [ ] Проверить pending migrations:

```bash
php artisan migrate:status
```

- [ ] Применить pending migrations стандартно, если среда целевая локальная и нет активного SQLite lock:

```bash
php artisan migrate
```

- [ ] Проверить read-only, что recommendation schema на месте:

```bash
sqlite3 database/database.sqlite <<'SQL'
.headers on
.mode column
PRAGMA table_info('catalog_title_recommendation_signals');
PRAGMA table_info('catalog_title_recommendations');
PRAGMA index_list('catalog_title_recommendation_signals');
PRAGMA index_list('catalog_title_recommendations');
SQL
```

### 3. Пересобрать рекомендации только через существующий lifecycle

- [ ] Не добавлять отдельную публичную команду для rebuild рекомендаций.
- [ ] Запустить или дождаться штатного цикла:

```bash
php artisan seasonvar:import --no-discovery
```

- [ ] После завершения проверить `last_recommendations`:

```bash
sqlite3 database/database.sqlite <<'SQL'
.headers on
.mode column
SELECT id, status,
       json_extract(summary, '$.last_recommendations.mode') AS mode,
       json_extract(summary, '$.last_recommendations.algorithm_version') AS algorithm_version,
       json_extract(summary, '$.last_recommendations.titles') AS titles,
       json_extract(summary, '$.last_recommendations.titles_with_recommendations') AS titles_with_recommendations,
       json_extract(summary, '$.last_recommendations.stored') AS stored,
       json_extract(summary, '$.last_recommendations.average_recommendations') AS average_recommendations,
       json_extract(summary, '$.last_recommendations.max_per_title') AS max_per_title,
       json_extract(summary, '$.last_recommendations.duration_ms') AS duration_ms
FROM seasonvar_import_runs
WHERE json_extract(summary, '$.last_recommendations.algorithm_version') IS NOT NULL
ORDER BY id DESC
LIMIT 1;
SQL
```

Критерии:

- `algorithm_version = v2`;
- `titles_with_recommendations > 0`;
- `stored > 0`;
- `average_recommendations <= max_per_title`;
- `max_per_title = 12`, если env не переопределяет `SEASONVAR_RECOMMENDATION_MAX_PER_TITLE`;
- `duration_ms` заполнен и не выглядит зависшим.

### 4. Проверить, что локальная таблица больше не сверхлимитная

- [ ] Проверить агрегаты рекомендаций:

```bash
sqlite3 database/database.sqlite <<'SQL'
.headers on
.mode column
SELECT 'recommendations_total' AS metric, COUNT(*) AS value FROM catalog_title_recommendations;
SELECT algorithm_version, COUNT(*) AS rows FROM catalog_title_recommendations GROUP BY algorithm_version ORDER BY algorithm_version;
SELECT 'titles_with_recommendations' AS metric, COUNT(DISTINCT catalog_title_id) AS value FROM catalog_title_recommendations;
SELECT 'max_recommendations_per_title' AS metric, COALESCE(MAX(c), 0) AS value
FROM (SELECT COUNT(*) AS c FROM catalog_title_recommendations GROUP BY catalog_title_id);
SELECT 'titles_over_12' AS metric, COUNT(*) AS value
FROM (
    SELECT catalog_title_id
    FROM catalog_title_recommendations
    GROUP BY catalog_title_id
    HAVING COUNT(*) > 12
);
SQL
```

Критерии:

- `algorithm_version` содержит `v2`;
- `max_recommendations_per_title <= 12`;
- `titles_over_12 = 0`.

### 5. Проверить качество рекомендаций read-only SQL

- [ ] Запустить инварианты:

```bash
sqlite3 database/database.sqlite <<'SQL'
.headers on
.mode column
SELECT 'self_recommendations' AS metric, COUNT(*) AS value
FROM catalog_title_recommendations
WHERE catalog_title_id = recommended_title_id;
SELECT 'duplicate_pairs' AS metric, COUNT(*) AS value
FROM (
    SELECT catalog_title_id, recommended_title_id
    FROM catalog_title_recommendations
    GROUP BY catalog_title_id, recommended_title_id
    HAVING COUNT(*) > 1
);
SELECT 'without_published_media' AS metric, COUNT(*) AS value
FROM catalog_title_recommendations r
WHERE NOT EXISTS (
    SELECT 1
    FROM licensed_media lm
    WHERE lm.catalog_title_id = r.recommended_title_id
      AND lm.status = 'published'
);
SELECT 'score_mismatches' AS metric, COUNT(*) AS value
FROM catalog_title_recommendations
WHERE score <> metadata_score + source_score + quality_score;
SELECT 'empty_reasons' AS metric, COUNT(*) AS value
FROM catalog_title_recommendations
WHERE reasons IS NULL OR reasons = '' OR reasons = '{}' OR reasons = '[]';
SQL
```

Критерии:

- все пять метрик равны `0`.

- [ ] Выбрать 10 контрольных тайтлов и сохранить список slug в заметке к QA:
  - популярный длинный сериал;
  - короткий сериал;
  - сериал с несколькими жанрами;
  - сериал без видео;
  - сериал с переводами;
  - сериал с рейтингом;
  - сериал без рейтинга;
  - аниме;
  - документальный;
  - свежий тайтл.

### 6. Проверить importer signals

- [ ] Проверить распределение signals:

```bash
sqlite3 database/database.sqlite <<'SQL'
.headers on
.mode column
SELECT source, signal_type, COUNT(*) AS rows, MIN(weight) AS min_weight, MAX(weight) AS max_weight
FROM catalog_title_recommendation_signals
GROUP BY source, signal_type
ORDER BY rows DESC, source, signal_type;
SQL
```

- [ ] Проверить, что есть ожидаемые типы signals: `taxonomy_genre`, `taxonomy_tag`, `taxonomy_director`, `taxonomy_actor`, `taxonomy_studio` или `taxonomy_network`, `taxonomy_translation`, `taxonomy_status`, `taxonomy_country`, `taxonomy_age_rating`, `rating`, `release_year`, `page_quality`.
- [ ] Проверить stale cleanup через повторный import одного измененного source page: старые rows того же `catalog_title_id + source` должны удаляться, новые должны upsert-иться.
- [ ] Проверить, что `signal_value` не содержит raw HTML и длинные source payloads:

```bash
sqlite3 database/database.sqlite <<'SQL'
.headers on
.mode column
SELECT COUNT(*) AS suspicious_signal_values
FROM catalog_title_recommendation_signals
WHERE signal_value LIKE '%<html%'
   OR signal_value LIKE '%<script%'
   OR LENGTH(signal_value) > 255;
SQL
```

Критерий: `suspicious_signal_values = 0`.

### 7. Прогнать Playwright regression matrix

- [ ] Использовать managed Chromium/Node fallback, если Playwright MCP daemon снова требует `/opt/google/chrome/chrome`.
- [ ] Проверить desktop и mobile:
  - `/`
  - `/titles`
  - `/titles?q=<query>`
  - `/titles/{slug}` с precomputed recommendations
  - `/titles/{slug}` без precomputed recommendations
  - `/feed.xml`
- [ ] Для каждой страницы собрать status, `h1`, panel headings, overflow, console/page errors, failed local assets, screenshots.
- [ ] Внешние video URL failures учитывать отдельно от local asset failures.
- [ ] Для тайтла с precomputed recommendations проверить реальные карточки и reason badges: `Жанр`, `Год`, `Источник`, `Видео`, если эти причины есть в `reasons`.
- [ ] Для тайтла без precomputed recommendations проверить fallback внутри одного блока `Советуем посмотреть`: нет отдельного `Похожие сериалы` и нет пустого текста перед полезным fallback.
- [ ] Сохранить артефакты в `output/playwright/`, не создавать новый top-level каталог.

### 8. Финальные проверки

- [x] `python3 /root/.codex/skills/.system/skill-creator/scripts/quick_validate.py .agents/skills/seasonvar-playwright-qa`
- [x] `python3 /root/.codex/skills/.system/skill-creator/scripts/quick_validate.py .agents/skills/seasonvar-recommendations`
- [x] `python3 /root/.codex/skills/.system/skill-creator/scripts/quick_validate.py .agents/skills/seasonvar-skill-authoring`
- [x] `./vendor/bin/pint --dirty --format agent`
- [x] `php artisan test --filter=CatalogPageTest`
- [x] `php artisan test --filter=CatalogTitleRecommendationBuilderTest`
- [x] `php artisan test --filter=SeasonvarCatalogParserTest`
- [x] `php artisan test --filter=SeasonvarParsePageCommandTest`
- [x] `php artisan test`
- [x] `npm run build`

### 9. Skills и MCP остаются без расширения project config

- [x] Новые project skills подключены:
  - `seasonvar-playwright-qa`
  - `seasonvar-recommendations`
  - `seasonvar-skill-authoring`
- [x] Не добавлять Playwright/Google/GitHub remote MCP в проектный `.codex/config.toml`, пока для них нужны user-level credentials или OS-specific browser setup.
- [ ] Для Playwright MCP можно держать только user-level пример, не project config:

```toml
[mcp_servers.playwright]
command = "npx"
args = ["@playwright/mcp@latest"]
required = false
default_tools_approval_mode = "prompt"
```
