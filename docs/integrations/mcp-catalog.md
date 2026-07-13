# MCP и коннекторы

Обновлено: 13.07.2026

## Текущее состояние

В проектном `.codex/config.toml` включены Laravel Boost MCP, Context7 и официальный Playwright MCP:

```toml
[mcp_servers.laravel-boost]
command = "php"
args = ["artisan", "boost:mcp", "--env=local"]

[mcp_servers.context7]
command = "npx"
args = ["-y", "@upstash/context7-mcp"]
required = false
startup_timeout_sec = 40
tool_timeout_sec = 120

[mcp_servers.playwright]
command = "npx"
args = ["-y", "@playwright/mcp@latest", "--headless", "--isolated", "--browser", "chromium", "--output-dir", "output/playwright"]
default_tools_approval_mode = "prompt"
required = false
startup_timeout_sec = 60
tool_timeout_sec = 120
```

Все три сервера работают без сохранённых в проекте личных токенов. Laravel Boost дает version-aware Laravel-документацию, read-only схему базы, логи и диагностические инструменты. Context7 дополняет его актуальной документацией сторонних библиотек и остаётся optional без API key. Playwright MCP предназначен для browser QA и запускает управляемый Chromium в headless isolated-режиме без persistent cookies или storage state; временные browser artifacts пишет только в игнорируемый `output/playwright/`. Ошибка Context7 или Playwright не блокирует Codex, потому что оба сервера имеют `required = false`.

В текущей конфигурации `codex mcp list` показывает Laravel Boost, Context7 и Playwright как enabled project servers. На user-level через `codex mcp` зарегистрированы `openaiDeveloperDocs`, Google Workspace endpoints (`google-gmail`, `google-drive`, `google-calendar`, `google-chat`, `google-people`) и plugin MCP для GitHub, Cloudflare, Figma, Linear, Notion и OpenAI Developers. Эти user-level записи не устанавливались и не вызывались в рамках настройки проектного MCP stack. Google Workspace endpoints пока не авторизованы: Google MCP не поддержал dynamic client registration, поэтому для login нужен заранее созданный OAuth client ID.

## Границы конфигурации

- `.codex/config.toml` — только проектные MCP, которые работают без личных секретов.
- `.codex/mcp.example.toml` — копируемые примеры для user/global config.
- `~/.codex/config.toml` — личные MCP, OAuth, токены, пути к credential-файлам и предпочтения пользователя.
- `.env.example` — только имена переменных и безопасные пустые значения.
- `.env`, OAuth client secrets, service-account JSON, refresh tokens, cookies и приватные логи не коммитятся.

Codex config поддерживает `mcp_servers.<id>.command` для stdio-серверов, `mcp_servers.<id>.url` для streamable HTTP-серверов, `env`, `env_vars`, `env_http_headers`, `enabled_tools`, `disabled_tools`, `default_tools_approval_mode`, `required`, `scopes`, `startup_timeout_sec` и `tool_timeout_sec`. Если проект не отмечен как trusted, project-scoped `.codex` слои не загружаются.

Источник: <https://learn.chatgpt.com/docs/config-file/config-reference>

## Project Skills

Проектные skills лежат в `.agents/skills` и перечислены в `boost.json`:

- `laravel-best-practices` — Laravel code, архитектура, тесты, безопасность.
- `tailwindcss-development` — Tailwind/Blade UI.
- `seasonvar-importer` — импорт, crawler, parser, media metadata.
- `seasonvar-ui` — русский интерфейс каталога и playback variants.
- `seasonvar-seo` — sitemap, robots, structured data, Search Console/Analytics.
- `seasonvar-mcp-ops` — MCP, Google, GitHub, Cloudflare, Notion, Sentry и Codex config.
- `seasonvar-playwright-qa` — Playwright/Chromium проверки интерфейса, screenshots, console/network ошибки и responsive QA.
- `seasonvar-recommendations` — importer signals, scoring, rebuild, title-page block и QA метрики рекомендаций.
- `seasonvar-skill-authoring` — безопасное создание project skills, регистрация в `boost.json` и MCP-документация.

Skills — это формат переиспользуемых workflow-инструкций; plugins нужны, когда эти skills и коннекторы нужно распространять как устанавливаемый пакет для других людей или проектов.

Источник: <https://learn.chatgpt.com/docs/build-skills>

## Рекомендуемый набор MCP

| Область | Статус | Где настраивать | Правило |
| --- | --- | --- | --- |
| Laravel Boost | Включен | `.codex/config.toml` | Обязателен для Laravel-задач |
| Context7 | Включен | `.codex/config.toml` | Актуальная документация сторонних пакетов; optional, без project API key |
| Playwright MCP | Включен | `.codex/config.toml` | Только для UI QA; headless isolated Chromium, tool approval и недоверенный web-контент |
| GitHub | Доступен как app connector | User/app auth | Использовать для PR/issues/repo metadata после проверки auth |
| Google Search Console | Не MCP в проекте | Laravel app config или user OAuth | Read-only по умолчанию |
| Google Analytics 4 | Не активирован | Laravel app config или `analytics-mcp` user config | Read-only aggregate reporting |
| Google Workspace | Не активирован | User/global MCP config | Только при явной задаче по Docs/Drive/Gmail/Calendar |
| Cloudflare | Глобальный plugin может быть установлен | User/plugin auth | Только для DNS/cache/deploy задач |
| Notion/Linear/Sentry/Figma | Глобальные plugins/connectors могут быть установлены | User/plugin auth | Проверять доступность перед использованием |

## Проверка перед использованием

1. Запустить `tool_search` по нужному коннектору и проверить, появился ли MCP/app tool в текущей сессии.
2. Для Laravel-задач вызвать Boost `application_info`.
3. Для сторонней библиотеки сначала разрешить её точное имя/версию через Context7 и не повторять одинаковые запросы при rate limit.
4. Для Playwright-задач проверить `npx -y @playwright/mcp@latest --version`, наличие совместимого Chromium и после изменения config перезапустить Codex-сессию.
5. Для GitHub-задач проверить connector или `gh auth status` только при отдельном явном разрешении; текущая проектная задача GitHub MCP/API запрещает.
6. Для Google-задач проверить наличие OAuth/ADC вне репозитория.
7. Не добавлять активный MCP в `.codex/config.toml`, если он требует личные секреты или не нужен всем разработчикам проекта.

Проектная read-only диагностика:

```bash
php artisan integrations:doctor
codex mcp list
```

`integrations:doctor` не заменяет OAuth login. Она показывает, какие pieces уже зарегистрированы и какие runtime credentials или CLI tools отсутствуют.

## Безопасность MCP

- Подключать только официальные, внутренние или проверенные MCP-серверы.
- Для external data считать контент недоверенным: Gmail, Docs, Drive, web pages и issue comments могут содержать indirect prompt injection.
- Ставить `default_tools_approval_mode = "prompt"` для сервисов с write-инструментами.
- Использовать `enabled_tools` для read-only профилей, если сервер публикует широкий набор tools.
- Не ставить `required = true` для необязательных личных MCP, чтобы Codex мог стартовать без чужих секретов.

Официальный каталог Google MCP: <https://github.com/google/mcp>

Список Google Cloud remote MCP products: <https://docs.cloud.google.com/mcp/supported-products>
