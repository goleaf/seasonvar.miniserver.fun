# Архитектура приложения

Обновлено: 12.07.2026

## Контроллеры

- Контроллеры остаются тонкими: принимают route/request зависимости, выбирают view или responder и не собирают сложные запросы, SEO-массивы или view state.
- Страницы каталога используют page-builder сервисы в `App\Services\Catalog`:
  - `CatalogHomePageBuilder` готовит данные главной страницы.
  - `CatalogTitlesPageBuilder` готовит выдачу каталога, фильтры, счетчики и SEO для списка.
  - `CatalogTitlePageBuilder` готовит статическую metadata-оболочку тайтла, summaries сезонов, рекомендации и SEO без загрузки всех серий.
- `/titles`, `/titles/year/{year}` и taxonomy-маршруты обслуживает full-page `App\Livewire\CatalogSeries`. Компонент отвечает только за URL/page state и пользовательские действия, валидирует состояние через `CatalogTitlesRequest` и ровно один раз за render делегирует данные в `CatalogTitlesPageBuilder`.
- Sitemap, feed, OpenSearch и `llms.txt` обслуживает отдельный `CatalogSitemapController`, который делегирует XML/text-ответы в `CatalogSitemapResponder`.
- JSON API обслуживает `App\Http\Controllers\Api\CatalogTitleController`: контроллер только принимает Form Request/model binding и возвращает API Resources, а выбор публичных связей выполняет `CatalogApiTitleQuery`.
- `/stats` обслуживается тонким controller-view слоем: `CatalogController::stats()` отдает SEO и Livewire-обертку, live-данные рендерит `App\Livewire\StatsDashboard`, а постеры статистики отдает `CatalogStatsPosterResponder` через внутренний proxy-маршрут.
- `/titles/{catalogTitle:slug}` сохраняет implicit route binding и статическую Blade-оболочку; `App\Livewire\CatalogTitlePlayer` отвечает только за URL-state активного сезона/серии/media и authenticated user actions. Locked `catalogTitleId` не принимается от browser updates, а Eloquent-коллекции существуют только как render data.

## Actions и сервисы

- Класс получает одну причину для изменения: атомарная операция оформляется Action, координация нескольких шагов или внешней интеграции остаётся Service, неизменяемое состояние между слоями передаётся DTO. Новая папка создаётся только вместе с реально используемым классом; пустые архитектурные каталоги не добавляются.
- Дискретные бизнес-операции оформляются как небольшие сервисы или action-классы с constructor/method injection; контроллеры и команды не должны держать тяжелую логику внутри `handle()` или action-методов.
- Параллельный режим `seasonvar:import --queued` использует `SeasonvarQueuedImportDispatcher`, атомарные lease в `SeasonvarPageClaimManager`, Redis job `ImportSeasonvarSourcePage` и единый `FinalizeSeasonvarQueuedImport`. SQLite не используется как очередь импорта.
- `SeasonvarRefreshPlanner` перед обычными due-кандидатами выбирает не более одного import chunk страниц `missing_data`, отсортированных по времени следующей попытки и последнего импорта. Planner исключает страницы с живым claim до применения limit, поэтому recovery chunk заполняется реально доступными страницами; истёкшие claims остаются кандидатами.
- Worker проверяет lease token до HTTP-запроса и пересчитывает Redis lock по canonical slug текущей `SourcePage`; поэтому разные numeric ID сезонов одного тайтла не могут одновременно менять общие связи, включая jobs из уже накопленного backlog.
- SQLite catalog transactions используют `IMMEDIATE` mode вместе с WAL и busy timeout, чтобы разные workers не сталкивались на DEFERRED read-to-write upgrade; внешний fetch остаётся за пределами transaction.
- `RecordSeasonvarPageFailure` является единственной границей записи ошибочного состояния `SourcePage`; `SeasonvarImportFailureClassifier` разделяет transient connection/408/425/429/5xx/SQLite-lock ошибки и permanent ошибки страницы. Только transient exception покидает queued job и активирует Laravel backoff/retry window.
- `SeasonvarQueueServiceProvider` изолирует queue lifecycle hooks от HTTP/view bootstrap, откатывает оставленные job транзакции и передаёт исключения/QueueBusy в throttled monitor. `SeasonvarQueueStatusData` и `SeasonvarQueueStatus` питают read-only режим `seasonvar:import --status`; при нескольких running runs основным считается run с максимальным числом живых claims.
- Сервисы возвращают типизированный результат или готовые данные для вызывающего слоя, а вывод сообщений, HTTP-ответы и консольные коды остаются в контроллере или команде.
- Не добавлять repository-классы для простых Eloquent-связей; reusable запросы остаются в query-сервисах, scopes или page-builder сервисах.
- `project:docs-refresh` делегирует обновление управляемых блоков документации в `App\Services\ProjectDocumentation\ProjectDocumentationRefresher`, а команда только печатает результат и возвращает код выхода.
- Статистика `/stats` собирается через `CatalogStatsSnapshotBuilder`, очищается `CatalogStatsSnapshotSanitizer` и кешируется `CatalogStatsSnapshotCache`; Livewire-компонент не хранит полный stats-массив в публичном состоянии.
- `CatalogStatsPosterUrlGuard` проверяет, можно ли безопасно проксировать внешний poster URL; `CatalogStatsPageBuilder` не рендерит `poster_src` для URL, которые guard отвергнет, а `CatalogStatsPosterResponder` повторно применяет тот же guard перед HTTP-запросом.
- `CatalogTitlePlaybackQuery` является общей playback boundary карточки: видимые summaries, точные counts, один активный сезон, playable media и deterministic next episode. `CatalogPrimaryActionResolver` выбирает continue/next/replay/start, а `CatalogUserStateService` записывает watchlist, rating и progress только после повторной проверки доступности.

