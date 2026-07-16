# Recommendation v3 List Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Пересобрать рекомендации каждого публичного тайтла на основе сильных relations и тем описания, а блок «Советуем посмотреть» вывести единым вертикальным списком с широкими постерами.

**Architecture:** `CatalogRecommendationThemeExtractor` формирует bounded semantic profile без сети. `CatalogTitleRecommendationBuilder` использует packed relation/theme candidate maps, inverse-frequency scoring, отдельный relevance gate и bounded MMR reranking, после чего сохраняет `v3`. `CatalogTitlePageBuilder` нормализует precomputed/fallback строки в DTO, а Blade рендерит один list layout через существующие poster/title components.

**Tech Stack:** PHP 8.5, Laravel 13.19, Eloquent/SQLite, Blade, Livewire 4, Tailwind CSS 4.3, PHPUnit 12.5, Playwright managed Chromium.

## Global Constraints

- Работать только в существующей ветке `main`; не создавать branch или worktree.
- `php artisan seasonvar:import` остаётся единственной публичной командой импорта.
- Не добавлять production dependency и не выполнять HTTP во время recommendation rebuild.
- Candidate без опубликованного публичного media не сохраняется.
- Контроллеры и Blade не получают database queries; пользовательский текст остаётся русским.
- Сохранять bounded candidate pool и профильный memory budget worker `256M`/recycle `192M`.
- Все production PHP changes проходят test-first red-green-refactor и Pint.
- Не включать в commit параллельные пользовательские изменения рабочего дерева.

---

### Task 1: Bounded semantic themes

**Files:**
- Create: `app/Services/Catalog/CatalogRecommendationThemeExtractor.php`
- Create: `tests/Unit/CatalogRecommendationThemeExtractorTest.php`

**Interfaces:**
- Produces: `extract(?string $title, ?string $originalTitle, ?string $description): array<string, string>` where keys are stable English theme IDs and values are short Russian labels.
- Constraints: normalized lowercase Unicode text, `ё→е`, maximum eight returned themes, no I/O.

- [ ] **Step 1: Write failing extractor tests**

Cover the target description and false positives:

```php
public function test_it_extracts_relationship_themes_for_aynen_aynen(): void
{
    $themes = app(CatalogRecommendationThemeExtractor::class)->extract(
        'Именно так',
        'Aynen Aynen',
        'Двое молодых людей с юных лет являются друзьями. Между ними появляются чувства и начинается большая любовь.',
    );

    $this->assertArrayHasKey('romance', $themes);
    $this->assertArrayHasKey('relationships', $themes);
    $this->assertArrayHasKey('friendship', $themes);
    $this->assertArrayHasKey('youth', $themes);
}

public function test_it_does_not_treat_a_detective_or_a_man_as_family_or_marriage(): void
{
    $themes = app(CatalogRecommendationThemeExtractor::class)->extract(
        'Детектив',
        null,
        'Мужчина расследует преступление и ищет убийцу.',
    );

    $this->assertArrayNotHasKey('family', $themes);
    $this->assertArrayNotHasKey('relationships', $themes);
    $this->assertArrayHasKey('crime', $themes);
}
```

- [ ] **Step 2: Verify RED**

Run: `php artisan test tests/Unit/CatalogRecommendationThemeExtractorTest.php`

Expected: FAIL because `CatalogRecommendationThemeExtractor` does not exist.

- [ ] **Step 3: Implement the extractor**

Use one constant map whose entries contain `label` and Unicode regex patterns. The public method concatenates the three inputs, normalizes through `Str`, evaluates patterns in declaration order, and returns `array_slice($themes, 0, 8, true)`. Required keys are:

```php
private const THEMES = [
    'romance' => ['label' => 'Романтика', 'pattern' => '/(?:любов\p{L}*|влюб\p{L}*|романтическ\p{L}*|чувств\p{L}*|свидан\p{L}*)/u'],
    'relationships' => ['label' => 'Отношения', 'pattern' => '/(?:отношен\p{L}*|супруг\p{L}*|жених\p{L}*|невест\p{L}*|семейной пары|молодая пара)/u'],
    'friendship' => ['label' => 'Дружба', 'pattern' => '/(?:дружб\p{L}*|друзья|друзей|друг с другом|близкими друзьями)/u'],
    'youth' => ['label' => 'Молодые герои', 'pattern' => '/(?:молод\p{L}*|подрост\p{L}*|юнош\p{L}*|юных лет)/u'],
    'family' => ['label' => 'Семья', 'pattern' => '/(?:семь\p{L}*|родител\p{L}*|ребен\p{L}*|детей|сынов\p{L}*|дочер\p{L}*)/u'],
    'workplace' => ['label' => 'Работа', 'pattern' => '/(?:работ\p{L}*|офис\p{L}*|карьер\p{L}*|бизнес\p{L}*)/u'],
    'school' => ['label' => 'Учёба', 'pattern' => '/(?:школ\p{L}*|лице\p{L}*|университет\p{L}*|студент\p{L}*)/u'],
    'medical' => ['label' => 'Медицина', 'pattern' => '/(?:врач\p{L}*|больниц\p{L}*|медицин\p{L}*|пациент\p{L}*)/u'],
    'legal' => ['label' => 'Право', 'pattern' => '/(?:адвокат\p{L}*|юрист\p{L}*|судебн\p{L}*|прокурор\p{L}*)/u'],
    'crime' => ['label' => 'Преступление', 'pattern' => '/(?:преступ\p{L}*|убий\p{L}*|расслед\p{L}*|детектив\p{L}*|криминал\p{L}*)/u'],
    'mystery' => ['label' => 'Тайна', 'pattern' => '/(?:тайн\p{L}*|загад\p{L}*|мистическ\p{L}*)/u'],
    'fantasy' => ['label' => 'Фэнтези', 'pattern' => '/(?:маг\p{L}*|волшеб\p{L}*|фэнтези|сказочн\p{L}*)/u'],
    'supernatural' => ['label' => 'Сверхъестественное', 'pattern' => '/(?:вампир\p{L}*|оборот\p{L}*|призрак\p{L}*|сверхъестествен\p{L}*)/u'],
    'science_fiction' => ['label' => 'Фантастика', 'pattern' => '/(?:космическ\p{L}*|инопланет\p{L}*|робот\p{L}*|будущ\p{L}*|научн\p{L}* фантаст)/u'],
    'historical' => ['label' => 'История', 'pattern' => '/(?:историческ\p{L}*|император\p{L}*|королев\p{L}*|древн\p{L}*|средневек\p{L}*)/u'],
    'military' => ['label' => 'Военная тема', 'pattern' => '/(?:военн\p{L}*|войн\p{L}*|солдат\p{L}*|арм\p{L}*)/u'],
    'adventure' => ['label' => 'Приключения', 'pattern' => '/(?:приключ\p{L}*|путешеств\p{L}*|экспедиц\p{L}*)/u'],
    'sports' => ['label' => 'Спорт', 'pattern' => '/(?:спорт\p{L}*|футбол\p{L}*|баскетбол\p{L}*|соревнован\p{L}*)/u'],
    'music' => ['label' => 'Музыка', 'pattern' => '/(?:музык\p{L}*|пев\p{L}*|песн\p{L}*|оркестр\p{L}*)/u'],
    'show_business' => ['label' => 'Шоу-бизнес', 'pattern' => '/(?:актер\p{L}*|актрис\p{L}*|съем\p{L}*|телевиден\p{L}*|шоу-бизнес)/u'],
    'everyday_life' => ['label' => 'Повседневная жизнь', 'pattern' => '/(?:повседнев\p{L}*|обычной жизн\p{L}*|бытов\p{L}*|житейск\p{L}*)/u'],
];
```

- [ ] **Step 4: Verify GREEN and format**

Run: `php artisan test tests/Unit/CatalogRecommendationThemeExtractorTest.php`

Run: `./vendor/bin/pint --dirty --format agent app/Services/Catalog/CatalogRecommendationThemeExtractor.php tests/Unit/CatalogRecommendationThemeExtractorTest.php`

Expected: all extractor tests pass and Pint exits `0`.

---

### Task 2: Recommendation scoring v3

**Files:**
- Modify: `app/Services/Catalog/CatalogTitleRecommendationBuilder.php`
- Modify: `app/Models/CatalogTitleRecommendation.php`
- Modify: `config/seasonvar.php`
- Test: `tests/Unit/CatalogTitleRecommendationBuilderTest.php`

**Interfaces:**
- Consumes: `CatalogRecommendationThemeExtractor::extract()`.
- Produces: existing `rebuild(?callable $progress = null): array` with `algorithm_version=v3` and unchanged summary keys.
- Internal profiles add `themes: list<string>`; candidate maps add `theme`, `theme+country`, and `theme+genre` packed IDs.

- [ ] **Step 1: Write failing ranking tests**

Add tests proving:

```php
public function test_relationship_comedy_ranks_above_unrelated_title_with_shared_actor(): void
{
    config(['seasonvar.recommendations.min_score' => 300]);
    // Create source: Turkish 2019 comedy, relationship/friendship description.
    // Create topical: Turkish comedy with romance/relationship description and published media.
    // Create actor-only: Turkish supernatural drama sharing one actor and published media.
    // Attach the same source actor only to actor-only and rebuild.
    // Assert algorithm_version v3 and topical rank 1 before actor-only.
}

public function test_candidate_quality_without_shared_relevance_is_not_a_source_signal(): void
{
    // Source and candidate share only broad type; candidate has many ratings/reviews/media signals.
    // Rebuild and assert no stored pair for them.
}

public function test_diversity_reranking_keeps_the_highest_relevance_first(): void
{
    // Three candidates share source themes; highest raw score must remain rank 1.
    // Lower candidates may swap only within configured bounded penalty.
}
```

- [ ] **Step 2: Verify RED**

Run: `php artisan test tests/Unit/CatalogTitleRecommendationBuilderTest.php`

Expected: new assertions fail because results are `v2` and actor/generic source signals still dominate.

- [ ] **Step 3: Implement compact theme candidate maps**

