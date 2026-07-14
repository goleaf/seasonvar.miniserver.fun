# Seasonvar UI Evolution Implementation Plan

> **Для agentic workers:** REQUIRED SUB-SKILL: после явного одобрения визуального направления выполнять план в существующей ветке `main` с `superpowers:executing-plans`, `superpowers:test-driven-development`, `seasonvar-ui`, `tailwindcss-development` и `seasonvar-playwright-qa`. Project rules запрещают worktree и дополнительные ветки. Задачи используют checkbox (`- [ ]`) для отслеживания.

**Цель:** последовательно улучшить доступность, визуальную иерархию, скорость и понятность всех route-поверхностей Seasonvar без смены работающей Laravel/Blade/Livewire-архитектуры, без вымышленного контента и без риска для импорта, SEO и playback.

**Архитектура:** общий светлый shell, Blade-компоненты и Tailwind 4 tokens остаются фундаментом. Публичные страницы сохраняют server-rendered essential content, Livewire отвечает только за уже существующие интерактивные islands, а длинная служебная статистика получает bounded секции поверх очищенных cache snapshots. SEO/feed изменения остаются streamed/chunked и отражают только реальные записи. Визуальная регрессия закрепляется браузерной матрицей на временной SQLite базе.

**Tech Stack:** PHP 8.5, Laravel 13.19, Livewire 4.3, Blade, Tailwind CSS 4.3, Vite 8, локальные FontAwesome/Plyr/HLS, PHPUnit 12.5 и существующие dev-only Playwright/axe gates.

**Статус:** audit и дизайн-направление подготовлены; implementation не начинать до одобрения владельцем проекта. Постоянные правила принадлежат [`docs/UI_STANDARDS.md`](../../UI_STANDARDS.md), frontend runtime — [`docs/frontend.md`](../../frontend.md), SEO — [`docs/DATA_RELATIONS.md`](../../DATA_RELATIONS.md) и [`docs/performance.md`](../../performance.md). Этот файл — исполняемый план, а не второй источник доменных контрактов.

---

## 1. Как проведен аудит

- Проверен `php artisan route:list --json`, соседние controllers, Livewire components, page builders, Blade-компоненты, `resources/css/app.css`, `resources/js`, тесты и действующая документация.
- Рабочая база читалась без изменений: на момент снимка в ней было 34 177 опубликованных тайтлов, 58 777 сезонов, 615 770 серий и 688 678 media rows.
- Визуальные страницы открывались в Chromium на `1440×1200`, `768×1024` и `390×844`; собирались status, final URL, landmarks, headings, overflow, изображения, controls, console/page/network errors и full-page screenshots.
- Production Livewire-сценарии повторно проверялись по HTTPS, чтобы secure cookies и middleware соответствовали реальному окружению.
- Для закрытых экранов использовались временная SQLite база и временная server-side сессия с тестовым администратором; production users и данные не изменялись.
- Внешнее видео блокировалось. Аудит проверял shell плеера, signed boundary, lifecycle и тестовые контракты, но не скачивал видеофайлы.
- Machine-readable endpoints проверялись read-only через HTTP status, content type, TTFB/total time и размер ответа. Замеры являются одиночными диагностическими наблюдениями, не SLA и не p95.

## 2. Вывод одним абзацем

Сайт работоспособен и визуально целостен. Все доступные публичные страницы отрисовываются, Livewire-фильтры и поиск отвечают, mobile dialog доступен с клавиатуры, авторизованные экраны открываются при корректных правах, а горизонтального переполнения на проверенных ширинах нет. Поэтому тотальный редизайн не нужен. Максимальный эффект дадут пять точечных программ: секционная архитектура `/stats`, WCAG-контраст и hit targets, более ясная иерархия controls `/titles`, завершение брендовых/error surfaces и bounded SEO/feed responses.

## 3. Текущее визуальное направление

### 3.1. Рекомендованный стиль

«Тихий технологичный каталог»:

- светлая slate-основа и белые непрозрачные поверхности;
- emerald как единственный основной action-color;
- реальные постеры как главный визуальный материал;
- системный кириллический sans-serif без нового font bundle;
- типографическая иерархия и разделители важнее дополнительной карточки;
- motion только как feedback, а не как самостоятельный слой;
- публичный каталог спокойнее, service/admin surfaces плотнее и более операционны.

### 3.2. Design dials

| Параметр | Значение | Почему |
| --- | ---: | --- |
| Вариативность композиции | `4/10` | Нужна узнаваемая система, но каталог, detail, directory и dashboard решают разные задачи. |
| Интенсивность движения | `2/10` | Livewire уже дает динамику; дополнительная анимация не должна мешать поиску и просмотру. |
| Плотность публичных страниц | `6/10` | Постеры и основное действие требуют воздуха, но каталог не должен становиться лендингом. |
| Плотность service/admin | `8/10` | Операционные данные должны быстро сканироваться, сохраняя 44 px controls и перенос текста. |
| Контраст | WCAG AA | Малый текст и controls обязаны проходить минимум `4.5:1`, крупный — `3:1`. |

### 3.3. Как применены дизайн-навыки

