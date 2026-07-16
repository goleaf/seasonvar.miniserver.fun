# Глобальный поиск и русский редакторский режим — план реализации

**Цель:** заменить блокирующий текстовый Livewire-autocomplete двухскоростным публичным поиском с постерами и счётчиками, обеспечить адаптивность mobile/tablet/desktop/TV и оставить языковой выбор только в профиле.

**Архитектура:** существующий read-only API получает два header-scope. Query-классы сериалов и портала независимы; Vite-модуль запускает их параллельно, отменяет устаревшие запросы и безопасно строит доступный DOM. Общая GET-страница объединяет bounded preview и канонические переходы. Редакторский locale фиксируется доменными константами `ru`, не удаляя legacy English data.

**Стек:** Laravel 13, PHP 8.5, Eloquent/SQLite FTS, API Resources, Blade, JavaScript, Tailwind CSS 4, PHPUnit 12, Playwright, Vite 8.

## 1. Закрепить новый title suggestion contract

- [x] Дополнить `CatalogTitleSuggestionQueryTest`: poster/year и доступные seasons/episodes загружаются без N+1; hidden release rows не считаются.
- [x] Запустить тест и получить ожидаемое падение.
- [x] Расширить `CatalogTitleSuggestionQuery` минимальными columns и bounded relation/count queries.
- [x] Проверить query budget и отсутствие description/taxonomy eager loads.
- [x] Запустить unit test.

## 2. Создать лёгкий публичный поиск по порталу

- [x] Создать `PortalSearchSuggestionQuery` и отдельные тесты публичных границ.
- [x] Покрыть taxonomy types, years, collections, requests, profiles и sections.
- [x] Доказать тестом, что private/pending/hidden/deleted rows отсутствуют.
- [x] Заменить `TagQuery::searchPublic()` на lightweight tag branch.
- [x] Добавить точное/префиксное/word/contains ранжирование и стабильные лимиты.
- [x] Измерить текущую SQLite-базу и сохранить query/time budget в тесте и документации.

## 3. Расширить API без поломки legacy clients

- [x] Добавить allowlisted `scope=header_titles|header_portal` в `SearchSuggestionRequest`; locale брать только из текущего интерфейсного контекста.
- [x] Сначала написать API-тесты validation, legacy shape и новых scope.
- [x] Расширить `CatalogSearchSuggestionQuery` ветвлением scope без выполнения ненужного контура.
- [x] Расширить `SearchSuggestionResource` только allowlisted optional fields.
- [x] Добавить scope и серверную interface locale в cache dimensions и OpenAPI schema.
- [x] Проверить rate limit и JSON error boundary.

## 4. Заменить Livewire header search на progressive Vite UI

- [x] Добавить Blade contract-тест для API endpoint, GET fallback, neutral input frame и accessible hooks.
- [x] Создать `resources/views/components/layout/header-search.blade.php`.
- [x] Создать `resources/js/header-search.js` без inline business JS и unsafe `innerHTML`.
- [x] Реализовать 160ms debounce, два AbortController, sequence guard и memory cache.
- [x] Реализовать независимые loading/error/empty states и безопасную same-origin URL проверку.
- [x] Реализовать keyboard navigation и focus/blur/outside behavior.
- [x] Добавить title rows с 2:3 poster, year, season/episode counts и honest fallback.
- [x] Ограничить readable max-width на desktop/TV и сохранить page-only scroll.
- [x] Подключить модуль в `app.js` и повторно инициализировать после `livewire:navigated` без duplicate listeners.
- [x] Удалить старый Livewire component/service path после доказанной замены и обновить его тесты.

## 5. Добавить общую страницу результатов

- [x] Написать feature test `/search?q=`: validation, groups, public-only, noindex и GET fallback.
- [x] Создать тонкий controller/page builder и passive Blade view.
- [x] Переиспользовать query-классы с page limits, не дублировать SQL.
- [x] Добавить явный переход в `/titles?q=` и канонические domain links.
- [x] Не добавлять маршрут в sitemap/public warm manifest.

## 6. Удалить locale switch из шапки

- [x] Добавить regression test: header не содержит `locale.switch`, RU/EN controls или POST form.
- [x] Сохранить account settings locale select и покрыть тестом как единственный интерфейс выбора.
- [x] Удалить header locale data из `AppLayoutData` и markup из `site-header`.
- [x] Удалить неиспользуемый POST route/controller/request после reference audit.
- [x] Сохранить direct localized routes и account preference behavior.

## 7. Зафиксировать русский authoring locale

