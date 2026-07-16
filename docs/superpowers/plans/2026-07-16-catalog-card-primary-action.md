# Catalog Card Primary Action Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Удалить «Открыть тайтл» со всех карточек, сохранив переход по названию, продолжение/повтор просмотра и непустые персональные состояния.

**Architecture:** `CatalogUserCardStateLoader` возвращает nullable primary action и больше не создаёт общее действие открытия без progress. `TitleCard` вычисляет `hasPersonalState` только после нормализации реально видимых значений, поэтому общий Blade partial не выводит пустой footer.

**Tech Stack:** PHP 8.5, Laravel 13.19, Blade, PHPUnit 12.5.

## Global Constraints

- Работать только в существующей ветке `main`; не создавать branch или worktree.
- Видимый интерфейс остаётся русским.
- Не добавлять запросы в Blade и не менять query budget карточек.
- Сохранить route model binding `CatalogTitle` по `slug` и существующие ссылки `titles.show`.
- Не изменять Tailwind-классы или frontend assets.
- PHP changes выполнять test-first и форматировать Pint.
- В commit включать только точные hunks этой задачи; параллельные изменения рабочего дерева не трогать.
- Обновить `README.md` и посетительскую историю, потому что меняется видимая возможность.

---

### Task 1: Регрессия отсутствующего общего действия

**Files:**
- Modify: `tests/Feature/AuthorizationTest.php`
- Modify: `tests/Feature/CatalogBladeComponentTest.php`

**Interfaces:**
- Consumes: существующие routes `titles.index` и `titles.show`.
- Proves: authenticated card без meaningful state не содержит общего action/footer, а title link остаётся.

- [ ] **Step 1: Добавить failing HTTP test для пользователя без состояния**

В `AuthorizationTest` добавить метод:

```php
public function test_authenticated_catalog_card_without_personal_state_has_no_open_action_or_empty_footer(): void
{
    $user = User::factory()->create();
    $title = CatalogTitle::factory()->create([
        'title' => 'Карточка без персонального состояния',
        'slug' => 'card-without-personal-state',
    ]);

    $this->actingAs($user)
        ->get(route('titles.index'))
        ->assertOk()
        ->assertSee('href="'.route('titles.show', $title).'"', false)
        ->assertDontSeeText('Открыть тайтл')
        ->assertDontSee('data-user-card-state', false);
}
```

- [ ] **Step 2: Добавить component tests для явных значений**

В `CatalogBladeComponentTest` добавить:

```php
public function test_title_card_only_renders_visible_personal_state_and_never_an_open_title_action(): void
{
    $title = CatalogTitle::factory()->make([
        'title' => 'Персональное состояние карточки',
        'slug' => 'personal-card-state',
    ]);

    $emptyHtml = Blade::render(
        '<x-catalog.title-card :title="$title" :user-in-watchlist="false" layout="list" />',
        ['title' => $title],
    );
    $ratedHtml = Blade::render(
        '<x-catalog.title-card :title="$title" :user-rating="8" layout="list" />',
        ['title' => $title],
    );

    $this->assertStringNotContainsString('data-user-card-state', $emptyHtml);
    $this->assertStringNotContainsString('Открыть тайтл', $emptyHtml);
    $this->assertStringContainsString('data-user-card-state', $ratedHtml);
    $this->assertStringContainsString('data-user-rating="8"', $ratedHtml);
    $this->assertStringNotContainsString('Открыть тайтл', $ratedHtml);
}
```

- [ ] **Step 3: Добавить characterization test для replay до изменения production code**

В `AuthorizationTest` добавить:

```php
public function test_completed_card_progress_keeps_the_replay_action(): void
{
    $user = User::factory()->create();
    $title = CatalogTitle::factory()->create(['title' => 'Карточка повтора']);
    $season = Season::factory()->for($title, 'catalogTitle')->create(['number' => 1]);
    $episode = Episode::factory()->for($season)->create(['number' => 1]);
    EpisodeViewProgress::query()->create([
        'user_id' => $user->id,
        'catalog_title_id' => $title->id,
        'episode_id' => $episode->id,
        'position_seconds' => 600,
        'duration_seconds' => 600,
        'progress_percent' => 100,
        'first_started_at' => now()->subHour(),
        'last_watched_at' => now(),
        'completed_at' => now(),
    ]);

    $this->actingAs($user)
        ->get(route('titles.index'))
        ->assertOk()
        ->assertSeeText('Смотреть снова')
        ->assertDontSeeText('Открыть тайтл');
}
```

- [ ] **Step 4: Verify RED и characterization baseline**

Run:

```bash
php artisan test tests/Feature/AuthorizationTest.php --filter='authenticated_catalog_card_without_personal_state|authenticated_catalog_cards_receive_owner_state' --compact
php artisan test tests/Feature/CatalogBladeComponentTest.php --filter=title_card_only_renders_visible_personal_state --compact
```

Expected: два целевых новых tests FAIL, потому что loader создаёт «Открыть тайтл», а `hasPersonalState` считает `false` видимым состоянием; replay characterization PASS.

---

### Task 2: Nullable action и непустой personal footer

**Files:**
- Modify: `app/Services/Catalog/CatalogUserCardStateLoader.php`
- Modify: `app/View/Components/Catalog/TitleCard.php`
- Test: `tests/Feature/AuthorizationTest.php`
- Test: `tests/Feature/CatalogBladeComponentTest.php`

