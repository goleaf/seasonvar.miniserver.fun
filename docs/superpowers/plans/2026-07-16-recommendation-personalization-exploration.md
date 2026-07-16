# Recommendation Personalization and Exploration Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Поверх активного similarity `v6` выпустить confidence-aware персонализацию с recency/depth, безопасными отрицательными сигналами, честным fallback и bounded exploration.

**Architecture:** Новый profile builder агрегирует существующую private историю в bounded DTO без user identity; pure candidate scorer нормализует `v6` similarity и суммирует независимые source supports. Query boundary смешивает персональный/public pool по confidence, а отдельный deterministic mixer резервирует до 15% релевантных explore slots. Existing visibility, exclusions, availability, diversity, repeat suppression и result DTO остаются canonical outer boundaries.

**Tech Stack:** PHP 8.5, Laravel 13.19, Eloquent/Query Builder, SQLite, PHPUnit 12.5, Livewire/Blade, Tailwind CSS 4.3, Playwright.

## Global Constraints

- Начинать только после успешной активации content similarity `v6` и совместимой score normalization version.
- Работать только в существующей ветке `main`; не создавать branch или worktree.
- Не добавлять collaborative filtering, embeddings, cross-user profiles или внешние inference API.
- Не добавлять impression/click/play analytics в этот выпуск.
- Private results никогда не попадают в shared cache, URL, Livewire public properties или public API.
- Не раскрывать episode/timecode, названия personal tags/collections, negative profile, user ID или raw score.
- Exact feedback/dropped exclusions сохраняются и имеют приоритет над ranking.
- Candidate обязан пройти canonical visibility и playable-media boundary до personal score.
- Все queries bounded; избегать N+1 и использовать grouped aggregates/owner-first indexes.
- Каждый behavior change выполнять TDD; после PHP changes запускать Pint.
- Видимый текст остаётся русским и проходит translation architecture.
- Каждый product commit включает осмысленный README hunk и не захватывает чужие изменения.

---

### Task 1: Private profile DTO и confidence rules

**Files:**
- Create: `app/Enums/CatalogPersonalizationConfidence.php`
- Create: `app/Enums/CatalogPersonalEvidence.php`
- Create: `app/DTOs/CatalogPersonalSourceSignal.php`
- Create: `app/DTOs/CatalogPersonalPreferenceProfile.php`
- Create: `tests/Unit/CatalogPersonalPreferenceProfileTest.php`
- Modify: `config/recommendations.php`

**Interfaces:**
- Confidence enum: `cold`, `low`, `medium`, `high`.
- Source signal fields: `titleId`, `confidence` (`0..320`), `evidence`, `reasonCodes`, `lastActivityAt`.
- Profile fields: bounded `signals`, `confidence`, `totalConfidence`, and private feature demotions.

- [x] **Step 1: Add failing DTO/confidence tests**

Assert:

```php
$this->assertSame(CatalogPersonalizationConfidence::Cold, CatalogPersonalPreferenceProfile::fromSignals([])->confidence);
$this->assertSame(CatalogPersonalizationConfidence::Low, CatalogPersonalPreferenceProfile::fromSignals([$weak])->confidence);
$this->assertSame(CatalogPersonalizationConfidence::Medium, CatalogPersonalPreferenceProfile::fromSignals([$mediumA, $mediumB])->confidence);
$this->assertSame(CatalogPersonalizationConfidence::High, CatalogPersonalPreferenceProfile::fromSignals([$strongA, $strongB, $strongC])->confidence);
```

Use thresholds exactly: cold has no signal; low has fewer than two source titles or total `<240`; medium has at least two and total `240..599`; high has at least three and total `>=600`.

- [x] **Step 2: Verify RED**

Run: `php artisan test tests/Unit/CatalogPersonalPreferenceProfileTest.php --compact`.

- [x] **Step 3: Implement readonly DTOs and enums**

`CatalogPersonalSourceSignal` validates/clamps confidence in a named factory and unique evidence/reasons. `CatalogPersonalPreferenceProfile::fromSignals()` sorts confidence descending then title ID, takes configured `history_title_limit`, calculates thresholds, and stores no `User`/user ID.

- [x] **Step 4: Add exact personalized_v2 config**

