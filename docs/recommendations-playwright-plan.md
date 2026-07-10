# План Playwright QA и рекомендаций

Обновлено: 09.07.2026

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

## План изменений

### 1. Довести базу до новой схемы

- Применить pending migrations в целевой среде стандартным `php artisan migrate`.
- Не запускать destructive команды вроде `migrate:fresh`.
- Проверить read-only:
  - таблица `catalog_title_recommendation_signals` существует;
  - новые поля score breakdown есть в `catalog_title_recommendations`;
  - индексы созданы.

### 2. Построить рекомендации через существующий importer lifecycle

- Не добавлять отдельную публичную команду.
- Использовать текущий конец `php artisan seasonvar:import`: parse pages, media refresh, relation cleanup, title merge, recommendation rebuild.
- Хранить только top-N рекомендаций на тайтл. Базовый лимит: `SEASONVAR_RECOMMENDATION_MAX_PER_TITLE=12`.
- После первого цикла проверить `seasonvar_import_runs.summary.last_recommendations`:
  - `algorithm_version = v2`;
  - `titles_with_recommendations > 0`;
  - `stored > 0`;
  - `average_recommendations <= max_per_title`;
  - `duration_ms` разумный.

### 3. Исправить UX пустого блока

- Если precomputed recommendations пустые, не показывать пустой `Советуем посмотреть` перед fallback.
- Лучше объединить fallback в тот же пользовательский блок `Советуем посмотреть`, но с менее сильной подписью вроде `По похожим жанрам` или `За тот же год`.
- Если нет ни precomputed, ни fallback, показывать один короткий empty state или скрывать блок, чтобы не обещать рекомендации без результата.
- Обновить тесты `CatalogPageTest`: title page без precomputed recommendations не должен показывать пустой блок перед полезным fallback.

### 4. Проверить качество рекомендаций

- Выбрать 10 контрольных сериалов: популярный длинный сериал, короткий сериал, сериал с несколькими жанрами, сериал без видео, сериал с переводами, сериал с рейтингом, сериал без рейтинга, аниме, документальный, свежий тайтл.
- Для каждого read-only SQL проверить:
  - нет рекомендации самого себя;
  - есть опубликованное видео у candidate;
  - reasons не пустые;
  - `score = metadata_score + source_score + quality_score`;
  - `algorithm_version = v2`;
  - нет дублей candidate в одном title.
- В Playwright проверить, что в `Советуем посмотреть` есть реальные карточки и reason badges: `Жанр`, `Год`, `Источник`, `Видео` по ситуации.

### 5. Проверить importer signals

- После import cycle проверить, что signals сохраняются и stale signals удаляются по `catalog_title_id + source`.
- Проверить веса source signals: genre, tag, director, actor, studio/network, translation/status, country, age rating, rating, release year, page quality.
- Проверить, что importer не хранит raw source HTML в signals и не создает отдельные тайтлы для сезонов.

### 6. Playwright regression matrix

- Desktop/mobile:
  - `/`
  - `/titles`
  - `/titles?q=<query>`
  - `/titles/{slug}` с precomputed recommendations
  - `/titles/{slug}` без precomputed recommendations
  - `/feed.xml`
- Собрать для каждой страницы: status, `h1`, panel headings, overflow, console/page errors, failed local assets, screenshots.
- Отдельно учитывать внешние video URL failures, чтобы не смешивать их с локальными asset regressions.

### 7. Skills и MCP

- Новые project skills подключены:
  - `seasonvar-playwright-qa`
  - `seasonvar-recommendations`
  - `seasonvar-skill-authoring`
- Не добавлять Playwright/Google/GitHub remote MCP в проектный `.codex/config.toml`, пока для них нужны user-level credentials или OS-specific browser setup.
- Для Playwright MCP можно держать user-level пример:

```toml
[mcp_servers.playwright]
command = "npx"
args = ["@playwright/mcp@latest"]
required = false
default_tools_approval_mode = "prompt"
```

### 8. Проверки перед завершением

- `python3 /root/.codex/skills/.system/skill-creator/scripts/quick_validate.py .agents/skills/<skill>`
- `./vendor/bin/pint --dirty --format agent`
- `php artisan test --filter=CatalogPageTest`
- `php artisan test --filter=CatalogTitleRecommendationBuilderTest`
- `php artisan test --filter=SeasonvarCatalogParserTest`
- `php artisan test --filter=SeasonvarParsePageCommandTest`
- `php artisan test`
- `npm run build`
