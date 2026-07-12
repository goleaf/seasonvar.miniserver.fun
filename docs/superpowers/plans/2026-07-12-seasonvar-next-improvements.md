# Seasonvar: следующий подробный план улучшений после текущей итерации

Дата: 12.07.2026  
Ветка текущей итерации: `seasonvar-design-code-polish`  
Ограничение текущего рабочего режима: новые test-файлы не создавать; при необходимости усиливать уже существующие проверки только после отдельного разрешения пользователя.

## 1. Текущая база после выполненной итерации

### 1.1 Что уже улучшено в коде

- Каталог `/titles` разложен на явные Blade-компоненты `x-catalog.*`, чтобы основная страница перестала содержать крупные повторяющиеся блоки фильтров, сортировки, алфавита и пустых состояний.
- Состояние ссылок каталога перенесено в `CatalogTitlesViewModel`: query-string очищается от пустых значений, одиночные значения не превращаются в массивные query-параметры, активные таксономии сравниваются по всему выбранному набору.
- `CatalogTitlesRequest` корректно отличает пустой `title`/taxonomy параметр от реально указанного slug, поэтому пустые фильтры не превращаются в ложный контекст.
- Общие UI-компоненты `x-ui.taxonomy-chip` и `x-ui.status-pill` получили перенос длинного текста, стабильные иконки, `min-width: 0` и защиту от переполнения.
- `x-title-card` и `x-title-list-row` получили нормальные русские склонения для сезонов, серий и видео.
- Facet-counts в `CatalogFacetQuery` исправлены на корректные pivot-ключи для связей справочник → тайтлы.
- Proxy постеров статистики перестал отклонять валидные ответы с пустым `Content-Length`, но сохранил блокировку нулевого и слишком большого размера.
- В публичном интерфейсе переключатель вида использует нейтральный термин `Плитка`, не запрещённый публичной терминологией проекта.
- Радиусы, тени и chip-плотность в `resources/css/app.css` стали спокойнее и компактнее.

### 1.2 Проверенная база

- `./vendor/bin/pint --dirty --format agent`
- `php artisan test --filter=BladeTemplateTest`
- `php artisan test --filter=CatalogBladeComponentTest`
- `php artisan test --filter=CatalogVisualSystemTest`
- `php artisan test --filter=CatalogTitlesRequestTest`
- `php artisan test --filter=CatalogSearchPageTest`
- `php artisan test --filter=CatalogAdvancedFilterTest`
- `php artisan test --filter=CatalogTitlesViewModelTest`
- `php artisan test --filter=CatalogPageTest`
- `php artisan test --filter=PublicOutputTerminologyTest`
- `php artisan test`
- `npm run build`

## 2. Принципы следующих работ

### 2.1 Архитектура

- Контроллеры оставлять тонкими: request validation, orchestration, выбор view или response.
- Данные для Blade готовить в ViewModel, ViewData, page builder, service или классе компонента.
- Не добавлять query-логику в Blade.
- Не использовать `@php` и `@endphp` в Blade.
- Не создавать второй способ построения ссылок каталога, если уже есть метод в `CatalogTitlesViewModel`.
- Не вводить production dependency без отдельного подтверждения.

### 2.2 UI

- Светлая тема остаётся обязательной.
- Видимый текст остаётся на русском языке.
- Публичный UI не использует служебные слова проекта: source brand, parser/import wording, raw route/debug terminology, термин с корнем `карточк`.
- Длинные названия и связи переносятся, а не обрезаются.
- Mobile-first: результаты каталога остаются выше фильтров на телефоне.
- Компоненты `x-ui.panel`, `x-ui.taxonomy-chip`, `x-title-poster`, `x-title-card`, `x-title-list-row`, `x-stat`, `x-form.search-field` использовать до добавления новой разметки.

### 2.3 Проверки