## Запросы и валидация

- Входные параметры списка каталога нормализует и проверяет `CatalogTitlesRequest`.
- URL-состояние `/titles` хранит `CatalogSeriesFilters`: только скаляры и ограниченные массивы slug/годов. Route-контекст года и taxonomy защищён `#[Locked]`; paginator, Eloquent-модели, фасеты и SEO не сериализуются в публичный Livewire snapshot.
- `CatalogTitlesPageBuilder` один раз разбирает нормализованный `q` через `CatalogSearchQueryParser` и собирает неизменяемый `CatalogTitlesCriteria`; тот же объект передается в выдачу, контекстные счетчики связей и счетчики годов.
- Multi-select фильтры каталога передаются как повторяемые query-параметры: годы, relation-фильтры, типы публикации, качества и наличие субтитров остаются ограниченными наборами, relation slug резолвятся пакетно, а `CatalogTitlesCriteria` хранит только нормализованные уникальные ID и enum-значения. Значения одной группы объединяются через OR, отдельные группы — через AND.
- Query-параметры выбранной серии и видео на странице карточки проверяет `CatalogShowRequest`.
- Поддерживаемые типы фильтров перечислены в `App\Enums\CatalogFilterType`, а slug-значения проверяет `App\Rules\CatalogFilterSlug`.
- Единая public query boundary находится в `CatalogTitleQuery`: `visibleTo()` первым условием применяет publication status, legacy-флаг публикации, окно доступности, audience текущего пользователя и soft delete; `filteredTitles()` затем применяет поиск, годы, relation- и media/rating-фильтры, а `sorted()` — только enum-сортировку с `id` tie-breaker.
- Главная, список, API, публичные блоки статистики, sitemap/feed, facet-счетчики и построитель рекомендаций начинают выборку тайтлов через эту boundary. Служебные показатели качества импорта могут намеренно считать все сохраненные строки и не являются публичной выдачей.
- Каждый relation-фильтр реализован отдельным grouped pivot `whereIn`-подзапросом: несколько ID внутри подзапроса дают OR, а несколько подзапросов в основной выборке дают AND. Основная выборка не соединяется с pivot-таблицами, поэтому не требует `distinct`, а paginator count совпадает с числом видимых тайтлов.
- `CatalogFacetQuery` загружает не более 24 актеров или режиссеров за запрос и применяет серверный поиск только к нормализованной строке от двух до 80 символов; выбранные записи поднимаются в начало без дублей.
- Описание поддерживаемых фильтров, моделей связей и eager-load наборов находится в `CatalogTaxonomyRegistry`.

## Publication boundary

