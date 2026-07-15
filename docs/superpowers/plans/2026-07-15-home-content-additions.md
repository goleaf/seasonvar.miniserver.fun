# Home Content Additions Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Показывать на главной только тайтлы с фактически созданными сериями/видео и группировать «Новые серии» по тайтлу без потери серий и видеовариантов.

**Architecture:** `CatalogHomeContentAdditionQuery` агрегирует публичные `episodes.created_at` и `licensed_media.created_at`, а snapshot хранит упорядоченные координаты тайтлов. Page builder гидратирует карточки и получает bounded группы дополнений; class-based Blade component превращает загруженные модели в presentation rows без запросов.

**Tech Stack:** PHP 8.5, Laravel 13.19, Eloquent/Query Builder, Blade, Tailwind CSS 4.3, PHPUnit 12.5, SQLite/MySQL-compatible additive migration.

## Global Constraints

- Работать только в существующей ветке `main`; не создавать branch/worktree.
- Не менять `indexed_at`: это timestamp поисковой индексации, а не фактического пополнения.
- Учитывать только публично доступные title/season/episode/media и не выполнять запросы из Blade.
- Один тайтл занимает одну карточку «Новых серий»; все additions за его последний календарный день видимы.
- Существующий API `latest_releases` остаётся плоским и не раскрывает raw URL/importer state.
- Новый production dependency не добавляется.
- Чужие изменения в рабочем дереве не редактируются и не включаются в commit.

---

### Task 1: Зафиксировать поведение фактических дополнений

**Files:**
- Create: `tests/Feature/CatalogHomeContentAdditionTest.php`
- Test: `tests/Feature/CatalogHomeContentAdditionTest.php`

**Interfaces:**
- Consumes: существующие factories `CatalogTitle`, `Season`, `Episode`, `LicensedMedia` и route `home`.
- Produces: executable contract для `CatalogHomeContentAdditionQuery::latestTitleUpdates()` и сгруппированного HTML.

- [ ] **Step 1: Написать failing tests для источника «Последних обновлений»**

Создать PHPUnit-класс с `RefreshDatabase`. В первом тесте создать metadata-only тайтл с самым новым `indexed_at`, тайтл с серией `created_at = now()->subMinute()` и тайтл с новым опубликованным видео `created_at = now()`. Проверить, что snapshot возвращает только два content-title ID и ставит видео-тайтл первым:

```php
$updates = app(CatalogHomeSnapshotCache::class)->refresh()['latest_title_updates'];

$this->assertSame(
    [$videoTitle->id, $episodeTitle->id],
    collect($updates)->pluck('id')->all(),
);
$this->assertNotContains($metadataOnly->id, collect($updates)->pluck('id')->all());
```

- [ ] **Step 2: Написать failing test сгруппированного блока**

Создать один тайтл, две серии одного сезона и три опубликованных media (два варианта у первой серии, один у второй) с одной датой `created_at`. Запросить `route('home')` и проверить одну карточку тайтла, обе серии и metadata всех media:

```php
$response = $this->get(route('home'))->assertOk();
$html = $response->getContent();

$this->assertSame(1, substr_count($html, 'data-home-latest-media-group="'.$title->id.'"'));
$response
    ->assertSeeText('1 серия')
    ->assertSeeText('2 серия')
    ->assertSeeText('1080P')
    ->assertSeeText('720P')
    ->assertSeeText('Профессиональный перевод')
    ->assertSeeText('Оригинальная дорожка');
```

Добавить новую серию без media и проверить, что её номер и «Видео для серии пока не добавлено.» видимы.

- [ ] **Step 3: Написать failing test индексов**

```php
$this->assertSame(
    ['season_id', 'created_at', 'id'],
    collect(Schema::getIndexes('episodes'))->firstWhere('name', 'episodes_home_additions_idx')['columns'],
);
$this->assertSame(
    ['catalog_title_id', 'created_at', 'id'],
    collect(Schema::getIndexes('licensed_media'))->firstWhere('name', 'licensed_media_home_additions_idx')['columns'],
);
```

