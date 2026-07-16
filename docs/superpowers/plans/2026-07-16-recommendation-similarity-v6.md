# Recommendation Similarity v6 Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Выпустить измеримый content-similarity `v6` без ложных substring-тем, с overlap-aware scoring, точными причинами, shadow quality gate и безопасным scoped rebuild.

**Architecture:** Существующий builder остаётся importer-facing orchestrator, а theme extraction, candidate generation, pair scoring, diversity, quality evaluation и activation становятся отдельными тестируемыми services. Новый build сначала записывается в additive shadow tables, сравнивается с active rows и только затем атомарно копируется в `catalog_title_recommendations`; runtime readers продолжают использовать одну active table.

**Tech Stack:** PHP 8.5, Laravel 13.19, Eloquent/Query Builder, SQLite, PHPUnit 12.5, Blade/Livewire, Tailwind CSS 4.3, Playwright.

## Global Constraints

- Работать только в существующей ветке `main`; не создавать branch или worktree.
- `php artisan seasonvar:import` остаётся единственной публичной командой импорта.
- Не добавлять production dependencies, embeddings, vector database, внешний inference или HTTP во время rebuild.
- Candidate без canonical visibility и реально воспроизводимого published media не сохраняется.
- Не выполнять database queries в Blade; scoring не попадает в controllers.
- Публичные причины остаются короткими и русскими; raw score, IDs и provider payload не выдаются.
- Новая schema additive/reversible, с индексами для build/source/rank и activation.
- Full/scoped writes использовать транзакционно, bulk insert/upsert и bounded chunks.
- Каждый behavior change выполнять TDD; после PHP changes запускать Pint.
- Не прерывать существующий `seasonvar:import`; production-sized rebuild запускать только после его завершения.
- Не включать параллельные изменения рабочего дерева в commits.

---

### Task 1: Зафиксировать baseline и golden relevance set

**Files:**
- Create: `resources/recommendations/golden-v6.json`
- Create: `app/DTOs/CatalogRecommendationQualityReport.php`
- Create: `app/Services/Catalog/CatalogRecommendationQualityEvaluator.php`
- Create: `tests/Unit/CatalogRecommendationQualityEvaluatorTest.php`
- Create: `tests/Fixtures/recommendations/quality-baseline.php`

**Interfaces:**
- Produces: `CatalogRecommendationQualityEvaluator::evaluate(iterable $rows, array $grades, int $limit = 12): CatalogRecommendationQualityReport`.
- Produces: report fields `precisionAtLimit`, `ndcgAtLimit`, `sourceCount`, `emptySourceCount`, `watchableRate`, `candidateCoverage`, `maximumIncoming`, `incomingAtLeast100`, `reasonFaithfulnessFailures`, `judgedRowCount`, `judgmentCoverage`.
- Golden JSON identifies titles by stable `slug`, never local numeric ID.

- [x] **Step 1: Добавить failing metric tests**

Use a three-source fixture with graded candidates:

```php
public function test_it_calculates_precision_ndcg_coverage_and_concentration(): void
{
    $rows = [
        ['source' => 'source-a', 'candidate' => 'great', 'rank' => 1, 'watchable' => true, 'reasons' => ['genre']],
        ['source' => 'source-a', 'candidate' => 'bad', 'rank' => 2, 'watchable' => true, 'reasons' => ['actor']],
        ['source' => 'source-b', 'candidate' => 'great', 'rank' => 1, 'watchable' => false, 'reasons' => []],
    ];
    $grades = [
        'source-a' => ['great' => 2, 'bad' => 0],
        'source-b' => ['great' => 1],
        'source-c' => [],
    ];

    $report = app(CatalogRecommendationQualityEvaluator::class)->evaluate($rows, $grades, 2);

    $this->assertSame(0.75, $report->precisionAtLimit);
    $this->assertGreaterThan(0.7, $report->ndcgAtLimit);
    $this->assertSame(3, $report->sourceCount);
    $this->assertSame(1, $report->emptySourceCount);
    $this->assertSame(2, $report->maximumIncoming);
    $this->assertSame(1, $report->reasonFaithfulnessFailures);
    $this->assertSame(3, $report->judgedRowCount);
    $this->assertSame(1.0, $report->judgmentCoverage);
}
```

- [x] **Step 2: Verify RED**

