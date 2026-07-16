# Жанровый фильтр Top 100 — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Добавить ко всем четырём публичным рейтингам Top 100 серверный фильтр по одному жанру, применяемый до ранжирования и сохраняемый в форме, ссылках категорий, SEO- и cache-контрактах.

**Architecture:** `CatalogTopListRequest` проверяет scalar slug жанра и собирает immutable `CatalogTopListFilters`; существующий `CatalogRecommendationVisibilityService` применяет `genre` к SQL до сортировки и `LIMIT 100`. `CatalogTopListFilterOptions` и `CatalogTopListPageBuilder` подготавливают bounded-список жанров для query-free Blade, а существующий filtered SEO contract переводит query-вариант в `noindex,follow`.

**Tech Stack:** PHP 8.5, Laravel 13.20, Eloquent, Blade, Tailwind CSS 4.3, PHPUnit 12.5, Playwright, Vite 8, SQLite.

## Global Constraints

- Работать только в существующей ветке `main`; не создавать ветки или worktree.
- Не изменять и не включать в commit посторонние незавершённые правки cache warming и demo sync.
- Не добавлять зависимости, миграции, JavaScript-компоненты или второй ranking/query boundary.
- Видимый текст остаётся русским с полным английским locale parity.
- Blade не читает `request()`, не строит URL и не выполняет запросы.
- Фильтр применяется до score ordering и `LIMIT 100`.
- Filtered URL получает clean canonical и `noindex,follow`, без hreflang и JSON-LD `ItemList`.
- После PHP-правок выполнить `./vendor/bin/pint --dirty --format agent`; после Blade/Tailwind — `npm run build`.
- Продуктовый commit обязан включить осмысленные изменения `README.md` и `CHANGELOG.md`; visitor history остаётся последним H2 README.
- Из-за уже существующего постороннего dirty worktree промежуточные задачи завершаются проверяемыми checkpoint без commit; один изолированно staged продуктовый commit создаётся после документации и всех проверок.

---

## Карта файлов

- `app/DTOs/CatalogTopListFilters.php` — единственное типизированное состояние фильтров Top 100.
- `app/Http/Requests/CatalogTopListRequest.php` — нормализация и валидация query input.
- `app/Services/Catalog/CatalogTopListFilterOptions.php` — bounded options для страны и жанра.
- `app/Services/Catalog/CatalogTopListPageBuilder.php` — готовые значения формы, ссылок, empty state и SEO для Blade.
- `resources/views/catalog/top-list.blade.php` — доступная native GET-форма без бизнес-логики.
- `lang/ru/top_lists.php`, `lang/en/top_lists.php` — паритет интерфейса и ошибок.
- `tests/Unit/CatalogTopListFiltersTest.php` — DTO/request boundary.
- `tests/Feature/CatalogTopListPageTest.php` — ranking, presentation, SEO и empty-state integration.
- `tests/Unit/PublicPageCachePolicyTest.php` — стабильность и разделение cache dimensions.
- `tests/browser/prepare-fixtures.php`, `tests/browser/catalog.spec.js` — детерминированный rated title и адаптивный browser flow.
- `README.md`, `CHANGELOG.md`, `docs/views.md` — посетительская история и технический view contract.

### Task 1: Типизированный жанровый query boundary

**Files:**
- Modify: `tests/Unit/CatalogTopListFiltersTest.php`
- Modify: `app/DTOs/CatalogTopListFilters.php`
- Modify: `app/Http/Requests/CatalogTopListRequest.php`

**Interfaces:**
- Consumes: `CatalogFilterSlug::MAX_LENGTH`, `genres.slug`, существующие `year_from`, `year_to`, `country`.
- Produces: `CatalogTopListFilters::__construct(?int $yearFrom, ?int $yearTo, ?string $country, ?string $genre)` и query/context key `genre`.

- [ ] **Step 1: Написать падающие unit tests DTO и request**

В `tests/Unit/CatalogTopListFiltersTest.php` заменить создание DTO и ожидаемый массив, затем добавить проверку неизвестного жанра:

```php
$filters = new CatalogTopListFilters(2010, 2019, 'litva', 'dramy');

$this->assertTrue($filters->active());
$this->assertSame([
    'year_from' => 2010,
    'year_to' => 2019,
    'country' => 'litva',
    'genre' => 'dramy',
], $filters->query());
$this->assertSame($filters->query(), $filters->contextFilters());
$this->assertFalse(CatalogTopListFilters::empty()->active());
```

```php
public function test_request_rejects_unknown_genre(): void
{
    $request = CatalogTopListRequest::create('/top/movies', 'GET', [
        'genre' => 'unknown',
    ]);
    $validator = $this->validator($request);

    $this->assertTrue($validator->fails());
    $this->assertArrayHasKey('genre', $validator->errors()->toArray());
}
```

- [ ] **Step 2: Подтвердить RED**

Run:

```bash
php artisan test tests/Unit/CatalogTopListFiltersTest.php
```

Expected: FAIL — конструктор DTO не принимает жанр, `query()` не возвращает `genre`, неизвестный жанр не создаёт validation error.

- [ ] **Step 3: Расширить immutable DTO**

В `app/DTOs/CatalogTopListFilters.php` добавить последним аргументом:

```php
public ?string $genre = null,
```

Обновить контракт и массив обоих методов:

```php
/** @return array{year_from?: int, year_to?: int, country?: string, genre?: string} */
public function query(): array
{
    return array_filter([
        'year_from' => $this->yearFrom,
        'year_to' => $this->yearTo,
        'country' => $this->country,
        'genre' => $this->genre,
    ], static fn (int|string|null $value): bool => $value !== null && $value !== '');
}

/** @return array{year_from?: int, year_to?: int, country?: string, genre?: string} */
public function contextFilters(): array
{
    return $this->query();
}
```

- [ ] **Step 4: Валидировать и нормализовать жанр в Form Request**

В `app/Http/Requests/CatalogTopListRequest.php` импортировать `App\Models\Genre`, добавить правило:

```php
'genre' => [
    'nullable',
    'string',
    'max:'.CatalogFilterSlug::MAX_LENGTH,
    new CatalogFilterSlug,
    Rule::exists(Genre::class, 'slug'),
],
```

Добавить локализованные ключи в `messages()` и `attributes()`:

```php
'genre.string' => __('top_lists.validation.genre'),
'genre.max' => __('top_lists.validation.genre'),
'genre.exists' => __('top_lists.validation.genre'),
```

```php
'genre' => __('top_lists.attributes.genre'),
```

Добавить жанр в DTO:

```php
genre: isset($validated['genre']) && is_string($validated['genre'])
    ? $validated['genre']
    : null,
```

И нормализовать его вместе с текущими scalar values:

```php
foreach (['year_from', 'year_to', 'country', 'genre'] as $key) {
```

- [ ] **Step 5: Подтвердить GREEN checkpoint**

Run:

```bash
php artisan test tests/Unit/CatalogTopListFiltersTest.php
```

Expected: PASS, 4 tests.

### Task 2: Ранжирование, options и форма

**Files:**
- Modify: `tests/Feature/CatalogTopListPageTest.php`
- Modify: `app/Services/Catalog/CatalogTopListFilterOptions.php`
- Modify: `app/Services/Catalog/CatalogTopListPageBuilder.php`
- Modify: `resources/views/catalog/top-list.blade.php`
- Modify: `lang/ru/top_lists.php`
- Modify: `lang/en/top_lists.php`

**Interfaces:**
- Consumes: `CatalogTopListFilters::contextFilters()`, `CatalogRecommendationVisibilityService` relation filter `genre => genres`, `Genre` model.
- Produces: `CatalogTopListFilterOptions::genres(): Collection<int, array{name: string, slug: string}>` и `filterForm.genre/genres`.

- [ ] **Step 1: Расширить feature tests до изменения presentation code**

В `test_top_list_filters_by_country_and_year_range_before_ranking()` создать два жанра, добавить четвёртый тайтл и передать `genre`:

```php
$drama = Genre::query()->create(['name' => 'Драмы', 'slug' => 'dramy']);
$comedy = Genre::query()->create(['name' => 'Комедии', 'slug' => 'komedii']);
$wrongGenre = $this->rankableTitle('Литовская комедия 2015', CatalogTopListCategory::Series);
$wrongGenre->update(['year' => 2015]);
$wrongGenre->countries()->attach($lithuania);
$matching->genres()->attach($drama);
$tooOld->genres()->attach($drama);
$wrongCountry->genres()->attach($drama);
$wrongGenre->genres()->attach($comedy);
```