- [ ] **Step 4: Запустить tests и подтвердить RED**

Run:

```bash
php artisan test --filter=CatalogHomeContentAdditionTest
```

Expected: FAIL, потому что snapshot ещё не содержит `latest_title_updates`, metadata-only тайтл выбирается через `indexed_at`, HTML повторяет карточку на каждый media, а индексы отсутствуют.

---

### Task 2: Добавить query boundary и покрывающие индексы

**Files:**
- Create: `app/Services/Catalog/CatalogHomeContentAdditionQuery.php`
- Create: `database/migrations/2026_07_15_235000_add_home_content_addition_indexes.php`
- Modify: `app/Services/Catalog/CatalogHomeSnapshotCache.php`
- Test: `tests/Feature/CatalogHomeContentAdditionTest.php`

**Interfaces:**
- Consumes: `CatalogTitleQuery::visibleTo()`, `Episode::availableTo()`, `Season::availableTo()`, `LicensedMedia::published()` и `forAvailableReleases()`.
- Produces: `latestTitleUpdates(int $limit = 48): array` и `latestReleaseGroups(Collection $titles, array $updates, int $limit = 12): Collection`.

- [ ] **Step 1: Реализовать агрегат latest title updates**

Создать final service с constructor injection `CatalogTitleQuery`. Объединить episode/media additions и агрегировать:

```php
public function latestTitleUpdates(int $limit = 48): array
{
    $additions = $this->episodeAdditions()->unionAll($this->mediaAdditions());

    return DB::query()
        ->fromSub($additions, 'catalog_home_content_additions')
        ->select('catalog_title_id')
        ->selectRaw('MAX(added_at) AS added_at')
        ->groupBy('catalog_title_id')
        ->orderByDesc('added_at')
        ->orderByDesc('catalog_title_id')
        ->limit($limit)
        ->get()
        ->map(fn (object $row): array => [
            'id' => (int) $row->catalog_title_id,
            'added_at' => CarbonImmutable::parse($row->added_at)->toDateTimeString(),
        ])
        ->all();
}
```

Episode query соединяет derived table names `Episode::getTable()`/`Season::getTable()`, применяет availability к обеим моделям и фильтрует visible title subquery. Media query применяет текущие public media scopes и visible title subquery.

- [ ] **Step 2: Реализовать bounded grouped loads**

`latestReleaseGroups()` берёт первые 12 update coordinates, оставляет только гидратированные title IDs и строит OR-пары `(catalog_title_id, startOfDay..endOfDay)`. Один episode query выбирает `episodes.*`, eager-loads `season`; один media query eager-loads `season` и `episode`. Возврат:

```php
return $coordinates->map(fn (array $coordinate): array => [
    'title' => $titlesById->get($coordinate['id']),
    'episodes' => $episodesByTitle->get($coordinate['id'], collect())->values(),
    'media' => $mediaByTitle->get($coordinate['id'], collect())->values(),
])->filter(fn (array $group): bool => $group['episodes']->isNotEmpty() || $group['media']->isNotEmpty())->values();
```

- [ ] **Step 3: Перевести snapshot на новый источник**

Внедрить query service в `CatalogHomeSnapshotCache`, заменить latest query по `indexed_at`:

```php
$latestTitleUpdates = $this->contentAdditions->latestTitleUpdates();
$latestTitleIds = collect($latestTitleUpdates)->pluck('id')->all();
```

Добавить `latest_title_updates` в documented/empty snapshot shape и изменить cache segment с `content-index` на `content-index-v2`.

- [ ] **Step 4: Добавить reversible indexes migration**