- После PHP-правок запускать Pint.
- После Blade/CSS/JS/Vite-правок запускать `npm run build`.
- Сначала запускать focused-проверки рядом с изменением.
- После правок query-сервисов, request-валидации или общих компонентов запускать полный `php artisan test`.
- Новые test-файлы не создавать в текущем режиме; если покрытие некуда добавить без нового файла, зафиксировать это в отчёте как блокер для отдельного разрешения.

## 3. Эпик A: довести `/titles` до полностью стабильного UI-контракта

### A1. Проверить все состояния страницы

- Проверить `/titles` без query-параметров.
- Проверить `/titles?q=драма` с нормальным запросом.
- Проверить `/titles?q=и` или другой слишком общий запрос.
- Проверить `/titles?genre=drama`.
- Проверить `/titles/country/{slug}`.
- Проверить `/titles/year/{year}`.
- Проверить пустой результат после фильтров.
- Проверить пустой результат после поиска.
- Проверить результат с одной записью.
- Проверить результат с 24 записями.
- Проверить результат с несколькими страницами пагинации.
- Проверить grid-view.
- Проверить list-view.
- Проверить переключение sort.
- Проверить active filters.
- Проверить exclusion filters.
- Проверить advanced filters.

### A2. Упростить визуальную иерархию панели фильтров

- Сравнить `x-catalog.filter-section` с панелью стран на главной странице.
- Привести высоту controls к единому минимуму 44px.
- Проверить, что длинные названия жанров, стран, актёров и переводов не создают горизонтальный scroll.
- Убедиться, что counts не сжимают название до одной буквы.
- Для пустых секций оставить короткий текст `Нет данных.`
- Проверить, что icon-only элементы имеют видимый текст рядом или `sr-only` текст.

### A3. Доработать мобильное поведение фильтров

- На ширине 360px проверить, что основной список не уходит под фильтры.
- На ширине 390px проверить перенос toolbar controls.
- На ширине 430px проверить advanced filters.
- На ширине 768px проверить переход к двум колонкам без переполнения.
- На ширине 1024px проверить sidebar и main content.
- На ширине 1440px проверить количество колонок grid.
- На ширине 1760px проверить максимальную ширину и spacing.
- Если mobile-фильтры остаются слишком длинными, перевести фильтры в native `<details>` на mobile и раскрытую sidebar-панель на desktop без JavaScript-зависимости.

### A4. Зафиксировать canonical query-поведение

- Все ссылки сортировки должны сохранять активный search/filter контекст.
- Сброс search не должен сбрасывать taxonomy, если пользователь нажимает taxonomy-specific reset.
- Сброс filters не должен сохранять пустые массивы.
- `genre=slug` должен оставаться scalar.
- `genre[]=slug-a&genre[]=slug-b` допустим только для множественного выбора.
- `sort` не должен дублироваться при повторном клике.
- `page` должен сбрасываться при изменении filter/search/sort.
- `view` должен сохраняться при пагинации.
- `letter` должен сохранять остальные совместимые параметры.
- Несовместимые параметры должны очищаться в request/view model, а не в Blade.

### A5. Согласовать тексты и термины

- Заменить публичные термины, которые звучат как внутренняя реализация.
- Для grid режима использовать `Плитка`.
- Для list режима использовать `Список`.
- Для empty search использовать текст про отсутствие совпадений, без технических объяснений.
- Для слишком общего search использовать текст про слишком общий запрос, без слов про stop words.
- Для filters empty state использовать короткие нейтральные фразы.
- Для stats public page держать технические данные только там, где страница явно служебная, и не раскрывать raw URLs.

## 4. Эпик B: производительность каталога и справочников

### B1. Аудит query-count

- Измерить query-count для home.
- Измерить query-count для `/titles`.
- Измерить query-count для `/titles?view=list`.
- Измерить query-count для `/titles?genre=drama`.
- Измерить query-count для `/titles/country/{slug}`.
- Измерить query-count для `/titles/{slug}`.
- Измерить query-count для `/stats`.
- Зафиксировать baseline в maintenance log после подтверждения подхода.