Run: `php artisan test tests/Unit/CatalogRecommendationQualityEvaluatorTest.php --compact`

Expected: FAIL because DTO/evaluator do not exist.

- [x] **Step 3: Реализовать immutable report и pure evaluator**

Create the DTO with exact scalar fields and `toArray(): array<string, int|float>`. Evaluator must:

```php
$gain = static fn (int $grade): float => (2 ** max(0, min(2, $grade))) - 1;
$discount = static fn (int $rank): float => 1 / log($rank + 1, 2);
```

Group rows by source and truncate to `$limit`. Для Precision/nDCG учитывать только candidates, которые явно присутствуют в grade map; неразмеченные строки исключать, а не считать нулём. Отдельно считать `judgedRowCount` и его долю среди ранжированных строк. Compute DCG/ideal DCG per graded source, then average sources. A row is reason-faithful only when `reasons !== []`. `watchableRate` is watchable rows divided by all rows. Round ratios to four decimals in the DTO factory.

- [x] **Step 4: Добавить stratified golden JSON**

JSON schema:

```json
{
  "version": "v6-2026-07-16",
  "limit": 12,
  "sources": [
    {
      "slug": "vo-vse-tiazhkie-breaking-bad",
      "segment": "popular",
      "grades": {
        "luchshe-zvonite-solu-better-call-saul": 2
      }
    }
  ]
}
```

Add at least 30 sources across `popular`, `sparse`, `anime`, `documentary`, `show`, `long_cast`, `empty_baseline`, and `lexical_collision`. Only rows manually inspected in the local catalogue receive grades; omitted candidates are unjudged and excluded from nDCG rather than assumed bad.

- [x] **Step 5: Verify GREEN and commit**

Run:

```bash
php artisan test tests/Unit/CatalogRecommendationQualityEvaluatorTest.php --compact
./vendor/bin/pint --dirty --format agent app/DTOs/CatalogRecommendationQualityReport.php app/Services/Catalog/CatalogRecommendationQualityEvaluator.php tests/Unit/CatalogRecommendationQualityEvaluatorTest.php
```

Expected: tests PASS and Pint exits `0`.

Commit only Task 1 files with `README.md` roadmap wording if product state is described as available; otherwise this foundation-only commit does not claim visitor-visible behavior.

---

### Task 2: Token/phrase theme extractor v6

**Files:**
- Modify: `app/Services/Catalog/CatalogRecommendationThemeExtractor.php`
- Modify: `tests/Unit/CatalogRecommendationThemeExtractorTest.php`
- Create: `tests/Fixtures/recommendations/theme-corpus.php`

**Interfaces:**
- Preserves: `extract(?string $title, ?string $originalTitle, ?string $description): array<string, string>`.
- Adds no I/O and returns at most eight stable theme codes.

- [x] **Step 1: Добавить collision corpus и failing test**

Fixture must include these exact negatives:

```php
return [
    ['text' => 'У героя сложный характер', 'missing' => ['show_business']],
    ['text' => 'Она работает в магазине', 'missing' => ['fantasy']],
    ['text' => 'На его лице появилась улыбка', 'missing' => ['school']],
    ['text' => 'У агента двойной паспорт', 'missing' => ['military', 'sports']],
    ['text' => 'Семь участников дошли до финала', 'missing' => ['family']],
    ['text' => 'Он разбирает старые бумаги', 'missing' => ['fantasy']],
    ['text' => 'Магия меняет семью молодого актёра', 'present' => ['fantasy', 'family', 'youth', 'show_business']],
];
```

Data-provider test calls `extract(null, null, $case['text'])` and asserts every `present`/`missing` key.

- [x] **Step 2: Verify RED**

Run: `php artisan test tests/Unit/CatalogRecommendationThemeExtractorTest.php --compact`

Expected: FAIL on all documented substring collisions.

- [x] **Step 3: Заменить regex по всему тексту на token-prefix/phrase matcher**

Represent each theme as `terms`, safe `prefixes`, and `phrases`. Tokenize with:

```php
preg_match_all('/[\p{L}\p{N}]+/u', $text, $matches);
$tokens = array_fill_keys($matches[0] ?? [], true);
```

Rules for collision-prone themes are exact:

```php
'family' => [
    'terms' => ['семья', 'семьи', 'семье', 'семью', 'семьей'],
    'prefixes' => ['семейн', 'родител', 'ребен', 'детск', 'сынов', 'дочер'],
    'phrases' => [],
],
'fantasy' => [
    'terms' => ['маг', 'маги', 'магия', 'магии', 'магию'],
    'prefixes' => ['магическ', 'волшеб', 'сказочн'],
    'phrases' => ['мир фэнтези'],
],
'school' => [
    'terms' => ['лицей', 'лицея', 'лицее', 'лицеем', 'лицеи'],
    'prefixes' => ['школ', 'университет', 'студент'],
    'phrases' => [],
],
'military' => [
    'terms' => ['война', 'войны', 'войне', 'войну', 'войной', 'армия', 'армии', 'армией'],
    'prefixes' => ['военн', 'солдат'],
    'phrases' => [],
],
'sports' => [
    'terms' => ['спорт', 'спорта', 'спорте', 'спортом'],
    'prefixes' => ['спортивн', 'футбол', 'баскетбол', 'соревнован'],
    'phrases' => [],
],
'show_business' => [
    'terms' => ['актер', 'актеры', 'актера', 'актеров', 'актриса', 'актрисы'],
    'prefixes' => ['актерск', 'актрис', 'съемочн', 'телевиден'],
    'phrases' => ['шоу бизнес'],
],
```

Convert the remaining existing theme patterns with prefixes that must start at token offset zero. `matches()` returns true for an exact term, `str_starts_with($token, $prefix)`, or normalized phrase contained between padded spaces. Never search a prefix at a nonzero token offset.

- [x] **Step 4: Verify GREEN, corpus and format**

Run:

```bash
php artisan test tests/Unit/CatalogRecommendationThemeExtractorTest.php --compact
./vendor/bin/pint --dirty --format agent app/Services/Catalog/CatalogRecommendationThemeExtractor.php tests/Unit/CatalogRecommendationThemeExtractorTest.php tests/Fixtures/recommendations/theme-corpus.php
```

Expected: all positive themes remain and all collision cases PASS.

---

### Task 3: Pure pair scorer with overlap-aware confidence

**Files:**
- Create: `app/DTOs/CatalogRecommendationScore.php`
- Create: `app/Services/Catalog/CatalogRecommendationPairScorer.php`
- Create: `tests/Unit/CatalogRecommendationPairScorerTest.php`
- Modify: `config/recommendations.php`

**Interfaces:**
- Produces: `score(array $source, array $candidate, array $documentFrequency, int $documentCount): ?CatalogRecommendationScore`.
- Profile shape contains `id`, `type`, `year`, feature ID lists, `themes`, `signals`, and quality counters.
- DTO exposes `total()`, `matchedFeaturesCount`, `metadataScore`, `sourceScore`, `qualityScore`, `reasons`.

- [x] **Step 1: Write failing scorer tests**

Cover exact behaviors:

```php
public function test_one_common_actor_in_large_cast_does_not_pass_relevance_gate(): void
public function test_two_specific_shared_actors_and_genre_pass_the_gate(): void
public function test_exact_theme_and_country_beat_broad_genre_only(): void
public function test_quality_cannot_rescue_an_unrelated_candidate(): void
public function test_verified_provider_relation_has_a_source_score_and_reason(): void
```

Use profiles as literal arrays; assert `null` for weak pairs and exact bucket/reason keys for strong pairs.

- [x] **Step 2: Verify RED**

Run: `php artisan test tests/Unit/CatalogRecommendationPairScorerTest.php --compact`

Expected: FAIL because scorer/DTO do not exist.

- [x] **Step 3: Implement bounded scoring math**

Use these helpers:

```php
private function overlap(array $left, array $right): array
{
    $shared = array_values(array_intersect($left, $right));
    $minimum = max(1, min(count($left), count($right)));
    $union = max(1, count(array_unique([...$left, ...$right])));

    return [$shared, count($shared) / $minimum, count($shared) / $union];
}

private function idf(int $documentCount, int $frequency): float
{
    return max(1.0, min(2.5, 1.0 + log10(($documentCount + 1) / (max(1, $frequency) + 1))));
}
```

Per-feature contribution is `baseWeight * averageSharedIdf * (0.5 + 0.5 * overlapCoefficient)`. Actor/director features multiply by `max(0.35, 1 - max(count(left), count(right)) / 80)` and are capped at three shared IDs. A strong match is exact theme, verified provider relation, tag with coefficient ≥0.5, director with coefficient ≥0.5, or at least two actors with coefficient ≥0.2. Genre alone never marks strong.