- `seasonvar-ui`, `tailwindcss-development` и `redesign-existing-projects` задают основной режим: сохранить продуктовые сценарии и улучшать существующую систему без поломки Livewire/Blade.
- `impeccable`, `high-end-visual-design` и `minimalist-ui` усиливают иерархию, типографику, spacing, contrast и отказ от лишних nested cards.
- `industrial-brutalist-ui` полезен только для операционной ясности `/stats`: tabular numerals, четкие labels, короткие status markers. Его темная/военная эстетика проекту не подходит.
- `gpt-taste`, `design-taste-frontend` и v1-вариант подтверждают необходимость сильной композиции, но AIDA, cinematic motion и landing-page hero не переносятся в продуктовый каталог.
- `brandkit`, `imagegen-frontend-web`, `image-to-code` и общая image generation не нужны для route-аудита: у продукта уже есть реальные постеры, а новые brand assets нельзя придумывать без отдельного brief. Они могут быть подключены позже только для согласованного favicon/logo system или предварительного визуального макета крупной перестройки.
- Темный, brutalist, luxury и cinematic варианты использовались как контрастные контрольные точки, а не как предложение сменить текущую тему.

## 4. Оценка качества

Оценка следует audit-шкале `impeccable`; она измеряет запас качества, а не процент работающих маршрутов.

| Область | Балл | Подтверждение |
| --- | ---: | --- |
| Доступность | `2/4` | Есть семантика, skip-link, focus, alt и keyboard dialog; значимый `slate-400` дает около `2.63:1`, а часть Plyr controls требует 44 px hit-area. |
| Производительность | `2/4` | Есть lazy/dynamic assets, content-visibility и caches; `/stats` — около 951 КБ HTML, feed — около 66 МБ, taxonomy sitemap — около 33 МБ. |
| Темизация | `3/4` | Светлая slate/emerald-система последовательна; semantic tokens пока частичные, многие views используют raw utilities. |
| Responsive | `4/4` | На 1440/768/390 не найден horizontal overflow, dialog и адаптивные сетки работают. |
| Anti-patterns | `2/4` | Нет glass/purple/gradient-text slop, но повтор белых rounded panels, pills и uppercase micro-labels делает интерфейс местами шаблонным. |
| **Итого** | **`13/20` — приемлемо** | Основа надежна; нужен целевой polish и performance/IA, не полный rebuild. |

## 5. Полная матрица маршрутов

### 5.1. Публичные HTML-поверхности

| Route pattern | Проверенные значения | Результат | Что можно улучшить |
| --- | --- | --- | --- |
| `/` | реальные production данные | `200`, desktop/tablet/mobile без overflow | Уменьшить визуальную одинаковость секций; сохранить текущий порядок и реальные данные. |
| `/titles` | default, сортировка, checkbox filter | `200`; Livewire `200`; 24 → 1 карточка после фильтра | Снизить конкуренцию 11 sort-controls, вида, размера и алфавита; выделить primary/secondary controls. |
| `/titles/year/{year}` | `2020` | `200` | Использовать ту же ясную contextual heading и reset-навигацию, что общий каталог. |
| `/titles/{type}/{taxonomy}` | `genre/komediia`, `country/ssa`, `actor/lindsi-sou`, `director/dzil-dzanger`, `age_rating/18`, `translation/newstudio`, `network/cbs`, `tag/molodeznyi` | `200` | Сохранять естественные русские заголовки; не создавать отдельный визуальный шаблон. |
| `/titles/{type}/{taxonomy}` | неизвестные `status/neizvestno`, `studio/neizvestno` | корректный `404` | Добавить брендовый русский 404 с поиском и возвратом в каталог. |
| `/titles/{catalogTitle:slug}` | тайтл с сезонами, плеером и 12 рекомендациями | `200`; player/recommendations есть; Livewire `200` | Увеличить effective hit-area плеера, сделать next/previous context заметнее, сократить nested panels. |
| `/actors` | directory и live search «Линдси» | `200`; Livewire `200` | Сохранить быстрый search; улучшить status copy и визуальный ритм длинного списка. |
| `/age-ratings` | populated directory | `200` | Числовые/возрастные значения показывать компактнее, не превращая каждое в тяжелую карточку. |
| `/countries` | populated directory | `200` | Сохранить алфавит/поиск; counts выводить tabular и контрастно. |
| `/directors` | populated directory | `200` | Те же правила people-directory, без второго компонента. |
| `/genres` | populated directory | `200` | Жанры можно группировать более типографически, не добавляя цвет каждой категории. |
| `/networks` | populated directory | `200` | Сохранить card grid; вторичную информацию отделять spacing/divider. |
| `/statuses` | источник пока пуст | `200`, понятный empty state | Добавить реальный переход «Открыть весь каталог», не упоминать импорт посетителю. |
| `/studios` | источник пока пуст | `200`, понятный empty state | То же поведение, что `/statuses`; не генерировать фиктивные студии. |
| `/tags` | populated directory | `200` | Не превращать tags в бесконечное облако pills; текущий список безопаснее. |
| `/translations` | populated directory | `200` | Явно сохранять смысл «озвучка/перевод», не смешивать с locale UI. |
| `/years` | populated directory | `200` | Десятилетия и годы могут иметь более компактную grid-иерархию. |
| `/{directory}/{value}` | все одиннадцать directory detail patterns | redirect в canonical `/titles/...`, итоговый `200` | Сохранить canonical redirect; не создавать дублирующие detail pages. |
| `/stats` | production snapshot и poll | `200`; Livewire poll `200`; без overflow | Главный P1: summary first, URL-секция, bounded payload/DOM, issue-first navigation. |
| `/watching` | guest | ожидаемый `403` | Русская брендовая 403 должна объяснять недоступность без fake login. |
| `/watching` | временный authorized user | `200` desktop/mobile | Empty states хороши; действия history должны сохранять спокойную destructive hierarchy. |
| `/admin/catalog` | guest / temporary admin | `403` / `200` | На admin surface усилить field grouping, save feedback и sticky context без темной темы. |
| `/admin/imports` | guest / temporary admin | `403` / `200` | Health summary читаем; сделать run state hierarchy и опасные действия еще яснее. |
| `/up` | framework health page | `200` | Не считать продуктовой страницей; основной branded health contract — `/health/ready`. |
| неизвестный web path | `/put-kotorogo-net` | `302` → `/`, итоговый `200` | Рекомендуется явный брендовый `404`, потому что молчаливый redirect теряет контекст и затрудняет диагностику. |