### B2. FacetQuery

- Проверить все типы `CatalogTaxonomyRegistry::filterTypes()`.
- Убедиться, что каждый facet-count считает опубликованные тайтлы по правильному pivot-ключу.
- Убедиться, что сортировка deterministic: count desc, name asc, id asc.
- Для больших справочников оставить limit только там, где это реально нужно UX.
- Для selected taxonomy гарантировать присутствие выбранного элемента даже за пределами лимита.
- Для multi-select гарантировать присутствие всех выбранных элементов.
- Рассмотреть отдельный метод `taxonomiesForFilterPanel()` вместо расширения универсального `taxonomies()`, если появится divergent behavior.

### B3. Пагинация и counts

- Проверить, что `withCount` покрывает сезоны, серии и опубликованное media.
- Для list-view не загружать лишние связи, которые не используются в строке.
- Для grid-view не загружать `latestSeason`, если он не нужен.
- Проверить, что сортировка по video availability использует индексируемый подзапрос или агрегат.
- Проверить, что сортировка по episodes/seasons не создаёт expensive full scans на больших данных.
- Проверить query plan SQLite локально и production DB отдельно, если production не SQLite.

### B4. Постеры

- Для публичного каталога рассмотреть безопасный proxy или signed cache для внешних постеров.
- Не выводить raw private poster URLs там, где страница служебная.
- Для публичных страниц сохранить `referrerpolicy="no-referrer"`.
- Добавить width/height или aspect-ratio contract там, где браузеру нужно заранее резервировать место.
- Проверить lazy loading на списках.
- Проверить object-fit: постеры не должны обрезаться.
- Ввести negative cache для недоступных постеров, если внешний источник часто отдаёт ошибки.

## 5. Эпик C: страница тайтла `/titles/{slug}`

### C1. Верхняя часть страницы

- Поднять блок просмотра выше справочных SEO-блоков.
- Убедиться, что постер, заголовок, год, тип и основные связи читаются на телефоне.
- Сохранить один главный route model binding по slug.
- Проверить, что неопубликованный тайтл возвращает 404.
- Проверить, что выбранная серия через query валидируется `CatalogShowRequest`.

### C2. Сезоны и серии

- Все сезоны остаются внутри одного `CatalogTitle`.
- Не создавать отдельные тайтлы для сезонов.
- Сезонный accordion должен быть доступен с клавиатуры.
- Серии должны иметь крупные touch-targets.
- На mobile список серий остаётся в одну колонку.
- На tablet допустимы две колонки.
- На широком desktop допустимы три колонки, если названия не ломаются.
- Выбранная серия должна иметь явное состояние.

### C3. Видео-варианты

- Отображать качество.
- Отображать формат.
- Отображать перевод или voice label.
- Отображать состояние субтитров.
- Отображать availability без служебной терминологии.
- Не скачивать видео.
- Хранить только внешние URL и metadata.
- Для HLS использовать текущий локальный Plyr/HLS bundle.
- Проверить fallback, если браузер поддерживает HLS нативно.
- Проверить fallback, если HLS требует `hls.js/light`.

### C4. Рекомендации

- Проверить, что recommendations не тянут тяжёлые связи.
- Reason badges должны быть короткими и русскими.
- Не использовать слово с корнем `карточк` в публичном выводе.
- Ссылки рекомендаций должны вести только на опубликованные тайтлы.
- Не показывать пустые декоративные блоки, если рекомендаций нет.

## 6. Эпик D: главная страница

### D1. Последние обновления

- Проверить, что блок не пустует при наличии опубликованных тайтлов без постеров.
- Проверить, что даты группируются стабильно.
- Проверить, что title rows показывают все важные связи без случайного обрезания.
- Если связей много, использовать disclosure или приоритетные группы вместо жёсткого `take`.
- Не перегружать главную длинными справочниками до основного контента.