Apply configured `min_relevance_score` to `metadata + source`; only then add bounded quality. Store reason details as:

```php
[
    'actor' => ['count' => 2, 'ratio' => 0.4, 'score' => 310],
    'genre' => ['count' => 1, 'ratio' => 1.0, 'score' => 180],
]
```

- [x] **Step 4: Add versioned config**

Under `similarity_v6`, define exact values:

```php
'similarity_v6' => [
    'algorithm_version' => 'v6',
    'feature_version' => 'tokens-v2',
    'min_relevance_score' => 600,
    'max_per_title' => 12,
    'candidate_limit' => 240,
    'build_history_limit' => 5,
    'weights' => [
        'genre' => 160, 'tag' => 240, 'director' => 300, 'actor' => 160,
        'network' => 220, 'studio' => 220, 'translation' => 40,
        'status' => 25, 'country' => 70, 'age_rating' => 15, 'theme' => 240,
    ],
    'quality' => ['media' => 80, 'rating' => 40, 'reviews' => 20],
];
```

- [x] **Step 5: Verify GREEN and format**

Run focused tests and Pint. Expected: all five cases PASS with exact bucket totals and no negative score.

---

### Task 4: Bounded candidate generator and provider-only path

**Files:**
- Create: `app/Services/Catalog/CatalogRecommendationCandidateGenerator.php`
- Create: `tests/Unit/CatalogRecommendationCandidateGeneratorTest.php`
- Modify: `app/Services/Catalog/CatalogTitleRecommendationBuilder.php`

**Interfaces:**
- Produces: `add(array $profile): void`, `idsFor(array $source, int $limit): array<int>`, `reset(): void`.
- Candidate keys: feature ID, theme, `theme|genre`, `theme|country`, and directed provider target IDs.

- [x] **Step 1: Add failing deterministic-pool tests**

Assert that rare composite candidates precede broad genre candidates, provider target is reachable without shared metadata, current title is excluded, result is unique/stable and never exceeds limit.

- [x] **Step 2: Verify RED**

Run: `php artisan test tests/Unit/CatalogRecommendationCandidateGeneratorTest.php --compact`.

- [x] **Step 3: Implement packed indexes**

Use associative maps `key => list<int>` and seed score `1000 / max(1, bucketSize)`; provider target receives priority `10_000`. Sort by seed descending, then title ID ascending, and slice to limit. `reset()` sets every mutable map to `[]` and is called from builder `finally`.

- [x] **Step 4: Integrate builder candidate selection**

Inject generator and pair scorer. Keep existing compact profile query/eager-load boundaries, but replace private `candidateIds()` and `score()` calls. Set algorithm version from `config('recommendations.similarity_v6.algorithm_version')`. Do not change persistence yet; tests continue to use active table.

- [x] **Step 5: Run builder/candidate regression**

Run:

```bash
php artisan test tests/Unit/CatalogRecommendationCandidateGeneratorTest.php tests/Unit/CatalogTitleRecommendationBuilderTest.php --compact
./vendor/bin/pint --dirty --format agent app/Services/Catalog/CatalogRecommendationCandidateGenerator.php app/Services/Catalog/CatalogTitleRecommendationBuilder.php tests/Unit/CatalogRecommendationCandidateGeneratorTest.php
```

Expected: deterministic tests PASS; existing builder visibility/media/max tests remain green after updating intentional `v5→v6` assertions.

---

### Task 5: Faithful multi-reason runtime pipeline

**Files:**
- Modify: `app/Enums/CatalogRecommendationReason.php`
- Modify: `app/Services/Catalog/CatalogRecommendationPresenter.php`
- Modify: `app/Services/Catalog/CatalogRecommendationService.php`
- Modify: `app/Services/Catalog/CatalogRecommendationCache.php`
- Modify: `lang/ru/recommendations.php`
- Modify: `lang/en/recommendations.php`
- Modify: `tests/Feature/CatalogRecommendationListTest.php`
- Modify: `tests/Feature/Api/V1/CatalogRelatedContentTest.php`

**Interfaces:**
- Candidate row gains optional `reasons: list<string>` while retaining primary `reason` for backward compatibility.
- `CatalogRecommendationItem::$explanations` receives 1–4 explanation DTOs.