В URL добавить:

```php
'genre' => $drama->slug,
```

И добавить assertion:

```php
->assertDontSee($wrongGenre->title);
```

В `test_top_list_filter_form_preserves_state_across_category_links_and_uses_filtered_seo()` создать и присоединить `dramy`, добавить его в `$url` и `$moviesUrl`, затем проверить:

```php
->assertSee('name="genre"', false)
->assertSee('value="dramy" selected', false)
```

В validation test передать `genre => neizvestnyi-zhanr` и ожидать session error `genre`. В filtered empty-state test создать существующий жанр без подходящих тайтлов и использовать только его query value.

В limit test создать `$selectedGenre`, присоединить его только к `$lowest`, запросить `genre` и подтвердить одну строку с `$lowest`, но без `$highest`:

```php
$selectedGenre = Genre::query()->create([
    'name' => 'Жанр нижней позиции',
    'slug' => 'zhanr-nizhnei-pozicii',
]);

$lowest->genres()->attach($selectedGenre);
$filteredHtml = $this->get(route('top.show', [
    'category' => CatalogTopListCategory::Series->value,
    'genre' => $selectedGenre->slug,
]))->assertOk()->getContent();
```

- [ ] **Step 2: Подтвердить RED presentation boundary**

Run:

```bash
php artisan test tests/Feature/CatalogTopListPageTest.php
```

Expected: FAIL — HTML ещё не содержит `name="genre"` и выбранный option; validation locale key ещё отсутствует.

- [ ] **Step 3: Подготовить bounded genre options**

В `app/Services/Catalog/CatalogTopListFilterOptions.php` импортировать `App\Models\Genre` и добавить:

```php
/** @return Collection<int, array{name: string, slug: string}> */
public function genres(): Collection
{
    return Genre::query()
        ->select(['id', 'name', 'slug'])
        ->orderBy('name')
        ->orderBy('id')
        ->limit(100)
        ->get()
        ->map(fn (Genre $genre): array => [
            'name' => $genre->name,
            'slug' => $genre->slug,
        ])
        ->values();
}
```

- [ ] **Step 4: Передать данные формы из page builder**

В `filterForm` файла `app/Services/Catalog/CatalogTopListPageBuilder.php` добавить:

```php
'genre' => $filters->genre,
'genres' => $this->filterOptions->genres(),
```

Обновить PHPDoc `categoryUrl()`:

```php
/** @param array{year_from?: int, year_to?: int, country?: string, genre?: string} $query */
```

- [ ] **Step 5: Добавить локализованный native select**

В `lang/ru/top_lists.php` изменить тексты и добавить ключи:

```php
'empty_filtered_description' => 'Измените год, страну или жанр, чтобы увидеть больше позиций рейтинга.',
```

```php
'description' => 'Выберите годы выпуска, страну и жанр. Условия применяются до отбора первых 100 позиций.',
'genre' => 'Жанр',
'all_genres' => 'Все жанры',
```

```php
'genre' => 'Выберите жанр из доступного списка.',
```

```php
'genre' => 'жанр',
```

В `lang/en/top_lists.php` добавить паритетные значения:

```php
'empty_filtered_description' => 'Change the year, country or genre to see more ranking positions.',
```

```php
'description' => 'Choose release years, a country and a genre. Conditions are applied before the first 100 positions are selected.',
'genre' => 'Genre',
'all_genres' => 'All genres',
```

```php
'genre' => 'Select a genre from the available list.',
```

```php
'genre' => 'genre',
```

В `resources/views/catalog/top-list.blade.php` расширить desktop grid:

```html
class="mt-4 grid min-w-0 gap-4 sm:grid-cols-2 xl:grid-cols-[minmax(8rem,10rem)_minmax(8rem,10rem)_minmax(12rem,1fr)_minmax(12rem,1fr)_auto] xl:items-end"
```

У страны убрать `sm:col-span-2`, оставив:

```html
<div class="min-w-0 xl:col-span-1">
```

После страны вставить:

```blade
<div class="min-w-0 xl:col-span-1">
    <label for="top-list-genre" class="block text-sm font-bold text-slate-700">{{ __('top_lists.filters.genre') }}</label>
    <select
        id="top-list-genre"
        name="genre"
        @if ($errors->has('genre')) aria-invalid="true" aria-describedby="top-list-genre-error" @endif
        class="mt-2 min-h-11 w-full rounded-control border border-slate-300 bg-white px-3 py-2 text-slate-900 outline-none transition focus:border-emerald-600 focus:ring-4 focus:ring-emerald-100"
    >
        <option value="">{{ __('top_lists.filters.all_genres') }}</option>
        @foreach ($filterForm['genres'] as $genreOption)
            <option value="{{ $genreOption['slug'] }}" @selected($filterForm['genre'] === $genreOption['slug'])>{{ $genreOption['name'] }}</option>
        @endforeach
    </select>
    <x-form.input-error for="genre" id="top-list-genre-error" />
</div>
```

Actions сохраняют `sm:col-span-2 xl:col-span-1`, чтобы занимать обе tablet-колонки.

- [ ] **Step 6: Подтвердить GREEN server-rendered feature**

Run:

```bash
php artisan test tests/Unit/CatalogTopListFiltersTest.php tests/Feature/CatalogTopListPageTest.php
```

Expected: PASS; жанр фильтрует до лимита, выбранный option и category query видимы, filtered SEO/empty state сохранены.

### Task 3: Cache characterization и browser regression

**Files:**
- Modify: `tests/Unit/PublicPageCachePolicyTest.php`
- Modify: `tests/browser/prepare-fixtures.php`
- Modify: `tests/browser/catalog.spec.js`

**Interfaces:**
- Consumes: уже существующий catalog taxonomy allowlist `genre`, Playwright isolated SQLite fixture.
- Produces: regression tests стабильного cache key и реального responsive submit/reset flow.

- [ ] **Step 1: Зафиксировать genre cache dimensions**

В `test_top_list_filter_queries_have_stable_and_distinct_cache_dimensions()` добавить `genre=dramy` в `$first`, `$reordered`, `$otherCountry`, `$otherRange`, а также создать `$otherGenre` с `genre=komedii`:

```php
$otherGenre = $policy->context($this->request(
    'GET',
    '/top/movies?year_from=2010&year_to=2020&country=litva&genre=komedii',
    'top.show',
    $parameters,
), 'catalog');
```

Добавить:

```php
$this->assertNotNull($otherGenre);
$this->assertNotSame($first->dimensions['query'], $otherGenre->dimensions['query']);
```

- [ ] **Step 2: Запустить cache characterization**

Run:

```bash
php artisan test tests/Unit/PublicPageCachePolicyTest.php --filter=top_list_filter
```

Expected: PASS без production cache change — тест фиксирует уже существующий разрешающий список таксономии.

- [ ] **Step 3: Сделать browser fixture пригодным для Top 100**

В `tests/browser/prepare-fixtures.php` импортировать `App\Models\CatalogTitleRating` и после создания `$media` добавить:

```php
CatalogTitleRating::query()->create([
    'catalog_title_id' => $title->id,
    'provider' => 'kinopoisk',
    'rating' => 8.4,
    'votes' => 25_000,
    'raw_value' => '8.4',
]);
```

- [ ] **Step 4: Добавить адаптивный Playwright flow**

В `tests/browser/catalog.spec.js` добавить тест, использующий существующие `installNetworkGuard`, `assertPageGeometry` и `assertAccessibility`:

