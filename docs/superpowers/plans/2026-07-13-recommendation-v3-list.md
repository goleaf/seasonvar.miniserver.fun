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
