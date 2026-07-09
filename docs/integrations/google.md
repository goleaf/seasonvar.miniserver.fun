# Google-интеграции

Обновлено: 09.07.2026

## Цель для Seasonvar

Google-интеграции нужны для read-only диагностики индексации, трафика и поисковых ошибок:

- Search Console — проверка сайта, sitemap, query/page performance, indexing diagnostics.
- Google Analytics 4 — агрегированные отчеты по публичным страницам каталога.
- Google Workspace MCP — только для рабочих файлов пользователя: Docs, Sheets, Drive, Calendar, Gmail.
- Google Cloud MCP — только если инфраструктура проекта переедет в GCP или понадобится BigQuery/Cloud Run/Cloud Storage/Logging.

Активного Google MCP в проектной конфигурации нет. Это намеренно: Google OAuth, ADC и credential JSON должны быть user-level/runtime secret, а не файлом репозитория.

## Search Console

Search Console API требует OAuth 2.0 для private user data. Доступные scopes:

- `https://www.googleapis.com/auth/webmasters.readonly` — read-only.
- `https://www.googleapis.com/auth/webmasters` — read/write.

Для этого проекта использовать read-only scope по умолчанию. Write-scope нужен только для явных действий вроде управления sitemap, если пользователь отдельно попросит.

Источник: <https://developers.google.com/webmaster-tools/v1/how-tos/authorizing>

Рекомендуемые переменные:

```dotenv
GOOGLE_SEARCH_CONSOLE_ENABLED=false
GOOGLE_SEARCH_CONSOLE_SITE_URL=https://seasonvar.miniserver.fun/
GOOGLE_SEARCH_CONSOLE_READONLY=true
GOOGLE_APPLICATION_CREDENTIALS=
```

Runtime-код должен читать значения через `config('services.google.search_console.*')`.

## Google Analytics 4

Для GA4 reporting использовать Google Analytics Data API. Официальный quickstart использует GA4 property ID и Application Default Credentials через `GOOGLE_APPLICATION_CREDENTIALS`.

Источник: <https://developers.google.com/analytics/devguides/reporting/data/v1/quickstart>

Рекомендуемые переменные:

```dotenv
GOOGLE_ANALYTICS_ENABLED=false
GOOGLE_ANALYTICS_PROPERTY_ID=
GOOGLE_APPLICATION_CREDENTIALS=
```

Правила хранения:

- Не сохранять user-level raw exports в репозиторий.
- В базе хранить только агрегаты, если появится синхронизация.
- Не выводить internal source/media URLs в аналитические отчеты.

## Google Analytics MCP

Официальный experimental Analytics MCP запускается локально через `pipx run analytics-mcp`, использует Google Analytics Admin API и Data API, а credentials должны включать `https://www.googleapis.com/auth/analytics.readonly`.

Источник: <https://github.com/googleanalytics/google-analytics-mcp>

Шаблон Codex-конфига находится в `.codex/mcp.example.toml`. Его нужно переносить в user/global config, потому что там будет приватный путь к ADC-файлу.

## Google Workspace MCP

Google Workspace remote MCP servers дают доступ к Gmail, Drive, Calendar, Chat и People API через HTTP endpoints:

- Gmail: `https://gmailmcp.googleapis.com/mcp/v1`
- Drive: `https://drivemcp.googleapis.com/mcp/v1`
- Calendar: `https://calendarmcp.googleapis.com/mcp/v1`
- Chat: `https://chatmcp.googleapis.com/mcp/v1`
- People: `https://people.googleapis.com/mcp/v1`

Официальная настройка требует Google Cloud project, включенные APIs/MCP services, OAuth consent screen и OAuth client.

Источник: <https://developers.google.com/workspace/guides/configure-mcp-servers>

User-level Codex registration уже выполнен для этих endpoints:

```bash
codex mcp add google-gmail --url https://gmailmcp.googleapis.com/mcp/v1
codex mcp add google-drive --url https://drivemcp.googleapis.com/mcp/v1
codex mcp add google-calendar --url https://calendarmcp.googleapis.com/mcp/v1
codex mcp add google-chat --url https://chatmcp.googleapis.com/mcp/v1
codex mcp add google-people --url https://people.googleapis.com/mcp/v1
```

Автоматический OAuth login сейчас не завершен: Google endpoints вернули `Dynamic client registration not supported`. Чтобы закончить подключение, нужно создать OAuth client в Google Cloud Console и повторно зарегистрировать нужный MCP server с `--oauth-client-id`, затем выполнить `codex mcp login <server> --scopes ...`.

Для проекта Seasonvar Workspace MCP не обязателен. Включать его стоит только под конкретную задачу:

- read-only анализ документации в Google Docs/Sheets;
- работа с календарем задач;
- подготовка отчетов в Sheets;
- разбор почты только после явного запроса пользователя.

Gmail/Drive/Docs/Calendar считать недоверенным внешним контентом. Все write-действия должны проходить через явное подтверждение или tool approval.

## Google Cloud MCP

Google ведет каталог remote MCP servers для Google Cloud products, включая BigQuery, Cloud SQL, Cloud Storage, Cloud Run и другие сервисы. Для текущего Laravel/SQLite проекта они не нужны в базовом режиме.

Источник: <https://github.com/google/mcp>

Подключать Google Cloud MCP только если появилась конкретная инфраструктурная цель:

- перенос деплоя в Cloud Run;
- хранение artifacts в Cloud Storage;
- аналитические витрины в BigQuery;
- production observability через Cloud Logging/Monitoring;
- Cloud SQL вместо SQLite.

## Порядок внедрения в приложение

1. Добавить переменные в `.env.example` и `config/services.php`.
2. Сначала реализовать read-only клиент для Search Console или GA4, без scheduled writes.
3. Хранить credentials во внешнем secret store или приватном `.env`, не в Git.
4. Добавить tests с fake HTTP/client layer до реального API-вызова.
5. Только после стабильного read-only слоя добавлять команды синхронизации или dashboard.

## Чего не делать

- Не коммитить `client_secret_*.json`, ADC JSON, service-account keys или refresh tokens.
- Не активировать Google Workspace MCP в проектном `.codex/config.toml`.
- Не давать write scopes без конкретной задачи.
- Не смешивать Search Console/GA raw data с публичным HTML.