### D2. Страны и жанры

- Страны должны считаться через исправленный `CatalogFacetQuery`.
- Жанры должны считаться через тот же исправленный путь.
- Если элементов больше первого лимита, disclosure должен показывать оставшиеся элементы.
- Counts должны соответствовать опубликованным тайтлам.
- Пустые состояния не должны выглядеть как ошибка.

### D3. Сейчас можно смотреть

- Показывать только опубликованные media.
- Не выводить raw playback/source URLs.
- Показывать качество, формат и перевод.
- Если media нет, оставить короткое пустое состояние.
- Проверить responsive layout на mobile и desktop.

## 7. Эпик E: статистика `/stats`

### E1. Безопасность вывода

- Не выводить raw source URLs.
- Не выводить raw playback URLs.
- Не выводить private poster URLs.
- Не выводить stack traces.
- Не выводить route table internals.
- Не выводить database indexes.
- Не выводить внутренние названия таблиц и колонок, если это не нужно публичной странице.

### E2. Poster proxy

- Сохранять HTTPS-only ограничение.
- Блокировать localhost и private/reserved IP.
- Блокировать слишком большой Content-Length.
- Блокировать нулевой Content-Length.
- Игнорировать пустой Content-Length, если тело валидное.
- Проверять финальный размер body.
- Разрешать только image MIME types.
- Не следовать redirect без явного разрешения.
- Использовать короткие timeout и connect timeout.
- Добавить cache headers согласно выбранной политике.

### E3. Livewire dashboard

- Проверить `wire:poll.1s` на production-нагрузке.
- Если `/stats` станет тяжёлой, увеличить poll interval или добавить server-side cache.
- Проверить mobile layout.
- Проверить tablet layout.
- Проверить wide layout.
- Проверить, что Livewire payload не содержит private data.

## 8. Эпик F: импорт Seasonvar

### F1. Public import command

- `php artisan seasonvar:import` остаётся единственной публичной командой импорта.
- Не добавлять отдельные публичные commands для обхода этого контракта.
- Новые maintenance операции делать внутренними сервисами или опциями существующей команды.

### F2. Parser

- Валидировать source URL только внутри `https://seasonvar.ru/`.
- Нормализовать относительные URL через parser service.
- Не сохранять пустые taxonomy values.
- Не создавать отдельные тайтлы для сезонов.
- Сохранять сезоны и серии внутри одного `CatalogTitle`.
- Сохранять media metadata без скачивания видео.
- Сохранять hash источника, чтобы пропускать неизменённые страницы.

### F3. Bulk writes

- Для catalog titles использовать upsert там, где это безопасно.
- Для связей использовать sync/upsert пакетами.
- Для сезонов использовать grouped operations.
- Для серий использовать bulk operations.
- Для media использовать upsert по устойчивому ключу.
- Multi-table запись каталога держать в transaction.

### F4. Crawler politeness

- Сохранять консервативные delays.
- Использовать HTTP timeout.
- Использовать retries с ограничением.
- Не делать неограниченные remote calls.
- Логировать ошибки без secret/raw private content.
- Разделять retryable и non-retryable ошибки.

## 9. Эпик G: SEO и публичные discovery-файлы

### G1. Sitemap

- `/sitemap.xml` должен оставаться совместимым индексом.
- `/sitemap-index.xml` должен отражать статические страницы, годы, справочники, landings, titles, videos.
- Title sitemap включает только опубликованные тайтлы.
- Video sitemap включает только опубликованные media с валидными абсолютными URL.
- Sitemap responses остаются streamed.
- Canonical URLs не должны иметь пустой host.

### G2. Robots

- `robots.txt` должен ссылаться на актуальный sitemap index.
- Не блокировать публичные страницы каталога.
- Не раскрывать внутренние debug paths.

### G3. Feeds

- RSS не должен раскрывать private URLs.
- Feed должен включать только опубликованные тайтлы или опубликованные media.
- Тексты feed должны быть русскими и нейтральными.

