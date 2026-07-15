# Обновление Laravel и runtime

Обновлено: 15.07.2026. Детальный package inventory и решения находятся в [`audits/dependency-report.md`](audits/dependency-report.md), исполняемый порядок — в [`plans/laravel-video-portal-modernization.md`](plans/laravel-video-portal-modernization.md). Этот файл — короткий owner-контракт controlled upgrades.

## Текущее состояние

- PHP 8.5.8 — актуальный stable PHP 8.5 patch.
- Laravel Framework 13.19.0; доступен совместимый 13.20.0 patch.
- Livewire 4.3.3, Laravel Boost 2.4.12, Pint 1.29.3, Larastan 3.10.0, PHPUnit 12.5.31.
- Tailwind CSS 4.3.2, Vite 8.1.4, Laravel Vite plugin 3.1.0; для plugin доступен 3.1.3 patch.
- Node 26.4.0; официальный current — 26.5.0. npm 12.0.1 и Composer 2.10.2 актуальны.

Laravel 13 требует PHP >=8.3. Vite 8 требует Node 20.19+ или 22.12+; текущий Node совместим. PHPUnit 13 — отдельный major и не обновляется в рамках Laravel 13 modernization: существующие 826 PHPUnit tests и Laravel upgrade guidance используют PHPUnit 12.

## Обязательный процесс

Перед update:

1. Зафиксировать installed/latest versions и hashes обоих lockfiles.
2. Прочитать официальные upgrade/release notes и найти затронутые API в репозитории.
3. Записать rollback: восстановление предыдущего фазового commit/lockfiles, `composer install`, `npm ci`, cache rebuild.
4. Не смешивать Laravel patch, frontend patch и system Node в один change set.

После каждого update:

```bash
composer validate --strict
composer audit
composer check-platform-reqs
./vendor/bin/pint --test --format agent
./vendor/bin/phpstan analyse --no-progress --memory-limit=1G
php artisan test
npm ci
npm audit --audit-level=high
npm run build
npm run test:browser
```

Laravel 13.20.0 release добавляет/fixes QueueFake hooks, worker memory event data, session prefix support, HTTP header normalization, sensitive parameters and several Eloquent/Storage helpers. Перед update выполняется targeted search по этим API; новые features не принимаются автоматически.

## Запрещено

- Одновременный широкий `composer update` без package allowlist.
- Major update PHPUnit/Node/package ради номера версии без compatibility/value evidence.
- Lockfile change без install/audit/build/tests.
- Production dependency install из незакоммиченного или непроверенного состояния.
- Огромный static-analysis baseline или подавление diagnostics для создания ложного green status.
