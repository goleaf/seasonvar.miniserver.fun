# Полная модернизация repository

Дата: 30.07.2026.

Статус: approved. Пользователь предоставил исчерпывающий master-prompt и
прямо потребовал продолжать от анализа через implementation, verification,
final documentation, commit и push без routine approval pauses.

## Цель

Получить единый production-grade Seasonvar repository на PHP `8.5`,
Laravel `13`, Livewire `4` и Tailwind CSS `4`, где документация является
проверяемым source of truth, код сохраняет доменные/public contracts,
каждая подходящая Eloquent model имеет factory и meaningful states,
seeders безопасны, а фактическая готовность доказана тестами и build.

## Подтверждённое текущее состояние

- Проект уже является большим зрелым Laravel-каталогом, а не scaffold:
  `1456` first-party PHP files, `146` concrete Eloquent models,
  `134` migrations,
  `86` Livewire class files, `144` Blade views, `434` PHP test files и
  `259` routes.
- Runtime уже обновлён до PHP `8.5.8`, Laravel `13.23.0`, Livewire `4.3.3`,
  Tailwind `4.3.2`; manifests имеют более широкие/старые minimum constraints.
- Canonical requirement system уже существует в `docs/requirements` и
  тематических owner docs. Его нужно расширять и нормализовать, а не
  заменять параллельным набором церемониальных файлов.
- Markdown corpus содержит `536` tracked files; основная масса
  `docs/superpowers/specs|plans` является историческим evidence и не должна
  конкурировать с canonical owners.
- Full backend baseline зелёный: `2307` tests, `208533` assertions,
  `11` skipped.
- Главный подтверждённый implementation gap относительно нового требования:
  `146` моделей против `11` factory files и `4` seeders; первый RED
  перечислил `135` missing factory/`HasFactory` contracts.
- `DatabaseSeeder` напрямую запускает seeders с известными demo credentials;
  production safeguard должен быть зафиксирован failing test до исправления.

## Архитектурное решение

Модернизация выполняется dependency-aware passes, каждый из которых:

1. уточняет canonical requirement и compatibility boundary;
2. создаёт failing test для изменяемого поведения;
3. вносит минимально достаточное изменение;
4. запускает focused static/test/build gates;
5. обновляет living plan и compliance evidence.

Существующие Laravel boundaries сохраняются:

- full-page class-based Livewire владеет HTML routes;
- API controllers/resources остаются thin;
- policies/gates, Form Requests и server validation остаются authority;
- `app/Services/Seasonvar`, `Media`, `Crawler` и существующие domain services
  переиспользуются;
- Blade остаётся passive presentation;
- CSS-first Tailwind и local assets остаются единственным UI pipeline;
- production catalogue data не создаются seeders и не удаляются migrations.

## Documentation source of truth

Корневой `AGENTS.md` и `docs/requirements/index.md` сохраняют governance.
Новые стабильные IDs добавляются в существующий подходящий canonical owner.
Если для factories/seeding отсутствует владелец, создаётся один тематический
requirement file и он регистрируется в index/map до code implementation.

Исторические specs/plans/archive evidence не переписываются как текущие
requirements. Устаревшие duplicate документы получают canonical pointer
только если старый path является полезным compatibility contract.

## Dependency strategy

- Проверяются authoritative package metadata и official upgrade docs.
- Production и development packages обновляются только targeted patch/minor
  группами с записанными purpose, compatibility, rollout и rollback.
- PHP constraint становится `>=8.5.0 <8.6.0`; Laravel constraint следует
  official `^13.0`; PHPUnit остаётся основной line `12`.
- Livewire/Tailwind minimum становится не ниже реально выбранной стабильной
  `4.3.x`.
- Major PHPUnit 13, concurrently 10 и infrastructure packages не
  добавляются без отдельного доказанного maintenance/product основания.
- Lock files меняются только package managers.

## Factory and seeding design

Factory coverage является model inventory, а не генерацией одной
универсальной factory:

- independent aggregate/entity models получают normal valid-by-default
  factories;
- meaningful enums/statuses получают named states;
- graph creation остаётся explicit через helpers, чтобы default factory не
  создавал неограниченные связи;
- pivot/internal projection/cache/audit models получают factory только если
  их самостоятельное создание является валидным domain scenario; иначе
  exemption фиксируется с точной причиной;
- reference seeders используют stable natural keys/upsert и повторный запуск;
- demo/development accounts и graph seeders fail closed вне
  `local|development|testing`/явно разрешённого environment;
- production seeding никогда не truncate/delete и не создаёт demo
  credentials;
- catalog production data продолжает поступать только через importer.

## Livewire, Blade и Tailwind

Цель — maximum appropriate use, не syntax count:

- Livewire feature matrix сопоставляет feature реальному component use case;
  неиспользованная feature получает `not_applicable` reason;
- public state минимален, IDs locked only as defense-in-depth, auth/validation
  выполняются для каждой mutation;
- `#[Async]`, islands, stream, persist и polling не вводятся без реального
  concurrency/lifecycle contract;
- Blade architecture tests продолжают запрещать Volt, `@php`, DB/model/
  service calls, dynamic unsafe Tailwind classes и debug code;
- existing CSS-first `@theme`/`@source` system расширяется только по
  доказанным design/a11y gaps;
- ru/en, touch, keyboard, reduced motion, forced colors и representative
  viewports входят в final browser verification.

## Data, security и production

Schema changes допускаются только additive/reversible, после tests и
production-impact review. Pending production migration не применяется без
backup/writer evidence. External HTTP остаётся bounded, faked in tests and
restricted by existing source allowlists. Secrets, private URLs, credentials
и production values не записываются в docs/logs.

Seed/factory changes выполняются только на isolated in-memory/testing DB.
Production DML, cache flush, worker restart, provider call и destructive
maintenance не входят в разрешённый scope.

## Verification и completion

Final completion требует:

- full PHP suite и safe parallel suite;
- coverage либо точный подтверждённый environmental blocker;
- Pint, PHPStan/Larastan, Rector, syntax and architecture checks;
- fresh migrations, rollbacks where meaningful, fresh seed and idempotency;
- Composer validate/audit and npm audit;
- production build and player release check;
- applicable Playwright matrix, console/network/a11y review;
- final repository legacy scan, second Markdown pass, README/CHANGELOG sync;
- exact staged diff/lease approval, commit in existing `main`, factual push
  result.

Невыполненный пункт остаётся `unresolved`; он не переименовывается в
limitation только из-за объёма работы.
