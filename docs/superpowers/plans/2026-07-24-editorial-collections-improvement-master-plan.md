# Editorial Collections Improvement Implementation Master Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use `superpowers:executing-plans` to implement this plan one finite change set at a time. `superpowers:subagent-driven-development` is allowed only after a new explicit user request permitting sub-agents. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Превратить редакционные подборки в плотный, проверяемый и регулярно обновляемый слой сериалов: честно отделить несовместимые с каталогом фильмы источника, повысить долю безопасных совпадений, дать редактору bounded-инструменты ручной проверки и последовательно выпускать отсутствующие локальные подборки без fuzzy auto-link, smart collections и фиктивных утверждений.

**Architecture:** Существующие `CatalogCollection`, `CatalogCollectionQuery`, `CatalogCollectionItemService`, policy/moderation/cache/SEO boundaries и HDRezka provenance остаются единственными владельцами домена. Source-sync продолжает применять только точные или вручную подтверждённые связи; новые локальные подборки являются обычными persisted editorial collections, а allowlisted candidate query лишь помогает редактору выбрать тайтлы и не превращается в динамическую коллекцию.

**Tech Stack:** PHP `8.5.8`, Laravel `13.21.1`, Laravel Boost `2.4.13`, Livewire `4.3.3`, SQLite, Redis queues/locks, Memcached-compatible tiered cache, PHPUnit `12.5.31`, Pint `1.29.3`, Node.js `26.4.0`, Vite `8.1.4`, Tailwind CSS `4.3.2`.

**Plan status:** rolling implementation без верхнего лимита задач. Tasks 1–2 реализованы и полностью проверены локально, но их commit/push остаются `unresolved` из-за активных foreign importer/player/system/dependency изменений и обязательного clean-worktree hook. Tasks 3–18 образуют следующий evidence-driven проход; Tasks 19+ принимаются только по монотонному rolling protocol из разделов 24–28. План не разрешает provider HTTP, production DML, миграцию, изменение `.env`, запуск очереди, feature activation или destructive action без отдельного task-specific preflight и необходимой авторизации.

## Global Constraints

- Работать только в существующей `main`; не создавать branch/worktree и не обходить Git guards.
- Не stage/reset/stash/delete foreign importer/player/system/dependency changes общего checkout.
- `php artisan seasonvar:import` остаётся единственной публичной командой импорта Seasonvar; `catalog-collections:sync-hdrezka` остаётся отдельной collection-only boundary.
- Не создавать `CatalogTitle` из внешней подборки и не добавлять отсутствующий фильм под видом сериала.
- Не ослаблять exact matcher до fuzzy auto-link. Любая неточная связь требует явного staff decision с audit.
- Не скачивать видео, HLS, фильмовые постеры, raw HTML или произвольные external assets. Сохраняются только уже разрешённые collection covers и bounded provenance.
- Не показывать посетителю или обычному администратору source URL/path, raw `match_reasons`, error body, credentials или filesystem path.
- Не создавать второй collection/list/playlist model, smart-collection criteria storage, recommendation repository, cache store, permission system или admin route.
- Новая локальная подборка хранит обычные manual memberships. Candidate criteria являются только bounded preview и не вычисляются на visitor request.
- Переведённые labels не становятся identity. Интерфейсные keys имеют `ru`/`en` parity; editorial content создаётся в существующем русском DB workflow.
- Public activation требует реальных visible/playable items, ручной проверки, approved/public state и документированного rollback.
- Production content не добавляется migration/seeder. Редакторские rows создаются только через авторизованный UI/service и существующий audit.
- Любая метрика указывает дату и dataset; единичное локальное наблюдение не называется production SLA.

---

## 1. Проверенный baseline — 24.07.2026

### 1.1 Состояние локального каталога

| Метрика | Значение |
| --- | ---: |
| Опубликованные `serial` | 24 871 |
| Опубликованные `documentary` | 3 912 |
| Опубликованные `anime` | 3 030 |
| Опубликованные `show` | 1 157 |
| Editorial collections | 54 |
| Непустые editorial collections | 15 |
| Пустые editorial collections | 39 |
| Editorial memberships | 99 |
| Source items | 5 633 |
| Matched source items | 100 |
| Unmatched source items | 5 533 |
| Ambiguous source items | 0 |

Последний breakdown: `no_exact_candidate=5215`, `no_eligible_candidate=318`, `alias=85`, `primary=15`. Средняя плотность — `1,83` membership на редакционную подборку; это operational baseline, а не целевой уровень качества.

### 1.2 Главный discovery: пустая не всегда означает сломанную

Большинство пустых source-managed подборок ориентированы на фильмы, которых в текущем каталоге нет как самостоятельного publication type:

- «Про приключения и путешествия»: 358 фильмов из 365 source items;
- «Основанно на реальных событиях»: 226 фильмов из 227;
- «Про психов или маньяков»: 184 фильма из 185;
- «Фильмы NETFLIX»: 175 фильмов из 175;
- «Про агентов или полицейских»: 168 фильмов из 169;
- «Про мистику»: 159 фильмов из 159;
- «Лучшие экранизации литературных произведений»: 63 фильма из 63.

Поэтому запрещено считать все 39 пустых подборок matcher defect и заполнять их тематически похожими сериалами. Source health должен различать:

- `supported` — тип источника существует в локальном catalog type contract;
- `unsupported` — источник описывает фильм/другой отсутствующий тип;
- `unknown` — данных типа недостаточно;
- `actionable_unmatched` — совместимый тип есть, но exact candidate не найден;
- `healthy` — есть хотя бы один фактический public membership.

### 1.3 Данные, пригодные для новых локальных подборок

Проверенные country relations: Китай — `2 914`, Южная Корея — `1 778`, Турция — `736` опубликованных тайтлов.

Проверенные tag relations: школа — `398`, медицина — `243`, врачи — `234`, космос — `219`, юристы — `156`, сильная женщина — `139`, экранизация — `177`.

Проверенные network relations: Netflix — `369`, HBO — `80`, FOX — `47`, ABC — `41`, Showtime — `39`, CBS — `27`, Syfy — `23`, AMC — `12`.

Rating rows покрывают `18 870` тайтлов IMDb и `16 773` тайтла Кинопоиска; почти все имеют votes. При этом `studios` и `catalog_statuses` пусты, а один age-rating value назначен всем `32 970` тайтлам. Значит:

- country/tag/network/rating могут участвовать в bounded candidate preview;
- studio/status/age нельзя использовать как доказанный editorial criterion;
- номинальное заполнение pivot не считается качественной metadata coverage без distribution audit.

### 1.4 Уже заполненные source collections

Самые плотные: «Сериалы NETFLIX» — `30`, «Сериалы 2026» — `19`, «Сериалы Apple TV+» — `12`, «Мультфильмы про друзей» — `9`, «Сериалы Amazon» — `7`, «Аниме 2026» — `6`, «Сериалы HBO» — `5`, «Сериалы Disney» — `4`.