### G4. Meta и JSON-LD

- Не добавлять чрезмерные keyword/meta блоки без пользы.
- Проверить, что JSON-LD URL строятся с корректным host.
- Проверить, что title page JSON-LD не раскрывает raw media URL, если это считается приватным.
- Проверить, что public terminology filter проходит по `home`, `titles.index`, `titles.show`, `stats`, API, feed, opensearch и llms.

## 10. Эпик H: API

### H1. Read-only contract

- API остаётся read-only.
- Eloquent models отдавать через API Resources.
- Pagination meta держать явным.
- Не раскрывать source HTML snapshots.
- Не раскрывать importer state.
- Не раскрывать raw remote URLs.
- Не раскрывать stack traces.

### H2. Filters

- Нормализовать query-параметры через Form Request.
- Для taxonomy filters использовать тот же slug validation contract.
- Для пагинации ограничить `per_page`.
- Для сортировки использовать allow-list.
- Для year ranges проверять нижнюю и верхнюю границу.

### H3. Resources

- `CatalogTitleResource` должен отдавать только публичные поля.
- Связи отдавать только через `whenLoaded`.
- Seasons и episodes не должны провоцировать N+1.
- Media resource должен скрывать private/internal fields.

## 11. Эпик I: документация и automation

### I1. Project docs

- README должен описывать реальный Laravel Seasonvar каталог.
- `docs/CODE_STANDARDS.md` должен отражать текущие importer и UI rules.
- `docs/DATA_RELATIONS.md` должен отражать pivot-таблицы и связи.
- `docs/UI_STANDARDS.md` должен отражать текущий light-theme contract.
- `docs/frontend.md` должен отражать Vite, Tailwind 4, FontAwesome, Plyr, HLS.
- `docs/views.md` должен отражать запрет `@php` и текущую структуру ViewModel.

### I2. Docs refresh

- Проверить `php artisan project:docs-refresh`.
- Проверить managed blocks.
- Проверить, что команда не добавляет публичный маркетинговый текст.
- Проверить, что hook не коммитит посторонние изменения.
- Проверить, что auto-push включается только при `SEASONVAR_DOCS_AUTO_PUSH=1`.

### I3. Maintenance log

- После каждой значимой правки добавлять короткую запись.
- Запись должна содержать дату.
- Запись должна содержать факт изменения.
- Запись не должна раскрывать secrets.
- Запись не должна заменять проектную документацию общим Laravel текстом.

## 12. Эпик J: accessibility

### J1. Keyboard

- Проверить tab order на home.
- Проверить tab order на `/titles`.
- Проверить tab order на `/titles/{slug}`.
- Один title tile должен иметь один главный tab-stop.
- Relation links должны оставаться отдельными доступными ссылками поверх stretched link.
- Native disclosure controls должны быть достижимы клавиатурой.
- Focus ring должен быть видимым.

### J2. Screen reader text

- Icon-only controls должны иметь `aria-label` или видимый текст.
- Decorative icons должны иметь `aria-hidden="true"`.
- Forms должны иметь labels.
- Search fields должны иметь понятный label.
- Empty states должны быть понятны без визуального контекста.

### J3. Motion

- Hover lift должен быть только `motion-safe`.
- Не добавлять обязательные анимации для понимания интерфейса.
- Проверить reduced motion.

## 13. Эпик K: визуальная полировка

### K1. Design tokens

- Проверить `--radius-control`.
- Проверить `--radius-panel`.
- Проверить `--shadow-panel`.
- Проверить `--shadow-panel-hover`.
- Проверить контраст slate/emerald оттенков.
- Проверить светлую тему без dark variants.

### K2. Component consistency