- [x] Зафиксировать `ru` доменными константами collection/tag authoring-компонентов.
- [x] Collection tests: create/edit всегда пишут `ru`, selector/label отсутствуют, existing EN row не удаляется.
- [x] Personal tag tests: create/edit используют `ru`, visible language field отсутствует.
- [x] Tag admin tests: одна ru form без language heading/select; alias создаётся с `ru`; EN rows сохраняются.
- [x] Изменить dashboard/editor/managers и Blade views минимально, сохранив policies/validation.
- [x] Оставить request/media original/audio/subtitle language fields и покрыть статическим контрактом.

## 8. Адаптивная и браузерная матрица

- [x] Playwright: быстрые title results появляются независимо от portal delay.
- [x] Проверить настоящий poster и fallback, year, seasons, episodes.
- [x] Проверить Arrow/Home/End/Enter/Escape, outside click и GET fallback.
- [x] Проверить отказ одного scope и stale response cancellation на уровне независимых settle/sequence boundaries.
- [x] Проверить 375, 768, 1280 и 1920 без horizontal/internal overflow; общая CI-матрица дополнительно покрывает 390/768/1440.
- [x] Проверить touch targets, TV keyboard flow, console/page/network errors.
- [x] Не запускать Vite build параллельно Playwright, чтобы manifest/assets не участвовали в гонке.

## 9. Документация

- [x] Обновить `docs/catalog-search.md`: scopes, sources, ranking, limits, fallback, performance.
- [x] Обновить `docs/api.md`/OpenAPI owner document новым optional contract.
- [x] Обновить `docs/frontend.md`, `docs/views.md`, `docs/UI_STANDARDS.md` progressive UI и responsive contract.
- [x] Обновить collection/tag owner docs русским authoring boundary.
- [x] Обновить `README.md` на русском: посетительская возможность, единственное место выбора языка, датированная история.
- [x] Обновить `CHANGELOG.md` техническими деталями.
- [x] Запустить `php artisan project:docs-refresh` только для managed blocks, затем `--check`.

## 10. Финальная проверка и доставка

- [x] Запустить Pint только по изменённым PHP-файлам, не форматируя постороннюю грязную работу.
- [x] Запустить focused unit/feature/API tests.
- [ ] Запустить полный `php artisan test`.
- [x] Запустить `npm run build`, затем Playwright последовательно.
- [x] Проверить `git diff --check`, route list, docs freshness и отсутствие секретов.
- [x] Проверить README и точный task diff.
- [x] Не коммитить посторонние изменения; dirty tree с активной параллельной HDRezka/Top-100 сессией явно зафиксирован как блокер безопасного commit.

Состояние 16.07.2026: focused scope стабильно проходит, но полный `php artisan test` нельзя достоверно завершить, пока отдельный writer-процесс продолжает добавлять миграции, классы и тесты в общий каталог. Два condition-based monitor подряд (15 и 30 минут) не получили стабильного окна; последний процесс менял файлы за пять секунд до timeout. Пункт полного suite остаётся открытым намеренно, без ложной отметки.

Дополнительная browser-проверка 16.07.2026 воспроизвела общий источник прежних шести падений: shared page cache вернул локальному порту HTML с другим `APP_URL`, из-за чего network guard блокировал CSS/JS и same-origin защита заменяла ссылки подсказок на `#`. `playwright.config.js` теперь изолирует все пять tiered stores вместе с default store; после fresh build desktop/mobile/tablet matrix прошла `6/6`. Header fetch передаёт locale текущего документа, а API regression фиксирует грамматическую `meta` (`1 сезон`, `3 серии`). Axe-проверка открытого dropdown дополнительно выявила и закрыла critical `aria-required-children`: визуальные заголовки групп исключены из accessibility tree, live-status вынесен за пределы `listbox`, а groups/options сохранили доступные имена и клавиатурную навигацию.

Финальный task-scoped gate 16.07.2026 прошёл `24/24` теста и `166` утверждений. Он дополнительно зафиксировал, что fallback без готового FTS возвращает ограниченный набор частичных совпадений по названиям и aliases вместо схлопывания до единственного exact match, укладывается в query budget и строит канонические taxonomy URL. Production build, docs freshness, PHP/JavaScript syntax, search routes, OpenAPI JSON и `git diff --check` прошли на том же снимке.

Повторная матрица после последней параллельной правки общего JS дала `5/6`: tablet завершил переход и итоговые утверждения, но общий timeout истёк внутри `press('Enter')`. Trace подтвердил уже загруженный `/titles/browser-smoke`, отсутствие browser error и успешные последующие checks. Комплексному тесту с axe, delayed portal scope и четырьмя resize-проходами назначен точечный лимит 90 секунд; отдельный повтор Tablet Chromium прошёл `1/1` за 47,7 секунды, тем самым текущая сборка подтверждена во всех шести desktop/mobile/tablet сценариях.
