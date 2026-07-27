# Текущая задача — Task 113: безопасные практики современного PHP

## Реестр активных workstreams

| Workstream | Status | Evidence |
| --- | --- | --- |
| Task 113: безопасные практики современного PHP | `in_progress: commit и remote delivery` | [Task 113 evidence](archive/2026-07-27-modern-php-practices-evidence.md) |

## Реестр blocked/unresolved

| Workstream | Status | Evidence |
| --- | --- | --- |

## Task-specific compliance matrix

| Requirement | Status | Evidence |
| --- | --- | --- |
| Native warning и architecture boundaries | `completed` | [Task 113 evidence](archive/2026-07-27-modern-php-practices-evidence.md) |
| Routes, schema, cache keys и permissions | `already_compliant` | Публичные и data contracts не менялись |
| Full verification | `completed` | Backend: `2294` tests, `208356` assertions; frontend: build и release-check прошли |
| Commit и remote delivery | `in_progress` | Exact staged review и push выполняются после финального reread |

## Последнее подтверждённое evidence

- [Task 113: современные PHP-практики](archive/2026-07-27-modern-php-practices-evidence.md)

## Цель

Проверить рекомендации статьи «Stop Using These Bad PHP Practices in 2026»
на фактическом коде Seasonvar, устранить подтверждённые подавления нативных
предупреждений без изменения публичных контрактов и закрепить результат
автоматическими архитектурными и runtime-тестами.

## Активный checklist

| Priority | Workstream | Status | Evidence |
|---|---|---|---|
| critical | Requirements, versions, article и current implementation audit | `completed` | PHP `8.5.8`, Laravel `13.22.0`, SQLite; 8 рекомендаций сопоставлены с repository |
| critical | Workspace lease и exact manifest | `completed` | `task-113-modern-php-practices`, paths declared |
| high | `@`/`exit`/manual include/unsafe SQL AST contract | `completed` | `0` запрещённых AST-узлов после GREEN |
| high | Typed native-warning boundary и 16 call sites | `completed` | `App\Support\NativeCall`; все 16 call sites переведены |
| high | Existing domain behavior и security compatibility | `completed` | Focused suites и полный backend gate прошли |
| medium | Bounded PHPStan expansion и code-quality documentation | `completed` | Larastan level 6: `0` errors; owners обновлены |
| critical | Full verification | `completed` | Backend/frontend gates exit `0` |
| critical | Exact commit и push in `main` | `in_progress` | Pending exact staged review |

## Подтверждённые выводы

- В `app/` найдено 16 выражений подавления ошибок `@` в девяти runtime-файлах.
- В `app/` не найдено `die`/`exit`, ручных `include`/`require` и
  `DB::unprepared`.
- Проверенные raw SQL boundaries используют bindings для значений и
  allowlisted либо schema-derived identifiers; подтверждённой SQL injection
  нет.
- HTML routes остаются full-page Livewire, а API-контроллеры делегируют
  Form Requests, services/query objects и API Resources; новый repository
  layer не нужен.
- Composer lock, bounded Larastan level 6, Rector, Pint и PHPUnit уже входят
  в канонический CI gate; dependencies не меняются.

## Решение

- добавить `App\Support\NativeCall`, который на время одного callback
  преобразует `E_WARNING`/`E_USER_WARNING` в `ErrorException` и всегда
  восстанавливает прежний handler;
- заменить каждый `@` явным вызовом boundary и локально сопоставить ожидаемую
  ошибку с существующим domain exception, fail-closed `null`/`false` либо
  sanitized operational report;
- запретить AST-тестом возврат `@`, `exit`/`die`, runtime
  `include`/`require` и `DB::unprepared` в `app`;
- включить новый support boundary в существующий bounded PHPStan scope;
- не менять schema, routes, query parameters, cache keys, translations,
  authorization, queue payloads, provider URL policy и UI.

## Compatibility, rollout и rollback

- migrations/data writes/backfill/backup: `not_applicable`;
- routes/API/Blade/Livewire/translations/permissions: `unchanged`;
- cache key, format и invalidation: `unchanged`; corrupt gzip по-прежнему
  считается miss либо существующей безопасной domain error;
- external providers: DNS/OpenSSL/EXIF/GD/proc errors остаются bounded и не
  раскрывают credential, URL, body или private path;
- rollout: обычный application commit без dependency install, migration,
  asset build или worker payload conversion;
- rollback: согласованный revert PHP/tests/docs commit; cache flush и data
  rollback не требуются.

## Детальные документы

- [Design](../superpowers/specs/2026-07-27-modern-php-practices-design.md)
- [Implementation plan](../superpowers/plans/2026-07-27-modern-php-practices.md)
- [Compliance matrix](task-113-modern-php-practices-compliance.md)