### 5.2. Playback, изображения и health

| Route | Результат | Вывод |
| --- | --- | --- |
| `/playback/{licensedMedia}` без подписи | ожидаемый `403` | Signed boundary работает; raw provider URL не раскрыт. |
| `/stats/poster/{catalogTitle:slug}` | `200 image/jpeg` для валидного кандидата | Сохранять SSRF guard, size/content-type limits и same-origin proxy. |
| `/health/ready` | `200 application/json`, около `0.14 s` | Работает как read-only operational endpoint; не стилизовать как публичную страницу. |
| `/favicon.ico` | `200 image/x-icon`, `0 bytes` | Route формально отвечает, но asset пустой; нужен реальный локальный favicon set. |

### 5.3. Machine-readable и SEO routes

| Route | Результат и одиночный замер | Рекомендация |
| --- | --- | --- |
| `/sitemap.xml` | `200 XML`, около `23 КБ`, `2.33 s` | Профилировать counts/cache; сохранить stream. |
| `/sitemap-index.xml` | `200 XML` | Сохранить стабильный индекс и актуальные страницы. |
| `/sitemap-static.xml` | `200 XML` | Работает; изменения не нужны без доказанной проблемы. |
| `/sitemap-taxonomies.xml` | `200 XML`, около `32.8 МБ`, `6.76 s total` | Разделить по типам или bounded pages до приближения к protocol limits; добавить compression/cache evidence. |
| `/sitemap-landings.xml` | `200 XML`, около `669 КБ`, `7.52 s TTFB`, `10.2 s total` | Профилировать grouped joins, precompute immutable snapshot after import. |
| `/sitemap-titles-1.xml` | `200 XML` | Pagination уже есть; сохранить limit и deterministic order. |
| `/sitemap-videos-1.xml` | `200 XML` | Pagination и internal player URL сохранить; raw media не публиковать. |
| `/feed.xml` | `200 RSS`, около `65.9 МБ`, `6.23 s total` | Ограничить RSS последними обновлениями; архив каталога уже принадлежит sitemap, не feed. |
| `/opensearch.xml` | `200 OpenSearch XML`, около `0.12 s` | Работает; сохранить. |
| `/llms.txt` | `200 text/plain`, `1.4 КБ`, около `3.39 s` | Считать totals из compact cached snapshot, а не тяжелых live aggregates. |

Любое изменение этих routes обязано сохранить streaming/chunking, реальные данные, canonical URLs и отсутствие private source/media URLs. Search Console/GA данные не смешивать с публичным HTML.

### 5.4. JSON API

| Route | Результат | Вывод |
| --- | --- | --- |
| `/api/titles` | `200 JSON` | Resource/pagination contract работает. |
| `/api/titles/{catalogTitle:slug}` | `200 JSON` | Route model visibility сохраняется. |
| `/api/catalog/people?type=actor&q=Линдси` | `200 JSON` | Combobox endpoint работает. |
| `/api/catalog/people` без обязательного `type` | ожидаемый `422 JSON` | Валидация корректна. |
| неизвестный `/api/*` с JSON Accept | `404 JSON` в PHPUnit | Laravel contract существует. |
| неизвестный `/api/*` с обычным curl Accept | production nginx `404 text/html` | Выровнять edge/nginx и Laravel contract, если API должен быть JSON-first независимо от Accept. |

### 5.5. Внутренние Livewire routes

- `livewire-e94d3c2d/update` проверен через реальные filter/search/poll/refresh interactions и отвечает `200`.
- CSS/JS component routes и source maps не являются самостоятельными страницами; фактически подключенные layout assets загрузились без browser errors.
- `upload-file` и `preview-file` не вызывались искусственно: корректный запрос требует подписанного Livewire protocol и реального разрешенного upload flow. Их безопасность принадлежит framework middleware и existing upload tests.
- POST routes не следует «проверять» случайными payload на production. Browser acceptance должен проходить через пользовательское действие, а mutation tests — на временной базе.

## 6. Что уже сделано хорошо и должно быть сохранено

- Один визуальный язык на public, personal и admin surfaces.
- Светлая тема соответствует проектному договору и не выглядит незавершенной.
- Header search всегда доступен; mobile header не вызывает overflow.
- Основные controls и pagination в большинстве случаев уже имеют `min-h-11`.
- Карточка тайтла поднимает просмотр выше справочных секций.
- Native dialog фильтров лучше кастомной modal implementation: Escape/focus работают нативно.
- Один stretched-link на title card сокращает повторные tab-stops.
- Постеры имеют alt, async decoding, предсказуемый frame и русскую заглушку.
- `content-visibility`, dynamic player import и локальные assets уменьшают frontend cost.
- Empty states не заполняются фиктивными данными.
- Service/admin screens не раскрывают raw URLs и stack traces.