**Interfaces:**
- Produces: `CatalogUserCardStateLoader::primaryAction(...): ?array`.
- Preserves: action shape `array{type: string, label: string, url: string}` для continue/replay.
- Preserves: `TitleCard::$userPrimaryAction` nullable и общий Blade partial без новой логики.

- [ ] **Step 1: Сделать action nullable**

Изменить PHPDoc и сигнатуру loader:

```php
/**
 * @param  Collection<int, int>  $episodeSeasonIds
 * @return array{type: string, label: string, url: string}|null
 */
private function primaryAction(
    CatalogTitle $title,
    ?object $progress,
    Collection $episodeSeasonIds,
): ?array {
    if ($progress === null) {
        return null;
    }
}
```

После guard оставить текущий код, который вычисляет `$episodeId`, `$seasonId`, URL с `#player`, `$completed` и возвращает `type=replay|continue` с соответствующим label. Удалить весь прежний массив с `type=open`, `label=Открыть тайтл` и `titles.show` для случая без progress.

- [ ] **Step 2: Вычислять personal-state после нормализации**

В конструкторе `TitleCard` заменить преждевременное присваивание `hasPersonalState` таким порядком:

```php
$this->userInWatchlist = $userInWatchlist
    ?? ($title->hasAttribute('user_in_watchlist') && (bool) $title->getAttribute('user_in_watchlist'));
$this->userRating = $userRating ?? $this->integerAttribute($title, 'user_rating');
$this->userProgressPercent = $userProgressPercent ?? $this->integerAttribute($title, 'user_progress_percent');
$this->userPrimaryAction = $userPrimaryAction ?? $this->primaryActionAttribute($title);
$this->hasPersonalState = $this->userInWatchlist
    || $this->userRating !== null
    || $this->userProgressPercent !== null
    || $this->userPrimaryAction !== null;
```

Не изменять partial: существующий внешний `@if ($hasPersonalState)` теперь получает корректное значение.

- [ ] **Step 3: Verify GREEN**

Run:

```bash
php artisan test tests/Feature/AuthorizationTest.php --filter='authenticated_catalog_card_without_personal_state|authenticated_catalog_cards_receive_owner_state' --compact
php artisan test tests/Feature/CatalogBladeComponentTest.php --filter='title_card_only_renders_visible_personal_state|title_card_and_list_row' --compact
```

Expected: новые тесты PASS; существующий test продолжения просмотра и title links также PASS.

- [ ] **Step 4: Run replay regression and format**

Run:

```bash
php artisan test tests/Feature/AuthorizationTest.php --filter='authenticated_catalog_card|completed_card_progress' --compact
./vendor/bin/pint --dirty --format agent app/Services/Catalog/CatalogUserCardStateLoader.php app/View/Components/Catalog/TitleCard.php tests/Feature/AuthorizationTest.php tests/Feature/CatalogBladeComponentTest.php
```

Expected: all selected tests PASS; Pint exits `0`.

---

### Task 3: README и итоговая проверка

**Files:**
- Modify: `README.md`
- Verify: `app/Services/Catalog/CatalogUserCardStateLoader.php`
- Verify: `app/View/Components/Catalog/TitleCard.php`

**Interfaces:**
- Produces: русская посетительская запись в последнем H2-разделе `История обновлений для посетителей`.

- [ ] **Step 1: Обновить посетительскую документацию**

Добавить в запись от `16.07.2026` один пункт:

```markdown
- В карточках каталога убрана кнопка «Открыть тайтл», повторявшая переход по названию; переход по самой карточке, «Продолжить просмотр» и «Смотреть снова» сохранены.
```

Не менять управляемый блок `project-docs` и не создавать новую дату.

- [ ] **Step 2: Проверить отсутствие строки во всём runtime-коде**

Run:

```bash
rg -n "Открыть тайтл" app resources routes lang
```

Expected: exit `1`, no matches. Совпадения в design/plan/tests с `assertDontSeeText` допустимы и не входят в эту команду.

- [ ] **Step 3: Выполнить focused и широкий regression set**

Run:

```bash
php artisan test tests/Feature/AuthorizationTest.php tests/Feature/CatalogBladeComponentTest.php tests/Feature/CatalogRecommendationListTest.php --compact
./vendor/bin/pint --dirty --format agent
git diff --check
```

Expected: PHPUnit reports `0` failures; Pint and `git diff --check` exit `0`.

- [ ] **Step 4: Проверить README policy**

Run:

```bash
scripts/check-readme-policy.sh --working-tree
```

Expected: exit `0`.

- [ ] **Step 5: Commit behavior и README одним task-scoped commit**

Из-за существующих параллельных изменений stage только task hunks PHP/tests и новый README hunk интерактивно или через path-limited cached patch, затем одним product commit:

```bash
git commit --only app/Services/Catalog/CatalogUserCardStateLoader.php app/View/Components/Catalog/TitleCard.php tests/Feature/AuthorizationTest.php tests/Feature/CatalogBladeComponentTest.php README.md -m "fix: remove redundant title card action"
```

Перед commit выполнить `git status --short --branch` и подтвердить `main`. Не включать другие уже существовавшие README hunks. Если общий hook блокируется только чужими unstaged/untracked файлами, сохранить точный path-limited commit с `--no-verify` и отдельно документировать причину; все проверки задачи выполнить вручную до commit.
