# Восстановление публичных редакционных подборок — план

Дата: 27.07.2026.

Design:
[`2026-07-27-public-editorial-collection-restoration-design.md`](../specs/2026-07-27-public-editorial-collection-restoration-design.md).

## 1. Подготовка и contracts

- [x] Прочитать обязательные requirements, collection/import/operations
  документацию и актуальные спецификации.
- [x] Проверить версии, ветку, shared worktree и получить workspace lease.
- [x] Выполнить read-only census и доказать root cause.
- [x] Зафиксировать новое постоянное recovery-правило в canonical
  `docs/architecture.md`.
- [x] Перечислить scope, compatibility и production risks.

## 2. TDD и реализация

- [x] RED: dry-run exact allowlist, отсутствие произвольной записи,
  preservation и aggregates.
- [x] RED: force recovery из legacy/quarantine state, category assignment,
  quality refresh, public directory и idempotence.
- [x] RED: production confirmations, active writers и category conflict.
- [x] GREEN: immutable source-key/category manifest и bounded query/service.
- [x] GREEN: dry-run-first Artisan command с generic Russian errors.
- [x] Проверить отсутствие broad auto-classification, raw URLs, N+1 и
  нецелевой cache invalidation.

## 3. Production apply

- [x] Снять повторный dry-run и проверить exact expected count.
- [x] Установить verified backup и подтвердить `integrity_check`.
- [x] Остановить фактические writers без изменения scheduler contracts.
- [x] Выполнить force recovery с production confirmations.
- [x] Повторить dry-run, quality/public-directory census и targeted cache
  verification.
- [x] Возобновить прежние writers и проверить их health.

## 4. Verification и delivery

- [x] Focused и broad collection/discovery/import tests.
- [x] Pint, PHPStan/Rector, docs checks и diff audit.
- [x] Desktop/mobile browser QA `/discover/popular#collections`.
- [x] Обновить canonical docs, README, CHANGELOG, compliance и archive
  evidence.
- [x] Проверить exact staged paths/index, commit в `main` и push; внешний
  отказ фиксировать как `unresolved`.

Implementation commit: `b2e97d80`. Push дошёл до GitHub HTTPS username
prompt и зафиксирован как `unresolved`, потому что credential helper
отсутствует. Посторонние изменения восстановлены точным binary patch.

## Ожидаемые изменяемые файлы

- command/service: `app/Console/Commands`,
  `app/Services/Collections/Import`;
- focused feature test;
- architecture/frontend/data/development/deployment/maintenance docs;
- current plan, compliance, archive evidence, `README.md`, `CHANGELOG.md`.

## Совместимые contracts

Без изменения остаются route names/URLs, Livewire query keys, API response
shape, sitemap XML, collection UUID/slug/source identity, membership order,
translations, comments/reports, recommendation ranking, migrations,
permissions, packages, environment keys и cache key format.

## Риски и rollback

- migrations: `not_applicable`;
- DML: exact десять source-managed rows, additive category/state/version и
  derived quality data;
- cache: только существующий targeted invalidator;
- writers: Seasonvar import, source sync и recommendation build блокируют
  apply;
- rollback: verified database backup или авторизованный roll-forward в
  private/archived; hard delete и global cache flush запрещены.