- `CatalogStatus` остаётся production metadata источника; публичную видимость определяют `PublicationStatus`, audience, availability window и soft-delete scope.
- Общие условия находятся в `HasPublicationAvailability`, а публичные page builders/API queries ограничивают сезоны, серии и media parents до eager loading и `withCount()`.
- `ReleaseKind` и составные unique keys отделяют specials от обычной нумерации. Relationship-модели отвечают за единый порядок, поэтому контроллеры и Blade не сортируют выпуски самостоятельно.
- Доступ `authenticated` пока означает только наличие `User`; entitlement/territory policy не вводится до появления реальной лицензионной модели.

## Поиск каталога

- Полная значимая фраза сначала проверяется на точное совпадение с основным или оригинальным названием и хэшами алиасов. При наличии точного совпадения дальнейший широкий текстовый поиск не выполняется; ID кандидатов остаются SQL-подзапросом и не загружаются полной коллекцией в PHP.
- Если точного имени нет, каждый значимый терм образует отдельную `AND`-группу. Внутри группы варианты названия, описания, slug, внешнего ID, алиасов и имен связанных справочников объединяются через `OR`.
- Один распознанный год из `q` является жестким ограничением. Несовместимые годы из `q` и параметра `year` дают нулевую выдачу и не переходят к временному fallback.
- `CatalogTitleQuery` для запроса только из стоп-слов дает нулевое условие без `title` context, но сохраняет существующий `whereKey()` для title-scoped страниц. `CatalogTitlesPageBuilder` использует единственный paginator и не заменяет нулевой результат полным каталогом.
- Все варианты сортировки завершаются `catalog_titles.id DESC`, поэтому строки с одинаковыми годом, названием, счетчиками или `indexed_at` имеют устойчивый порядок.

## Авторизация

- Основные страницы каталога остаются публичными read-only страницами.
- `CatalogTitle::resolveRouteBindingQuery()` ограничивает все текущие публичные implicit bindings опубликованными тайтлами. Карточка, API show и `stats.poster` поэтому используют одну publication boundary; query-сервисы дополнительно сохраняют явный `published()` как защиту для вызовов вне HTTP binding.
- Служебная страница `/stats` тоже доступна как публичная read-only сводка, но остается под rate limiter и не раскрывает raw source URLs, приватные media URLs или stack traces.
- Livewire update endpoint дополнительно использует `throttle:catalog-stats`, а страница `/stats` использует щадящий `wire:poll.15s.visible`, чтобы не держать polling в скрытой вкладке и не перегружать общий лимит.
- Счетчики rate limiter используют `CACHE_LIMITER_STORE=file`, отдельно от основного `CACHE_STORE=database`, чтобы публичный throttle не усиливал SQLite write contention.
- Новые write/admin/import-control endpoints должны получать отдельный gate или policy до регистрации маршрута.
- Authenticated действия карточки проходят auto-discovered `CatalogTitlePolicy::interact`; скрытие кнопок в Blade не используется как контроль доступа.

## Защитные ограничения

- В non-production `Model::shouldBeStrict()` запрещает lazy loading, молчаливое отбрасывание mass-assignment полей и чтение невыбранных атрибутов.
- В production `DB::prohibitDestructiveCommands()` блокирует `db:wipe`, `migrate:fresh`, `migrate:refresh`, `migrate:reset` и rollback-команды.
- Новые domain/action/DTO/exception/provider классы используют `declare(strict_types=1)`; массовое механическое добавление в старые файлы не требуется.

## Представление и SEO

- Blade получает готовые переменные и не использует `@php`/`@endphp`.
- Переменные для layout SEO готовит `AppLayoutData`.
- View state для фильтров и страницы тайтла находится в `App\View\ViewModels`.
- SEO, JSON-LD, breadcrumbs, поисковые фразы и related links готовит `CatalogSeoBuilder`.

## API Resources

- Публичные JSON-ответы используют ресурсы в `app/Http/Resources`, а не массивы в контроллерах.
- Ресурсы не раскрывают source URL, HTML-снимки, внутреннее состояние импортера, raw media URLs, ключи медиа или stack traces.
- Связи и счетчики в ресурсах добавляются только через `whenLoaded()` и `whenCounted()`; query-сервисы заранее загружают нужные отношения.
