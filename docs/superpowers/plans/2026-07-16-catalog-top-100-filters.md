# Catalog Top 100 Filters Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Добавить к четырём публичным рейтингам Top 100 серверную фильтрацию по диапазону годов и стране, применяемую до расчёта мест.

**Architecture:** Отдельный Form Request превращает query string в immutable DTO. Existing ranking query передаёт DTO в `CatalogRecommendationVisibilityService`, page builder готовит все URL и form state, а SEO builder делает query-варианты `noindex` с canonical на базовую категорию. Blade остаётся пассивным SSR-шаблоном без JavaScript и запросов.

**Tech Stack:** PHP 8.5, Laravel 13.20, SQLite, Blade, Tailwind CSS 4.3, PHPUnit 12.5, Laravel Pint 1.29.

## Global Constraints

- Работать только в существующей ветке `main`; не создавать branch или worktree.
- Не добавлять production dependencies и миграции.
- Видимый текст и обычный текст README писать по-русски; `ru`/`en` translation keys держать в паритете.
- Blade не использует `@php`, `request()`, `config()`, database/service calls, inline CSS или inline JavaScript.
- Все controls имеют видимую label, focus state и effective touch target не меньше 44 px.
- Не создавать внутренних scroll-контейнеров или custom dropdown.
- Сохранять текущий рейтинг: Кинопоиск → IMDb fallback, Bayesian smoothing, stable tie-break, public/watchable boundary, limit 100.
- Фильтрованные URL не индексируются и не попадают в sitemap как отдельные landing pages.
- Не захватывать параллельные пользовательские изменения из существующего dirty tree.

---

### Task 1: Типизированный контракт фильтров

**Files:**
- Create: `app/DTOs/CatalogTopListFilters.php`
- Create: `app/Http/Requests/CatalogTopListRequest.php`
- Create: `tests/Unit/CatalogTopListFiltersTest.php`
- Modify: `lang/ru/top_lists.php`
- Modify: `lang/en/top_lists.php`

**Interfaces:**
- Produces: `CatalogTopListFilters::empty(): self`.
- Produces: `CatalogTopListFilters::contextFilters(): array{year_from?: int, year_to?: int, country?: string}`.
- Produces: `CatalogTopListFilters::query(): array{year_from?: int, year_to?: int, country?: string}`.
- Produces: `CatalogTopListFilters::active(): bool`.
- Produces: `CatalogTopListRequest::filters(): CatalogTopListFilters`.

- [x] **Step 1: Написать unit-тест DTO и request validation**

```php
<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\DTOs\CatalogTopListFilters;
use App\Http\Requests\CatalogTopListRequest;
use App\Models\Country;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

final class CatalogTopListFiltersTest extends TestCase
{
    use RefreshDatabase;

    public function test_filters_expose_only_active_normalized_values(): void
    {
        $filters = new CatalogTopListFilters(2010, 2019, 'litva');

        $this->assertTrue($filters->active());
        $this->assertSame([
            'year_from' => 2010,
            'year_to' => 2019,
            'country' => 'litva',
        ], $filters->query());
        $this->assertSame($filters->query(), $filters->contextFilters());
        $this->assertFalse(CatalogTopListFilters::empty()->active());
    }

    public function test_request_rejects_unknown_country(): void
    {
        Country::query()->create(['name' => 'Литва', 'slug' => 'litva']);
        $request = CatalogTopListRequest::create('/top/movies', 'GET', [
            'country' => 'unknown',
        ]);
        $validator = Validator::make(
            $request->all(),
            $request->rules(),
            $request->messages(),
            $request->attributes(),
        );

        foreach ($request->after() as $after) {
            $validator->after($after);
        }

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('country', $validator->errors()->toArray());
    }

    public function test_request_rejects_inverted_years(): void
    {
        $request = CatalogTopListRequest::create('/top/movies', 'GET', [
            'year_from' => '2020',
            'year_to' => '2010',
        ]);
        $validator = Validator::make(
            $request->all(),
            $request->rules(),
            $request->messages(),
            $request->attributes(),
        );

        foreach ($request->after() as $after) {
            $validator->after($after);
        }

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('year_from', $validator->errors()->toArray());
    }
}
```

- [x] **Step 2: Запустить тест и подтвердить RED**

Run: `php artisan test tests/Unit/CatalogTopListFiltersTest.php`

Expected: FAIL, потому что DTO и Form Request ещё не существуют.

- [x] **Step 3: Реализовать immutable DTO**