```php
'personalized_v2' => [
    'enabled' => false,
    'rollout_percent' => 0,
    'rollout_seed' => 'personalized-v2',
    'history_limit' => 120,
    'source_confidence_cap' => 320,
    'profile_high_threshold' => 600,
    'recency_half_life_days' => 180,
    'legacy_recency_factor' => 0.5,
    'negative_minimum_sources' => 3,
    'negative_feature_cap' => 120,
    'negative_total_cap' => 240,
    'exploration_ratio' => 0.15,
    'exploration_relevance_floor' => 0.45,
];
```

- [x] **Step 5: Verify GREEN, format and commit**

Run focused unit test and Pint; expected PASS/exit `0`.

---

### Task 2: Aggregate multi-evidence, depth and recency

**Files:**
- Create: `app/Services/Catalog/CatalogPersonalPreferenceProfileBuilder.php`
- Create: `tests/Feature/CatalogPersonalPreferenceProfileBuilderTest.php`
- Modify: `app/Models/EpisodeViewProgress.php` only if a reusable scope is missing

**Interfaces:**
- Produces: `forUser(User $user): CatalogPersonalPreferenceProfile`.
- Reads existing progress, state, collection and personal-tag tables in a bounded constant number of queries.

- [x] **Step 1: Add failing aggregation tests**

Test these independent scenarios:

1. watchlist + rating + progress on the same title combine above each individual evidence but remain `<=320`;
2. rating 10 contributes more than rating 7;
3. recent progress contributes more than equally deep year-old progress;
4. one completed episode of a 20-episode title does not produce completed-title evidence;
5. 10 completed episodes of 20 do;
6. missing semantic timestamp uses factor `0.5` rather than `now()`;
7. query count remains constant between 2 and 40 source titles.

- [x] **Step 2: Verify RED**

Run the new feature test; expected failure: current query keeps only maximum fixed weight.

- [x] **Step 3: Implement recency and diminishing evidence**

Use:

```php
private function recencyFactor(?CarbonImmutable $activity): float
{
    if ($activity === null) {
        return (float) config('recommendations.personalized_v2.legacy_recency_factor', 0.5);
    }

    $days = max(0, $activity->diffInDays(now(), absolute: true));
    $halfLife = max(1, (int) config('recommendations.personalized_v2.recency_half_life_days', 180));

    return max(0.2, min(1.0, 2 ** (-$days / $halfLife)));
}
```

Raw evidence weights:

- watchlist 60;
- planned 50;
- watching 100;
- completed status 150;
- rating `(rating - 6) * 35` for 7–10;
- collection 45;
- personal tag 35;
- meaningful progress `60 + round(100 * depth)`;
- completed-depth bonus 140.

Sort evidence contributions descending; source confidence is first + `0.6*second + 0.35*remaining`, then multiply each item by its own recency factor and cap 320.

- [x] **Step 4: Compute progress depth in grouped queries**

For each title aggregate distinct started episodes, completed episodes, maximum progress and last activity. Load published episode counts for the bounded title IDs with one grouped query. Completed-depth evidence requires:

```php
$required = min(3, max(1, (int) ceil($publishedEpisodes * 0.5)));
$completedDepth = $completedEpisodes >= $required;
```

This permits a one-episode special while preventing one episode from completing a long series profile.

- [x] **Step 5: Preserve negative-source removal and private labels**

Do not add `recommendation_feedback`/dropped titles to positive signals. Collections/tags contribute evidence codes only; never select or store their display names.

- [x] **Step 6: Verify GREEN and query budget**

Run feature test and Pint. Expected: all scenarios PASS and 40-title query count is no more than the 2-title count plus one.

---

### Task 3: Versioned similarity normalization and personal candidate scorer

**Files:**
- Create: `app/DTOs/CatalogRecommendationScoreRange.php`
- Create: `app/Services/Catalog/CatalogRecommendationScoreNormalizer.php`
- Create: `app/Services/Catalog/CatalogPersonalizedCandidateScorer.php`
- Create: `tests/Unit/CatalogRecommendationScoreNormalizerTest.php`
- Create: `tests/Unit/CatalogPersonalizedCandidateScorerTest.php`
- Modify: `app/Models/CatalogRecommendationBuild.php`