- [x] **Step 1: Add failing reason-preservation tests**

Store a row with `theme_romance`, `director`, `actor`, `country` and assert title page/API return four matching labels in contribution order, not only `Похожие жанры и темы`. Assert score/breakdown remain absent from public API.

- [x] **Step 2: Verify RED**

Run the two focused feature files; expected failure is the current one-reason reduction.

- [x] **Step 3: Add stable reason enum mapping**

Add `SimilarTheme` and `ImportedRelation` enum cases. In presenter add:

```php
public function storedSimilarityExplanations(mixed $storedReasons): array
```

Sort by stored `score`, map `theme_* → SimilarTheme` with translated `theme` parameter, other keys to existing enums, unique by `reason+parameters`, take four.

- [x] **Step 4: Preserve reasons through rows/cache/result**

`similarCandidates()` returns `reasons` as enum values/parameters. Cache normalization validates a bounded list of strings and keeps at most four. `result()` constructs all valid explanations and falls back to the primary reason only when list is absent. Personalized/public rows remain backward compatible.

- [x] **Step 5: Verify GREEN and privacy**

Run focused page/API tests, translation tests if present, and assert JSON contains no `score`, `metadata_score`, `source_score`, `quality_score`, or raw reason breakdown.

---

### Task 6: Additive shadow build schema

**Files:**
- Create: `database/migrations/2026_07_16_240000_create_catalog_recommendation_shadow_builds.php`
- Create: `app/Models/CatalogRecommendationBuild.php`
- Create: `app/Models/CatalogRecommendationBuildRow.php`
- Create: `tests/Feature/CatalogRecommendationShadowSchemaTest.php`

**Interfaces:**
- Build statuses: `building`, `evaluated`, `rejected`, `active`, `failed`.
- Rows belong to one build and mirror active scoring columns.

- [x] **Step 1: Add failing schema/model test**

Create a build and two rows; assert unique `(build_id, catalog_title_id, recommended_title_id)`, indexed rank retrieval and cascade delete.

- [x] **Step 2: Verify RED**

Run schema test; expected failure: missing tables/models.

- [x] **Step 3: Create reversible migration**

`catalog_recommendation_builds` columns: id, algorithm_version(32), feature_version(32), status(16), metrics JSON nullable, failure_message nullable text, started_at, completed_at, activated_at, timestamps; index `(status, created_at)`.

`catalog_recommendation_build_rows` columns: id, build_id FK cascade, source/candidate FKs cascade, score/rank, matched_features_count, metadata/source/quality scores, reasons JSON nullable, computed_at, timestamps; unique build/source/candidate; indexes `(build_id, catalog_title_id, rank)` and `(build_id, recommended_title_id, score)`.

- [x] **Step 4: Implement typed models and Verify GREEN**

Use `#[Fillable]`, integer/array/datetime casts and typed `BelongsTo` relations following `CatalogTitleRecommendation`. Run migration test and Pint.

---

### Task 7: Shadow persistence, quality gate and atomic activation

**Files:**
- Create: `app/Services/Catalog/CatalogRecommendationBuildActivator.php`
- Modify: `app/Services/Catalog/CatalogRecommendationQualityEvaluator.php`
- Modify: `app/Services/Catalog/CatalogTitleRecommendationBuilder.php`
- Modify: `tests/Unit/CatalogTitleRecommendationBuilderTest.php`
- Create: `tests/Feature/CatalogRecommendationBuildActivationTest.php`

**Interfaces:**
- Builder preserves `rebuild(?callable $progress = null): array`.
- Activator exposes `activate(CatalogRecommendationBuild $build): void`.
- Summary adds `build_id`, `activated`, `gate_passed`, `baseline_metrics`, `candidate_metrics`, `row_churn` without removing existing keys.

- [x] **Step 1: Add failing rejection/activation tests**

Test A seeds active rows, builds a candidate with unavailable rows/empty regression, and asserts build status `rejected` plus unchanged active IDs.

Test B seeds a passing build, activates it, and asserts active table contains only build rows, all `algorithm_version=v6`, build `active`, previous active build `evaluated`, and cache invalidator called after transaction.

- [x] **Step 2: Verify RED**

Run activation test; expected failure: activator missing and builder writes active rows directly.

- [x] **Step 3: Persist builder output only to shadow rows**

