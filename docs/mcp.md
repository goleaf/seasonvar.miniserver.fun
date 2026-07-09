# MCP

Обновлено: 09.07.2026

## Доступные настройки

Проект использует Laravel Boost MCP. Конфигурация включена в `boost.json`, а локальный запуск для Codex описан в `.codex/config.toml`:

```toml
[mcp_servers.laravel-boost]
command = "php"
args = ["artisan", "boost:mcp", "--env=local"]
```

Флаг `--env=local` обязателен: Boost регистрирует MCP-команды только в local/debug окружении.

Дополнительные MCP и коннекторы описаны отдельно:

- `docs/integrations/mcp-catalog.md` — границы проектной и user-level MCP-конфигурации, список доступных/рекомендуемых коннекторов.
- `docs/integrations/google.md` — Search Console, Google Analytics, Google Workspace MCP и Google Cloud MCP.
- `.codex/mcp.example.toml` — копируемые шаблоны MCP, которые нельзя включать целиком в проектный config.

## Когда использовать

- `application_info` — в начале Laravel-задач, чтобы подтвердить версии PHP, Laravel и пакетов.
- `search_docs` — перед версионно-зависимыми изменениями Laravel, Livewire, Tailwind, Vite или Laravel-пакетов.
- `database_connections` и read-only `database_query` — только когда нужна проверка схемы или данных.
- `read_log_entries` и `last_error` — при отладке backend-ошибок.
- `browser_logs` — только при проверке браузерного поведения.

## Ограничения

Другие MCP-серверы не активированы в проектном `.codex/config.toml`, потому что большинство из них требуют личные OAuth-токены, credential JSON, cloud project или workspace authorization. Такие подключения должны жить в user/global Codex config или в подключенном app connector, а не в Git.

GitHub MCP/app connector нужен только для issue, PR, commit или repository metadata; browser/Playwright MCP — только для UI-проверок; database MCP — только для read-only проверки схемы или данных, если Laravel Boost недостаточно; Google MCP — только для конкретных Search Console, Analytics, Workspace или GCP задач.

Если MCP не запускается, сначала проверьте, что зависимости установлены через `composer install`, а команда `php artisan boost:mcp --env=local` выполняется из корня проекта.

## Диагностика

```bash
php artisan integrations:doctor
php artisan integrations:doctor --json
```

Команда проверяет проектный Laravel Boost MCP, `.codex/mcp.example.toml`, список skills в `boost.json`, user-level OpenAI docs/Google Workspace MCP registration, Google Search Console/Analytics config и наличие CLI-инструментов `codex`, `gh`, `gcloud`, `pipx`. Команда не делает OAuth login, не вызывает Google API и не выводит секреты.

## Проектные skills

`boost.json` перечисляет локальные skills, которые должны помогать Codex выбирать правильный workflow:

- `laravel-best-practices`
- `tailwindcss-development`
- `seasonvar-importer`
- `seasonvar-ui`
- `seasonvar-seo`
- `seasonvar-mcp-ops`

Новые Seasonvar skills лежат в `.agents/skills` и не содержат секретов или исполняемых интеграций. Они задают правила для импорта, интерфейса, SEO и MCP-операций.
