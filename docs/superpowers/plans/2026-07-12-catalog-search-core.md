# Catalog Search Core Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make catalog queries normalized, validated, intent-preserving, and honest before the FTS5 index is introduced.

**Architecture:** Add pure search normalizer/parser objects under `App\Services\Catalog\Search`, parse the request once in `CatalogTitlesPageBuilder`, and pass the immutable query through `CatalogTitleQuery`. Keep the existing Eloquent filter/facet boundary, but require all meaningful terms, apply an inferred year as a hard constraint, preserve title-scoped stopword searches, and remove the unrelated full-catalog fallback.

**Tech Stack:** PHP 8.5, Laravel 13.19, SQLite 3.46.1, PHPUnit 12.5, Laravel Pint 1.29.

## Global Constraints

- Visible UI text remains Russian.
- `q` is normalized to one-line whitespace and validated as `nullable|string|min:2|max:80`.
- Two-character and numeric tokens remain searchable; one inferred year is a hard constraint.
- Global stopword-only input returns an insufficient state and zero titles; a title-scoped query may retain its explicit title.
- Zero matching titles never fall back to unrelated catalog cards.
- No migration, live database write, full PHPUnit suite, or browser QA runs while importer PID `3053646` is active.
- Focused tests use PHPUnit and in-memory SQLite with `RefreshDatabase`.
- Run `./vendor/bin/pint --dirty --format agent` after every PHP task.
- Run `php artisan project:docs-refresh` before every commit so the post-commit hook does not create a second documentation commit.
- Only the root integration agent stages, commits, and publishes; implementation subagents do not run Git write commands.

---

## File Structure

- Create `app/Services/Catalog/Search/CatalogSearchState.php`: enum for `empty`, `ready`, and `insufficient`.
- Create `app/Services/Catalog/Search/CatalogSearchQuery.php`: immutable parsed-query value object.
- Create `app/Services/Catalog/Search/CatalogSearchNormalizer.php`: NFKC, case, whitespace, punctuation, `е/ё`, and transliteration rules.
- Create `app/Services/Catalog/Search/CatalogSearchQueryParser.php`: stopwords, year extraction, exact hashes, and safe FTS expression.
- Create `tests/Unit/CatalogSearchNormalizerTest.php`: pure normalization regression tests.
- Create `tests/Unit/CatalogSearchQueryParserTest.php`: parser state and token regression tests.
- Modify `app/Http/Requests/CatalogTitlesRequest.php`: one public request contract with no silent truncation.
- Modify `tests/Unit/CatalogTitlesRequestTest.php`: unit contract coverage.
- Modify `tests/Feature/CatalogValidationTest.php`: HTTP validation and input preservation.
- Modify `app/Services/Catalog/CatalogTitleQuery.php`: corrected legacy matching and hard inferred-year behavior.
- Create `tests/Feature/CatalogSearchPageTest.php`: isolated search behavior coverage.
- Modify `app/Services/Catalog/CatalogTitlesPageBuilder.php`: parse once, remove fallback, and expose state.
- Modify `app/Services/Catalog/CatalogSeoBuilder.php`: remove fallback claims and unsupported field claims.
- Modify `app/View/ViewModels/CatalogTitlesViewModel.php`: clear-search and reset-filter query helpers.
- Modify `resources/views/catalog/titles.blade.php`: honest zero/insufficient copy and distinct reset actions.
- Create `docs/catalog-search.md`: user-visible contract and acceptance corpus.
- Modify `docs/validation.md`, `docs/architecture.md`, `docs/performance.md`, `docs/forms.md`, `docs/views.md`, `docs/testing.md`, and `docs/MAINTENANCE_LOG.md` with the behavior owned by each task.

### Task 1: Normalize And Parse Search Queries

**Files:**
- Create: `app/Services/Catalog/Search/CatalogSearchState.php`
- Create: `app/Services/Catalog/Search/CatalogSearchQuery.php`
- Create: `app/Services/Catalog/Search/CatalogSearchNormalizer.php`
- Create: `app/Services/Catalog/Search/CatalogSearchQueryParser.php`
- Create: `tests/Unit/CatalogSearchNormalizerTest.php`
- Create: `tests/Unit/CatalogSearchQueryParserTest.php`
- Create: `docs/catalog-search.md`