Inject `CatalogRecommendationThemeExtractor`, select `title`, `original_title`, and `description` in `compactProfileIndex()`, store themes in the encoded profile, count relation/theme document frequency, and append packed IDs for exact and composite theme keys. Reset all new mutable maps in `finally`.

Keep `CANDIDATE_FEATURE_TYPES` bounded. Extend `candidateIds()` to accumulate deterministic seed scores from single theme, `theme|country:{id}`, and `theme|genre:{id}` maps, still trimming to `candidate_limit * 4` and returning no more than `candidate_limit` IDs.

- [ ] **Step 4: Implement relevance and quality buckets**

Set `ALGORITHM_VERSION = 'v3'`. Replace fixed contributions with:

```php
private const MATCH_WEIGHTS = [
    'genre' => 180,
    'tag' => 220,
    'director' => 280,
    'actor' => 230,
    'network' => 200,
    'studio' => 200,
    'translation' => 45,
    'status' => 35,
    'country' => 80,
    'age_rating' => 20,
];

private const THEME_WEIGHTS = [
    'romance' => 260,
    'relationships' => 240,
    'friendship' => 160,
    'family' => 150,
    'youth' => 100,
];
```

Unlisted themes use base `180`. Multiply contributions by `1 + min(1.5, log10(1 + profileCount / documentFrequency))`. Cap repeated relation contributions at three shared IDs.

Require at least one strong shared feature: theme, tag, actor, director, network, or studio. Apply `min_score` to `metadata_score + source_score`; add quality only after the relevance gate. Quality is `60..100` for published media, `0..50` rating, and `0..30` reviews.

`sourceSignalScore()` compares only identical allowlisted provider-related keys and never adds `candidateSignalScore`. Generic `rating`, `page_quality`, `release_year`, and taxonomy copies contribute `0`.

- [ ] **Step 5: Implement bounded MMR and reason labels**

Add `diversity_penalty` to `config/seasonvar.php` with default `120`. Rank greedily by `score - round(maxJaccardOverlap * diversityPenalty)`, deterministic ties by raw score then `recommended_title_id`. Remove internal diversity keys before insert.

In `CatalogTitleRecommendation::reasonLabels()`, sort reasons by stored `score` descending, map `theme_*` keys through stable Russian labels, omit `type`, and return at most four unique strings.

- [ ] **Step 6: Verify GREEN, regression and formatting**

Run: `php artisan test tests/Unit/CatalogTitleRecommendationBuilderTest.php`

Run: `./vendor/bin/pint --dirty --format agent app/Services/Catalog/CatalogTitleRecommendationBuilder.php app/Models/CatalogTitleRecommendation.php config/seasonvar.php tests/Unit/CatalogTitleRecommendationBuilderTest.php`

Expected: all builder tests pass; existing public visibility, max-per-title, memory chunk and media-boundary tests remain green.

---

### Task 3: One recommendation presentation collection

**Files:**
- Create: `app/DTOs/CatalogRecommendationListItem.php`
- Modify: `app/Services/Catalog/CatalogTitlePageBuilder.php`
- Create: `tests/Feature/CatalogRecommendationListTest.php`

**Interfaces:**
- Produces: `CatalogRecommendationListItem(CatalogTitle $title, int $rank, array $reasonLabels, ?int $score)`.
- `CatalogTitlePageBuilder::data()` returns `recommendationItems: Collection<int, CatalogRecommendationListItem>` and no longer exposes separate genre/year collections to Blade.

- [ ] **Step 1: Write failing page-builder tests**

Create ranked recommendations and assert `recommendationItems` preserves rank, reason labels, score, public visibility and the configured limit. Create a title without precomputed rows and assert genre/year fallbacks are merged by title ID, have consecutive ranks and do not duplicate a title matching both conditions.

- [ ] **Step 2: Verify RED**

Run: `php artisan test tests/Feature/CatalogRecommendationListTest.php`

Expected: FAIL because `CatalogRecommendationListItem` and `recommendationItems` do not exist.

- [ ] **Step 3: Implement DTO and builder boundary**

The DTO is `final readonly`, declares strict types, and validates presentation state through typed constructor parameters. In `CatalogTitlePageBuilder`, query precomputed rows first. Only when empty, run existing genre/year queries, merge in insertion order, accumulate labels `Похожий жанр` and `Тот же год`, unique by title ID, take `recommendationDisplayLimit()`, and assign ranks `1..N`.

- [ ] **Step 4: Verify GREEN and format**

Run: `php artisan test tests/Feature/CatalogRecommendationListTest.php`

Run: `./vendor/bin/pint --dirty --format agent app/DTOs/CatalogRecommendationListItem.php app/Services/Catalog/CatalogTitlePageBuilder.php tests/Feature/CatalogRecommendationListTest.php`

Expected: page-builder tests pass and query boundaries remain eager-loaded.

---

### Task 4: Recommendation list and wide poster layout

**Files:**
- Modify: `app/View/Components/Catalog/TitleCard.php`
- Modify: `app/View/Components/Ui/PosterCard.php`
- Modify: `app/View/Components/Ui/PosterFrame.php`
- Create: `resources/views/components/catalog/title-card-recommendation.blade.php`
- Modify: `resources/views/components/ui/poster-card.blade.php`
- Modify: `resources/views/components/ui/poster-frame.blade.php`
- Modify: `resources/views/livewire/catalog-title-detail.blade.php`
- Test: `tests/Feature/CatalogRecommendationListTest.php`
- Modify: `tests/Feature/CatalogPageTest.php`