```php
Schema::table('episodes', function (Blueprint $table): void {
    $table->index(['season_id', 'created_at', 'id'], 'episodes_home_additions_idx');
});
Schema::table('licensed_media', function (Blueprint $table): void {
    $table->index(['catalog_title_id', 'created_at', 'id'], 'licensed_media_home_additions_idx');
});
```

`down()` удаляет только эти два именованных индекса.

- [ ] **Step 5: Запустить источник/индексы и подтвердить частичный GREEN**

Run:

```bash
php artisan test --filter=CatalogHomeContentAdditionTest
```

Expected: source ordering и index assertions PASS; HTML grouping всё ещё FAIL до Task 3.

---

### Task 3: Сгруппировать web-view по тайтлу

**Files:**
- Modify: `app/Services/Catalog/CatalogHomePageBuilder.php`
- Modify: `app/View/Components/Catalog/LatestMediaCard.php`
- Modify: `resources/views/components/catalog/latest-media-card.blade.php`
- Modify: `resources/views/catalog/index.blade.php`
- Test: `tests/Feature/CatalogHomeContentAdditionTest.php`

**Interfaces:**
- Consumes: snapshot `latest_title_updates` и `CatalogHomeContentAdditionQuery::latestReleaseGroups()`.
- Produces: `latestReleaseGroups` view data и `<x-catalog.latest-media-card :title :episodes :media>`.

- [ ] **Step 1: Назначить content timestamp и группы в page builder**

Построить map timestamps, назначить загруженным latest titles `content_added_at`, группировать `latestByDate` по нему и запросить release groups:

```php
$latestUpdateTimes = collect($snapshot['latest_title_updates'] ?? [])
    ->mapWithKeys(fn (array $update): array => [(int) $update['id'] => CarbonImmutable::parse($update['added_at'])]);
$latestTitles->each(fn (CatalogTitle $title) => $title->setAttribute(
    'content_added_at',
    $latestUpdateTimes->get((int) $title->id),
));
$latestReleaseGroups = $this->contentAdditions->latestReleaseGroups(
    $latestTitles,
    $snapshot['latest_title_updates'] ?? [],
);
```

Существующий `$latestMedia` оставить для API Resource.

- [ ] **Step 2: Подготовить presentation rows в class component**

Изменить constructor на `CatalogTitle $title`, `Collection $episodes`, `Collection $media`. Собрать один item на episode ID, присоединить все media этой серии и добавить standalone media items. Для каждого media подготовить:

```php
[
    'url' => route('titles.show', [
        'catalogTitle' => $title,
        'episode' => $media->episode_id,
        'media' => $media->id,
    ]).'#player',
    'quality' => filled($media->quality) ? mb_strtoupper((string) $media->quality) : null,
    'meta' => collect([$media->translation_name, $media->format ? mb_strtoupper($media->format) : null, $media->published_at?->format('d.m.Y')])->filter()->implode(' / ') ?: 'Видео сериала',
]
```

Episode presentation row содержит готовые `season_label`, `episode_label`, `episode_title`, `media`.

- [ ] **Step 3: Отрисовать одну карточку на тайтл**

В `catalog/index.blade.php` заменить flat loop:

```blade
@forelse ($latestReleaseGroups as $releaseGroup)
    <x-catalog.latest-media-card
        :title="$releaseGroup['title']"
        :episodes="$releaseGroup['episodes']"
        :media="$releaseGroup['media']"
    />
@empty
```

В component view вывести один `x-ui.poster-card`, header link тайтла и nested divided list. Не использовать stretched-link overlay, чтобы ссылки каждого media оставались кликабельными. Добавить `data-home-latest-media-group="{{ $title->id }}"`; каждый media row показывает quality и полный `meta`, episode без media показывает точный empty text.

- [ ] **Step 4: Запустить focused test и подтвердить GREEN**

Run:

```bash
php artisan test --filter=CatalogHomeContentAdditionTest
```

Expected: все tests PASS, один title group содержит все series/media rows.