**Interfaces:**
- Normalizer: `forActiveBuild(): ?CatalogRecommendationScoreRange`, `normalize(int $score, CatalogRecommendationScoreRange $range): float`.
- Scorer: `score(CatalogPersonalPreferenceProfile $profile, iterable $similarityRows): array` returning existing candidate row shape plus bounded multiple reasons.

- [x] **Step 1: Add failing normalization tests**

Assert min maps to 0, p95 and above map to 1, median remains between; missing/incompatible active build returns `null` and disables v2 personalization.

- [x] **Step 2: Add failing multi-source support tests**

The same candidate supported by three medium sources must outrank a candidate supported by one strong source when normalized content relevance is comparable. A candidate with normalized relevance below `0.2` is rejected even if profile confidence is high.

- [x] **Step 3: Verify RED**

Run both unit files; expected missing classes.

- [x] **Step 4: Store/resolve active range**

Read `score_min`, `score_median`, `score_p95`, algorithm and feature version from active build metrics. Normalize:

```php
return max(0.0, min(1.0, ($score - $range->minimum) / max(1, $range->p95 - $range->minimum)));
```

Reject range when algorithm is not `v6` or p95 <= minimum.

- [x] **Step 5: Implement candidate contributions**

For each source row:

```php
$contribution = (int) round($sourceSignal->confidence * $normalizedSimilarity);
```

Sort contributions per candidate; total personal support is first + `0.65*second + 0.4*remaining`, capped at 1,000. Add normalized content base up to 500. Keep strongest three broad reasons and source count, but never source title IDs in public output.

- [x] **Step 6: Verify GREEN and format**

Run both unit files and Pint; expected PASS.

---

### Task 4: Confidence-aware query and honest public blending

**Files:**
- Modify: `app/Services/Catalog/CatalogPersonalizedRecommendationQuery.php`
- Modify: `app/Services/Catalog/CatalogRecommendationService.php`
- Modify: `app/DTOs/CatalogRecommendationResult.php`
- Create: `tests/Feature/CatalogPersonalizedRecommendationQueryTest.php`
- Modify: `tests/Feature/Api/V1/CatalogDiscoveryTest.php`

**Interfaces:**
- Personalized query consumes profile builder/normalizer/scorer and returns `{candidates, confidence}` through a new readonly `CatalogPersonalizedCandidateSet` DTO.
- Result adds private-safe confidence code only for server-side presentation; API serialization remains explicit.

- [x] **Step 1: Add failing cold/low/medium/high tests**

Expected behavior:

- cold: existing editorial→trending→popular fallback, `personalized=false`, `coldStart=true`;
- low: public candidates retain at least 75% of slots, personal rows may receive bounded boost, display type remains the actual fallback type;
- medium: at least half available slots may be personal;
- high: personal ranking fills first, public only deduplicated fallback;
- all levels: exact exclusions, current/source titles and unavailable media absent.

- [x] **Step 2: Verify RED**

Run focused test; current max-only query cannot satisfy confidence/blend assertions.

- [x] **Step 3: Replace private `signals()` implementation**

Inject profile builder, score normalizer and candidate scorer. Query stored `v6` rows for bounded source IDs/rank <=24, preserve structured reasons, then visibility-filter candidate IDs. If normalizer returns null, return cold set.

- [x] **Step 4: Blend without duplicates**

In service use quotas:

```php
$personalQuota = match ($set->confidence) {
    CatalogPersonalizationConfidence::Cold => 0,
    CatalogPersonalizationConfidence::Low => (int) floor($limit * 0.25),
    CatalogPersonalizationConfidence::Medium => (int) floor($limit * 0.60),
    CatalogPersonalizationConfidence::High => $limit,
};
```

Interleave personal rows at stable positions, then fill from public fallback excluding personal/source/hard-excluded IDs. `personalized=true` only when at least one personal row is actually displayed.

- [x] **Step 5: Verify GREEN and legacy routes**

Run personalized query and discovery API tests. Assert public/contextual title recommendations still ignore authenticated private state by contract.

---

### Task 5: Bounded negative feature demotion