## 7. Подтвержденные проблемы по приоритету

### P0 — блокирующих дефектов нет

Нет подтвержденного маршрута, на котором доступный пользовательский сценарий полностью не работает. Поэтому P0 не используется и не должен искусственно заполняться.

### P1 — высокий эффект и измеримый риск

1. `/stats`: 951 КБ HTML и экстремальная длина страницы. Причина — все диагностические секции рендерятся одновременно. Решение — summary first и bounded URL-section.
2. Контраст: `text-slate-400` на white/slate дает около `2.63:1`, ниже `4.5:1`; `emerald-600` с белым текстом дает около `3.67:1`. Решение — semantic muted text не светлее slate-500, action hover не светлее emerald-700/800.
3. SEO payload: feed около 66 МБ, taxonomy sitemap около 33 МБ, landing sitemap до 10 секунд. Решение — bounded feed, sitemap partition/cache/precompute с protocol tests.
4. Reproducible QA: базовый Playwright/axe gate уже реализован; оставшаяся задача этого плана — расширить существующую route matrix, не создавая второй runner.

### P2 — ясность и завершенность продукта

1. Catalog control hierarchy: много равноправных sorting chips и вторичных переключателей.
2. Plyr touch targets и selected-state contrast.
3. Пустой favicon и generic/redirect error surfaces.
4. Повтор одинаковых rounded panels на длинных страницах.
5. Production API unknown path иногда завершается nginx HTML, а не JSON.

### P3 — polish после измерений

1. Более выразительная, но спокойная брендовая система icon/favicon.
2. Точечные loading placeholders без layout shift.
3. Улучшение табличных чисел и microcopy service/admin screens.
4. Visual regression baselines для ключевых страниц после стабилизации структуры.

## 8. Последовательность реализации

### Task 1: Закрепить browser/a11y baseline как автоматическую проверку

**Почему первой:** без воспроизводимой матрицы нельзя безопасно менять общий shell, controls и responsive layouts.

**Зависимость:** переиспользовать существующие Playwright/axe dependencies и browser fixtures, не ставить второй набор.

**Files:**

- Modify: `playwright.config.js`, `tests/browser/prepare-fixtures.php`, `tests/browser/catalog.spec.js`
- Create: `tests/browser/routes.spec.js`
- Create: `tests/browser/accessibility.spec.js`
- Modify: `.github/workflows/ci.yml`
- Modify: `docs/testing.md`
- Modify: `docs/frontend.md`

**Acceptance:**

- matrix `390`, `768`, `1440`;
- `/`, `/titles`, year/taxonomy listing, title, representative directory, empty directory, `/stats`, authorized personal/admin fixture;
- checks: status/final URL, one `main`, one product `h1`, no overflow, no serious/critical axe violations, no broken local assets, no console/page errors;
- dialog Escape/focus return, catalog filter, directory search, title refresh, stats poll;
- external poster/media requests blocked or fulfilled by explicit fixtures;
- artifacts only under ignored `output/playwright/`.

- [ ] Написать contract test и browser specs до изменения UI.
- [ ] Запустить RED: `php artisan test --filter=BrowserCiContractTest` и `npm run test:browser`.
- [ ] Добавить только dev dependencies, уже разрешенные основным backlog-планом.
- [ ] Запустить GREEN и убедиться, что production DB не используется.

### Task 2: Ввести semantic color/surface tokens и исправить контраст

**Почему:** цветовая система уже последовательна, но raw `slate-*` utilities позволяют незаметно вернуть малоконтрастный текст.

**Files:**

- Modify: `resources/css/app.css`
- Modify: `resources/views/components/ui/panel.blade.php`
- Modify: `resources/views/components/ui/status-pill.blade.php`
- Modify: `resources/views/components/form/search-field.blade.php`
- Modify: `resources/views/catalog/titles.blade.php`
- Modify: `resources/views/livewire/stats-dashboard.blade.php`
- Modify: `resources/views/livewire/catalog-title-player.blade.php`
- Modify: authorized admin/import views only where axe proves a failure
- Test: `tests/Feature/CatalogVisualSystemTest.php`
- Test: `tests/Unit/FrontendAssetContractTest.php`
- Test: `tests/browser/accessibility.spec.js`

**Interface:**

- semantic tokens для `surface-page`, `surface-panel`, `surface-muted`, `ink`, `ink-muted`, `ink-subtle`, `action`, `action-hover`, `success`, `warning`, `danger`, `focus`;
- `slate-400` остается декоративным, а labels/metadata/placeholder переходят минимум на slate-500;
- primary action: white on emerald-700, hover emerald-800 или другой подтвержденный AA цвет;
- focus ring сохраняет `2 px` outline + внешнюю светлую зону;
- состояние не кодируется только цветом.

- [ ] Сначала добавить browser contrast assertions для meaningful text и primary actions.
- [ ] Запустить RED на известных `slate-400` labels и emerald-600 hover.
- [ ] Ввести tokens без глобальной механической замены декоративных иконок.
- [ ] Исправлять только подтвержденные semantic usages; disabled controls проверять отдельно.
- [ ] Запустить focused PHPUnit, axe и `npm run build`.

### Task 3: Перестроить `/stats` как summary-first workspace

**Почему:** это единственная страница с доказанным критическим IA/DOM-размером; косметическая замена карточек проблему не решит.

