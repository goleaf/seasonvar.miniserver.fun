# Заметные карточки персональных рекомендаций — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Выделить блок «Сериалы каталога» на
`/discover/personalized` светло-зелёным фоном, поместить результаты в белые
карточки и дать каждой рекомендации постоянно заметную кнопку
«Открыть сериал».

**Architecture:** Секция discovery получает conditional Tailwind shell
только для `personalized`, а строка результата владеет белым контейнером,
включающим карточку и feedback. Общий recommendation template переиспользует
существующий `.title-card-action-primary`; новый перевод добавляется с
RU/EN parity. Data/query/Livewire boundaries не меняются.

**Tech Stack:** PHP 8.5, Laravel 13.22, Livewire 4.3, Blade, Tailwind CSS
4.3, PHPUnit 12.5, Vite 8, Playwright.

## Global Constraints

- Работать только в существующей ветке `main` и lease
  `task-115-personalized-series-cards`.
- Не включать staged/unstaged изменения Task 113 и Task 114.
- Сохранить routes, Livewire state/actions, recommendation ranking,
  feedback, API, schema, cache keys, permissions и persistent data.
- Не добавлять packages, JavaScript, CSS rules, inline CSS, `@php`,
  database calls из Blade, gradients, glassmorphism или новую тень.
- Использовать существующие `rounded-panel`, `.title-card-action-primary`,
  local FontAwesome и light-theme tokens.
- Каждое новое поведение сначала получает failing PHPUnit assertion.
- CTA видна без hover, имеет минимум 44 px, видимый focus и перевод
  `Открыть сериал` / `Open series`.
- Phone остаётся одноколоночным без horizontal overflow; `xl` сохраняет две
  колонки.

---

### Task 1: Основное действие recommendation-card

**Files:**

- Modify: `tests/Feature/CatalogCompactTitleCardTest.php`
- Modify: `tests/Feature/CatalogRecommendationListTest.php`
- Modify: `lang/ru/catalog.php`
- Modify: `lang/en/catalog.php`
- Modify: `resources/views/components/catalog/title-card-recommendation.blade.php`

**Interfaces:**

- Consumes: существующий `route('titles.show', $title)`,
  `data-title-card-details` и `.title-card-action-primary`.
- Produces: translation key `catalog.title.open_series` и постоянную
  primary CTA recommendation-card.

- [ ] **Step 1: Написать failing assertion для заметной CTA**

В
`test_recommendation_card_prioritizes_three_reasons_and_bounded_metadata_over_long_copy()`
после существующей проверки `data-title-card-details` добавить:

```php
$this->assertStringContainsString('Открыть сериал', $html);
$this->assertMatchesRegularExpression(
    '/data-title-card-details[^>]*class="[^"]*title-card-action-primary[^"]*w-full[^"]*sm:w-auto[^"]*"/',
    $html,
);
$this->assertStringContainsString('href="'.route('titles.show', $title).'"', $html);
```

В
`test_title_page_renders_one_ranked_recommendation_list_with_uncropped_portrait_posters()`
добавить:

```php
$this->assertSame(2, substr_count($html, 'Открыть сериал'));
$this->assertSame(2, substr_count($html, 'title-card-action-primary'));
```

- [ ] **Step 2: Запустить RED**

Run:

```bash
php artisan test tests/Feature/CatalogCompactTitleCardTest.php \
  --filter=recommendation_card_prioritizes
php artisan test tests/Feature/CatalogRecommendationListTest.php \
  --filter=title_page_renders_one_ranked
```

Expected: FAIL, потому что HTML содержит `Подробнее` и не содержит
`title-card-action-primary`.

- [ ] **Step 3: Добавить RU/EN translation key**

Сразу после `more_details` в обоих каталогах добавить:

```php
// lang/ru/catalog.php
'open_series' => 'Открыть сериал',

// lang/en/catalog.php
'open_series' => 'Open series',
```

- [ ] **Step 4: Выполнить минимальную замену CTA**

В `title-card-recommendation.blade.php` сохранить route и marker, заменив
классы и label:

```blade
<a
    data-title-card-details
    href="{{ route('titles.show', $title) }}"
    class="title-card-action-primary relative z-10 mt-3 w-full sm:w-auto"
>
    <span>{{ __('catalog.title.open_series') }}</span>
    <x-ui.icon name="fa-solid fa-arrow-right text-xs" />
</a>
```

- [ ] **Step 5: Запустить GREEN и translation parity**

Run:

```bash
php artisan test \
  tests/Feature/CatalogCompactTitleCardTest.php \
  tests/Feature/CatalogRecommendationListTest.php \
  tests/Unit/TranslationCatalogParityTest.php
```

Expected: PASS; рекомендационная CTA использует canonical title route, а
структура RU/EN каталогов совпадает.

---

### Task 2: Персональный зелёный shell и белые строки-карточки

**Files:**

- Modify: `tests/Feature/CatalogDiscoveryLayoutTest.php`
- Modify: `resources/views/livewire/catalog-discovery-page.blade.php`

**Interfaces:**

- Consumes: `$type`, `data-discovery-title-results`,
  `data-recommendation-row`, существующую `xl:grid-cols-2` topology.
- Produces: `data-personalized-series-surface` на персональной секции и
  `data-discovery-series-card` на белых строках.

- [ ] **Step 1: Добавить imports и failing layout test**

В `CatalogDiscoveryLayoutTest.php` добавить:

```php
use App\Models\CatalogTitle;
use App\Models\LicensedMedia;
```

Затем добавить тест:

```php
public function test_personalized_series_results_use_green_surface_and_white_cards_only_in_personalized_mode(): void
{
    $title = CatalogTitle::factory()->create([
        'title' => 'Заметная персональная рекомендация',
        'indexed_at' => now(),
    ]);
    LicensedMedia::factory()->create([
        'catalog_title_id' => $title->id,
        'status' => 'published',
    ]);

    $personalizedHtml = $this->get(route('discover.index', ['type' => 'personalized']))
        ->assertOk()
        ->assertSee('data-personalized-series-surface', false)
        ->assertSee('data-discovery-series-card', false)
        ->getContent();

    preg_match(
        '/<section[^>]*data-discovery-title-results[^>]*class="([^"]*)"/',
        $personalizedHtml,
        $personalizedSection,
    );
    preg_match(
        '/<li[^>]*data-discovery-series-card[^>]*class="([^"]*)"/',
        $personalizedHtml,
        $personalizedCard,
    );

    $this->assertStringContainsString('bg-emerald-50', $personalizedSection[1] ?? '');
    $this->assertStringContainsString('border-emerald-200', $personalizedSection[1] ?? '');
    $this->assertStringContainsString('bg-white', $personalizedCard[1] ?? '');
    $this->assertStringContainsString('border-emerald-100', $personalizedCard[1] ?? '');

    $randomHtml = $this->get(route('discover.index', ['type' => 'random']))
        ->assertOk()
        ->assertDontSee('data-personalized-series-surface', false)
        ->getContent();

    preg_match(
        '/<section[^>]*data-discovery-title-results[^>]*class="([^"]*)"/',
        $randomHtml,
        $randomSection,
    );

    $this->assertStringNotContainsString('bg-emerald-50', $randomSection[1] ?? '');
}
```

- [ ] **Step 2: Запустить RED**

Run:

```bash
php artisan test tests/Feature/CatalogDiscoveryLayoutTest.php \
  --filter=personalized_series_results
```

Expected: FAIL на отсутствующем
`data-personalized-series-surface`.

- [ ] **Step 3: Добавить conditional section shell**

Заменить фиксированный `class` секции на:

```blade
<section
    id="{{ $titleResultsAnchor }}"
    data-discovery-title-results
    @if ($type === 'personalized') data-personalized-series-surface @endif
    aria-labelledby="discovery-results"
    aria-busy="false"
    @class([
        'scroll-mt-28',
        'rounded-panel border border-emerald-200 bg-emerald-50 p-4 sm:p-5' => $type === 'personalized',
        'border-t border-slate-200 pt-5' => $type !== 'personalized',
    ])
>
```

