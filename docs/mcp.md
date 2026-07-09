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

## Когда использовать

- `application_info` — в начале Laravel-задач, чтобы подтвердить версии PHP, Laravel и пакетов.
- `search_docs` — перед версионно-зависимыми изменениями Laravel, Livewire, Tailwind, Vite или Laravel-пакетов.
- `database_connections` и read-only `database_query` — только когда нужна проверка схемы или данных.
- `read_log_entries` и `last_error` — при отладке backend-ошибок.
- `browser_logs` — только при проверке браузерного поведения.

## Ограничения

Другие MCP-серверы не настроены и не требуются для обычной разработки этого проекта. GitHub MCP нужен только для issue, PR, commit или repository metadata; browser/Playwright MCP — только для UI-проверок; database MCP — только для read-only проверки схемы или данных, если Laravel Boost недостаточно.

Если MCP не запускается, сначала проверьте, что зависимости установлены через `composer install`, а команда `php artisan boost:mcp --env=local` выполняется из корня проекта.
