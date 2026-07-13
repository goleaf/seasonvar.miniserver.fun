# MCP setup

Проверено: 13.07.2026. В project config GitHub MCP не устанавливался и не настраивался; pre-existing user-level entry появился в read-only `codex mcp list`, но не вызывался и не изменялся. GitHub API не вызывался.

| Server | Transport / command | cwd и timeouts | Verification | Current session |
| --- | --- | --- | --- | --- |
| `laravel-boost` | stdio, `php artisan boost:mcp --env=local`; child env `APP_ENV=local` | absolute project cwd; startup 30 s, tool 120 s | MCP initialize/tools-list и application-info прошли после env fix; `boost:mcp` ожидаемо ждёт stdin | встроенный до правки inventory устарел; новая session required |
| `context7` | stdio, `npx -y @upstash/context7-mcp` | project cwd; 40/120 s | protocol 2025-06-18, resolve/query tools; hls.js docs query прошёл | newly configured; restart required |
| `playwright` | stdio, `npx -y @playwright/mcp@latest --headless --isolated --browser chromium` | project cwd; 60/120 s; artifacts `output/playwright` | protocol 2025-06-18, 24 tools; managed Chromium and real public desktop/mobile flow passed | project config existed, but this thread used verified CLI fallback; restart exposes MCP tools |

Все servers `required=false`: documentation/browser package startup не блокирует Codex. API keys, cookies, persistent profiles и credentials в tracked files отсутствуют. `default_tools_approval_mode` применяется только там, где текущий Codex config его поддерживает.

## Ошибки и исправления

1. `php artisan boost:mcp --env=local` запускал parent в local, но дочерние Artisan processes не наследовали option и видели production. Добавлен `env = { APP_ENV = "local" }`; исходная application-info проверка повторена успешно.
2. Context7 отсутствовал. Добавлен official npx server без API key; anonymous mode работает с меньшими лимитами. Rate-limit loop не выполнялся.
3. Playwright CLI по умолчанию искал branded Chrome. Установленный MCP использует managed Chromium; CLI smoke получил тот же executable через ignored temporary config. Browser artifacts удалены после проверки.
4. Текущий Codex process не hot-load-ит новые tables. Repository work продолжен через protocol handshakes, official docs и Playwright CLI; новая session должна перечитать config.
5. Повторная загрузка official Codex manual helper завершилась ошибкой `Manual response is missing x-content-sha256`. Команда не запускалась повторно без изменения условий; проектный MCP status подтверждён через `codex mcp list`, strict integration doctor и уже выполненные protocol handshakes.

## Проверки

```bash
codex mcp list
php artisan integrations:doctor --strict --json
APP_ENV=local php artisan boost:mcp --env=local </dev/null
```

Последний strict doctor: required missing `0`; optional Google/user connectors остаются предупреждениями и не относятся к этому stack. Прямой Boost stdio process корректно завершился с exit `0`, получив EOF вместо MCP client input.