**Interfaces:**
- Consumes: PHP `Normalizer::FORM_KC`, `Illuminate\Support\Str`, and the current catalog stopword list.
- Produces: `CatalogSearchQueryParser::parse(string $value): CatalogSearchQuery` and `CatalogSearchNormalizer::legacyVariants(string $value): array`.

- [ ] **Step 1: Write failing normalizer tests**

Create tests that assert these exact values:

```php
public function test_it_normalizes_unicode_case_whitespace_and_punctuation(): void
{
    $normalizer = new CatalogSearchNormalizer;

    $this->assertSame('OA', $normalizer->display("  ＯＡ\n"));
    $this->assertSame('федор лавров', $normalizer->key('ФЁДОР, ЛАВРОВ'));
    $this->assertSame(['11', '22', '63'], $normalizer->tokens('11.22.63'));
}

public function test_it_builds_user_facing_cyrillic_transliteration_and_legacy_variants(): void
{
    $normalizer = new CatalogSearchNormalizer;

    $this->assertSame('znakhar', $normalizer->transliterate('Знахарь'));
    $this->assertContains('znaxar', $normalizer->legacyVariants('znakhar'));
    $this->assertContains('фёдор', $normalizer->legacyVariants('Федор'));
}
```

- [ ] **Step 2: Write failing parser tests**

Cover the immutable result without database access:

```php
public function test_it_extracts_one_year_and_keeps_only_meaningful_terms(): void
{
    $query = app(CatalogSearchQueryParser::class)->parse('сериал Знахарь 2019 смотреть онлайн');

    $this->assertSame(CatalogSearchState::Ready, $query->state);
    $this->assertSame('сериал Знахарь 2019 смотреть онлайн', $query->raw);
    $this->assertSame(['знахарь'], $query->terms);
    $this->assertSame(2019, $query->year);
    $this->assertSame('"знахарь"*', $query->ftsExpression);
    $this->assertContains(hash('sha256', 'знахарь'), $query->exactNameHashes);
}

public function test_it_preserves_short_and_punctuation_queries(): void
{
    $parser = app(CatalogSearchQueryParser::class);

    $this->assertSame(['oa'], $parser->parse('OA')->terms);
    $this->assertSame('"oa"', $parser->parse('OA')->ftsExpression);
    $this->assertSame(['11', '22', '63'], $parser->parse('11.22.63')->terms);
    $this->assertNull($parser->parse('11.22.63')->year);
}

public function test_it_distinguishes_empty_and_stopword_only_queries(): void
{
    $parser = app(CatalogSearchQueryParser::class);

    $this->assertSame(CatalogSearchState::Empty, $parser->parse('')->state);
    $this->assertSame(CatalogSearchState::Insufficient, $parser->parse('смотреть онлайн')->state);
}
```

- [ ] **Step 3: Run the new unit tests and verify RED**

Run:

```bash
php artisan test --filter=CatalogSearchNormalizerTest
php artisan test --filter=CatalogSearchQueryParserTest
```

Expected: both commands fail because the four search classes do not exist.

- [ ] **Step 4: Add the state enum and immutable query object**

Use these exact public interfaces:

```php
enum CatalogSearchState: string
{
    case Empty = 'empty';
    case Ready = 'ready';
    case Insufficient = 'insufficient';
}
```

```php
final readonly class CatalogSearchQuery
{
    /**
     * @param  list<string>  $terms
     * @param  list<string>  $exactNameHashes
     */
    public function __construct(
        public string $raw,
        public string $normalized,
        public array $terms,
        public ?int $year,
        public CatalogSearchState $state,
        public string $ftsExpression,
        public array $exactNameHashes,
    ) {}

    public function phrase(): string
    {
        return implode(' ', $this->terms);
    }

    public function isReady(): bool
    {
        return $this->state === CatalogSearchState::Ready;
    }
}
```

- [ ] **Step 5: Implement deterministic normalization**

`CatalogSearchNormalizer` must use NFKC, normalize `ё` to `е` in keys, split on non-letter/non-number boundaries, and use this lowercase Cyrillic map:

```php
private const CYRILLIC_TO_LATIN = [
    'а' => 'a', 'б' => 'b', 'в' => 'v', 'г' => 'g', 'д' => 'd',
    'е' => 'e', 'ж' => 'zh', 'з' => 'z', 'и' => 'i', 'й' => 'y',
    'к' => 'k', 'л' => 'l', 'м' => 'm', 'н' => 'n', 'о' => 'o',
    'п' => 'p', 'р' => 'r', 'с' => 's', 'т' => 't', 'у' => 'u',
    'ф' => 'f', 'х' => 'kh', 'ц' => 'ts', 'ч' => 'ch', 'ш' => 'sh',
    'щ' => 'shch', 'ъ' => '', 'ы' => 'y', 'ь' => '', 'э' => 'e',
    'ю' => 'yu', 'я' => 'ya',
];
```

`legacyVariants()` returns unique display/lowercase/title/uppercase values, both `е` and `ё` spellings, the user transliteration, and an `kh -> x` compatibility spelling for existing slugs.

- [ ] **Step 6: Implement parser semantics**

Move the current stopword list from `CatalogTitleQuery` into `CatalogSearchQueryParser`. The parser must:

```php
$raw = $this->normalizer->display($value);
$tokens = collect($this->normalizer->tokens($raw));
$years = $tokens
    ->filter(fn (string $term): bool => preg_match('/^\d{4}$/', $term) === 1)
    ->map(fn (string $term): int => (int) $term)
    ->filter(fn (int $year): bool => $year >= 1900 && $year <= ((int) now()->format('Y') + 1))
    ->unique()
    ->values();
$year = $years->count() === 1 ? $years->first() : null;
$terms = $tokens
    ->reject(fn (string $term): bool => $year !== null && $term === (string) $year)
    ->reject(fn (string $term): bool => in_array($term, self::STOP_WORDS, true))
    ->filter(fn (string $term): bool => preg_match('/^\d+$/', $term) === 1 || mb_strlen($term) >= 2)
    ->unique()
    ->take(8)
    ->values();
```

An empty raw value is `Empty`; nonempty input with no terms and no year is `Insufficient`; all other input is `Ready`. FTS terms of two characters or numbers are exact quoted tokens; letter terms of three or more characters use a quoted prefix. Exact hashes are SHA-256 hashes of lowercase squished `legacyVariants($terms->implode(' '))`.

- [ ] **Step 7: Run the parser tests and verify GREEN**

Run both commands from Step 3. Expected: PASS.

- [ ] **Step 8: Document the contract**

Create `docs/catalog-search.md` with sections `Контракт запроса`, `Нормализация`, `Состояния`, `Acceptance corpus`, and `Ограничения rollout`. Record `OA`, `FM`, `11.22.63`, `Знахарь 2019`, `смотреть онлайн`, and `znakhar` as named regression cases.

- [ ] **Step 9: Format, refresh docs, verify, commit, and publish**

Run:

```bash
./vendor/bin/pint --dirty --format agent
php artisan project:docs-refresh
php artisan project:docs-refresh --check
git diff --check
php artisan test --filter=CatalogSearchNormalizerTest
php artisan test --filter=CatalogSearchQueryParserTest
```

Expected: every command passes. Commit message: `feat: parse normalized catalog search queries`. Publish `main` without force and verify local `main` equals `origin/main`.

### Task 2: Enforce One HTTP Query Contract

**Files:**
- Modify: `app/Http/Requests/CatalogTitlesRequest.php`
- Modify: `tests/Unit/CatalogTitlesRequestTest.php`
- Modify: `tests/Feature/CatalogValidationTest.php`
- Modify: `docs/validation.md`
- Modify: `docs/catalog-search.md`

**Interfaces:**
- Consumes: `CatalogTitlesRequest::normalizedSearch(): string`.
- Produces: validated, whitespace-normalized `q` with no silent truncation.

- [ ] **Step 1: Add failing request tests**

Assert rules and HTTP behavior:

```php
public function test_catalog_search_requires_two_and_allows_eighty_characters(): void
{
    $request = CatalogTitlesRequest::create('/titles', 'GET');
    $rules = $request->rules();

    $this->assertContains('min:2', $rules['q']);
    $this->assertContains('max:80', $rules['q']);
}
```

Add feature tests for `q=я`, 80 Cyrillic characters, and 81 Cyrillic characters. The first and third requests must redirect with `q` errors; the 80-character request must not have a validation error. Assert `Введите не менее 2 символов для поиска.` for the one-character case and `Поисковый запрос слишком длинный.` for 81 characters.

