# Следующая Codex-сессия

Перезапустите Codex из `/www/wwwroot/seasonvar.miniserver.fun`, затем выполните:

1. `codex mcp list` — должны быть project servers `laravel-boost`, `context7`, `playwright`.
2. Через Boost запросите application info и routes.
3. Через Context7 resolve/query проверьте одну текущую библиотеку.
4. Через Playwright откройте `/titles` в headless isolated Chromium и проверьте console/network.

Не включайте GitHub MCP и не переносите secrets/cookies в project config. Repository audit и implementation уже завершены; этот файл нужен только для подтверждения hot-loaded tool inventory.