- [ ] **Step 4: Дать строке единый white-card container**

Заменить `<li>` recommendation loop на:

```blade
<li
    wire:key="discovery-{{ $type }}-{{ $result->page }}-{{ $recommendationItem->title->id }}"
    data-recommendation-row
    data-discovery-series-card
    @class([
        'min-w-0',
        'overflow-hidden rounded-panel border border-emerald-100 bg-white' => $type === 'personalized',
        'border-b border-slate-200 last:border-b-0 xl:odd:border-r xl:odd:pr-4 xl:even:pl-4' => $type !== 'personalized',
    ])
>
```

- [ ] **Step 5: Запустить GREEN и соседний discovery scope**

Run:

```bash
php artisan test \
  tests/Feature/CatalogDiscoveryLayoutTest.php \
  tests/Feature/CatalogDiscoveryInteractionTest.php \
  tests/Feature/CatalogRecommendationListTest.php
```

Expected: PASS; все девять режимов, Livewire actions и title-detail
recommendations сохраняются.

---

### Task 3: Canonical documentation и visitor history

**Files:**

- Modify: `docs/UI_STANDARDS.md`
- Modify: `docs/frontend.md`
- Modify: `README.md`
- Modify: `CHANGELOG.md`
- Modify: `docs/plans/task-115-personalized-series-cards-compliance.md`
- Modify: `docs/plans/current-task-plan.md`
- Create: `docs/plans/archive/2026-07-27-personalized-series-cards-evidence.md`

**Interfaces:**

- Consumes: verified behavior и команды Tasks 1–2.
- Produces: canonical presentation contract, visitor-visible history и
  honest compliance/evidence statuses.

- [ ] **Step 1: Обновить UI owners**

В discovery/card разделах `docs/UI_STANDARDS.md` и `docs/frontend.md`
зафиксировать: персональная results-секция использует светло-зелёный shell,
white result cards, а recommendation-card имеет постоянную primary
`Открыть сериал`; hover не является единственным affordance.

- [ ] **Step 2: Обновить visitor/product history**

Добавить отдельный русский пункт в последний раздел README:

```markdown
- В персональных рекомендациях блок «Сериалы каталога» получил
  светло-зелёный фон и белые карточки. Переход к каждому сериалу теперь
  всегда виден как отдельная зелёная кнопка, в том числе на телефоне.
```

Добавить датированный русский технический пункт в `CHANGELOG.md`, не
изменяя прежние записи.

- [ ] **Step 3: Сохранить evidence и закрыть матрицу**

В archive evidence записать exact RED/GREEN, build и browser outputs.
В compliance/current plan заменить только фактически подтверждённые
`unresolved` на `completed`; delivery оставить `unresolved`, если общий
staged index нельзя безопасно отделить.

- [ ] **Step 4: Проверить документацию**

Run:

```bash
php artisan project:docs-refresh --check
scripts/check-current-plan-policy.sh docs/plans/current-task-plan.md
scripts/check-readme-policy.sh README.md
scripts/check-changelog-policy.sh CHANGELOG.md
git diff --check
```

Expected: все команды exit `0`, managed blocks не устарели.

---

### Task 4: Frontend и browser acceptance

**Files:**

- Verify: `resources/views/livewire/catalog-discovery-page.blade.php`
- Verify: `resources/views/components/catalog/title-card-recommendation.blade.php`
- Modify: `tests/browser/recommendation-ui.spec.js`

**Interfaces:**

- Consumes: completed Tasks 1–3.
- Produces: build и desktop/mobile visual evidence.

- [ ] **Step 1: Собрать frontend**

Run:

```bash
npm run build
```

Expected: Vite build exit `0`; Blade Tailwind classes присутствуют в
generated CSS.

- [ ] **Step 2: Запустить focused и broad UI tests**

Run:

```bash
php artisan test \
  tests/Feature/CatalogDiscoveryLayoutTest.php \
  tests/Feature/CatalogDiscoveryInteractionTest.php \
  tests/Feature/CatalogCompactTitleCardTest.php \
  tests/Feature/CatalogRecommendationListTest.php \
  tests/Unit/TranslationCatalogParityTest.php
```