**Files:**
- Create: `app/Services/Catalog/CatalogPersonalNegativePreferenceBuilder.php`
- Create: `tests/Feature/CatalogPersonalNegativePreferenceBuilderTest.php`
- Modify: `app/Services/Catalog/CatalogPersonalPreferenceProfileBuilder.php`
- Modify: `app/Services/Catalog/CatalogPersonalizedCandidateScorer.php`

**Interfaces:**
- Produces private `array<string, int>` feature demotions with stable keys `genre:{id}`, `tag:{id}`, `theme:{code}`.
- Requires at least three independent negative source titles per feature.

- [x] **Step 1: Add failing threshold/decay tests**

Assert one/two dropped crime titles cause no genre demotion; three recent independent titles cause bounded demotion; old negatives weigh less; later positive support reduces but never flips demotion into bonus; exact excluded titles remain absent regardless.

- [x] **Step 2: Verify RED**

Run new feature test; builder does not exist.

- [x] **Step 3: Aggregate only allowlisted content features**

Load at most 120 negative title IDs and reuse `CatalogRecommendationFeatureExtractor`. Count each feature once per source title. Weight by semantic feedback/status timestamp and the same recency function. Ignore actor/director negatives in v1 to avoid penalizing people from sparse evidence.

- [x] **Step 4: Apply caps**

For features with support >=3:

```php
$demotion = min(120, (int) round(30 * $supportWeight));
```

Candidate subtraction is sum of matching demotions capped at 240. Apply after positive content/personal score but before final sort. It cannot remove a candidate; visibility/exact exclusions own removal.

- [x] **Step 5: Verify GREEN and privacy**

Run tests and assert negative feature keys are absent from DTOs serialized by Livewire/API/presenter.

---

### Task 6: Deterministic relevance-bounded exploration

**Files:**
- Create: `app/Services/Catalog/CatalogRecommendationExplorationMixer.php`
- Create: `tests/Unit/CatalogRecommendationExplorationMixerTest.php`
- Modify: `app/Services/Catalog/CatalogRecommendationService.php`
- Modify: `app/Enums/CatalogRecommendationReason.php`
- Modify: `lang/ru/recommendations.php`
- Modify: `lang/en/recommendations.php`

**Interfaces:**
- Produces: `mix(array $exploit, array $explore, int $limit, string $seed): array`.
- Adds reason code `new_for_you` only to selected exploration rows.

- [x] **Step 1: Add failing mixer tests**

Assert limit 12 selects at most one explore row (`floor(12*0.15)`), limit 24 at most three, below-floor rows never selected, duplicates/exclusions never reintroduced, same seed is stable, different seed only changes explore ordering, empty explore returns exploit-only.

- [x] **Step 2: Verify RED**

Run mixer test; expected missing class.

- [x] **Step 3: Implement slot and seed rules**

Filter explore rows by normalized relevance >= configured 0.45. Order by `hash('xxh128', $seed.'|'.$id)` then original score. Compute count `min(count(explore), floor(limit*ratio))`. Insert at evenly distributed indices `floor(($slot + 1) * $limit / ($count + 1))`, replacing lowest exploit rows; final result remains exactly bounded and unique.

- [x] **Step 4: Integrate after exact exclusions/visibility, before diversity**

Build explore pool from watchable public content candidates not already in profile/exploit/recent set. `seed` is existing bounded session/refresh seed; no user ID enters URL/cache. Diversity and repeat suppression run after mixing.

- [x] **Step 5: Verify GREEN and translation**

Run mixer/service tests and assert presenter outputs «Новое для вас» only for selected explore rows.

---

### Task 7: Privacy, cache isolation and rollout flag

**Files:**
- Create: `app/Services/Catalog/CatalogPersonalizationRollout.php`
- Create: `tests/Unit/CatalogPersonalizationRolloutTest.php`
- Modify: `app/Services/Catalog/CatalogRecommendationService.php`
- Modify: `app/Services/Catalog/CatalogRecommendationCache.php`
- Modify: `.env.example`
- Modify: `tests/Feature/CatalogRecommendationPrivacyTest.php`

**Interfaces:**
- Rollout: `enabledFor(User $user): bool` based on config percentage and stable server-side hash.

- [x] **Step 1: Add failing rollout/cache/privacy tests**