Create build before profile loading. Chunk-insert rows with `build_id`; on exception mark build `failed`, store bounded exception class/message through existing error formatter, leave active table unchanged, then rethrow.

- [x] **Step 4: Implement gate**

Pass only when:

```php
$candidateRowCount > 0
    && $candidate->watchableRate === 1.0
    && $candidate->ndcgAtLimit >= $baseline->ndcgAtLimit
    && $candidate->emptySourceCount <= $baseline->emptySourceCount
    && $candidate->judgmentCoverage >= 0.8;
```

If golden slugs are absent locally, nDCG is reported as unavailable and activation requires an explicit config `similarity_v6.allow_activation_without_golden=false`; default false. Override никогда не разрешает пустой build, недоступный candidate или отсутствие достоверной причины.

- [x] **Step 5: Implement atomic copy**

Inside one `DB::transaction()` delete active rows, `insertUsing()` all mirrored columns from the selected build, mark previous `active` builds `evaluated`, mark selected build `active/activated_at`. Invalidate recommendation cache only after successful commit. A thrown exception rolls back active rows and leaves build `failed`.

- [x] **Step 6: Verify GREEN and importer summary compatibility**

Run activation, builder and `SeasonvarImportMaintenanceTest`. Assert existing summary keys and progress events remain available.

---

### Task 8: Remove dead generic recommendation signals safely

**Files:**
- Modify: `app/Services/Seasonvar/SeasonvarCatalogParser.php`
- Modify: `app/Services/Seasonvar/SeasonvarCatalogImporter.php`
- Modify: `tests/Feature/SeasonvarImportMaintenanceTest.php`
- Modify: parser unit/feature test that currently expects generic signals
- Create: `app/Services/Catalog/CatalogRecommendationSignalPruner.php`
- Create: `tests/Unit/CatalogRecommendationSignalPrunerTest.php`

**Interfaces:**
- Parser returns only normalized verified `provider_recommendation`/`related_title` signals when source HTML actually supplies them.
- Pruner deletes only `source=seasonvar_info` generic types, in bounded `chunkById`, and reports checked/deleted counts.

- [x] **Step 1: Add failing parser/importer tests**

Assert genre/rating/year/page-quality no longer create signal rows while normalized catalog relations still persist in their canonical pivot/rating tables. Assert a manually seeded `provider_recommendation` from a different managed source survives pruning.

- [x] **Step 2: Verify RED**

Run focused parser/importer tests; expected failure: generic signals are currently returned/upserted.

- [x] **Step 3: Stop generating duplicated generic rows**

Return an empty list from the generic recommendation-signal branch and delete unused taxonomy/rating weighting helpers only after tests prove no caller. Keep DTO field for forward-compatible provider signals.

- [x] **Step 4: Implement bounded pruner**

Allowlist deletable types `taxonomy_*`, `rating`, `release_year`, `page_quality` only when `source=seasonvar_info`. Delete IDs in chunks of 1,000; never issue a table-wide unqualified delete. Invoke after successful `v6` activation, not before.

- [x] **Step 5: Verify GREEN and storage metrics**

Run parser/importer/pruner tests. On a database copy, compare row counts and `dbstat`; do not prune the live database during automated tests.

---

### Task 9: Scoped dirty-title rebuild

**Files:**
- Create: `database/migrations/2026_07_16_240100_create_catalog_recommendation_dirty_titles.php`
- Create: `app/Models/CatalogRecommendationDirtyTitle.php`
- Create: `app/Services/Catalog/CatalogRecommendationDirtyTitleTracker.php`
- Modify: `app/Services/Seasonvar/SeasonvarCatalogImporter.php`
- Modify: `app/Services/Seasonvar/SeasonvarImportPipeline.php`
- Modify: `app/Services/Catalog/CatalogTitleRecommendationBuilder.php`
- Modify: `tests/Feature/SeasonvarImportMaintenanceTest.php`

**Interfaces:**
- Tracker exposes `mark(int $titleId, string $reason): void`, `ids(int $limit): array`, `forget(array $ids): void`.
- Builder exposes internal `rebuildDirty(?callable $progress = null): array`; no new Artisan command.

- [x] **Step 1: Add failing dirty/scoped tests**

Assert targeted URL import marks returned catalog title; affected neighbours are discovered from shared indexed features; unchanged titles retain row timestamps/hash; dirty set above configured 2,000 falls back to full shadow build.