- [ ] **Step 5: Запустить соседние regression tests**

Run:

```bash
php artisan test --filter='CatalogVisualSystemTest|CatalogBladeComponentTest|CatalogDiscoveryTest|CatalogRelatedContentTest'
```

Expected: PASS; если старый component test использует flat API, обновить его assertions только после подтверждённого failure от нового constructor contract.

---

### Task 4: Документация, форматирование и полная проверка

**Files:**
- Modify: `docs/importer.md`
- Modify: `docs/UI_STANDARDS.md`
- Modify: `docs/performance.md`
- Modify: `CHANGELOG.md`
- Verify: все изменённые task paths.

**Interfaces:**
- Consumes: реализованное и прошедшее focused tests поведение.
- Produces: project-owned contract и проверенный commit scope.

- [ ] **Step 1: Обновить владельцев контрактов**

В `docs/importer.md` уточнить, что homepage update signal основан только на созданных `episodes`/`licensed_media`; `indexed_at` остаётся search timestamp. В `docs/UI_STANDARDS.md` описать один title group и полностью видимые episode/media rows. В `docs/performance.md` добавить два home-addition индекса. В `CHANGELOG.md` добавить краткую русскую запись о пользовательском изменении.

- [ ] **Step 2: Форматировать PHP**

Run:

```bash
./vendor/bin/pint --dirty --format agent
```

Expected: exit 0; проверить, что Pint не изменил чужие dirty PHP paths, и при необходимости ограничить review только task files.

- [ ] **Step 3: Запустить проверки**

Run последовательно:

```bash
php artisan test --filter=CatalogHomeContentAdditionTest
php artisan test
npm run build
php artisan project:docs-refresh --check
git diff --check
```

Expected: все команды exit 0 без warnings/errors.

- [ ] **Step 4: Проверить diff и commit scope**

Run:

```bash
git status --short --branch
git diff -- app/Services/Catalog/CatalogHomeContentAdditionQuery.php app/Services/Catalog/CatalogHomeSnapshotCache.php app/Services/Catalog/CatalogHomePageBuilder.php app/View/Components/Catalog/LatestMediaCard.php resources/views/catalog/index.blade.php resources/views/components/catalog/latest-media-card.blade.php database/migrations/2026_07_15_235000_add_home_content_addition_indexes.php tests/Feature/CatalogHomeContentAdditionTest.php docs/importer.md docs/UI_STANDARDS.md docs/performance.md CHANGELOG.md docs/superpowers/specs/2026-07-15-home-content-additions-design.md docs/superpowers/plans/2026-07-15-home-content-additions.md
```

Expected: branch `main`; diff содержит только согласованное поведение. Если чужие изменения пересекают docs/CHANGELOG, не перезаписывать их и использовать path/hunk-specific staging либо явно сообщить блокер.

- [ ] **Step 5: Закоммитить только разрешённые изменения**

```bash
git add app/Services/Catalog/CatalogHomeContentAdditionQuery.php app/Services/Catalog/CatalogHomeSnapshotCache.php app/Services/Catalog/CatalogHomePageBuilder.php app/View/Components/Catalog/LatestMediaCard.php resources/views/catalog/index.blade.php resources/views/components/catalog/latest-media-card.blade.php database/migrations/2026_07_15_235000_add_home_content_addition_indexes.php tests/Feature/CatalogHomeContentAdditionTest.php docs/importer.md docs/UI_STANDARDS.md docs/performance.md CHANGELOG.md docs/superpowers/specs/2026-07-15-home-content-additions-design.md docs/superpowers/plans/2026-07-15-home-content-additions.md
git commit -m "feat: group homepage content additions"
```

Expected: commit создан на `main`; чужие staged/unstaged изменения не включены. Если repository guard блокирует commit из-за постороннего dirty tree, не обходить и не коммитить чужие файлы — сообщить точный blocker пользователю.