- [ ] **Step 2: Run request tests and verify RED**

Run:

```bash
php artisan test --filter=CatalogTitlesRequestTest
php artisan test --filter=CatalogValidationTest
```

Expected: failures show the old `max:160` contract and missing minimum message.

- [ ] **Step 3: Replace the request rule and remove truncation**

Use:

```php
'q' => ['nullable', 'string', 'min:2', 'max:80'],
```

Add:

```php
'q.min' => 'Введите не менее 2 символов для поиска.',
```

`normalizedSearch()` must only trim and collapse whitespace:

```php
public function normalizedSearch(): string
{
    return preg_replace('/\s+/u', ' ', trim($this->stringQuery('q'))) ?: '';
}
```

Do not perform a second length check or call `mb_substr()`.

- [ ] **Step 4: Run request tests and verify GREEN**

Run the two commands from Step 2. Expected: PASS.

- [ ] **Step 5: Update validation documentation and publish**

Document the shared `min:2|max:80` contract and Russian errors in `docs/validation.md` and `docs/catalog-search.md`. Run Pint, docs refresh/check, `git diff --check`, and the two focused test classes. Commit message: `fix: validate catalog search query length`. Publish and verify branch alignment.

### Task 3: Match Complete Search Intent

**Files:**
- Modify: `app/Services/Catalog/CatalogTitleQuery.php`
- Modify: `app/Services/Catalog/CatalogTitlesPageBuilder.php`
- Create: `tests/Feature/CatalogSearchPageTest.php`
- Modify: `tests/Feature/CatalogPageTest.php`
- Modify: `docs/architecture.md`
- Modify: `docs/performance.md`
- Modify: `docs/catalog-search.md`

**Interfaces:**
- Consumes: `CatalogSearchQueryParser::parse()` and `CatalogSearchNormalizer::legacyVariants()`.
- Produces: `CatalogTitleQuery::filteredTitles()` and `CatalogTitleQuery::relationContextCounts()` with a typed `CatalogSearchQuery $search` parameter.

- [ ] **Step 1: Add failing feature regressions**

Use `RefreshDatabase` and cover these independent behaviors in `CatalogSearchPageTest`:

```php
public function test_year_inside_query_is_a_hard_constraint(): void
{
    CatalogTitle::factory()->create(['title' => 'Знахарь', 'slug' => 'znaxar-2008', 'year' => 2008]);
    CatalogTitle::factory()->create(['title' => 'Знахарь', 'slug' => 'znaxar-2019', 'year' => 2019]);

    $this->get(route('titles.index', ['q' => 'Знахарь 2019']))
        ->assertOk()
        ->assertSee('znaxar-2019', false)
        ->assertDontSee('znaxar-2008', false);
}

public function test_short_and_punctuation_titles_remain_searchable(): void
{
    CatalogTitle::factory()->create(['title' => 'OA', 'slug' => 'oa']);
    CatalogTitle::factory()->create(['title' => '11/22/63', 'slug' => '11-22-63']);

    $this->get(route('titles.index', ['q' => 'OA']))->assertSee('href="'.route('titles.show', 'oa').'"', false);
    $this->get(route('titles.index', ['q' => '11.22.63']))->assertSee('href="'.route('titles.show', '11-22-63').'"', false);
}
```

Also add tests that all tokens of `Милли Бобби Браун` match one actor-linked title, `Федор Лавров` matches stored `Фёдор Лавров`, and an unpublished exact title is absent.

- [ ] **Step 2: Run focused page tests and verify RED**

Run:

```bash
php artisan test --filter=CatalogSearchPageTest
php artisan test --filter=test_title_scoped_catalog_search_stays_on_one_title
```

Expected: year, short-token, punctuation, or full-person assertions fail under the current OR/early-return behavior; the existing title-scoped test passes.

- [ ] **Step 3: Parse once in the page builder**

Inject `CatalogSearchQueryParser` into `CatalogTitlesPageBuilder`. Immediately after `$search = $request->normalizedSearch()`, call:

```php
$searchQuery = $this->searchParser->parse($search);
```

Pass the same object to results, relation context counts, and year context counts. Do not parse separately for each facet query.

- [ ] **Step 4: Replace legacy search semantics**

Inject `CatalogSearchNormalizer` into `CatalogTitleQuery`, change `filteredTitles()` and `relationContextCounts()` to accept `CatalogSearchQuery`, and start from:

