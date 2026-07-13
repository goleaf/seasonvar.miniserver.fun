# Project Playwright MCP Integration Design

## Goal

Подключить официальный `microsoft/playwright-mcp` к Codex-конфигурации Seasonvar так, чтобы браузерная автоматизация была доступна из trusted project без персональных токенов, persistent cookies и графического окружения.

## Chosen approach

Playwright MCP регистрируется в `.codex/config.toml` как необязательный project-scoped stdio server. Codex запускает официальный npm-пакет через `npx`; сервер использует управляемый Chromium в headless-режиме и изолированный in-memory профиль для каждой сессии.

Проект не добавляет npm dependency и не хранит browser profile, storage state или secrets. `npx` загружает пакет в пользовательский npm cache, а совместимый Chromium устанавливается в пользовательский Playwright cache отдельной документированной командой.

## Configuration

Конфигурация запуска содержит:

- `npx -y @playwright/mcp@latest` — официальный рекомендуемый пакет без интерактивного npm prompt;
- `--headless` — работа на сервере без display server;
- `--isolated` — отсутствие persistent cookies и конфликтов общего профиля;
- `--browser chromium` — использование управляемого Playwright Chromium вместо системного Chrome;
- `default_tools_approval_mode = "prompt"` — подтверждение browser write/action tools средствами Codex;
- `required = false` — отсутствие npm/browser не блокирует запуск Codex и Laravel Boost.

Доступ к файлам вне workspace не включается. OAuth, cookies, storage state и секреты в репозиторий не добавляются.

## Documentation

`docs/mcp.md` становится основной инструкцией запуска и диагностики. `docs/integrations/mcp-catalog.md` отражает активный project status. `.codex/mcp.example.toml` перестаёт предлагать Playwright как неактивный user-level шаблон и вместо этого ссылается на активную конфигурацию.

`CHANGELOG.md` фиксирует подключение в единственном журнале изменений проекта.

## Failure handling

Если `npx`, npm registry или совместимый Chromium недоступны, Playwright MCP остаётся необязательным и не ломает другие MCP. Документация содержит отдельные команды для проверки Node/npx, установки browser binary и чтения зарегистрированного списка через `codex mcp list`.

## Verification

Интеграция считается готовой, когда:

1. `npx -y @playwright/mcp@latest --version` завершается успешно.
2. Совместимый Chromium установлен и запускается headless.
3. `codex mcp list` показывает project server `playwright` как enabled.
4. Реальный MCP stdio handshake возвращает список browser tools, а навигационный smoke test открывает локальную страницу.
5. `php artisan integrations:doctor --strict` и `php artisan project:docs-refresh --check` проходят без ошибок.
6. Git остаётся на `main`, а все изменения закоммичены.