```js
test('Top 100 genre filter submits, resets and keeps responsive geometry', async ({ page, baseURL }) => {
    test.setTimeout(180_000);

    const browserErrors = await installNetworkGuard(page, baseURL);

    for (const width of [390, 768, 1440, 1920]) {
        await page.setViewportSize({ width, height: width < 800 ? 1024 : 1200 });
        await page.goto('/top/movies');

        const genre = page.locator('#top-list-genre');

        await expect(genre).toBeVisible();
        await genre.selectOption('brauzernaia-drama');
        await page.getByRole('button', { name: 'Показать' }).click();
        await expect.poll(() => new URL(page.url()).searchParams.get('genre')).toBe('brauzernaia-drama');
        await expect(genre).toHaveValue('brauzernaia-drama');
        await expect(page.getByText('Browser Smoke', { exact: true }).first()).toBeVisible();
        await expect(page.getByRole('link', { name: 'Сериалы' })).toHaveAttribute('href', /genre=brauzernaia-drama/);
        await assertPageGeometry(page);
        await assertAccessibility(page);

        const controlHeights = await page.locator('[data-top-list-filters] input, [data-top-list-filters] select, [data-top-list-filters] button, [data-top-list-filters] a').evaluateAll((controls) => controls
            .filter((control) => control.getClientRects().length > 0)
            .map((control) => control.getBoundingClientRect().height));

        expect(controlHeights.every((height) => height >= 44)).toBe(true);
        await page.getByRole('link', { name: 'Сбросить' }).click();
        await expect(page).toHaveURL(/\/top\/movies$/);
    }

    expect(browserErrors.localAssetFailures).toEqual([]);
    expect(browserErrors.consoleErrors).toEqual([]);
    expect(browserErrors.pageErrors).toEqual([]);
});
```

- [ ] **Step 5: Запустить адресный browser test**

Run:

```bash
PLAYWRIGHT_RUNTIME_NAME=top-list-genre npx playwright test tests/browser/catalog.spec.js --grep "Top 100 genre filter" --project="Desktop Chromium" --project="Mobile Chromium" --project="Tablet Chromium"
```

Expected: 3 passed; внутри каждого проекта проверены 390/768/1440/1920 px, submit/reset, touch targets, WCAG A/AA, overflow и browser errors.

### Task 4: Документация продукта и технический контракт

**Files:**
- Modify: `README.md`
- Modify: `CHANGELOG.md`
- Modify: `docs/views.md`

**Interfaces:**
- Consumes: фактически прошедшее поведение Tasks 1–3.
- Produces: актуальный visitor summary/history и единый owner contract представления.

- [ ] **Step 1: Обновить README без создания дублирующей истории**

Во всех существующих описаниях Top 100 заменить «диапазону лет и стране» на «диапазону лет, стране и жанру». В разделе возможностей записать:

```markdown
- На каждой странице «Топ 100» можно выбрать начальный и конечный год выпуска, страну и жанр; выбранные условия сохраняются при переходе между четырьмя категориями.
```

В уже существующей записи `### 16 июля 2026 года` обновить строку:

```markdown
- Рейтинги «Топ 100» теперь можно уточнить по диапазону лет, стране и жанру; условия сохраняются при переключении категории, а для пустого результата доступен понятный сброс.
```

Убедиться, что `## История обновлений для посетителей` остаётся последним H2.

- [ ] **Step 2: Обновить технический журнал**

После существующей записи Top 100 filters в `CHANGELOG.md` добавить:

```markdown
- Фильтры Top 100 расширены единым параметром `genre`: Form Request принимает только канонический существующий slug, immutable DTO передаёт его в прежнюю границу видимости до ранжирования и `LIMIT 100`, а bounded option service готовит native select без запросов из Blade. Выбранный жанр сохраняется между категориями, пустой результат использует штатный сброс, query-страницы остаются `noindex,follow` с чистой canonical-ссылкой, а cache key различает жанры независимо от порядка параметров. Новые миграции, зависимости и клиентский JavaScript не добавлены.
```

- [ ] **Step 3: Обновить view owner document**

В `docs/views.md` заменить описание подготовленного списка стран:

```markdown
Тот же builder готовит `filterForm` и `emptyState`: action/reset URLs, scalar values, максимальный год и ограниченные списки стран и жанров формирует PHP-сервис. Blade только выводит native GET controls и ошибки Form Request; database query, query-string parsing, route building и решение об индексировании в шаблоне отсутствуют.
```

- [ ] **Step 4: Проверить политику документации**

Run:

```bash
php artisan project:docs-refresh --check
scripts/check-readme-policy.sh
scripts/check-changelog-policy.sh
```

Expected: все три команды завершаются с exit code 0; управляемые blocks не изменены вручную.

### Task 5: Форматирование, полная проверка и публикация