**Решение:** сохранить canonical `/stats`, но добавить allowlisted URL-state `section=overview|quality|imports|routes|database`. Default `overview` рендерит health, headline totals, issues и ссылки на разделы. Каждая другая секция получает только свои данные и собственный bounded snapshot. Никаких внутренних scroll-zones.

**Files:**

- Create: `app/Enums/CatalogStatsSection.php`
- Modify: `app/Livewire/StatsDashboard.php`
- Modify: `app/Services/Catalog/CatalogStatsPageBuilder.php`
- Modify: `app/Services/Catalog/CatalogStatsSnapshotBuilder.php`
- Modify: `app/Services/Catalog/CatalogStatsSnapshotCache.php`
- Modify: `app/Services/Catalog/CatalogStatsSnapshotSanitizer.php`
- Modify: `resources/views/catalog/stats.blade.php`
- Modify: `resources/views/livewire/stats-dashboard.blade.php`
- Test: `tests/Feature/CatalogPageTest.php`
- Test: `tests/Unit/CatalogStatsSnapshotCacheTest.php`
- Test: `tests/Unit/CatalogStatsSnapshotSanitizerTest.php`
- Test: new `tests/Feature/StatsDashboardTest.php`
- Modify: `docs/performance.md`, `docs/security.md`, `docs/UI_STANDARDS.md`

**Acceptance:**

- default HTML target `< 250 КБ` на production-like fixture;
- default mobile document target `< 30 000 px` на deterministic browser fixture;
- section response не содержит arrays других секций;
- invalid `section` нормализуется или дает validation response по существующему read-only pattern;
- canonical остается `/stats`, page is `noindex,nofollow`;
- poll обновляет только текущую section snapshot;
- issue summary остается первой и никогда не скрывается в collapsed block;
- source URLs, raw media, stack traces и route internals не попадают в snapshot.

- [ ] Написать RED tests на enum URL state, payload boundaries и sanitizer.
- [ ] Разделить builder/cache contract до изменения Blade.
- [ ] Добавить navigation с `aria-current`, русскими labels и counts.
- [ ] Проверить 390/768/1440, back/forward и poll.
- [ ] Зафиксировать cold/warm timings в `docs/performance.md`.

### Task 4: Упростить иерархию controls на `/titles`

**Почему:** каталог функционально силен, но sort/view/per-page/alphabet/filter controls визуально равноправны и требуют лишнего сканирования.

**Решение:** поиск и количество результатов остаются первым уровнем; `Сортировка` показывает текущий выбор и 3–4 частых варианта, остальные находятся в native disclosure «Другие варианты»; вид, размер и алфавит образуют один secondary toolbar. URL keys и Livewire actions не меняются.

**Files:**

- Modify: `resources/views/catalog/titles.blade.php`
- Modify: `resources/views/components/catalog/title-filters.blade.php`
- Modify: `app/View/ViewModels/CatalogTitlesViewModel.php`
- Modify only if required: `app/Livewire/CatalogSeries.php`
- Test: `tests/Feature/CatalogPageTest.php`
- Test: `tests/Feature/CatalogAdvancedFilterTest.php`
- Test: `tests/Unit/CatalogTitlesViewModelTest.php`
- Test: browser catalog scenarios

**Acceptance:**

- все текущие sort enum доступны без JavaScript;
- active sort виден до открытия disclosure;
- controls имеют 44 px hit-area и не превращаются в десятки outlined pills;
- mobile panel по-прежнему дает sorting/view/per-page/alphabet;
- GET/no-script, refresh, back/forward и canonical query normalization сохраняются;
- никакой новый client state не дублирует Livewire state.

- [ ] Написать failing markup/state tests.
- [ ] Реализовать ViewModel-driven grouping, без вычислений в Blade.
- [ ] Проверить keyboard/reader naming и отсутствие overflow.
- [ ] Сравнить task completion на desktop/mobile: поиск → фильтр → смена sort → reset.

### Task 5: Улучшить player workspace и episode navigation

**Почему:** карточка тайтла уже правильно ставит playback высоко, поэтому нужен polish controls, а не новая композиция всей страницы.

**Files:**

- Modify: `resources/css/app.css`
- Modify: `resources/views/livewire/catalog-title-player.blade.php`
- Modify: `resources/views/livewire/catalog-title-detail.blade.php`
- Modify only if presentation data is missing: `app/View/ViewModels/CatalogShowViewModel.php`
- Test: `tests/Feature/CatalogTitleLiveRefreshTest.php`
- Test: `tests/Feature/CatalogTitlePlaybackQueryTest.php`
- Test: `tests/Feature/CatalogVisualSystemTest.php`
- Test: browser title/player scenarios

**Acceptance:**

- `.plyr__control` и соседние custom player controls имеют effective `44×44 px` на touch layouts;
- selected/focus/hover contrast проходит AA;
- текущие сезон, серия, перевод, качество и доступность читаются рядом с player без повторной тяжелой панели;
- previous/next action объясняет, куда ведет, и не теряет длинное название;
- `wire:ignore`, signed URL TTL, player cleanup и URL fallback не меняются;
- responsive resize не заменяет video node.

- [ ] Сначала закрепить DOM identity/lifecycle и 44 px tests.
- [ ] Исправить CSS и presentation markup без изменения resolver.
- [ ] Проверить keyboard controls, reduced motion и внешний media block.

### Task 6: Снизить «карточность» общего визуального языка

**Почему:** одинаковая белая rounded surface для каждой группы делает hero, metadata и вторичную сводку равнозначными.

**Files:**