- [x] **Step 2: Create additive queue table**

Columns: unique `catalog_title_id` FK cascade, bounded `reason`, `marked_at`, timestamps; index `marked_at`. `mark()` uses upsert and never creates duplicates.

- [x] **Step 3: Return target title ID from URL cycle**

Extend the private `runUrlCycle()` result with `catalog_title_id: int|null`. After successful parse/import, call tracker `mark($id, 'targeted-import')`; preserve `targeted_maintenance_skipped=true` for unrelated heavy maintenance.

- [x] **Step 4: Implement affected-neighbour expansion**

For each dirty title collect IDs from the same bounded candidate-generator buckets, cap per source and total. If count exceeds threshold or active feature/algorithm version differs, call full `rebuild()`; otherwise recalculate dirty sources and sources whose active rows target a dirty candidate.

- [x] **Step 5: Avoid unchanged writes**

Hash ordered payload `[candidate_id, rank, score, bucket scores, reasons]`. Within transaction replace only sources whose hash changed. Clear dirty rows only after commit; failure preserves them for retry.

- [x] **Step 6: Verify targeted/full paths**

Run focused import tests with `Http::fake()` and `Http::preventStrayRequests()`. Assert no new public command and bounded query count.

---

### Task 10: Documentation, broad verification and controlled activation

**Files:**
- Modify: `README.md`
- Modify: recommendation owner document selected from `docs/README.md`
- Modify: `CHANGELOG.md`
- Verify: all Task 1–9 files

**Interfaces:**
- Documents active version separately from roadmap/planned personalization.

- [x] **Step 1: Update documentation only after behavior exists**

Document shadow gate, active algorithm version, scoped rebuild, reason contract and operational metrics in the mapped recommendation/importer document. Update Russian README roadmap/history without editing the managed `project-docs` block manually.

- [x] **Step 2: Run formatting and focused suite**

Run:

```bash
./vendor/bin/pint --dirty --format agent
php artisan test tests/Unit/CatalogRecommendationThemeExtractorTest.php tests/Unit/CatalogRecommendationPairScorerTest.php tests/Unit/CatalogRecommendationCandidateGeneratorTest.php tests/Unit/CatalogRecommendationQualityEvaluatorTest.php tests/Unit/CatalogTitleRecommendationBuilderTest.php --compact
php artisan test tests/Feature/CatalogRecommendationBuildActivationTest.php tests/Feature/CatalogRecommendationListTest.php tests/Feature/Api/V1/CatalogRelatedContentTest.php tests/Feature/SeasonvarImportMaintenanceTest.php --compact
npm run build
```

Expected: all commands exit `0`.

- [x] **Step 3: Run full suite**

Run: `php artisan test --compact`

Expected: `0` failures. If unrelated concurrent work fails, preserve complete output and demonstrate the recommendation-focused suite remains green; do not hide the broad failure.

Фактическая проверка 16 июля 2026 года: полный прогон выполнен — 1162 tests passed, 11 skipped и 13 failures в параллельно изменяемых контрактах поиска/шапки. После дополнительных fail-closed regressions изолированный recommendation/UI/importer набор прошёл: 141 tests, 719 assertions; отдельный API-контракт рекомендаций — 1 test, 14 assertions. Падения полного набора не скрыты и не исправлялись в рамках этой задачи.

- [ ] **Step 4: Wait for existing import and run shadow build**

Confirm no active `php artisan seasonvar:import` process. Run the existing import command in its normal supported mode; never kill the current import. Capture build ID, duration, rows, empty count, availability, nDCG, concentration, churn and peak memory.

- [ ] **Step 5: Activate only on gate pass and perform SQL QA**

Verify one active algorithm version, no self-pairs, no unavailable candidates, ranks 1..N without gaps, and no source above configured max rows. Compare the audited sample titles before/after.

- [x] **Step 6: Browser QA**

Use Playwright on desktop/mobile for one title with `v6` rows and one fallback title. Assert 2–4 faithful reasons, no duplicate/current title, no console/network errors and no horizontal overflow.

- [ ] **Step 7: Commit task-scoped changes**

Before each commit verify `main`; include meaningful README change in every product commit as required by the hook. With a dirty shared tree, stage exact patches and never absorb unrelated changes.