```php
$query = CatalogTitle::query()->published();
```

Apply the inferred year before text matching:

```php
if ($search->year !== null) {
    if ($year !== null && $year !== $search->year) {
        $query->whereRaw('1 = 0');
    } else {
        $query->where('year', $search->year);
    }
}
```

For `Insufficient`, add `1 = 0` only when `$titleContextId === null`; a scoped title keeps its existing `whereKey()` constraint. For `Ready` with text terms:

1. Try the complete meaningful phrase against exact title/original title and `exactNameHashes` aliases.
2. If exact IDs exist, apply one `whereKey($ids)` and return from text matching.
3. Otherwise add one outer `where` group per meaningful term so the groups are joined by `AND`.
4. Inside each term group, join title, original title, description, slug, external ID, aliases, and relation names with `OR` over `legacyVariants($term)`.

Delete the sequential single-term early-return loop and the three-character token filter from `CatalogTitleQuery`; the parser owns both decisions.

- [ ] **Step 5: Add deterministic default ordering**

Every branch in `CatalogTitlesPageBuilder::applySort()` must finish with `orderByDesc('catalog_titles.id')` after its existing order fields. This prevents result movement when timestamps or years tie.

- [ ] **Step 6: Run focused tests and verify GREEN**

Run:

```bash
php artisan test --filter=CatalogSearchPageTest
php artisan test --filter=CatalogPageTest
```

Expected: all search regressions and existing scoped/alias/taxonomy tests pass.

- [ ] **Step 7: Document and publish matching semantics**

Update `docs/architecture.md`, `docs/performance.md`, and `docs/catalog-search.md` with parse-once flow, AND semantics, hard inferred year, published scope, and deterministic ordering. Run Pint, docs refresh/check, `git diff --check`, and the two focused test classes. Commit message: `fix: match complete catalog search intent`. Publish and verify branch alignment.

### Task 4: Preserve Honest Empty And Insufficient States

**Files:**
- Modify: `app/Services/Catalog/CatalogTitlesPageBuilder.php`
- Modify: `app/Services/Catalog/CatalogSeoBuilder.php`
- Modify: `app/View/ViewModels/CatalogTitlesViewModel.php`
- Modify: `resources/views/catalog/titles.blade.php`
- Modify: `tests/Feature/CatalogSearchPageTest.php`
- Modify: `tests/Unit/CatalogTitlesViewModelTest.php`
- Modify: `docs/forms.md`
- Modify: `docs/views.md`
- Modify: `docs/UI_STANDARDS.md`
- Modify: `docs/testing.md`
- Modify: `docs/catalog-search.md`
- Modify: `docs/MAINTENANCE_LOG.md`

**Interfaces:**
- Consumes: parsed `CatalogSearchState` and the single paginator built in Task 3.
- Produces: `insufficientSearch`, `CatalogTitlesViewModel::$withoutSearchQuery`, and `CatalogTitlesViewModel::$withoutFiltersQuery`.

- [ ] **Step 1: Add failing page-state tests**

Add feature tests that assert:

```php
public function test_unknown_query_keeps_a_true_zero_result(): void
{
    CatalogTitle::factory()->create(['title' => 'Посторонний сериал', 'slug' => 'postoronnii-serial']);

    $this->get(route('titles.index', ['q' => 'шерлокк']))
        ->assertOk()
        ->assertSeeText('По запросу «шерлокк» ничего не найдено.')
        ->assertDontSeeText('Посторонний сериал')
        ->assertDontSeeText('ближайшие результаты');
}

public function test_stopword_only_query_has_an_insufficient_state(): void
{
    CatalogTitle::factory()->create(['title' => 'Посторонний сериал', 'slug' => 'postoronnii-serial']);

    $this->get(route('titles.index', ['q' => 'смотреть онлайн']))
        ->assertOk()
        ->assertSeeText('Запрос «смотреть онлайн» слишком общий.')
        ->assertDontSeeText('Посторонний сериал');
}
```

Add a query-helper unit test using `search=Знахарь`, `sort=year_desc`, `year=2019`, and one genre slug. Assert `withoutSearchQuery` preserves sort/year/genre without `q`, while `withoutFiltersQuery` preserves only `q` and sort.