- Modify: `resources/views/components/ui/panel.blade.php`
- Modify: `resources/views/components/ui/section-title.blade.php`
- Modify: `resources/views/components/catalog/page-stat.blade.php`
- Modify selectively: `resources/views/catalog/index.blade.php`, `resources/views/livewire/catalog-title-detail.blade.php`, directory/stats views
- Test: `tests/Feature/CatalogBladeComponentTest.php`
- Test: `tests/Feature/CatalogVisualSystemTest.php`

**Interface:** три уровня вместо одной поверхности:

1. structural panel — border/radius/shadow только для самостоятельной задачи;
2. section group — без внешней карточки, с heading/divider/spacing;
3. inline metadata — label/value или flat chip без собственного shell.

**Acceptance:**

- одна visual boundary не содержит вторую одинаковую boundary без функциональной причины;
- panel API остается backward-compatible или меняется одной явной migration;
- публичные title cards сохраняют одну внешнюю рамку;
- пустые/ошибочные/формовые состояния могут иметь structural border.

- [ ] Составить inventory всех `x-ui.panel` usages и выбрать подтвержденные nested cases.
- [ ] Добавить component tests на новые variants.
- [ ] Мигрировать по одной route family с screenshot review после каждой.

### Task 7: Точечно улучшить главную страницу

**Почему:** главная длинная, но ее текущий порядок и пятиколоночная wide-grid являются недавними согласованными решениями; менять их без пользовательского evidence нельзя.

**Files:**

- Modify: `resources/views/catalog/index.blade.php`
- Modify only if bounded data changes are approved: `app/Services/Catalog/CatalogHomePageBuilder.php`
- Test: `tests/Feature/CatalogPageTest.php`
- Test: `tests/Feature/CatalogVisualSystemTest.php`
- Test: browser home scenarios

**Рекомендации:**

- сохранить counters first, poster updates, new episodes, playable titles и navigation;
- сделать один явный primary content path и ослабить tertiary blocks через section grouping;
- не дублировать одинаковый тайтл визуально в соседних блоках, если page builder может безопасно исключить повтор;
- не вводить hero-слоган, marketing CTA или большой декоративный search, пока header search выполняет глобальную задачу;
- измерить LCP/poster priority до изменения количества карточек.

**Acceptance:** порядок и количество, закрепленные существующими specs/tests, не меняются без отдельного решения; mobile content остается раньше directory navigation; payload и query budget не растут.

- [ ] Написать/обновить order and uniqueness assertions.
- [ ] Сделать только component/spacing hierarchy pass.
- [ ] Проверить 390/768/1440 и LCP candidate.

### Task 8: Отполировать directory hubs и честные empty states

**Почему:** все одиннадцать hubs работают, поэтому нужна одна общая система, а не одиннадцать локальных редизайнов.

**Files:**

- Modify: `resources/views/livewire/catalog-directory-browser.blade.php`
- Modify: `resources/views/components/catalog/directory-card.blade.php`
- Modify: `app/Livewire/CatalogDirectoryBrowser.php` only if ViewModel data is required
- Modify: `lang/ru/catalog.php`
- Test: `tests/Feature/CatalogPageTest.php`
- Test: directory browser tests or new focused class
- Test: browser directory scenarios

**Acceptance:**

- people/taxonomy/year layouts используют один component contract с уместными variants;
- counts — tabular, контрастные и не обрезаются;
- empty `/statuses` и `/studios` объясняют отсутствие данных простым русским языком и дают реальную ссылку на каталог;
- search, alphabet, decade, pagination, back/forward сохраняются;
- неизвестный detail остается `404`, известный — canonical redirect.

- [ ] Добавить failing empty/canonical/browser tests.
- [ ] Свести визуальные варианты к общему component API.
- [ ] Проверить очень длинные имена и zero-count absence.

### Task 9: Завершить header/footer/brand identity и favicon

**Почему:** текущий shell последователен, но нулевой favicon оставляет продукт без browser identity.

**Gate:** сначала согласовать небольшой brand brief: сохраняем ли текущий знак «пленка/каталог», нужна ли только favicon-система или также wordmark. Без согласования не генерировать новый логотип.

**Files:**

- Modify: `resources/views/components/layout/site-header.blade.php`
- Modify: `resources/views/components/layout/site-footer.blade.php`
- Modify: `resources/views/layouts/app.blade.php`
- Create/Modify local assets under `public/` or `resources/images/` after brief
- Modify: `resources/js/app.js` only if Vite-owned asset URL is chosen
- Test: `tests/Feature/CatalogVisualSystemTest.php`
- Test: `tests/Unit/FrontendAssetContractTest.php`

**Acceptance:** favicon is non-empty, local, cacheable, has SVG/PNG/ICO fallbacks as required; no CDN or tracking; header remains compact and no mobile overflow; brand mark retains accessible text name; footer does not gain marketing copy.

- [ ] Утвердить brief и выбрать asset ownership (`public` или Vite).
- [ ] Подготовить favicon variants; image-generation skill использовать только если brief действительно требует нового raster asset.
- [ ] Добавить HTML/link and HTTP asset tests.
- [ ] Проверить browser tabs, high-DPI и reduced/data-saving contexts.

### Task 10: Добавить русские брендовые 403/404 и осмысленный fallback

**Почему:** silent redirect неизвестного URL домой скрывает ошибку пользователя, ломает ожидаемую семантику и затрудняет анализ битых ссылок.

**Files:**

