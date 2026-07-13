# Environment preflight

Проверено: 13.07.2026. Команды выполнялись из `/www/wwwroot/seasonvar.miniserver.fun`; secrets и значения `.env` не выводились.

## Версии и совместимость

| Проверка | Результат |
| --- | --- |
| `pwd` | корректный единственный Laravel root |
| `php -v`; `php -m` | PHP 8.5.8; требуемые PDO/SQLite, mbstring, DOM/XML, curl, fileinfo, Redis и Memcached доступны |
| `composer --version` | 2.10.2 |
| `node --version`; `npm --version`; `npx --version` | 26.4.0 / 12.0.0 / 12.0.0 |
| `codex --version` | 0.144.3 |
| `php artisan --version`; `php artisan about` | Laravel 13.19.0 загружается; production/debug off; locale `ru` |
| `composer show --direct` | lock установлен; Laravel Boost 2.4.12, Laravel MCP 0.8.2, Livewire 4.3.3, PHPUnit 12.5.31 |
| `composer validate`; `composer check-platform-reqs` | pass; Composer отдельно предупреждает, что root/plugins отключены политикой текущего CLI |
| `npm ls --depth=0` | pass; Tailwind 4.3.2, Vite 8.1.4, Plyr 3.8.4, hls.js 1.6.15 |
| `composer outdated --direct` | только PHPUnit 13 major; не обновлялся |
| `npm outdated` | ожидаемый exit 1 из-за доступных `concurrently` major и `laravel-vite-plugin` patch; это не install/build failure |
| `composer audit`; `npm audit --audit-level=high` | advisories не найдены |

PHP удовлетворяет `^8.3`, Laravel 13 и PHPUnit 12. Node совместим с lock/Vite: deterministic install уже присутствует, production build проходит. Flux, Pest и static-analysis package не установлены и не объявлены как доступные проверки.

## Runtime и данные

- Первый `php artisan migrate:status` read-only показал pending cleanup/availability migrations. Во время общей repository работы deployment workflow применил их и остальные существовавшие migrations. Позднее параллельный commit добавил relation source identity migration `171455`; финальный status для неё `Pending`, остальные migrations — `Ran`. Временная SQLite до этого прошла full migrate, rollback последних семи и повторный migrate; новая migration отдельно покрыта своим feature test, но live DB автономно не изменялась.
- `PRAGMA quick_check` вернул `ok`; `PRAGMA foreign_key_check` — 0 нарушений. Обнаружено 53 таблицы.
- Финальный `php artisan app:health --json`: status/readiness `ok/true`, DB, Redis cache/session/queue/locks, Memcached и queue worker `ok`; cache-warm state остаётся `unknown`.
- `php artisan seasonvar:import --status`: очереди пусты, активных runs нет, последний run завершён без errors. Команда была только read-only status.
- `php artisan about` подтвердил, что public storage link сейчас отсутствует; это не мешает внешней media delivery.

## Baseline build и tests

- `npm run build` выполнялся до изменений и подтвердил разрешение Vite/Tailwind/local player assets.
- Первый `php artisan test` обнаружил все 560 tests; 3 совместимых с runtime assertions были исправлены, valid tests не удалялись и не ослаблялись.
- Текущие focused suites для config/assets/MCP/CSP: 24 passed, 214 assertions.

Полный финальный прогон и точные итоговые counts ведутся в `docs/audits/verification-report.md`.