**Interfaces:**
- `x-catalog.title-card` accepts layout `recommendation`, `rank`, and `reason-labels`.
- `x-ui.poster-card` accepts layout `recommendation`.
- `x-ui.poster-frame` accepts `overscan` boolean; recommendation passes `false`.

- [ ] **Step 1: Write failing markup assertions**

Assert the rendered title page contains exactly one `data-recommendation-list`, recommendation rows in rank order, `data-ui-poster-layout="recommendation"`, `aspect-[16/10]`, reason labels and descriptions. Assert old section labels `Ближайшие совпадения`, `По похожим жанрам` and `За {year} год` are absent.

- [ ] **Step 2: Verify RED**

Run: `php artisan test tests/Feature/CatalogRecommendationListTest.php --filter=title_page`

Expected: FAIL because the current Blade renders three grid/compact topologies.

- [ ] **Step 3: Add component layouts**

Add `recommendation` to `TitleCard::LAYOUTS` and render `components.catalog.title-card-recommendation`. Its `PosterCard` layout uses:

```php
'recommendation' => 'grid grid-cols-[7rem_minmax(0,1fr)] gap-3 p-3 hover:bg-emerald-50/60 sm:grid-cols-[11rem_minmax(0,1fr)] sm:gap-4 sm:p-4',
```

The media class is `relative aspect-[16/10] w-full self-start overflow-hidden rounded-control`; body class is `min-w-0`. Recommendation root has no card border, radius or shadow because the parent list owns grouping.

`PosterFrame` uses `scale-[1.02] object-cover object-center` when `$overscan` is true and `object-cover object-center` when false. The new title-card view renders rank, title, original title, type/year, max four reason pills, counts and `line-clamp-2` description with one stretched title link.

- [ ] **Step 4: Replace the panel body with one ordered list**

Render:

```blade
@if ($recommendationItems->isNotEmpty())
    <ol class="divide-y divide-slate-200" data-recommendation-list>
        @foreach ($recommendationItems as $recommendationItem)
            <li wire:key="title-recommendation-{{ $title->id }}-{{ $recommendationItem->title->id }}" data-recommendation-row>
                <x-catalog.title-card
                    :title="$recommendationItem->title"
                    layout="recommendation"
                    :rank="$recommendationItem->rank"
                    :reason-labels="$recommendationItem->reasonLabels"
                />
            </li>
        @endforeach
    </ol>
@else
    <div class="p-4 text-sm text-slate-500">{{ __('catalog.title.recommendations_missing') }}</div>
@endif
```

Remove the precomputed/fallback grid branches. Update old feature expectations to the single-list copy.

- [ ] **Step 5: Verify UI tests, format and build**

Run: `php artisan test tests/Feature/CatalogRecommendationListTest.php tests/Feature/CatalogPageTest.php --filter=title_page`

Run: `./vendor/bin/pint --dirty --format agent app/View/Components/Catalog/TitleCard.php app/View/Components/Ui/PosterCard.php app/View/Components/Ui/PosterFrame.php`

Run: `npm run build`

Expected: tests pass, Vite build exits `0`, and generated assets contain no compilation warning.

---

### Task 5: Documentation, production rebuild and QA

**Files:**
- Modify: `docs/importer.md`
- Modify: `docs/views.md`
- Modify: `docs/UI_STANDARDS.md`
- Modify: `docs/performance.md`
- Modify: `CHANGELOG.md`
- Modify: `docs/MAINTENANCE_LOG.md`

**Interfaces:**
- Documents algorithm `v3`, local thematic profiles, strong relevance gate, MMR penalty, single list layout, landscape poster exception and measured production metrics.

- [ ] **Step 1: Update documentation**

Record that rebuild remains full/queued only, has no HTTP, uses packed bounded maps, and may intentionally omit weak pairs. Document `recommendation` as the only landscape `PosterFrame` variant with `overscan=false`; global catalog cards remain unchanged.

- [ ] **Step 2: Run focused and full verification**

Run:

```bash
php artisan test tests/Unit/CatalogRecommendationThemeExtractorTest.php tests/Unit/CatalogTitleRecommendationBuilderTest.php tests/Feature/CatalogRecommendationListTest.php
./vendor/bin/pint --dirty --format agent
php artisan test
npm run build
git diff --check
```

Expected: all commands exit `0`; skipped tests are reported separately from failures.

- [ ] **Step 3: Back up the live SQLite database**

Use SQLite online backup to `database/database.sqlite.before-recommendations-v3-20260713`, then run `PRAGMA quick_check` against the backup. Do not copy the live file with a raw filesystem copy while writers are active.

- [ ] **Step 4: Run the production rebuild through the existing service**

Invoke `CatalogTitleRecommendationBuilder::rebuild()` inside Tinker with progress output, under the configured importer memory limit. Capture `titles`, coverage, stored rows, average recommendations, duration and algorithm version. Query `Именно так` rank order and confirm no current-title duplicate or unavailable candidate.