- [ ] **Step 2: Run state tests and verify RED**

Run:

```bash
php artisan test --filter=CatalogSearchPageTest
php artisan test --filter=CatalogTitlesViewModelTest
```

Expected: the unknown query still shows unrelated fallback titles and the new helpers do not exist.

- [ ] **Step 3: Delete the fallback query**

In `CatalogTitlesPageBuilder`, remove `$querySearch`, `$searchFallback`, the second paginator query, and the `searchFallback` view/SEO argument. Results, taxonomy counts, and year counts must all receive the original parsed query.

Expose:

```php
'searchState' => $searchQuery->state->value,
'insufficientSearch' => $searchQuery->state === CatalogSearchState::Insufficient && $titleContext === null,
```

- [ ] **Step 4: Remove misleading SEO claims**

Delete the `bool $searchFallback` argument and every fallback branch from `CatalogSeoBuilder::titles()`. Replace the catalog lead suffix with:

```php
return ucfirst($parts).'. Выдача учитывает названия, оригинальные названия, алиасы, описания, жанры, страны, актеров и режиссеров.';
```

Do not claim that seasons, episodes, or available video participate in search.

- [ ] **Step 5: Add distinct query reset helpers**

`withoutSearchQuery` preserves title context, taxonomy filters, valid/invalid year, and non-default sort while omitting `q`. `withoutFiltersQuery` preserves `q` and non-default sort while omitting title context, year, and taxonomy filters.

- [ ] **Step 6: Render honest Russian states**

When `$insufficientSearch` is true, render:

```blade
<span>Запрос «{{ $search }}» слишком общий.</span>
<p>Добавьте название, имя актера, режиссера или жанр.</p>
```

When `$search !== ''` and the paginator is empty, render:

```blade
<span>По запросу «{{ $search }}» ничего не найдено.</span>
<p>Проверьте написание или измените фильтры.</p>
```

Render separate links `Очистить поиск`, `Сбросить фильтры`, and `Показать весь каталог` using the ViewModel helpers. Add `$search !== ''` to the active-condition summary so a search-only page can clear just its query.

- [ ] **Step 7: Run state tests and verify GREEN**

Run the commands from Step 2 plus:

```bash
php artisan test --filter=CatalogValidationTest
php artisan test --filter=CatalogPageTest
```

Expected: all commands pass and no fallback copy remains.

- [ ] **Step 8: Update documentation and publish**

Update forms, views, UI standards, testing, search contract, and maintenance log with true zero/insufficient states and reset semantics. Replace the old maintenance-log claim that zero matches show nearest catalog results. Run Pint, docs refresh/check, `git diff --check`, and all focused core tests. Because Blade changed, also run `npm run build`. Commit message: `fix: preserve empty catalog search results`. Publish and verify branch alignment.

## Core Milestone Verification

After Task 4, run only these isolated commands while the importer remains active:

```bash
./vendor/bin/pint --dirty --format agent
php artisan project:docs-refresh --check
php artisan test --filter=CatalogSearchNormalizerTest
php artisan test --filter=CatalogSearchQueryParserTest
php artisan test --filter=CatalogTitlesRequestTest
php artisan test --filter=CatalogValidationTest
php artisan test --filter=CatalogSearchPageTest
php artisan test --filter=CatalogTitlesViewModelTest
php artisan test --filter=CatalogPageTest
npm run build
git diff --check
git status --short --branch
```

Do not claim the full suite, FTS latency targets, transliteration top-3, or production rollout complete. Those belong to the subsequent FTS/index, UI/accessibility, and benchmark plans.

## Self-Review

- Spec coverage: query normalization, validation, stopwords, short tokens, punctuation, hard year, title scope, published scope, stable ordering, honest zero state, reset actions, unsupported SEO claims, focused tests, docs, commits, and importer safety are assigned to Tasks 1-4.
- Deferred by explicit subsystem boundary: FTS schema, index state, importer synchronization, ranked BM25, people lookup, mobile dialog, compact cards, trigram suggestions, Playwright matrix, and p95 gates.
- Placeholder scan: the plan contains no unfinished markers or unspecified implementation steps.
- Type consistency: all tasks use `CatalogSearchQueryParser::parse(string): CatalogSearchQuery`, `CatalogSearchQuery::$state`, and `CatalogTitleQuery` accepting the same immutable object.