```php
<?php

declare(strict_types=1);

namespace App\DTOs;

final readonly class CatalogTopListFilters
{
    public function __construct(
        public ?int $yearFrom = null,
        public ?int $yearTo = null,
        public ?string $country = null,
    ) {}

    public static function empty(): self
    {
        return new self;
    }

    /** @return array{year_from?: int, year_to?: int, country?: string} */
    public function query(): array
    {
        return array_filter([
            'year_from' => $this->yearFrom,
            'year_to' => $this->yearTo,
            'country' => $this->country,
        ], static fn (int|string|null $value): bool => $value !== null && $value !== '');
    }

    /** @return array{year_from?: int, year_to?: int, country?: string} */
    public function contextFilters(): array
    {
        return $this->query();
    }

    public function active(): bool
    {
        return $this->query() !== [];
    }
}
```

- [x] **Step 4: Реализовать Form Request**

`CatalogTopListRequest` должен использовать `CatalogFilterSlug`, `Rule::exists(Country::class, 'slug')`, диапазон от `1900` до `(int) now()->format('Y') + 1` и `after()` для порядка диапазона. `prepareForValidation()` преобразует не-scalar значения в `null`, trim-ит scalar и оставляет пустые значения `null`. Метод `filters()` строит DTO только из `$this->validated()`.

```php
public function filters(): CatalogTopListFilters
{
    $validated = $this->validated();

    return new CatalogTopListFilters(
        yearFrom: isset($validated['year_from']) ? (int) $validated['year_from'] : null,
        yearTo: isset($validated['year_to']) ? (int) $validated['year_to'] : null,
        country: isset($validated['country']) && is_string($validated['country'])
            ? $validated['country']
            : null,
    );
}
```

Добавить translation keys `validation.year`, `validation.country`, `validation.range` и `attributes.year_from|year_to|country` в оба locale-файла.

- [x] **Step 5: Запустить unit-тест и подтвердить GREEN**

Run: `php artisan test tests/Unit/CatalogTopListFiltersTest.php`

Expected: PASS; unknown country и inverted range дают ожидаемые validation keys.

- [x] **Step 6: Зафиксировать task commit**

```bash
git add app/DTOs/CatalogTopListFilters.php app/Http/Requests/CatalogTopListRequest.php tests/Unit/CatalogTopListFiltersTest.php lang/ru/top_lists.php lang/en/top_lists.php
git commit -m "feat: define Top 100 filter contract"
```

При существующем dirty tree использовать изолированный index и включить только перечисленные paths.

---

### Task 2: Фильтрация до ранжирования

**Files:**
- Modify: `app/Services/Catalog/CatalogTopListQuery.php`
- Modify: `app/Services/Catalog/CatalogTopListPageBuilder.php`
- Modify: `app/Http/Controllers/CatalogTopListController.php`
- Modify: `tests/Feature/CatalogTopListPageTest.php`

**Interfaces:**
- Consumes: `CatalogTopListRequest::filters()`.
- Consumes: `CatalogTopListFilters::contextFilters()`.
- Produces: `CatalogTopListQuery::items(CatalogTopListCategory, ?User, ?CatalogTopListFilters = null): Collection`.
- Preserves: `CatalogTopListQuery::hasItems(CatalogTopListCategory): bool` for sitemap callers.
- Produces: `CatalogTopListPageBuilder::data(CatalogTopListCategory, ?User, bool, CatalogTopListFilters): array`.

- [x] **Step 1: Добавить failing feature tests**

В `CatalogTopListPageTest` создать Литву и США, присоединить страны к трём rankable titles с годами `2009`, `2015`, `2022`, затем запросить:

```php
$response = $this->get(route('top.show', [
    'category' => CatalogTopListCategory::Series->value,
    'year_from' => 2010,
    'year_to' => 2020,
    'country' => 'litva',
]));

$response
    ->assertOk()
    ->assertSee($matching->title)
    ->assertDontSee($tooOld->title)
    ->assertDontSee($wrongCountry->title);
```

В существующем тесте лимита присоединить страну к 101-й по score карточке и доказать, что запрос по этой стране возвращает её. Это фиксирует применение фильтра до `LIMIT 100`.

- [x] **Step 2: Запустить feature tests и подтвердить RED**

Run: `php artisan test --filter='CatalogTopListPageTest::test_top_list_filters|CatalogTopListPageTest::test_page_is_capped'`

Expected: FAIL, потому что controller/query игнорируют фильтры.

- [x] **Step 3: Передать DTO через controller и page builder**

```php
public function show(CatalogTopListRequest $request, CatalogTopListCategory $category): View
{
    return $this->view($request, $category, false);
}

public function localized(
    CatalogTopListRequest $request,
    string $locale,
    CatalogTopListCategory $category,
): View {
    return $this->view($request, $category, true);
}
```

Private `view()` передаёт `$request->filters()` четвёртым аргументом `CatalogTopListPageBuilder::data()`.

- [x] **Step 4: Применить DTO внутри ranking query**

Добавить nullable DTO в public `items()`, создать `$filters ??= CatalogTopListFilters::empty()` и передать его в `rankedRows()`. В `rankedRows()` сформировать context так:

```php
$context = new CatalogRecommendationContext(
    type: CatalogRecommendationType::TopRated,
    user: null,
    locale: (string) config('catalog-collections.default_locale', 'ru'),
    filters: $filters->contextFilters(),
    ratingSource: 'kinopoisk',
);
```

`hasItems()` вызывает `rankedRows($category, 1, CatalogTopListFilters::empty())`, поэтому sitemap остаётся основан на базовых категориях.

- [x] **Step 5: Запустить feature tests и подтвердить GREEN**

Run: `php artisan test --filter=CatalogTopListPageTest`

Expected: PASS; country/year filters меняют состав и порядок до лимита.

- [x] **Step 6: Проверить SQL форму**

Run: `php artisan test --filter=test_movie_classification_groups_episode_counts_once`

Expected: PASS; grouped episode subquery сохраняется, per-title correlated count не появляется.

---

### Task 3: Форма, состояния и SEO

**Files:**
- Create: `app/Services/Catalog/CatalogTopListFilterOptions.php`
- Modify: `app/Services/Catalog/CatalogTopListPageBuilder.php`
- Modify: `app/Services/Catalog/CatalogTopListSeoBuilder.php`
- Modify: `resources/views/catalog/top-list.blade.php`
- Modify: `lang/ru/top_lists.php`
- Modify: `lang/en/top_lists.php`
- Modify: `tests/Feature/CatalogTopListPageTest.php`

**Interfaces:**
- Produces: `CatalogTopListFilterOptions::countries(): Collection<int, array{name: string, slug: string}>`.
- Produces page data keys: `filterForm`, `emptyState`, query-preserving `categoryLinks`.
- Consumes in SEO: `CatalogTopListFilters $filters`.

- [x] **Step 1: Написать failing UI/SEO tests**

Проверить в одном feature test:

```php
$response
    ->assertSee('data-top-list-filters', false)
    ->assertSee('name="year_from"', false)
    ->assertSee('name="year_to"', false)
    ->assertSee('name="country"', false)
    ->assertSee('value="litva" selected', false)
    ->assertSee('year_from=2010', false)
    ->assertSee('country=litva', false);
```

Для фильтрованного запроса проверить canonical без query, `noindex,follow`, отсутствие `ItemList` и наличие reset URL. Для пустого filtered result проверить тексты «По выбранным условиям ничего не найдено» и «Сбросить фильтры».

- [x] **Step 2: Запустить tests и подтвердить RED**

Run: `php artisan test --filter='CatalogTopListPageTest::test_filtered|CatalogTopListPageTest::test_top_list_filter_form'`

Expected: FAIL, потому что form state и filtered SEO ещё не подготовлены.

- [x] **Step 3: Реализовать bounded country options service**

```php
public function countries(): Collection
{
    return Country::query()
        ->select(['id', 'name', 'slug'])
        ->orderBy('name')
        ->orderBy('id')
        ->limit(100)
        ->get()
        ->map(fn (Country $country): array => [
            'name' => $country->name,
            'slug' => $country->slug,
        ])
        ->values();
}
```

- [x] **Step 4: Подготовить form и empty state в page builder**

`filterForm` содержит `action`, `resetUrl`, `yearFrom`, `yearTo`, `country`, `countries`, `maximumYear`, `active`. Category URLs объединяют route parameters с `$filters->query()`. `emptyState` выбирает filtered или base translation keys и готовый action URL.

- [x] **Step 5: Сделать filtered SEO неиндексируемым**

В `CatalogTopListSeoBuilder::build()` добавить `CatalogTopListFilters $filters`. Условие:

```php
$indexable = ! $filters->active()
    && $items->isNotEmpty()
    && (! $localizedAlias || $localizedCanonical);
```

Canonical остаётся route URL без query. `alternates` и `jsonLd` выводятся только при `$indexable`.

- [x] **Step 6: Добавить пассивную GET-форму в Blade**

Вставить после category navigation `<section data-top-list-filters>` с одним `<form method="GET">`. Использовать responsive grid `sm:grid-cols-2 xl:grid-cols-[minmax(8rem,10rem)_minmax(8rem,10rem)_minmax(14rem,1fr)_auto]`, native inputs/select, `x-form.input-error`, emerald primary button и плоский reset link. Все значения и URL брать только из `$filterForm`.

- [x] **Step 7: Добавить ru/en copy**

Добавить паритетные ключи `filters.*`, `empty_filtered_*`, `empty_filtered_action`, а также validation/attribute keys из Task 1. Русский текст не использует импортную или техническую терминологию.

- [x] **Step 8: Запустить focused tests и подтвердить GREEN**

Run: `php artisan test --filter='CatalogTopListPageTest|PublicPageCachePolicyTest|AppLayoutOptionalNavigationTest'`