- [ ] **Step 5: Browser QA**

Use managed Chromium at `1440×1200`, `768×1024`, and `390×844`. Capture screenshots under `output/playwright/` and verify status `200`, h1, panel heading, no horizontal overflow, no console/page errors, no failed local assets, one recommendation list, rank order and wide poster frames.

Run the impeccable layout assessment first, then:

```bash
node .agents/skills/impeccable/scripts/detect.mjs --json --scope layout resources/views/livewire/catalog-title-detail.blade.php resources/views/components/catalog/title-card-recommendation.blade.php resources/views/components/ui/poster-card.blade.php resources/views/components/ui/poster-frame.blade.php
```

Expected: unresolved detector findings `0`; any external media failure is reported separately.

- [ ] **Step 6: Commit only task files**

Check `git status --short --branch`, stage the exact files from this plan, inspect `git diff --cached --name-status` and `git diff --cached --check`, then commit on `main` with `feat: improve catalog title recommendations`. Do not stage parallel security, MCP, catalog-filter or unrelated documentation changes.

---

## Task 18 continuation: canonical recommendation and discovery architecture

This section supersedes only the unfinished execution notes above. It preserves the shipped similarity v4 rows and API contract while extending them through one canonical orchestration boundary. The repository audit was performed on 2026-07-16 before implementation; the installed runtime is Laravel 13.20.0, Livewire 4.3.3, PHP 8.5 and SQLite.

### Audited baseline

- The only recommendation route is `GET /api/v1/titles/{titleSlug}/recommendations` (`api.v1.titles.recommendations`). The title page embeds one similarity row; there is no web discovery route, private recommendation route or legacy/localized recommendation route.
- `CatalogTitleRecommendationBuilder` v4 is the only stored similarity builder. It uses bounded relation/theme candidate maps, a strong-feature gate, deterministic ranking and MMR-style diversity. The import finalizer performs a full rebuild. The title page has a genre/year fallback.
- Production-shaped SQLite contains 32,932 visible titles, 385,979 v4 rows for 32,508 sources and 627,095 provider signals. There are no self-relations, duplicate ranks, deleted targets or hidden targets. A total of 424 visible sources have no stored row.
- The fallback and `CatalogTitleRecommendation::reasonLabels()` contain Russian display text in PHP. Recommendation explanations are therefore not locale-safe.
- `CatalogSort::Popularity` sorts by published media and episode counts. It is availability ordering, not popularity. There is no trending query, time decay or use of `updated_at` as activity.
- Portal behavior exists for watchlist, portal rating and meaningful episode progress. The current database has one rating state and no progress/watchlist activity. There is no blacklist, not-interested or dropped-state field.
- Collections and private personal tags exist. Featured approved editorial collections are the existing editorial-content boundary, but the current database contains no collection rows. There are no favorite-genre/profile models.
- Per-user region or premium entitlement models do not exist. The canonical access boundary currently covers publication, soft deletion, availability windows and public/authenticated audience. Audio/subtitle language entities and creator/writer relations also do not exist; quality, subtitle availability, translation studio, actor and director filters do.
- There is no release-calendar entity. Future `episodes.released_at` and future title years are the only truthful upcoming signals; the audited database currently contains neither.
- The public recommendation API is cacheable. It must remain public/content-contextual; private signals must never be added to this response or its global cache.
- Existing homepage snapshots cache locale-aware public scalar ID lists. User card state is loaded afterward. Recommendation caches already have a dedicated version domain and fresh/stale windows.
- Existing routes, title binding, search/filter state, collection editor, personal tags, review/comment policies, importer signals and serial-card components are compatibility dependencies and must not be duplicated.

### Canonical target and stable identities

- `CatalogRecommendationService` is the sole page/API orchestration boundary. The existing v4 builder remains the precomputed `similar` provider, not a competing system.
- Stable types are limited to implemented behavior: `personalized`, `similar`, `related`, `editorial`, `trending`, `popular`, `top_rated`, `recently_added`, `recently_updated`, `upcoming` and `random`.
- Stable sources are limited to verified signals: user history, watchlist, collections, personal tag assignments, content similarity, editorial collections, recent portal activity, portal/provider ratings, catalogue publication/content events, release dates, random selection and imported provider similarity.
- A server-only context owns authenticated identity, locale, current title, access audience, filters, bounded result limit, hard exclusions and recent-display suppression. URLs never contain user IDs, private history, blacklist state or candidate lists.
- Presentation DTOs carry localized reason keys/parameters and card-ready titles. Scores remain internal and are never presented as percentages or ratings.

### Ranking, visibility and privacy rules