Assert 0% uses legacy query, 100% uses v2, same user/version bucket is stable, personalized query never calls shared `TieredCache`, URL/Livewire snapshot/API response contain none of user ID, source title IDs, evidence weights, progress, collection/tag labels or negative keys.

- [x] **Step 2: Verify RED**

Run rollout/privacy tests.

- [x] **Step 3: Implement deterministic rollout**

Use:

```php
$bucket = hexdec(substr(hash('sha256', $seed.'|'.$user->getKey()), 0, 8)) % 100;

return $enabled && $bucket < $percent;
```

Clamp percent `0..100`. Read environment only in `config/recommendations.php`; add `RECOMMENDATIONS_PERSONALIZED_V2_ENABLED=false` and `..._PERCENT=0` to `.env.example`.

- [x] **Step 4: Preserve hard cache boundary**

Keep `CatalogRecommendationCache::rememberPublic()` immediate bypass when user/type/seed is private. Do not add a new cache for profiles in the first release. All candidate arrays are request-local.

- [x] **Step 5: Verify GREEN and security diff**

Run rollout/privacy tests, inspect rendered HTML and cache keys, and Pint changed PHP files.

---

### Task 8: Documentation, broad QA and staged rollout

**Files:**
- Modify: `README.md`
- Modify: recommendation owner document from `docs/README.md`
- Modify: `CHANGELOG.md`
- Verify: Tasks 1–7

**Interfaces:**
- Documents confidence behavior, privacy boundary, exploration cap and fallback honestly; does not claim AI/ML.

- [x] **Step 1: Update project documentation**

Describe `cold/low/medium/high`, recency/depth, exact negative exclusions, bounded demotion, 15% maximum exploration, cache isolation, config flags and rollback. Update visitor history only when rollout becomes visitor-visible.

- [x] **Step 2: Run focused suite**

```bash
./vendor/bin/pint --dirty --format agent
php artisan test tests/Unit/CatalogPersonalPreferenceProfileTest.php tests/Unit/CatalogRecommendationScoreNormalizerTest.php tests/Unit/CatalogPersonalizedCandidateScorerTest.php tests/Unit/CatalogRecommendationExplorationMixerTest.php tests/Unit/CatalogPersonalizationRolloutTest.php --compact
php artisan test tests/Feature/CatalogPersonalPreferenceProfileBuilderTest.php tests/Feature/CatalogPersonalizedRecommendationQueryTest.php tests/Feature/CatalogPersonalNegativePreferenceBuilderTest.php tests/Feature/CatalogRecommendationPrivacyTest.php tests/Feature/Api/V1/CatalogDiscoveryTest.php --compact
npm run build
```

Expected: all commands exit `0`.

- [x] **Step 3: Run full PHPUnit suite**

Run: `php artisan test --compact`

Expected: `0` failures, or explicitly documented unrelated concurrent failures with the complete focused suite still green.

Фактическая проверка 16 июля 2026 года: полный прогон выполнен — 1162 tests passed, 11 skipped и 13 failures в параллельно изменяемых контрактах поиска/шапки. После дополнительных fail-closed regressions изолированный personal/recommendation/UI/importer набор прошёл полностью: 141 tests, 719 assertions; Playwright с принудительно включённым v2 прошёл 3/3 viewport без console/network ошибок, дублей или горизонтального переполнения.

- [x] **Step 4: Browser QA scenarios**

Use deterministic fixtures for guest, cold, low, high, feedback-hidden and refreshed sessions. Check mobile/desktop, labels, no duplicates, no unavailable rows, no console/network errors, no PII in DOM/URL/Livewire payload.

- [ ] **Step 5: Roll out 0→internal fixture→10→50→100 percent**

At each step record personalized share, fallback share, exact-exclusion violations (must remain 0), watchable rate (100%), repeat rate, explore floor violations (0), reason faithfulness and errors. Roll back to 0% on any privacy/exclusion/availability regression; content `v6` remains active.

- [ ] **Step 6: Remove legacy max-only branch later**

After 100% stable rollout and one full observation window, remove old `rememberSignal()`/fixed weight path in a separate TDD commit. Do not combine cleanup with initial activation.

- [ ] **Step 7: Commit only task-scoped changes**

Verify `main`, README policy and `git diff --check`; stage exact hunks because shared worktree contains unrelated modifications.