- Create: `resources/views/errors/403.blade.php`
- Create: `resources/views/errors/404.blade.php`
- Modify: `routes/web.php`
- Modify: `bootstrap/app.php` only if exception rendering contract requires it
- Modify: `tests/Feature/RouteFallbackTest.php`
- Modify: `tests/Feature/AuthorizationTest.php`
- Modify: `docs/frontend.md`, `docs/authorization.md`

**Acceptance:**

- unknown web route returns actual `404`, not `302`;
- page uses public light shell or minimal safe equivalent, one H1, Russian explanation, search/catalog/home actions;
- 403 does not promise login/account flow that product does not have;
- admin 403 does not reveal policy, email allowlist or internal resource;
- API errors remain JSON.

- [ ] Изменить тест fallback на RED expectation `404`.
- [ ] Добавить error templates без database queries.
- [ ] Проверить guest/admin/mobile/browser history and SEO robots.

### Task 11: Выровнять JSON API fallback на Laravel и nginx boundaries

**Почему:** PHPUnit JSON contract зеленый, но production edge отвечает HTML для неизвестного `/api/*` при обычном Accept. Клиенты должны получать предсказуемую форму.

**Files:**

- Modify: `routes/api.php` or exception rendering in `bootstrap/app.php`
- Modify deploy nginx config/documentation only after confirming edge ownership
- Modify: `tests/Feature/RouteFallbackTest.php`
- Modify: `docs/deployment.md`, `docs/security.md`

**Acceptance:** unknown `/api/*` returns `404 application/json` for `Accept: application/json` and agreed JSON-first behavior for `*/*`; body contains only stable message, no trace; nginx does not intercept Laravel API fallback unexpectedly.

- [ ] Написать tests для обоих Accept headers.
- [ ] Подтвердить, где возникает production nginx 404, до изменения app code.
- [ ] Исправить один owning boundary, не два конкурирующих fallback.
- [ ] Проверить API Resources и cache headers.

### Task 12: Ограничить feed и разделить тяжелые sitemap outputs

**Почему:** RSS на 66 МБ не помогает подписчику и дублирует роль sitemap; taxonomy sitemap уже велик и будет расти.

**Files:**

- Modify: `app/Services/Catalog/CatalogSitemapResponder.php`
- Modify: `app/Http/Controllers/CatalogSitemapController.php` only if route shape changes
- Modify: `routes/web.php`
- Modify: `config/catalog.php`
- Modify: `app/Services/Catalog/CatalogCacheWarmer.php` if snapshots are precomputed
- Modify/Create additive sitemap partition routes only after compatibility design
- Test: `tests/Feature/SitemapAndRobotsTest.php`
- Test: `tests/Feature/PublicHttpCacheHeadersTest.php`
- Modify: `docs/DATA_RELATIONS.md`, `docs/performance.md`, `docs/README.md` managed blocks through docs refresher

**Рекомендованный contract:**

- RSS содержит последние `100–500` обновленных тайтлов, число задается bounded config и имеет deterministic order/tie-breaker;
- taxonomy sitemap делится по allowlisted taxonomy type или bounded page; index перечисляет части;
- каждый sitemap остается не более 50 000 URLs и существенно ниже 50 МБ uncompressed;
- landings/taxonomy counts могут читаться из versioned snapshot, обновляемого after import/admin write;
- gzip решается HTTP server layer, но не заменяет bounded document design;
- `llms.txt` берет counts из compact catalog/stats snapshot.

**Acceptance:** streaming сохраняется; first byte и total size фиксируются тестами на deterministic fixture; raw playback/source URL отсутствует; robots ссылается на один актуальный index; existing canonical URLs не меняются.

- [ ] Написать RED tests на feed limit, sitemap partition и protocol ceilings.
- [ ] Сначала ограничить feed — минимальный и независимый change.
- [ ] Затем спроектировать backward-compatible taxonomy partition.
- [ ] Профилировать landing grouped queries и cache invalidation.
- [ ] Запустить sitemap/robots tests, XML validation и production-like size sample.

### Task 13: Улучшить изображения без скачивания media

**Почему:** постеры — главный визуальный слой; их стабильная геометрия важнее новых декоративных изображений.

**Files:**

- Modify: `app/View/Components/Ui/PosterFrame.php`
- Modify: `resources/views/components/ui/poster-frame.blade.php`
- Modify callers for explicit `loading`/priority only where needed
- Test: `tests/Feature/CatalogBladeComponentTest.php`
- Test: `tests/Feature/CatalogVisualSystemTest.php`
- Test: browser image metrics

**Acceptance:** explicit aspect/width/height or equivalent intrinsic geometry prevents CLS; только один above-the-fold poster может быть eager/high priority; остальные lazy/async; no broken image icon; remote URL validation remains outside Blade; no downloaded/committed catalog posters; `srcset` добавляется только при реальном transform/proxy source, не как вымышленный URL.

- [ ] Замерить LCP/CLS candidates до изменений.
- [ ] Добавить failing component/geometry tests.
- [ ] Внести минимальный contract и проверить all card variants.

### Task 14: Уточнить personal/admin visual hierarchy

**Почему:** authorized routes уже работают и выглядят согласованно; улучшения должны повышать уверенность в действиях, а не украшать формы.

**Files:**

- Modify: `resources/views/livewire/viewing-activity.blade.php`
- Modify: `resources/views/livewire/catalog-administration-manager.blade.php`
- Modify: `resources/views/livewire/seasonvar-import-manager.blade.php`
- Modify presentation data in corresponding Livewire classes only if necessary
- Test: `tests/Feature/AuthorizationTest.php`
- Add/use existing admin/import tests from repository
- Test: authorized browser fixtures