- Every pool starts from `CatalogTitleQuery::visibleTo()`. Watchable sections additionally require a published, healthy and currently available media source. Upcoming rows are explicitly non-playable until that boundary is met.
- Current title, self-relations and duplicate canonical IDs are hard exclusions. `not_interested` and `blacklisted` are stored additively on the existing one-row-per-user/title state and excluded from all private/contextual recommendations. Feedback is authenticated, authorized, idempotent, POST/Livewire-only and undoable.
- Meaningful history is bounded to recent title IDs and excludes accidental playback; completed/currently-watching states are derived from canonical episode progress. General discovery excludes current watching and completed titles unless a meaningful later release exists. A future explicit dropped state is not inferred from short playback.
- Personal ranking uses only real portal signals. No history yields a cold-start mix of actual watchlist/collection/tag signals when present, otherwise public editorial/trending/popular rows with public explanations. Public fallback is never labelled personalized.
- Similarity continues to use v4 strong metadata and trusted provider signals. Explicit related records distinguish directional sequel/prequel/spin-off/remake from computed similarity and are shown first. Editorial rows never bypass access or user exclusions.
- Popularity combines bounded lifetime portal interest/meaningful starts and one rating source at a time; it never uses raw page views alone. Trending uses recent 7-day portal events and is distinct from lifetime popularity. Empty recent activity yields an honest empty/fallback public section, not fabricated trending.
- Top-rated uses one requested stable source and a minimum vote count. IMDb, Kinopoisk and portal ratings are not averaged together.
- Diversity and repeat suppression operate after relevance: deterministic tie-breaking, bounded candidate pools, configurable per-primary-genre/actor limits and a bounded session history. The catalogue is never scored wholly in PHP and random discovery never uses unbounded `ORDER BY RANDOM()`.
- Private explanations are broad and truthful. They do not include progress timestamps, episode numbers, personal tag names or private collection names. Private result IDs are not globally cached; public caches contain scalar IDs only and include type, locale, public access class, period, filter/sort/page and recommendation version.

### Compatibility and migration plan

- Keep `catalog_title_recommendations`, its pair uniqueness, algorithm codes, API route/name and existing cache domain. Replace hardcoded label generation with a translator-backed presenter without changing stored reason keys.
- Add an additive explicit relation table rather than placing locked editorial rows in the builder-owned table. It stores source/target, stable relation type/source, priority, lock/active state and provider provenance; service validation rejects self-relations, invalid inverse/cycles and inaccessible targets.
- Add additive recommendation feedback/version/timestamp columns to `catalog_title_user_states`, preserving its unique user/title row and all watchlist/rating values. No destructive backfill is needed; null means no feedback.
- Reuse approved featured editorial collections as editorial sections. Their public UUID is stable independently of localized titles/slugs and item position is already deterministic and unique.
- Add targeted indexes only for the exact relation lookup, feedback exclusion, recent activity and release-event queries. All migrations must be reversible and SQLite-compatible.
- Importer v4 rows and provider signals stay idempotent. Explicit locked relations are stored separately, so full imports cannot overwrite them. Title merge/delete services must move/dedupe or hide explicit relations and invalidate the recommendation domain.
- Rolling-deploy fallback: schema capability checks retain the old similarity API/title block until additive tables/columns exist. Cache failure falls back to bounded database queries; no queue, scheduler or external service becomes mandatory.

### Phased implementation checklist

- [x] Add stable enums, context/item/explanation DTOs, translated presenter and central configuration.
- [x] Add additive feedback and explicit-relation schema/models/policies/services with merge/delete handling.
- [x] Add centralized visibility, hard-exclusion, diversity, repeat-suppression and scalar-ID cache services.
- [x] Add public queries for popular, trending, top-rated, recently added/updated, upcoming, editorial and efficient filtered random discovery.
- [x] Add bounded personalized candidate generation from real progress/watchlist/collection/tag/rating signals with honest cold start.
- [x] Route title-page related/similar and the legacy API through the canonical service while preserving response shape.
- [x] Add one discovery route/page with validated stable URL state, public/private SEO policy, pagination, refresh and feedback/undo.
- [x] Integrate lightweight sections into home, search empty state, library and calendar-compatible release views only where the corresponding feature exists.
- [x] Add authorized relation administration within the existing catalogue administration surface; reuse collection administration for editorial sections.
- [x] Add complete `ru`/`en` recommendation translations, localized reasons, accessibility/loading/empty/error states and mobile-first reusable cards.
- [x] Extend canonical SEO/sitemap generation only with non-empty stable public discovery types; keep personalized/random/filter state noindex and out of sitemap.
- [x] Update architecture/data/cache/security/performance/view/SEO/API documentation and the English changelog.

### Files expected to change

- Recommendation/catalog domain classes under `app/Enums`, `app/DTOs`, `app/Services/Catalog`, related models/policies and existing merge/cache/import integration points.
- Additive migrations, routes/controllers/requests, one Livewire discovery component, existing title/home/library/search views and `lang/en`, `lang/ru` catalogues.
- Relevant topic-owner Markdown files, this plan and `CHANGELOG.md`.

### Files and contracts that must remain unchanged

- The public import command, v4 stored row identity, current API recommendation route/name/field shape, title slug binding and existing catalogue/search/filter route codes.
- External media URL-only storage, current collection/tag/history/progress ownership, public cache keys already consumed by unrelated pages and all existing user data.
- Unrelated staged operational audit changes present before task 18.

### Rollback considerations