- `x-ui.panel` должен покрывать большинство boxed sections.
- `x-ui.taxonomy-chip` должен покрывать taxonomy links.
- `x-ui.status-pill` должен покрывать status markers.
- `x-stat` должен покрывать metrics.
- `x-title-poster` должен покрывать poster/placeholder.
- `x-title-card` должен покрывать grid results.
- `x-title-list-row` должен покрывать list results.

### K3. Dense data

- Плотные метаданные выводить как label/value или chips.
- Не выводить длинные comma-separated строки.
- Не использовать truncate/line-clamp для публичного текста.
- Не делать controls ниже 44px на интерактивных элементах.

## 14. Эпик L: безопасность

### L1. Input normalization

- Все query filters проходят Form Request.
- Slug filters проходят reusable validation rule.
- Year ranges имеют bounds.
- Sort values имеют allow-list.
- Video/subtitles/rating/source filters имеют allow-list.

### L2. URL handling

- Source URLs Seasonvar остаются внутри `https://seasonvar.ru/`.
- External poster/media URLs нормализуются.
- Private/internal IP блокируются в proxy flows.
- Код приложения читает env только через config files.

### L3. Public output

- Не выводить secrets.
- Не выводить tokens.
- Не выводить cookies.
- Не выводить raw private logs.
- Не выводить downloaded video files.
- Не выводить private source credentials.

## 15. Эпик M: Git, CI и релизный порядок

### M1. Перед commit

- Проверить `git status --short`.
- Проверить `git diff --stat`.
- Проверить, что нет `.env`.
- Проверить, что нет secrets.
- Проверить, что нет новых test-файлов в текущем режиме.
- Проверить Pint.
- Проверить focused tests.
- Проверить full tests при общих изменениях.
- Проверить `npm run build` при frontend changes.

### M2. Commit

- Использовать один осмысленный commit для текущей связанной итерации.
- Commit message должен описывать результат, а не процесс.
- Не включать generated build artifacts, если они ignored и не являются частью репозитория.
- Включить плановые markdown-файлы, если пользователь запросил план как артефакт.

### M3. Push

- Push текущей ветки в origin.
- Если upstream не задан, использовать `git push -u origin seasonvar-design-code-polish`.
- Если remote/auth недоступны, остановиться и явно сообщить команду, которую нужно выполнить после авторизации.

## 16. Эпик N: будущая проверка через браузер

### N1. Playwright smoke

- Открыть home.
- Открыть `/titles`.
- Открыть `/titles?view=list`.
- Открыть `/titles?q=драма`.
- Открыть `/titles/{slug}`.
- Открыть `/stats`.
- Проверить console errors.
- Проверить network errors.
- Проверить screenshots для 390px, 768px, 1440px.

### N2. Визуальные критерии

- Нет горизонтального scroll на mobile.
- Header не перекрывает контент.
- Search controls не вываливаются за viewport.
- Filter chips переносятся.
- Pagination остаётся светлой и кликабельной.
- Poster placeholders не растягивают layout.
- Player block остаётся светлым без media.

## 17. Порядок следующей итерации

1. Начать с аудита `/titles` в браузере на mobile и desktop.
2. Исправить только фактические UI/overflow проблемы, найденные в браузере.
3. Проверить canonical query links после browser-audit.
4. Затем перейти к `/titles/{slug}` и player placement.
5. Затем перейти к `/stats` payload/cache/performance.
6. Затем перейти к importer performance.
7. Затем обновить документацию и maintenance log.
8. В конце выполнить Pint, focused tests, `php artisan test`, `npm run build`.
9. Сделать отдельный commit.
10. Push текущей ветки.

## 18. Стоп-условия

- Нужна новая production dependency.
- Нужно редактировать `.env`.
- Нужно создать новый test-файл при действующем запрете пользователя.
- Нужно запускать destructive database command.
- Нужно менять публичный import command contract.
- Нужно вводить authenticated write/admin/moderation endpoint без отдельного authorization design.
- Нужен доступ к production secrets или внешним credentials.
- Push требует интерактивной авторизации, недоступной в текущей среде.