**Acceptance:** primary save/run action один на viewport section; destructive actions визуально отделены и требуют existing confirmation; loading/success/error feedback находится рядом с действием; counters use tabular numerals; no stored URL/error details rendered; forms preserve Russian validation and 44 px controls.

- [ ] Добавить failing tests для action hierarchy/status regions.
- [ ] Перегруппировать поля через существующие panels/dividers.
- [ ] Проверить long values, keyboard order и mobile forms.

### Task 15: Добавить restrained motion/loading contract

**Почему:** интерфейс уже быстрый визуально; motion должен только подтверждать Livewire state и не маскировать latency.

**Files:**

- Modify: `resources/css/app.css`
- Modify: targeted Livewire Blade views
- Modify: `resources/js/app.js` only for lifecycle-safe enhancement
- Test: `tests/Unit/FrontendAssetContractTest.php`
- Test: browser reduced-motion scenarios

**Contract:** durations `120–220 ms`, только opacity/transform/color; no global entrance animation; `prefers-reduced-motion` сводит motion почти к нулю; loading placeholder сохраняет геометрию; repeated polling не вызывает flash entire page.

- [ ] Закрепить reduced-motion test.
- [ ] Добавлять transition только к controls с реальной сменой состояния.
- [ ] Проверить no layout shift и no player re-init.

### Task 16: Финальная документация, замеры и rollout

**Files:**

- Modify topic owners: `docs/UI_STANDARDS.md`, `docs/frontend.md`, `docs/performance.md`, `docs/security.md`, `docs/DATA_RELATIONS.md`, `docs/testing.md`
- Modify: `CHANGELOG.md` только для реально реализованного поведения
- Update managed blocks only via `php artisan project:docs-refresh`
- Delete this plan only after every approved task is complete and its durable contract moved to owner docs

**Verification order:**

1. `./vendor/bin/pint --dirty --format agent` после PHP-правок.
2. Самый узкий PHPUnit filter каждой задачи.
3. `php artisan project:docs-refresh --check`.
4. `npm run build` после CSS/JS/Blade asset changes.
5. Browser matrix + axe на временной SQLite.
6. `php artisan test` для широкого общего изменения.
7. Read-only production smoke/timing sample после rollout.
8. `git diff --check` и `git status --short --branch`; commit только в `main`.

**Release gates:**

- нет horizontal overflow на 390/768/1440;
- нет serious/critical axe violations;
- один product H1 и один main;
- no raw source/media URLs, traces or secrets;
- `/stats` и machine endpoints укладываются в утвержденные byte/time budgets;
- sitemap/feed XML валиден;
- player lifecycle, filtering, back/forward и auth boundaries не регрессировали;
- рабочее дерево после каждого законченного этапа чистое.

## 9. Рекомендуемый порядок по релизам

### Релиз A — измеримость и доступность

Tasks 1–2. Самый низкий продуктовый риск, создает safety net и закрывает объективный WCAG gap.

### Релиз B — служебная статистика

Task 3. Самый большой измеримый UX/performance выигрыш, но требует отдельного cache/payload design review.

### Релиз C — основной пользовательский путь

Tasks 4–5. Каталог → тайтл → просмотр; changes оцениваются по task completion, а не по декоративности.

### Релиз D — визуальная система и справочники

Tasks 6–8. Убирается визуальная монотонность без изменения данных и URL.

### Релиз E — завершенность shell и ошибок

Tasks 9–11. Favicon, branded errors и одинаковый JSON boundary.

### Релиз F — discovery performance

Tasks 12–13. Bounded feed/sitemap и стабильные изображения; нужен SEO regression review.

### Релиз G — закрытые поверхности и polish

Tasks 14–16. Admin/personal confidence, restrained motion, финальные документы и rollout.

## 10. Что сознательно не рекомендуется

- Полный visual rewrite: работающие route/state/accessibility contracts слишком ценны, а проблема локализована.
- Темная тема: проект зафиксирован как light-only, реального пользовательского требования нет.
- Новый шрифт: системный стек уже корректно поддерживает кириллицу и не создает font-loading cost.
- UI-kit или component framework: существующие Blade components покрывают задачу и сохраняют server rendering.
- GSAP/scroll storytelling: каталог не является промо-лендингом, а motion ухудшит сканирование и reduced-motion burden.
- Бесконечные carousels и horizontal scroll: противоречат главному accessibility contract проекта.
- Скачивание видео или постеров: видео всегда внешнее; poster optimization строится на URL/geometry/cache boundaries.
- Fake accounts/login, recommendations, reviews или studio/status data: интерфейс отражает только существующие product models и реальные данные.
- Разбивка `/stats` на много новых public routes на первом шаге: allowlisted query-section сохраняет canonical и уменьшает миграционный риск.
- Оптимизация только gzip: большой feed или DOM остается большим даже после сжатия; сначала bounded data, затем transport compression.

## 11. Решение, которое нужно одобрить перед реализацией

Предлагается утвердить направление «тихий технологичный каталог» и начать с Release A, затем отдельно согласовать IA `/stats`. Самый важный product choice — оставить `/stats` одной canonical страницей с URL-секциями или разделить ее на отдельные routes. Рекомендован первый вариант: он дает меньший SEO/navigation risk и позволяет измерить эффект до более крупной перестройки.