Expected: PASS без warnings/errors.

- [ ] **Step 3: Проверить production page через Playwright**

Сначала обновить существующий browser contract с `Подробнее` на
`Открыть сериал`, затем выполнить его на отдельном свободном порту:

```bash
PLAYWRIGHT_PORT=8014 PLAYWRIGHT_RUNTIME_NAME=task115 \
  npx playwright test tests/browser/recommendation-ui.spec.js
```

Expected: Desktop/Mobile/Tablet Chromium PASS.

На `https://seasonvar.miniserver.fun/discover/personalized` проверить
desktop `1440×1200` и phone `390×844`:

- section computed background не transparent;
- card computed background белый;
- `Открыть сериал` видна до hover, имеет высоту не меньше 44 px;
- Tab показывает focus ring, Enter открывает canonical title route;
- `document.documentElement.scrollWidth === window.innerWidth`;
- refresh и feedback остаются доступными.

Сохранить screenshots в `output/playwright/` как transient evidence, не
добавляя их в Git.

- [ ] **Step 4: Выполнить финальный repository search**

Run:

```bash
rg -n "data-personalized-series-surface|data-discovery-series-card|open_series|data-title-card-details" \
  resources/views lang tests docs README.md CHANGELOG.md
rg -n "bg-emerald-50|title-card-action-primary" \
  resources/views/livewire/catalog-discovery-page.blade.php \
  resources/views/components/catalog/title-card-recommendation.blade.php
```

Expected: ровно один canonical implementation для каждого нового marker/key;
старое прозрачное recommendation action styling отсутствует.

---

### Task 5: Exact delivery

**Files:**

- Stage: только paths из lease manifest Task 115.

**Interfaces:**

- Consumes: successful verification и completed evidence.
- Produces: один reviewed commit в `main` и push либо честный
  `unresolved`.

- [ ] **Step 1: Проверить branch, lease и чужой index**

Run:

```bash
git status --short --branch
scripts/task-workspace-lease.sh status
git diff --cached --name-status
```

Expected: branch `main`, matching Task 115 lease. Если index по-прежнему
содержит Task 113, не unstage/reset и не включать его в Task 115; delivery
зафиксировать как `unresolved`.

- [ ] **Step 2: При безопасном index подготовить exact paths**

Run:

```bash
git add -- \
  resources/views/livewire/catalog-discovery-page.blade.php \
  resources/views/components/catalog/title-card-recommendation.blade.php \
  lang/ru/catalog.php lang/en/catalog.php \
  tests/Feature/CatalogDiscoveryLayoutTest.php \
  tests/Feature/CatalogCompactTitleCardTest.php \
  tests/Feature/CatalogRecommendationListTest.php \
  tests/browser/recommendation-ui.spec.js \
  docs/UI_STANDARDS.md docs/frontend.md README.md CHANGELOG.md \
  docs/plans/current-task-plan.md \
  docs/plans/task-115-personalized-series-cards-compliance.md \
  docs/plans/archive/2026-07-27-personalized-series-cards-evidence.md \
  docs/superpowers/specs/2026-07-27-personalized-series-cards-design.md \
  docs/superpowers/plans/2026-07-27-personalized-series-cards.md
scripts/update-changelog-for-staged-code.sh
scripts/task-workspace-lease.sh verify-paths "$SEASONVAR_TASK_ID"
git diff --cached --name-status
git diff --cached --check
```

Expected: staged set точно совпадает с Task 115 manifest и не содержит
чужих hunks.

- [ ] **Step 3: Одобрить snapshot, commit и push**

Run:

```bash
scripts/task-workspace-lease.sh approve-index "$SEASONVAR_TASK_ID"
scripts/task-workspace-lease.sh verify-index "$SEASONVAR_TASK_ID"
git commit -m "feat: улучшить карточки персональных рекомендаций"
git push origin main
scripts/task-workspace-lease.sh release "$SEASONVAR_TASK_ID"
```

Expected: commit создан только в `main`, push успешен, lease освобождён.
При внешнем или shared-index блокере изменения не выдаются за доставленные.