- Web integration can be rolled back while additive feedback/relation data remains inert and preserved.
- Down migration removes only new relation/feedback structures and never touches v4 rows, user watchlist/rating/progress or editorial collections.
- Versioned public keys make stale discovery lists unreachable without flushing unrelated cache domains. Private results are reconstructed from authoritative rows.
- If recent-signal queries or cache stores fail, public recently-added/similar lists remain available; authenticated users receive the same honest public fallback.

### Final verification checklist (no new or existing automated tests run for task 18)

- [x] Inspect every changed file and all directly related routes, bindings, models, relations, policies, import/merge and cache invalidators.
- [x] Run Pint for changed PHP, PHP syntax checks, static analysis already configured by the project, route/schema/query-plan inspection, migration dry inspection and `git diff --check`.
- [x] Exercise public/private/cold-start/feedback/undo/random/related/similar queries against the configured and disposable SQLite schemas without invoking the automated test runner; mutations were verified through authorization/validation/idempotent write-path inspection rather than changing a real user's state.
- [x] Run Vite build and browser smoke checks at phone/tablet/desktop widths, including keyboard/focus, no horizontal overflow, console/network errors and no private HTML/URL/cache leakage.
- [x] Verify public versus private cache dimensions, noindex/canonical/hreflang/sitemap policy, translation key parity and accessibility names.
- [x] Re-read the full task, record any unavailable portal capability as a verified limitation rather than a fabricated implementation, update all owner docs and changelog, then commit only task 18 changes on `main` and push.

### Implementation evidence, 2026-07-16

- [x] Canonical service/query/DTO/enum boundaries, public/private fallback, filters, visibility, feedback, diversity, repeat suppression and truthful explanations were statically inspected.
- [x] Additive migration applied successfully to configured SQLite and separately to `/tmp/seasonvar-task18-schema.sqlite`; exact table/index list was inspected. Project command policy prohibited even a disposable `migrate:rollback`, so `down()` was inspected rather than bypassing that guard.
- [x] Uncached route inspection resolves canonical, localized, legacy and existing API names. Public read-only smoke returned distinct IDs for trending, popular, top-rated, recently-added, recently-updated and seeded random. Empty editorial/upcoming results reflect the audited empty source data.
- [x] Live cold diagnostic observations on 32.9k titles: trending 4.255s, popular 5.686s, top-rated 4.434s, recently-added 4.200s, recently-updated 8.953s and seeded random 7.167s. These include cold title hydration/facet/cache work and are not p95/SLA claims.
- [x] The current relation table contains zero legacy anomalies after additive creation; existing 385,555 stored similarity rows remain algorithm v4 and readable. Code v5 is used at the next canonical full import rebuild, preserving old data until atomic per-title replacement.
- [x] Dedicated region/premium, audio/subtitle-language, creator/writer/billing, favorite-genre profile, release-calendar entity and recommendation analytics were confirmed absent and are documented as limitations rather than simulated features.
- [x] Complete Pint/lint/OpenAPI/translation/build/browser/diff verification.
- [x] Re-read the task, finish owner docs/changelog, inspect staged scope, commit task-only work on `main` and push.

Fresh final evidence: explicit task-file Pint returned `passed`; PHP syntax covered 42 PHP files; targeted Larastan and the repository `composer analyse` gate both returned zero errors; recommendation locale parity is 193 keys; OpenAPI JSON and `project:docs-refresh --check` passed; Vite 8.1.4 produced the production bundle. SQLite `PRAGMA quick_check` returned `ok`, relation/feedback queries selected their focused indexes, and route inspection preserved canonical/localized/legacy/API names.

Managed Chromium exercised public trending/popular/top-rated/random, English locale, anonymous personalized cold fallback, homepage, title detail and search no-result flows. Desktop `1440×1000`, tablet `768×1024` and phone `390×844` returned 200 with no page overflow or browser errors. Public pages exposed canonical/hreflang/ItemList metadata, personalized/random were `noindex`, sitemap static included only eligible public discovery URLs, title detail showed 12 recommendation rows without the current title, and axe reported no serious/critical violations after the two shared title controls received stronger contrast. Browser smoke also found and closed an unrelated title-page blocker in download filename sanitation; the safe slash/control-character normalization now renders the same title route successfully.

## Follow-up: cold-path hardening, 2026-07-16

> **For agentic workers:** execute inline on the existing `main`; project rules prohibit another branch/worktree and the active session does not delegate to subagents. No new or existing automated test runner is invoked because Task 18 explicitly forbids it.

**Goal:** reduce the measured cold `recently_updated` cost and proactively warm stable public discovery through the existing optional cache infrastructure without changing recommendation identities, URLs, ranking explanations or private-cache boundaries.

**Architecture:** authoritative media/episode publication events are read through bounded ordered source windows, merged and deduplicated in PHP, then passed through the existing visibility/watchability query. The existing public HTTP warmer gains only the five indexable default discovery routes; request-time bounded queries remain the fallback when cache/queue infrastructure is unavailable.

**Tech stack:** PHP 8.5, Laravel 13.20 query builder/cache/queue APIs, Livewire 4.3 unchanged, SQLite-compatible additive migration, existing `TieredCache`, `WarmCatalogCaches` and `PublicPageCacheWarmer`.