Остальные непустые тематические подборки имеют по одному membership. Они не должны автоматически feature-иться как полноценный editorial result.

## 2. Внешний research snapshot и отсутствующие форматы

Research является редакционным ориентиром, а не разрешением на scraping/import. Внешние title lists не копируются автоматически и не становятся authoritative metadata.

| Источник | Подтверждённые форматы | Состояние у нас |
| --- | --- | --- |
| [Netflix: Korean TV Shows](https://www.netflix.com/browse/genre/67879) | K-dramas, crime, romance, limited series, bingeworthy, watch in one weekend | Общая корейская и поджанровые serial-подборки отсутствуют |
| [Кинопоиск: лучшие мини-сериалы](https://www.kinopoisk.ru/lists/movies/best_miniseries/) | Лучшие мини-сериалы как отдельный curated list | Отсутствует; достоверный limited/completed status пока не импортирован |
| [Кинопоиск: сериалы Южной Кореи](https://www.kinopoisk.ru/lists/movies/country--26/?b=series) | Country wave с rating/order | В каталоге есть relation, но нет самостоятельной curated collection |
| [START: оригинальные сериалы](https://start.ru/collection/originalnye-serialy-start) | Platform originals | Есть отдельные platform collections, но START identity/metadata не подтверждены |
| [START: title selections](https://start.ru/watch/otpechatki-2026/selections) | Микроподборки: расследования, маньяки, реальная история, женщина-следователь | Часть тем существует только как пустые film-heavy source collections |
| [Okko: редакционные подборки](https://blog.okko.tv/selections) | Сезонные темы, mini-dorama, отношения, тематические подборки | Seasonal/event и mini-dorama отсутствуют |
| [Okko: зарубежные мини-сериалы](https://blog.okko.tv/selections/dlya-zapoinogo-prosmotra-10-zarubezhnykh-mini-serialov) | Короткий формат для просмотра за несколько дней | Отсутствует и требует ручной проверки завершённости |
| [PREMIER: сериалы 2026](https://premier.one/series/2026) | Годовые serial waves | «Сериалы 2026» уже есть; отдельная копия не нужна |

### Gap disposition

- **P0:** честная диагностика supported/unsupported/actionable source items.
- **P1:** корейские, турецкие, китайские и theme-based local editorial collections.
- **P1:** bounded staff curation preview и batch apply без smart collection.
- **P2:** mini-series/weekend только после evidence завершённости/длины.
- **P2:** platform originals только после verified network/provider identity.
- **P2:** seasonal/event collections с ручной датой публикации и снятия feature.
- **Already covered:** generic high-rated/recent/popular discovery уже существует в `/discover/{type}`; дублирующая подборка без новой editorial value не создаётся.
- **Separate future product:** celebrity-curated lists требуют согласия, attribution и editorial/legal workflow; они не имитируются сейчас.

## 3. Dependency order

| Фаза | Tasks | Gate |
| --- | --- | --- |
| 0. Delivery и truthful baseline | 1–2 | Diagnostics delivered; source scope separated from matcher defects |
| 1. Safe source recovery | 3–5 | Bounded exact retry; manual decision only if measured residual justifies schema |
| 2. Local curation tooling | 6–7 | Candidate preview read-only; batch write remains canonical/audited |
| 3. Editorial waves | 8–14 | Each collection reviewed privately before public/feature |
| 4. Discovery/recommendations | 15–17 | Readiness and offline quality gates pass |
| 5. Production acceptance | 18 | Rollback, docs, browser and operational evidence complete |
| Rolling intake | 19+ | Only next monotonic evidence-backed task |

Tasks 3, 5, 8–18 may be planned or implemented in code without production activation. Provider HTTP, production DML, feature activation and migrations remain separate explicit gates.

С 26.07.2026 private review является принудительной runtime-границей:
source sync создаёт новые строки `private/archived`, а public directory,
detail, API, search, sitemap и recommendation signals принимают только
категоризированные непустые collections размером не более 500. Это уточнение
заменяет историческое auto-public поведение ранней реализации sync.

## 4. Task 1 — безопасная диагностика здоровья

**Priority:** P0.

**Status:** `implementation_complete_local`, `verification_complete`, `delivery_unresolved`.

**Detailed plan:** [`2026-07-24-editorial-collection-health-diagnostics.md`](2026-07-24-editorial-collection-health-diagnostics.md).

**Files already changed locally:**

- `app/Services/Collections/CatalogCollectionQuery.php`
- `app/Livewire/Collections/CatalogCollectionAdministrationManager.php`
- `resources/views/livewire/collections/catalog-collection-administration-manager.blade.php`
- `lang/ru/collections.php`
- `lang/en/collections.php`
- `tests/Feature/HdRezkaCollectionPresentationTest.php`
- canonical docs, current plan, `README.md`, `CHANGELOG.md`

**Verified contract:**

```php
array{
    empty_collections: int,
    match_coverage_percent: float,
    match_methods: array<string, int>
}
```

- [x] Add one indexed empty-membership aggregate.
- [x] Add one indexed latest-run status/method aggregate.
- [x] Keep unknown method codes and raw source data out of presentation.
- [x] Verify `5 tests / 48 assertions`, all `HdRezkaCollection*` tests, full PHPUnit, Larastan and Vite.
- [ ] Commit exact Task 1 manifest only after foreign worktree release; push configured remote from `main`.

**Rollback:** remove additive diagnostics preparation/markup/translations. Schema/data/cache/provider state do not change.

## 5. Task 2 — классифицировать source scope вместо ложного empty alarm

**Priority:** P0.

**Status:** `implementation_complete_local`, `verification_complete`,
`delivery_unresolved`. Пользователь явно продолжил исполнение 24.07.2026.
Task 2 выполнен как локальный TDD-слой поверх проверенного Task 1 без
provider/data/production mutation; общий delivery Task 1–2 по-прежнему
заблокирован clean-worktree/rebase review.

**Files:**

- Create: `app/Enums/CatalogCollectionSourceScope.php`
- Create: `app/Services/Collections/Import/HdRezkaCollectionTypeCompatibility.php`
- Modify: `app/Services/Collections/Import/HdRezkaCollectionMatcher.php`
- Modify: `app/Services/Collections/CatalogCollectionQuery.php`
- Modify: `app/Livewire/Collections/CatalogCollectionAdministrationManager.php`
- Modify: `resources/views/livewire/collections/catalog-collection-administration-manager.blade.php`
- Modify: `lang/ru/collections.php`, `lang/en/collections.php`
- Modify: `tests/Feature/HdRezkaCollectionMatcherTest.php`
- Modify: `tests/Feature/HdRezkaCollectionPresentationTest.php`
- Modify: collection owner docs, current/master plans, `README.md`, `CHANGELOG.md`

**Interfaces:**

```php
enum CatalogCollectionSourceScope: string
{
    case Supported = 'supported';
    case Unsupported = 'unsupported';
    case Unknown = 'unknown';
}

final readonly class HdRezkaCollectionTypeCompatibility
{
    public function sourceScope(?string $sourceType): CatalogCollectionSourceScope;
    public function compatible(?string $sourceType, string $catalogType): bool;
}
```

**RED tests:**

- `test_film_source_is_reported_outside_current_catalog_scope`.
- `test_series_source_remains_actionable_for_serial_catalog_type`.
- `test_unknown_source_type_is_not_silently_called_unsupported`.
- `test_matcher_keeps_existing_year_and_type_fail_closed_results_after_extraction`.
- `test_admin_health_separates_unsupported_empty_collections_from_actionable_empty_collections`.

**Steps:**

- [x] Extract current matcher type normalization without changing match results.
- [x] Aggregate `supported|unsupported|unknown` source items for latest run with one grouped query.
- [x] Count an empty source collection as actionable when it contains a supported/unknown item or lacks current type evidence; only unsupported-only collections leave the actionable bucket.
- [x] Show safe localized counts; never show source path, raw title or reasons in the summary.
- [x] Run focused matcher/presentation tests, `EXPLAIN QUERY PLAN`, Pint, collection suite, full PHPUnit, Larastan, Vite and docs checks.

**Acceptance:** film-heavy collections are truthful `outside current catalog scope`, while compatible unresolved serial items remain visible as work. The total 39 empty rows is retained as a fact but no longer presented as 39 equivalent defects.

**Rollback:** restore matcher-local normalization and remove additive scope metrics/labels.

## 6. Task 3 — bounded exact retry и verified alias enrichment

**Priority:** P1 source quality.

**Status:** `planned`; provider execution requires a separate explicit operation.

**Files:**

- Modify: `app/Services/Collections/Import/HdRezkaCollectionSyncService.php`
- Modify: `app/Services/Collections/Import/HdRezkaCollectionMatcher.php`
- Modify: `config/catalog-collection-imports.php`
- Modify: `.env.example`
- Modify: `tests/Feature/HdRezkaCollectionSyncTest.php`
- Modify: `tests/Feature/SyncHdRezkaCollectionsCommandTest.php`
- Modify: `tests/Feature/HdRezkaCollectionMatcherTest.php`
- Modify: `docs/architecture.md`, `docs/importer.md`, `docs/security.md`, `docs/performance.md`, `docs/deployment.md`
- Modify: current/master plans, `README.md`, `CHANGELOG.md`

**Contract:**

```php
private function mayFetchRetryDetail(
    CatalogCollectionSourceScope $scope,
    int $collectionDetailRequests,
    int $runDetailRequests,
): bool;
```

Default ceilings:

- `HDREZKA_COLLECTION_MAX_RETRY_DETAILS_PER_RUN=250`;
- `HDREZKA_COLLECTION_MAX_RETRY_DETAILS_PER_COLLECTION=12`;
- only `supported|unknown` items are eligible;
- `film`/other unsupported rows never consume retry budget;
- only exact primary/original/approved-alias/detail-original evidence can link.

**RED tests:**

- `test_retry_unresolved_skips_unsupported_film_items`.
- `test_retry_unresolved_stops_at_run_and_collection_detail_budgets`.
- `test_detail_original_still_requires_year_and_type_compatibility`.
- `test_retry_dry_run_does_not_mutate_source_membership_or_aliases`.

**Steps:**

- [ ] Add bounded configuration and safe console counters `detail_attempted|detail_skipped_scope|detail_skipped_budget`.
- [ ] Run a fake-HTTP RED/GREEN cycle; stray requests remain prevented.
- [ ] Perform read-only alias coverage audit before proposing any alias write.
- [ ] Add an approved alias only through a separately authorized title metadata action when the alias is globally correct, not merely convenient for one collection.
- [ ] Execute provider `--dry-run --retry-unresolved` only after operational approval; compare matched delta against Task 2 baseline.
- [ ] Run the real retry only after sample review proves no incorrect link.

**Acceptance:** exact match gain is measured; request count is bounded; unsupported films do not consume the retry lane; zero fuzzy links.

**Rollback:** disable retry-detail budgets/configured execution; existing source rows and memberships remain authoritative.

## 7. Task 4 — persisted manual source decisions

**Priority:** P1 conditional safety boundary.

**Status:** `planned_evidence_gate`.

**Definition of Ready:** after Task 3, at least 20 repeated `actionable_unmatched` rows must have a clearly verifiable local title or a repeated false-positive decision worth preserving. If the threshold is not met, this task remains unimplemented and the plan records `not_required_by_evidence`.

**Files:**

- Create: `database/migrations/2026_07_24_260000_create_catalog_collection_source_resolutions.php`
- Create: `app/Enums/CatalogCollectionSourceResolutionDecision.php`
- Create: `app/Models/CatalogCollectionSourceResolution.php`
- Create: `app/Services/Collections/Import/CatalogCollectionSourceResolutionService.php`
- Modify: `app/Models/CatalogCollectionSourceItem.php`
- Modify: `app/Models/User.php`
- Modify: `app/Policies/CatalogCollectionPolicy.php`
- Modify: `app/Providers/AppServiceProvider.php`
- Create: `tests/Feature/CatalogCollectionSourceResolutionTest.php`
- Modify: `docs/DATA_RELATIONS.md`, `docs/architecture.md`, `docs/security.md`, `docs/authorization.md`, `docs/administration.md`
- Modify: deployment/rollback/current/master docs, `README.md`, `CHANGELOG.md`

**Schema:**

`catalog_collection_source_resolutions`:

- unique `catalog_collection_source_item_id`;
- nullable `catalog_title_id`;
- decision `linked|ignored`;
- nullable `resolved_by_id`;
- stable `reason_code`;
- positive `version`;
- timestamps;
- indexes `(decision,updated_at,id)` and `(catalog_title_id,decision,id)`;
- source-item delete cascades; title/user delete sets null while decision evidence remains.

**Interfaces:**

```php
final class CatalogCollectionSourceResolutionService
{
    public function link(
        User $actor,
        CatalogCollectionSourceItem $item,
        CatalogTitle $title,
        int $expectedVersion,
    ): CatalogCollectionSourceResolution;

    public function ignore(
        User $actor,
        CatalogCollectionSourceItem $item,
        string $reasonCode,
        int $expectedVersion,
    ): CatalogCollectionSourceResolution;

    public function clear(
        User $actor,
        CatalogCollectionSourceItem $item,
        int $expectedVersion,
    ): void;
}
```

**RED tests:**

- `test_moderator_can_link_only_supported_source_item_to_visible_title`.
- `test_resolution_rejects_stale_version_cross_source_and_incompatible_type`.
- `test_exact_retry_does_not_overwrite_manual_decision`.
- `test_link_ignore_and_clear_are_idempotent_and_audited`.
- `test_deleted_target_never_remains_effective_membership`.

**Steps:**

- [ ] Add additive/reversible migration and schema readiness guard.
- [ ] Add enum/model relations and `collections.moderate` + policy authorization.
- [ ] Normalize reason codes; raw notes, URL and remote HTML are not stored.
- [ ] Lock source item/resolution/title in one short transaction.
- [ ] Write secret-free `admin_audit_events` fingerprint atomically.
- [ ] Run migration pretend, SQLite migration tests, focused authorization/concurrency tests and full collection suite.

**Rollback:** stop writers, export decision evidence, revert code, retain additive table during normal code rollback; `down()` only after explicit dependency/data review.

## 8. Task 5 — staff review queue и effective reconciliation

**Priority:** P1 conditional on Task 4.

**Status:** `blocked_by_task_4`.

**Files:**

- Modify: `app/Services/Collections/CatalogCollectionQuery.php`
- Modify: `app/Services/Collections/Import/HdRezkaCollectionReconciler.php`
- Modify: `app/Services/Collections/Import/HdRezkaCollectionSignalSynchronizer.php`
- Modify: `app/Livewire/Collections/CatalogCollectionAdministrationManager.php`
- Modify: `resources/views/livewire/collections/catalog-collection-administration-manager.blade.php`
- Modify: `lang/ru/collections.php`, `lang/en/collections.php`
- Modify: `tests/Feature/HdRezkaCollectionReconciliationTest.php`
- Modify: `tests/Feature/HdRezkaCollectionPresentationTest.php`
- Modify: `tests/Feature/RebuildCatalogRecommendationsAfterCollectionSyncTest.php`
- Modify: collection/recommendation/admin/security/performance docs, plans, `README.md`, `CHANGELOG.md`

**Interfaces:**

```php
public function sourceResolutionQueue(
    string $status,
    string $query,
    int $perPage = 25,
): LengthAwarePaginator;

public function effectiveCatalogTitleId(
    CatalogCollectionSourceItem $item,
): ?int;
```

**Presentation allowlist:**

- sanitized source title, source year/type/countries;
- local candidate title/year/type and same-origin admin/title link;
- stable auto status/method label;
- stable manual decision/version;
- no source path, detail path, raw reasons, raw error or provider URL.

**RED tests:**

- `test_queue_is_private_permission_scoped_paginated_and_query_bounded`.
- `test_manual_link_wins_over_auto_unmatched_without_rewriting_auto_diagnostics`.
- `test_manual_ignore_excludes_membership_and_signal`.
- `test_complete_retry_preserves_decision_and_partial_run_never_deletes_it`.
- `test_unavailable_manual_target_fails_closed_and_is_visible_only_as_staff_state`.

**Steps:**

- [ ] Add one independent named paginator inside existing collection manager; no new route/full-page shell.
- [ ] Reuse public catalog search normalization for local candidate lookup with bounded result count.
- [ ] Resolve effective membership in reconciler while retaining automatic match fields.
- [ ] Synchronize source signals from effective membership and mark only changed title IDs dirty.
- [ ] Add accessible loading/empty/error/confirmation states and `ru`/`en` parity.
- [ ] Run focused tests, query count/`EXPLAIN`, Pint, Vite, collection/recommendation suites and admin browser QA.

**Rollback:** disable review controls and effective-resolution reader; retained decisions are inert evidence and automatic matching continues unchanged.

## 9. Task 6 — bounded editorial candidate preview

**Priority:** P1 local curation.

**Status:** `implemented_code_verified`; production canary remains an explicit
operator gate.

**Files:**

- Create: `app/DTOs/CatalogCollectionEditorialCandidateCriteria.php`
- Create: `app/Services/Collections/CatalogCollectionEditorialCandidateQuery.php`
- Modify: `app/Livewire/Collections/CatalogCollectionEditor.php`
- Modify: `resources/views/livewire/collections/catalog-collection-editor.blade.php`
- Modify: `lang/ru/collections.php`, `lang/en/collections.php`
- Create: `tests/Feature/CatalogCollectionEditorialCandidateQueryTest.php`
- Modify: `tests/Feature/CatalogCollectionTitleQueryTest.php`
- Modify: collection/query/UI/performance docs, plans, `README.md`, `CHANGELOG.md`

**DTO contract:**

```php
final readonly class CatalogCollectionEditorialCandidateCriteria
{
    /** @param list<int> $countryIds @param list<int> $tagIds
     *  @param list<int> $genreIds @param list<int> $networkIds */
    public function __construct(
        public array $countryIds,
        public array $tagIds,
        public array $genreIds,
        public array $networkIds,
        public ?int $yearFrom,
        public ?int $yearTo,
        public ?string $ratingProvider,
        public ?float $minimumRating,
        public ?int $minimumVotes,
        public int $limit = 100,
    ) {}
}
```

**Query contract:**

```php
public function candidates(
    User $actor,
    CatalogCollection $collection,
    CatalogCollectionEditorialCandidateCriteria $criteria,
): Collection;
```

Rules: only editorial collection manageable by actor; `CatalogTitleQuery::visibleTo(null)`/watchable boundary; allowlisted IDs/provider; limit `1..100`; deterministic rating/votes/year/title-ID order; existing members excluded; no provider HTTP; no Eloquent graph in Livewire state.

**RED tests:**

- `test_candidate_preview_applies_and_between_groups_and_or_inside_group`.
- `test_preview_excludes_hidden_unplayable_existing_and_wrong_type_titles`.
- `test_rating_filter_requires_allowlisted_provider_rating_and_vote_floor`.
- `test_preview_is_bounded_deterministic_and_performs_no_write`.
- `test_non_editor_cannot_probe_candidate_counts_or_private_metadata`.

**Steps:**

- [ ] Add typed criteria normalization in component/form boundary.
- [ ] Build one bounded SQL query with explicit eager-load projections.
- [ ] Return prepared title cards and stable selected IDs only.
- [ ] Add accessible preview filters using existing form/panel/card components.
- [ ] Verify long labels, 390px layout, query count, translations, Vite and full collection tests.

**Rollback:** remove preview UI/query/DTO; existing collection editor and memberships remain unchanged.

## 10. Task 7 — audited batch curation without smart collections

**Priority:** P1 local curation.

**Status:** `blocked_by_task_6`.

**Files:**

- Modify: `app/Services/Collections/CatalogCollectionItemService.php`
- Modify: `app/Services/Collections/CatalogCollectionRateLimiter.php`
- Modify: `app/Livewire/Collections/CatalogCollectionEditor.php`
- Modify: `resources/views/livewire/collections/catalog-collection-editor.blade.php`
- Modify: `lang/ru/collections.php`, `lang/en/collections.php`
- Create: `tests/Feature/CatalogCollectionEditorialBatchCurationTest.php`
- Modify: `tests/Feature/CatalogCollectionTitleQueryTest.php`
- Modify: collection/admin/cache/recommendation docs, plans, `README.md`, `CHANGELOG.md`

**Interface:**

```php
/** @param list<int> $catalogTitleIds
 *  @return array{added: list<int>, skipped_existing: list<int>} */
public function addMany(
    User $actor,
    CatalogCollection $collection,
    array $catalogTitleIds,
): array;
```

Rules: exact positive unique IDs; maximum 100; editorial + `content.manage`; collection/title rows re-resolved and authorized under lock; remaining capacity checked once; grouped insert with deterministic positions; one content-version change; one after-commit invalidation; one secret-free admin audit; identical retry is no-op.

**RED tests:**

- `test_editor_can_add_bounded_reviewed_candidates_once`.
- `test_batch_rejects_hidden_unplayable_cross_collection_and_more_than_100_ids`.
- `test_retry_does_not_duplicate_items_or_change_version`.
- `test_batch_invalidates_collection_domains_once_and_marks_recommendations_only_when_eligible`.
- `test_batch_writes_one_audit_event_without_candidate_titles_or_private_state`.

**Steps:**

- [ ] Add service method; do not create a parallel curation write service.
- [ ] Keep selected IDs bounded in Livewire and clear them only after successful apply.
- [ ] Show selected/add/skipped counts in localized live region.
- [ ] Preserve individual remove/reorder actions and manual positions.
- [ ] Run RED/GREEN, Pint, cache/admin/collection suites, Vite and desktop/mobile browser QA.

**Acceptance:** preview criteria are not persisted; visitor requests never re-evaluate them; resulting collection is an ordinary manual collection.

**Rollback:** remove batch action/UI; already curated memberships remain valid ordinary items and can be edited one by one.

## 11. Task 8 — Wave A: country editorial collections

**Priority:** P1 content.

**Status:** `blocked_by_tasks_6_7`; production content action requires authorized editor.

**Collections:**

1. `Корейские сериалы: выбор редакции`
2. `Турецкие сериалы: выбор редакции`
3. `Китайские сериалы: выбор редакции`

**Files:** no migration/seeder. Use existing `CatalogCollectionService`, `CatalogCollectionEditorialCandidateQuery`, `CatalogCollectionItemService` and `/my/collections/{uuid}/edit`; update current/master plans, `README.md` visitor history and `CHANGELOG.md` only after actual public activation.

**Candidate recipe:**

- exact country relation;
- published/watchable `serial`;
- preview priority: Кинопоиск or IMDb rating `>=7.0` with votes `>=1000`, then deterministic title ID;
- 60 candidate preview, 24–40 manually approved final items;
- no copy of external descriptions or rankings;
- no automatic claim «лучшие»; title says «выбор редакции».

**Steps:**

- [ ] Create each collection private/unfeatured with Russian editorial translation.
- [ ] Preview 60 candidates, manually verify title/type/country/playability and select 24–40.
- [ ] Check pairwise overlap and remove duplicate franchise/season entries.
- [ ] Add a factual plain-text description written by editor; no external marketing copy.
- [ ] Approve/public only after zero unavailable items and reviewer sign-off.
- [ ] Feature at most one country collection in the first rollout; observe discovery and recommendation metrics before expanding.

**Acceptance:** each collection has 24–40 guest-visible items, deterministic order, no source-only film, no fake percentage/award claim, valid SEO/sitemap eligibility and mobile QA.

**Rollback:** unfeature, then set private/archived through existing moderation; rows and audit remain recoverable.

## 12. Task 9 — Wave B: reliable thematic collections

**Priority:** P1 content.

**Status:** `blocked_by_tasks_6_7`.

**Collections and exact candidate relations:**

| Collection | Candidate relation |
| --- | --- |
| `Медицинские сериалы` | tags `медицина OR врачи` |
| `Юридические сериалы` | tag `юристы` |
| `Школа и взросление` | tag `школа`; editor rejects unrelated education/news rows |
| `Космос и дальние миры` | tag `космос` |
| `Сильные героини` | tag `сильная женщина` |

Shared recipe: published/watchable serial/anime where semantically appropriate; rating/votes only order candidates and never prove theme; 100 preview ceiling; 18–40 manually approved items; duplicate membership allowed across themes only when the title genuinely fits both.

**RED/content acceptance checks:**

- exact tag IDs are resolved once and saved in task evidence;
- every final title retains the tag and remains public/watchable;
- no collection has more than 60% Jaccard overlap with another wave collection;
- no title is included solely because of description substring;
- public collection has at least 12 items.

**Steps:**

- [ ] Curate one collection at a time, beginning with `Медицинские сериалы`.
- [ ] Keep the next collection private until the previous one passes browser/recommendation observation.
- [ ] Record candidate count, approved count, rejection categories and reviewer date without personal data.
- [ ] Update visitor docs only for actually published collections.

**Rollback:** unfeature/private/archive the affected collection; no taxonomy, title or user-state row is changed.

## 13. Task 10 — Wave C: mini-series и «на выходные»

**Priority:** P2.

**Status:** `blocked_by_metadata_evidence`.

**Reason:** `catalog_statuses` is empty, so episode count alone cannot prove a completed limited series. The labels «мини-сериал» and «можно посмотреть за выходные» are editorial claims and require verified completion/length evidence.

**Files if evidence gate passes:**

- Create: `app/Services/Collections/CatalogCollectionFormatEvidenceQuery.php`
- Modify: `app/Services/Collections/CatalogCollectionEditorialCandidateQuery.php`
- Modify: `app/Livewire/Collections/CatalogCollectionEditor.php`
- Modify: editor Blade/translations/tests/docs/plans/`README.md`/`CHANGELOG.md`
- Create: `tests/Feature/CatalogCollectionFormatEvidenceQueryTest.php`

**Interface:**

```php
/** @return array{
 *   published_episode_count:int,
 *   season_count:int,
 *   has_unknown_release_state:bool,
 *   evidence_codes:list<string>
 * } */
public function evidence(CatalogTitle $title): array;
```

**Gate and recipe:**

- 3–10 guest-visible playable episodes;
- one completed season or manually verified completed limited-series evidence;
- no pending/unknown episode structure;
- editor checks at least two independent metadata facts; external list is research reference only;
- target 12–24 items.

**RED tests:**

- `test_episode_count_without_completion_evidence_is_not_called_miniseries`.
- `test_hidden_or_unplayable_episode_does_not_enter_public_count`.
- `test_candidate_requires_bounded_verified_evidence_codes`.

**Acceptance:** if reliable completion evidence cannot be represented without new provider truth, the task closes as `not_ready` and no public collection is created.

**Rollback:** remove preview evidence UI; unfeature/private any draft. Title/episode metadata is not rewritten by this feature.

## 14. Task 11 — high-rated/bingeworthy gap disposition

**Priority:** P2 product integrity.

**Status:** `already_covered_by_existing_discovery` for generic high-rated/popular lists.

`/discover/top_rated`, `/discover/popular` and `/discover/trending` already provide deterministic public ranking. Therefore:

- do not create generic duplicates «Высокий рейтинг», «Популярное» or «Сейчас смотрят»;
- do not claim `bingeworthy` from rating alone;
- allow a future themed rating collection only when it combines a real editorial dimension, for example country + genre, and passes Task 8/9 review;
- do not add click/impression/play analytics under this task.

**Verification:**

- [ ] Confirm existing route/type/cache/SEO behavior before every future duplicate proposal.
- [ ] Record `not_required` when a proposed collection is only another presentation of an existing discovery sort.

**Rollback:** documentation-only disposition; no runtime/data change.

## 15. Task 12 — platform originals and networks

**Priority:** P2.

**Status:** `planned_metadata_gate`.

**Current facts:** reliable local networks exist for Netflix/HBO/FOX/ABC/Showtime/CBS/Syfy/AMC; studios are empty; START identity is not represented. Existing source-managed platform collections remain source-owned and must not be manually mixed with local memberships.

**Files if metadata gate passes:**

- Modify: importer parser/DTO/relation synchronizer only if Seasonvar provides verified network/studio identity.
- Modify: `CatalogCollectionEditorialCandidateQuery` for allowlisted network IDs.
- Modify: importer/collection/data/security/performance docs and focused `Http::fake()` tests.

**Steps:**

- [ ] Audit exact network/studio provenance in local rows and Seasonvar fixtures.
- [ ] Do not scrape START/Netflix/other platforms or create provider identity from a title substring.
- [ ] Keep existing `Сериалы NETFLIX/HBO/...` source collections source-managed.
- [ ] Create a separate local platform-original collection only when ownership/original status is explicitly verified, not merely distribution network.
- [ ] Require legal/editorial approval before copying any external attribution or brand description.

**Acceptance:** no platform-original claim is inferred from a network alone. If provenance remains absent, task closes `not_ready` without public data.

**Rollback:** remove candidate filter or unpublish draft; source sync and existing network relations remain unchanged.

## 16. Task 13 — экранизации и реальные события

**Priority:** P2.

**Status:** `partially_ready`.

**Ready collection:** `Экранизации книг` from exact tag `экранизация` (`177` published titles before watchability/review filtering).

**Blocked collection:** `Основано на реальных событиях`; current reliable tag/field is absent, and broad documentary/history genres do not prove the claim.

**Steps:**

- [ ] Curate `Экранизации книг` with Task 9 recipe, 18–36 final items and manual verification that the tag is semantically correct.
- [ ] Do not populate the film-heavy source collection «Лучшие экранизации литературных произведений» with unrelated serials; create a separate local editorial collection.
- [ ] Keep «Основано на реальных событиях» blocked until verified tag/provenance exists or each title receives human evidence through a separately approved metadata workflow.
- [ ] Do not infer a true story from synopsis keywords.

**Acceptance:** the published adaptation collection uses a distinct local identity and contains only verified serial/anime/documentary titles; true-story claim remains absent without evidence.

**Rollback:** unfeature/private/archive local collection; source collection and tag assignments are untouched.

## 17. Task 14 — seasonal and event collections

**Priority:** P2.

**Status:** `planned_after_wave_quality`.

**Examples:** новогодние сериалы, летние истории, к 1 апреля, осенние детективы. These are editorial schedules, not dynamic collections.

**Files if calendar support is needed:**

- Create: `app/Services/Collections/CatalogCollectionEditorialScheduleService.php`
- Modify: `app/Services/Collections/CatalogCollectionModerationService.php`
- Modify: existing collection admin manager/Blade/translations/tests/docs.
- Additive migration only if explicit future `featured_from`/`featured_until` fields are approved; no schema is added merely for a one-off event.

**Default first implementation:** manual feature/unfeature through existing moderation, with task checklist and calendar reminder outside repository. Automatic scheduling is allowed only after repeated operational need is measured.

**RED tests for any scheduler implementation:**

- `test_collection_is_featured_only_inside_server_owned_window`.
- `test_expired_window_unfeatures_without_hiding_collection`.
- `test_missing_scheduler_keeps_access_correct_and_admin_state_truthful`.

**Acceptance:** collection remains readable/public independently of feature window; no fake cron health; event copy is factual and removable.

**Rollback:** manual unfeature; scheduler/config/schema rollback only under production runbook.

## 18. Task 15 — publication and feature readiness gate

**Priority:** P1 before broad rollout.

**Status:** `planned`.

**Files:**

- Create: `app/Services/Collections/CatalogCollectionPublicationReadiness.php`
- Modify: `app/Services/Collections/CatalogCollectionModerationService.php`
- Modify: `app/Services/Collections/CatalogCollectionQuery.php`
- Modify: `app/Livewire/Collections/CatalogCollectionAdministrationManager.php`
- Modify: admin Blade/translations/tests/docs/plans/`README.md`/`CHANGELOG.md`
- Create: `tests/Feature/CatalogCollectionPublicationReadinessTest.php`

**Interface:**

```php
/** @return array{
 *   ready:bool,
 *   visible_items:int,
 *   total_items:int,
 *   unavailable_items:int,
 *   reason_codes:list<string>
 * } */
public function evaluate(CatalogCollection $collection): array;
```

**Rules:**

- feature requires approved/public/editorial/published;
- local editorial collection requires at least 12 guest-visible playable titles;
- source-managed collection requires at least 4, because exact source overlap can be narrower;
- unavailable count must be zero at feature time;
- unknown reason codes fail closed in UI;
- readiness does not silently publish, feature or alter content.

**Verified contracts:**

- [x] Empty, thin, unavailable, missing-source and structurally invalid
  collections fail closed with allowlisted reasons.
- [x] Ready local/source collections use the documented 12/4 minima.
- [x] Failed feature preserves state/version/audit; exact successful retry is
  a no-op and preserves the first audit.
- [x] Homepage and editorial discovery re-evaluate visibility/playability and
  exclude the whole collection after readiness loss.
- [x] Admin shows one batched text-only status/count/reason presentation and
  withholds feature from non-ready rows while preserving unfeature.

Focused readiness, discovery and adjacent collection suites passed; SQLite
plans use the existing feature/source/membership/media indexes, Vite built,
and Chromium desktop/mobile/tablet checks passed without collection images,
overflow or browser errors. No production row was featured by this task.

**Rollback:** remove readiness guard/panel; existing policy/moderation eligibility remains. Any already unfeatured collection stays public.

## 19. Task 16 — editorial signals and recommendation quality

**Priority:** P2 cross-feature.

**Status:** `blocked_by_tasks_8_9_15` and active compatible recommendation v6.

**Files:**

- Create: `app/Services/Collections/CatalogEditorialCollectionSignalSynchronizer.php`
- Modify: `app/Services/Collections/Import/HdRezkaCollectionSignalSynchronizer.php` to delegate shared signal persistence without changing provider keys.
- Modify: `app/Services/Collections/CatalogCollectionItemService.php`
- Modify: `app/Services/Collections/CatalogCollectionModerationService.php`
- Modify: recommendation builder/candidate/scorer/evaluator tests only where needed.
- Modify: collection/recommendation/cache/performance/importer docs, plans, `README.md`, `CHANGELOG.md`
- Create: `tests/Feature/CatalogEditorialCollectionSignalSynchronizerTest.php`

**Contract:**

```php
/** @return array{upserted:int, deleted:int, title_ids:list<int>} */
public function synchronize(CatalogCollection $collection): array;
```

Rules:

- HDRezka keeps provider/source-key identity;
- locally curated signals use stable collection `public_id`, not mutable name/slug;
- only readiness-passing, approved/public/featured editorial collections contribute;
- unfeature/private/item removal deletes only that collection’s signals;
- title IDs are marked dirty after commit; no synchronous full rebuild;
- broad collection penalty/cap remains in v6 scorer.

**RED tests:**

- `test_local_featured_editorial_membership_creates_stable_public_id_signal`.
- `test_unfeature_private_and_item_remove_delete_only_owned_signals`.
- `test_source_provider_signal_identity_remains_backward_compatible`.
- `test_signal_change_marks_union_of_added_and_removed_titles_dirty_once`.
- `test_offline_quality_gate_rejects_activation_when_relevance_or_availability_regresses`.

**Acceptance:** no recommendation activation until watchability 100%, empty-source count non-increasing, reason faithfulness and golden metrics pass. Collection names/private state never enter explanation/cache.

**Rollback:** stop local signal synchronization, remove its source rows by exact allowlisted source/key, keep HDRezka signals and active recommendation generation unchanged.

## 20. Task 17 — ongoing collection quality dashboard

**Priority:** P2 observability.

**Status:** `planned_after_content_waves`.

**Files:**

- Modify: `app/Services/Collections/CatalogCollectionQuery.php`
- Modify: `app/Livewire/Collections/CatalogCollectionAdministrationManager.php`
- Modify: admin Blade/translations/tests.
- Modify: `docs/administration.md`, `docs/performance.md`, current/master plans, `README.md`, `CHANGELOG.md`

**Metrics:**

- total/nonempty/empty/thin/readiness-passing collections;
- source `supported|unsupported|unknown|actionable_unmatched`;
- locally curated vs source-managed;
- featured collections failing readiness;
- public collections with unavailable items;
- per-collection visible count and last content change;
- match coverage and positive method breakdown already supplied by Task 1.

**Query budget:** one latest-run query, grouped collection aggregates, one bounded page query; no source-item hydration, no per-card count, no external HTTP.

**RED tests:**

- `test_dashboard_metrics_are_grouped_bounded_and_allowlisted`.
- `test_private_source_and_user_state_never_enter_health_payload`.
- `test_unknown_future_status_is_counted_as_unknown_not_rendered_raw`.

**Acceptance:** dashboard is diagnostic only; it does not retry sync, mutate collection, launch rebuild or claim monitoring/alerts that do not exist.

**Rollback:** remove additive cards/aggregates; Task 1 summary remains.

## 21. Task 18 — staged production rollout and closeout

**Priority:** final gate for first pass.

**Status:** `blocked_by_selected_predecessors`.

**Files/docs:**

- Update: all changed canonical collection/recommendation/admin/security/performance/cache/data docs.
- Update: `docs/deployment.md`, `docs/operations/rollback-runbook.md`, `docs/operations/production-checklist.md` only for actual runtime/schema changes.
- Update: current/master plans, `README.md`, `CHANGELOG.md`.

**Sequence:**

- [ ] Deliver Task 1 from clean `main`.
- [ ] Deploy code with collection source sync still disabled unless separately approved.
- [ ] Apply additive Task 4 migration only if its evidence gate passed; verify backup, writers, migration status and rollback.
- [ ] Execute Task 3 dry-run with bounded details; sample every new match method before real reconciliation.
- [ ] Activate one private local collection, then public/unfeatured, then one featured canary.
- [ ] Verify `/discover/popular`, direct collection page, cover, sitemap/API, cache invalidation, title recommendations and admin on desktop/mobile.
- [ ] Observe DB query time, queue/dirty-title behavior, warm health and source coverage; do not infer SLA from one request.
- [ ] Roll back feature visibility immediately on privacy, watchability, relevance, stale-cache or incorrect-membership failure.
- [ ] Reread canonical requirements, run legacy/duplicate/stale/dead-control search, update compliance and commit/push exact scope from `main`.

**Verification matrix:**

```bash
./vendor/bin/pint --dirty --format agent
php artisan test --filter=CatalogCollection
php artisan test --filter=HdRezkaCollection
php artisan test --filter=CatalogRecommendation
php artisan test
composer analyse
npm run build
php artisan project:docs-refresh --check
bash scripts/ci-check.sh docs
git diff --check
```

Playwright checks phone/tablet/desktop, keyboard, long RU/EN labels, loading/empty/failure, direct links, no browser call to HDRezka and no raw source data.

**Rollback:** unfeature/private first; stop optional schedule/job; return previous code/assets; retain additive schema/evidence unless a separately reviewed down migration is safe; targeted cache generation bump only, never broad flush.

## 22. Cross-feature impact matrix

| Domain | Impact | Contract |
| --- | --- | --- |
| Authentication/sessions | Unaffected | Existing authenticated editor/admin context only |
| Authorization | Affected Tasks 4–7, 15 | Existing `content.manage`, `collections.moderate`, policy and action reauthorization |
| Translations | Affected UI tasks | Exact `ru`/`en` parity; identity values stable |
| Caching | Affected membership/feature tasks | Existing `CatalogCollectionCacheInvalidator`; after-commit targeted versions |
| Search | Candidate query consumes existing search | No source title in public search; no new external index |
| SEO/sitemap | Affected only on public activation | Existing nonempty approved public collection presenter/stream |
| Notifications | Not applicable | No new notification category in first pass |
| Administration/audit | Affected | Same `/admin/catalog`; secret-free atomic audit for decisions/batches |
| Imports | Affected Tasks 2–5 | HDRezka remains collection-only; Seasonvar command and title identity unchanged |
| Recommendations | Affected Task 16 | Existing v6 signal/dirty/shadow gate; no second recommender |
| Privacy/security | Affected staff queue | Source title staff-only; URL/path/reasons/errors excluded |
| Mobile/accessibility | Affected UI tasks | 44px controls, keyboard, live regions, no overflow |
| API/public routes | Preserved | No `/collections` directory or new admin route |
| Premium/region/legal/advertising | Unaffected by data model | Canonical title visibility/watchability still filters candidates/items |
| Account export/delete/merge | Preserved | New source decisions retain nullable staff evidence; user collections unchanged |
| Production operations | Affected only by explicit migration/provider/data activation | Separate backup/rollback/deploy gate |

## 23. Planning-task file map and compliance

**Files changed by this planning refresh:**

- Create: `docs/superpowers/plans/2026-07-24-editorial-collections-improvement-master-plan.md`
- Modify: `docs/superpowers/plans/2026-07-24-editorial-collection-health-diagnostics.md`
- Modify: `docs/plans/current-task-plan.md`
- Modify: `docs/README.md`
- Modify: `README.md`
- Modify: `CHANGELOG.md`

**Protected unchanged contracts during planning:**

- all application/config/routes/migrations/translations/tests/assets;
- production DB, Redis, Memcached, queues, workers, scheduler, storage and `.env`;
- matcher/reconciler/signal behavior;
- foreign importer/player/system/lease/dependency files and commits.

| Requirement | Status | Evidence |
| --- | --- | --- |
| Root/index/read order | `completed` | Canonical owners and collection/importer/recommendation plans reread |
| Installed versions | `completed` | Boost/runtime/npm verified versions in header |
| Existing implementation | `completed` | Matcher, sync, reconciler, signals, item service, editor, admin and DB read-only baseline inspected |
| Internet research | `completed` | Official/current source pages listed in section 2 |
| Exact files/contracts/risks | `completed` | Sections 4–23 |
| Migrations/routes/translations/cache/permissions | `completed` | Each future task has explicit impact and rollback |
| Cross-feature impact | `completed` | Section 22 |
| README accuracy | `completed_for_plan` | Roadmap updated; visitor history unchanged because product behavior did not change |
| Runtime/data/provider mutation | `not_applicable` | Planning-only refresh |
| Commit/push | `unresolved` | Shared tree contains active foreign tracked/untracked work; clean-worktree hook must not be bypassed |

## 24. Безлимитный rolling protocol

Tasks 1–18 are the first measured pass, not a ceiling. “Безлимитный” means continuous evidence-driven intake with stable monotonic IDs, not endless execution without checkpoints.

For every Task 19+:

1. Assign the next never-reused Task ID.
2. Record date, evidence, business/quality/security impact and affected collection wave.
3. List exact files, interfaces, RED tests or operational assertions.
4. Mark dependencies and `ready|blocked|not_required`.
5. Record schema/data/provider/queue/cache/route/translation/permission impact.
6. Define rollback and failure recovery before implementation.
7. Implement one independently reviewable change set.
8. Verify focused first, broad proportional to blast radius.
9. Update canonical docs, current/master ledger, README only for actual product/roadmap change and Russian changelog.
10. Commit/push only from clean `main`; external failure remains `unresolved`.

Tasks are never renumbered, repurposed or silently deleted. A rejected idea remains with `not_required` and evidence, so the same duplicate proposal is not rediscovered.

## 25. Permanent rolling lanes

| Lane | Examples | Intake trigger |
| --- | --- | --- |
| Source matching | New supported type, recurring exact alias, parser evidence | Repeated actionable unmatched rows |
| Editorial coverage | Country/theme/format/event gap | Research + sufficient local metadata |
| Data quality | Missing/misleading tag/network/status/rating coverage | Measured distribution anomaly |
| Curation UX | Review time, batch errors, inaccessible workflow | Reproducible editor friction |
| Recommendation quality | Weak relevance, concentration, stale signals | Offline metric regression |
| Performance/cache | Slow grouped query, stale collection page | Query/HTTP measurement |
| Security/privacy | Source data leak, IDOR, unsafe provider behavior | Reproducible evidence/advisory |
| Operations | Failed schedule, retry storm, storage issue | Health/run evidence |
| Research | New competitor format | Official page + editorial value |

## 26. Definition of Ready for Task 19+

A rolling task is `ready` only when:

- the problem is observed in current code/data or a verified official source;
- it is not already covered by `/discover/{type}`, catalog filters or an existing collection;
- the canonical owner and protected contracts are named;
- exact files/interfaces/tests are known;
- production authority and data/provider implications are explicit;
- rollback does not require unsafe broad deletion;
- shared file ownership is available.

Otherwise the item remains `blocked` or `not_required`; no speculative code is created.

## 27. Definition of Done for every task

- Requirements and task plan reread before finalization.
- RED failure observed for code behavior; GREEN focused test passes.
- Exact diff, legacy/duplicate/stale/dead-control scan reviewed.
- Authorization, privacy, translations, cache, search, SEO/sitemap, recommendations, mobile/a11y, imports and account lifecycle assessed.
- Migrations additive/reversible with backup/rollback if present.
- Provider calls faked in tests and separately authorized in operations.
- Pint/static analysis/tests/build/browser checks proportional to impact pass or are honestly unresolved.
- Canonical docs, current/master ledger, Russian changelog and applicable README section updated.
- Exact task commit created in `main` and pushed, or delivery state explicitly `unresolved`.
- Production activation and observation recorded separately from code delivery.

## 28. Current execution ledger

| Task | Implementation | Verification | Delivery | Production activation |
| ---: | --- | --- | --- | --- |
| 1 | `complete_local` | `complete` | `unresolved` | `not_applicable` |
| 2 | `not_started` | `not_started` | `not_started` | `not_started` |
| 3 | `not_started` | `not_started` | `not_started` | `requires_authorization` |
| 4 | `evidence_gate` | `not_started` | `not_started` | `not_started` |
| 5 | `blocked_by_4` | `not_started` | `not_started` | `not_started` |
| 6 | `not_started` | `not_started` | `not_started` | `not_applicable` |
| 7 | `blocked_by_6` | `not_started` | `not_started` | `not_applicable` |
| 8–9 | `blocked_by_6_7` | `not_started` | `not_started` | `requires_editor` |
| 10 | `blocked_by_metadata` | `not_started` | `not_started` | `not_started` |
| 11 | `not_required_generic` | `verified_by_existing_routes` | `not_applicable` | `already_active_discovery` |
| 12 | `metadata_gate` | `not_started` | `not_started` | `not_started` |
| 13 | `partially_ready` | `not_started` | `not_started` | `requires_editor` |
| 14 | `not_started` | `not_started` | `not_started` | `requires_editor` |
| 15 | `not_started` | `not_started` | `not_started` | `not_started` |
| 16 | `blocked_by_8_9_15_v6` | `not_started` | `not_started` | `not_started` |
| 17 | `blocked_by_content_waves` | `not_started` | `not_started` | `not_started` |
| 18 | `blocked_by_selected_predecessors` | `not_started` | `not_started` | `not_started` |
| 19+ | `rolling_intake` | `not_started` | `not_started` | `not_started` |

**Next executable item:** first deliver the exact Tasks 1–2 manifest after
shared worktree release/rebase review. Task 3 code preparation may proceed in
a later explicitly continued implementation batch, but its provider execution,
Task 4 migration and Tasks 8–18 content/production actions are not authorized
by this plan alone.