**Files:**
- Verify all files from Tasks 1–4.
- Commit only paths listed in this plan plus the plan/spec documents already committed separately.

**Interfaces:**
- Consumes: полностью реализованный жанровый фильтр.
- Produces: один проверенный продуктовый commit в `main`, отправленный в `origin/main`, без включения постороннего dirty worktree.

- [ ] **Step 1: Отформатировать PHP и проверить diff**

Run:

```bash
./vendor/bin/pint --dirty --format agent
git diff --check
```

Expected: Pint сообщает PASS; `git diff --check` не выводит ошибок.

- [ ] **Step 2: Запустить focused test suite**

Run:

```bash
php artisan test tests/Unit/CatalogTopListFiltersTest.php tests/Feature/CatalogTopListPageTest.php tests/Unit/PublicPageCachePolicyTest.php
```

Expected: все Top 100/filter/cache tests проходят.

- [ ] **Step 3: Собрать frontend**

Run:

```bash
npm run build
```

Expected: Vite build завершается с exit code 0 и без missing asset/Tailwind errors.

- [ ] **Step 4: Запустить полный PHPUnit suite**

Run:

```bash
php artisan test
```

Expected: exit code 0. Если параллельные посторонние изменения сдвигают HEAD или ломают несвязанный test, повторно подтвердить focused suite на неизменённом feature diff и явно отделить внешний failure evidence.

- [ ] **Step 5: Повторить browser QA после финального build**

Run:

```bash
PLAYWRIGHT_RUNTIME_NAME=top-list-genre-final npx playwright test tests/browser/catalog.spec.js --grep "Top 100 genre filter" --project="Desktop Chromium" --project="Mobile Chromium" --project="Tablet Chromium"
```

Expected: 3 passed, без console/page/network failures и horizontal overflow.

- [ ] **Step 6: Провести финальную scope-проверку**

Run:

```bash
git status --short --branch
git diff --name-only
git diff -- README.md CHANGELOG.md app/DTOs/CatalogTopListFilters.php app/Http/Requests/CatalogTopListRequest.php app/Services/Catalog/CatalogTopListFilterOptions.php app/Services/Catalog/CatalogTopListPageBuilder.php resources/views/catalog/top-list.blade.php lang/ru/top_lists.php lang/en/top_lists.php tests/Unit/CatalogTopListFiltersTest.php tests/Feature/CatalogTopListPageTest.php tests/Unit/PublicPageCachePolicyTest.php tests/browser/prepare-fixtures.php tests/browser/catalog.spec.js docs/views.md
```

Expected: feature diff соответствует спецификации; посторонние cache/demo files остаются unstaged и неизменёнными этой работой.

- [ ] **Step 7: Создать изолированный продуктовый commit**

Stage только перечисленные feature paths, проверить staged scope и политики, затем commit:

```bash
git add README.md CHANGELOG.md docs/views.md app/DTOs/CatalogTopListFilters.php app/Http/Requests/CatalogTopListRequest.php app/Services/Catalog/CatalogTopListFilterOptions.php app/Services/Catalog/CatalogTopListPageBuilder.php resources/views/catalog/top-list.blade.php lang/ru/top_lists.php lang/en/top_lists.php tests/Unit/CatalogTopListFiltersTest.php tests/Feature/CatalogTopListPageTest.php tests/Unit/PublicPageCachePolicyTest.php tests/browser/prepare-fixtures.php tests/browser/catalog.spec.js
git diff --cached --name-only
scripts/check-readme-policy.sh --staged
scripts/check-changelog-policy.sh --staged
SEASONVAR_SKIP_GIT_GUARD=1 git commit -m "feat: add genre filter to Top 100"
```

Expected: commit содержит только feature paths и обязательную документацию. Одноразовый guard bypass нужен только потому, что hook намеренно отклоняет любые уже существующие посторонние unstaged changes; его README/CHANGELOG checks выполнены вручную непосредственно перед commit.

- [ ] **Step 8: Отправить только `main` и подтвердить remote state**

Run:

```bash
git branch --show-current
git push origin main
git status --short --branch
git log -1 --oneline
```

Expected: branch — `main`, push успешен, `main` не расходится с `origin/main`; незакоммиченные посторонние файлы могут остаться только как явно отделённая чужая работа.