### Task F1: bounded semantic update events

**Files:**

- Modify: `config/recommendations.php`
- Modify: `app/Services/Catalog/CatalogPublicDiscoveryQuery.php`
- Create: `database/migrations/2026_07_16_220000_add_recommendation_release_event_index.php`

**Interfaces:**

- `CatalogPublicDiscoveryQuery::recentlyUpdated()` keeps returning the existing list shape `{id, score, source, reason}`.
- Add private `recentContentEvents(int $limit): Collection` and `eligibleOrderedIds(CatalogRecommendationContext $context, Collection $ids, array $excludedIds): Collection`; neither is public API.
- Configuration supplies `recommendations.content_updates.event_window_multiplier=64`, `minimum_event_window=10000`, `maximum_event_window=20000`.

The bounded window is calculated exactly as:

```php
$eventWindow = min(
    max(1, (int) config('recommendations.content_updates.maximum_event_window', 20_000)),
    max(
        max(1, (int) config('recommendations.content_updates.minimum_event_window', 10_000)),
        $this->candidateLimit() * max(1, (int) config('recommendations.content_updates.event_window_multiplier', 64)),
    ),
);
```

The migration body is limited to:

```php
Schema::table('episodes', function (Blueprint $table): void {
    $table->index(
        ['publication_status', 'deleted_at', 'released_at', 'id', 'season_id'],
        'episodes_recommendation_release_events_idx',
    );
});
```

- [ ] Add the bounded window settings without environment variables or a second configuration file.
- [ ] Add the exact episode index `['publication_status', 'deleted_at', 'released_at', 'id', 'season_id']` with the stable name `episodes_recommendation_release_events_idx`; `down()` removes only this index.
- [ ] Replace the full historical UNION/GROUP aggregate with two ordered `limit($eventWindow)` source queries selecting only `catalog_title_id`, `event_at`, `event_id` and `event_source`.
- [ ] Merge by `event_at DESC`, then `event_source`, then `event_id DESC`; deduplicate positive title IDs before visibility, request up to the configured candidate limit, and preserve that ordering after one bulk eligible-ID query.
- [ ] Keep `CatalogRecommendationSource::ContentUpdate`, `CatalogRecommendationReason::RecentlyUpdated`, the 180-candidate cap and `catalog_titles.updated_at` prohibition unchanged.
- [ ] Run PHP syntax, Pint, migration pretend/static up/down inspection and SQLite `EXPLAIN QUERY PLAN`; expected media stream uses `licensed_media_home_feed_idx`, episode stream uses `episodes_recommendation_release_events_idx` after isolated migration rehearsal.

### Task F2: existing public cache warmer integration

**Files:**

- Modify: `app/Services/Catalog/PublicPageCacheWarmer.php`

**Interfaces:**

- `PublicPageCacheWarmer::warm()` response remains `{attempted, succeeded}`.
- `criticalUrls()` remains private and adds routes derived from `CatalogRecommendationType::publicCases()` filtered by `isIndexable()`.

The appended URL list is derived from stable enum identity:

```php
...collect(CatalogRecommendationType::publicCases())
    ->filter(fn (CatalogRecommendationType $type): bool => $type->isIndexable())
    ->map(fn (CatalogRecommendationType $type): string => route(
        'discover.index',
        ['type' => $type->value],
        false,
    ))
    ->all(),
```

- [ ] Import `CatalogRecommendationType` and append exactly `trending`, `popular`, `top_rated`, `recently_added`, `recently_updated` default URLs through `route('discover.index', ['type' => $type->value], false)`.
- [ ] Keep personalized, similar, related, random, editorial, upcoming, localized/filter/query state and user IDs outside proactive targets.
- [ ] Preserve URL de-duplication, configured URL cap, same-origin validation, bounded HTTP timeout/retry and failure semantics.
- [ ] Inspect the resolved target list and run a controlled local warm smoke only when the self-origin server is available; do not dispatch production queue work or flush cache.

### Task F3: verification, documentation and delivery

**Files:**

- Modify: `docs/performance.md`
- Modify: `docs/caching.md`
- Modify: `docs/DATA_RELATIONS.md`
- Modify: `docs/MAINTENANCE_LOG.md`
- Modify: `docs/deployment.md`
- Modify: `CHANGELOG.md`
- Modify: this plan and the existing Task 18 design spec only if implementation evidence changes the contract.

- [ ] Re-run uncached read-only candidate diagnostics for all five indexable types and record actual before/after observations without calling them p95/SLA.
- [ ] Verify 180 ordered unique eligible `recently_updated` IDs, stable explanations, no private state, bounded peak memory and unchanged public cache key dimensions.
- [ ] Run Pint, PHP lint, configured static analysis, `project:docs-refresh --check`, `git diff --check`, route inspection and Vite only if frontend assets changed; do not run PHPUnit/Pest.
- [ ] Browser-smoke `/discover/recently_updated` and one warmed public type for 200 status, canonical/noindex policy, no console error and no layout regression.
- [ ] Inspect every changed file and staged scope, commit only this follow-up on `main`, push, and verify remote SHA while preserving unrelated importer/media worktree changes.