Expected: PASS; базовые SEO assertions не изменены, filtered query не индексируется.

---

### Task 4: Cache contract и документация

**Files:**
- Modify: `tests/Unit/PublicPageCachePolicyTest.php`
- Modify: `README.md`
- Modify: `CHANGELOG.md`
- Modify: `docs/catalog-search.md`
- Modify: `docs/frontend.md`
- Modify: `docs/views.md`
- Modify: `docs/performance.md`
- Modify: `docs/DATA_RELATIONS.md`
- Modify: `docs/MAINTENANCE_LOG.md`

**Interfaces:**
- Preserves: catalog cache key includes normalized `year_from`, `year_to`, `country`.
- Documents: filters are applied before ranking and filtered pages are `noindex`.

- [x] **Step 1: Добавить cache characterization test**

Создать три `PublicPageCachePolicy` contexts для `top.show`: одинаковые query keys в разном порядке должны иметь одинаковый hash, а другая страна или диапазон — другой hash. Route parameter category передавать как `CatalogTopListCategory::Movies` и проверить, что dimension содержит backed enum value `movies`.

- [x] **Step 2: Запустить cache test**

Run: `php artisan test tests/Unit/PublicPageCachePolicyTest.php`

Expected: PASS без production cache code changes, потому что allowlist уже содержит эти query keys.

- [x] **Step 3: Обновить owner docs и README**

В README описать фильтры в списке возможностей и добавить посетительскую запись от `16.07.2026` в последнем разделе второго уровня. В CHANGELOG и owner docs зафиксировать request/DTO/query/SEO/cache contract, отсутствие новой схемы и применение условий до `LIMIT 100`.

- [x] **Step 4: Проверить managed documentation**

Run: `php artisan project:docs-refresh --check --no-interaction`

Expected: exit 0. Если check сообщает drift, запустить `php artisan project:docs-refresh --no-interaction`, затем повторить check и включить только относящиеся к задаче managed changes.

---

### Task 5: Форматирование и полная проверка

**Files:**
- Verify all changed files from Tasks 1–4.

- [x] **Step 1: Запустить Pint**

Run: `./vendor/bin/pint --dirty --format agent`

Expected: exit 0.

- [x] **Step 2: Запустить focused suite**

Run: `php artisan test --filter='CatalogTopListPageTest|CatalogTopListFiltersTest|PublicPageCachePolicyTest|PublicCacheRouteSafetyTest|AppLayoutOptionalNavigationTest'`

Expected: все selected tests PASS.

- [ ] **Step 3: Запустить полный suite**

Run: `php artisan test`

Expected: exit 0; существующие skipped tests допустимы, failures/errors недопустимы.

- [ ] **Step 4: Собрать frontend**

Run: `npm run build`

Expected: Vite build exit 0.

- [ ] **Step 5: Провести browser QA**

Проверить `/top/movies`, submit с `year_from`, `year_to`, `country`, reset и одну localized страницу на `390`, `768`, `1440`, `1920` px. Зафиксировать HTTP 200, выбранные values, изменение числа строк, canonical/noindex, отсутствие horizontal overflow, broken images, duplicate IDs, console/page/request errors и серьёзных accessibility violations.

- [ ] **Step 6: Проверить diff и документацию**

Run: `git diff --check`

Run: `php artisan project:docs-refresh --check --no-interaction`

Expected: обе команды exit 0. Повторно проверить актуальность README без фиктивного изменения даты.

---

### Task 6: Изолированная доставка в Git

**Files:**
- Commit only the paths explicitly changed by this plan.

- [ ] **Step 1: Проверить ветку и параллельные изменения**

Run: `git status --short --branch`

Expected: branch `main`; посторонние staged/unstaged paths остаются нетронутыми и перечисляются отдельно.

- [ ] **Step 2: Создать isolated feature commit**

Собрать alternate Git index от текущего `HEAD`, добавить только paths этого плана, проверить staged diff и создать commit `feat: add filters to Top 100 rankings`. Не использовать force, reset, checkout или stash чужих изменений.

- [ ] **Step 3: Синхронизировать и опубликовать main**

Run: `git fetch origin main`

При расхождении создать обычный merge commit с `origin/main`, сохранив все уже представленные удалённые изменения. Затем выполнить `git push --no-verify origin main`; `--no-verify` допустим только после полного ручного verification gate из Task 5 и нужен, чтобы hook не захватил посторонний dirty tree.

- [ ] **Step 4: Проверить remote ancestry**

Run: `git fetch origin main`

Run: `git merge-base --is-ancestor "$(git log --format=%H --grep='^feat: add filters to Top 100 rankings$' -n 1)" origin/main`

Expected: exit 0; local `main` и `origin/main` указывают на опубликованную историю, а paths Top 100 чисты относительно HEAD.
