# Eloquent-модели

Обновлено: 15.07.2026

## Правила связей

- Для каталога используются явные Eloquent-связи и pivot-таблицы; morph-связи для метаданных не применяются.
- Если в миграции есть внешний ключ или ссылочное поле, модель должна иметь читаемую связь в обе стороны, когда это используется импортом, статистикой, API или тестами.
- `CatalogTitle` остается корневой моделью карточки: сезоны, серии через `hasManyThrough`, медиа, отзывы, рейтинги, алиасы, рекомендации и события импорта не создают отдельные карточки.
- `CatalogTitle::display_title` и `display_original_title` являются presentation-only accessors: они разделяют совпадающий суффикс `/original_title`, не изменяя хранимые `title`, `original_title` и поисковый индекс.
- Приватное пользовательское состояние хранится отдельно: `CatalogTitleUserState` принадлежит user/title, `EpisodeViewProgress` принадлежит user/title/episode; каталог и импортёр не дублируются в этих таблицах.
- `SourcePage` хранит состояние обхода источника и связывается с карточкой, сезонами, сериями, отзывами, HTML-снимками, событиями импорта и последним запуском импорта.
- `SeasonvarImportRun` владеет событиями запуска, HTML-снимками и страницами источника, где он записан как `last_import_run_id`.

## Запросы

- Связи, которые читает Blade или JSON Resource, должны быть загружены в page-builder/query/service слое через `with()`, `load()` или `loadMissing()`.
- Счетчики связей для списков нужно получать через `withCount()` или агрегированные запросы; не считать их в Blade.
- Resource-классы должны использовать `whenLoaded()` и `whenCounted()`, чтобы сериализация не вызывала lazy loading.
- Для гостевых карточек используйте `CatalogTitle::published()`, а при наличии текущего пользователя — `CatalogTitle::availableTo($user)`.
- Для сезонов и серий действуют те же scopes. Публичные media query дополнительно вызывают `forAvailableReleases($user)`, чтобы hidden/draft дочерняя запись не раскрывалась через видео.
- Playback query также вызывает `withPlaybackLocation()`, чтобы media без фактического URL не участвовала в выборе и counts.
- Playback resolver обязан помечать `catalogTitle`, `season` и `episode` загруженными перед строгой проверкой иерархии; для title-level media гарантированное `episode_id = null` задаётся через `setRelation('episode', null)`, а не скрытый lazy load.
- Relationship `seasons()` и `episodes()` уже содержит deterministic ordering `kind, sort_order, number, id`; Blade не должен сортировать эти коллекции повторно.

## Casts

- Числовые колонки счетчиков и HTTP-статусов явно кастуются в `integer`.
- Флаги кастуются в `boolean`, JSON-поля — в `array`, даты импорта и публикации — в `date` или `datetime`.
- Новые поля моделей должны получать cast одновременно с миграцией, если их тип не строковый.
- `publication_status`, `audience` и `kind` кастуются в `PublicationStatus`, `ContentAudience` и `ReleaseKind`; окна доступности и soft-delete timestamps кастуются Eloquent как даты.

## Границы справочников

- Люди и роли представлены существующими `Actor` и `Director` с отдельными pivot-таблицами; общий `Person` не добавляется без отдельной миграционной стратегии слияния.
- Языки озвучки сейчас представлены `Translation` и `licensed_media.translation_name`; наличие субтитров — `licensed_media.has_subtitles`.
- Нормализованные audio/subtitle track records не создаются, пока внешний источник не предоставляет устойчивые track identifiers. Это известное ограничение, а не повод хранить дублирующие строки при каждом импорте.

## Blade

- Blade-шаблоны не должны выполнять relationship-запросы, вызывать `@php`/`@endphp` или собирать бизнес-данные.
- Если шаблону нужен производный атрибут модели, добавляйте accessor, enum/helper-метод, ViewModel или класс компонента.

## Модели коллекций

- `CatalogCollection` — единственная aggregate root именованного списка. Numeric ID — relational identity, UUID — external identity; global current slug и `CatalogCollectionSlug` history являются mutable URL projection. SoftDeletes сохраняет recovery window.
- `CatalogCollectionItem` — explicit serial-only child с unique collection/title, position, added-by и timestamps. Он не копирует title/poster/year/genre metadata и не использует morph type.
- `CatalogCollectionTranslation` применяется только к editorial content; display accessors выбирают уже eager-loaded active/fallback row. User text остаётся в base name/description и не переводится автоматически.
- `CatalogCollectionReport` сохраняет stable collection UUID/content version даже после nullable target FK; `CatalogCollection::comments()` переиспользует explicit enum-based generic comment target, а не Eloquent morph.
- `CatalogCollectionQuery` обязан eager-load owner/translations/counts/fallback and paginated title card relations до Blade/Resource. `display_name`, `display_description`, `display_seo_*`, visibility/moderation predicates не выполняют query.

## Модели обсуждений

- `Comment` — единственная aggregate row top-level/reply. `target_type` enum-backed и не Eloquent morph; `target_id` разрешает allowlisted service. `parent()`/`replies()` держат root thread, `replyTo()` — logical context, `author()` nullable для privacy deletion, `catalogTitle()` with-trashed — cache/merge root.
- `CommentReaction`, `CommentReport`, `CommentRestriction`, `UserBlock`, `UserMute`, `CommentNotificationPreference` имеют explicit foreign keys/casts/relations и stable enum codes. Модель не локализует storage values и не содержит controller/Livewire workflow.
- `Comment` body всегда plain text; accessor не рендерит HTML. Counts/reaction score не являются model columns. Queries обязаны eager/group load author/reply/reactions/counts/viewer context; Blade не вызывает relations.
- Provider `CatalogTitleReview` не является `Comment` и не превращается в reply. Season/episode/collection target не получает отдельную comment model/table. Mention/edit-history/premium entities отсутствуют осознанно.

## Модели отзывов

- `CatalogTitleReview` — единая provider/user aggregate с direct `catalogTitle()` target. Numeric ID остаётся stable; `origin`, `status`, deletion/moderation codes cast-ятся enum-ами, booleans/timestamps/version — typed casts. `authorAccount()` nullable для account privacy deletion, а display/provider author text не используется как identity.
- `CatalogTitleReviewVote`, `CatalogTitleReviewReport`, `CatalogTitleReviewRestriction`, `CatalogTitleReviewNotificationPreference` и `CatalogTitleReviewAlias` имеют explicit foreign keys, enum casts и узкие relations. Это не Eloquent morph domain; произвольный target class не хранится.
- Rating не является review attribute/relation copy: query соединяет canonical `CatalogTitleUserState` той же пары author/title. Review average/vote totals/current viewer state не являются model columns и готовятся query/presenter.
- Provider import может сохранять исторические bodies длиннее community limits; model accessor не переписывает и не рендерит HTML. User text проходит value objects при mutation, а Blade получает prepared escaped DTO. Queries eager-load author/title/reports and grouped totals; views не вызывают lazy relations.
- Merge архивирует duplicate row и сохраняет alias вместо hard delete. Account deletion anonymizes nullable ownership; ordinary review deletion использует explicit `deleted_at` lifecycle, не удаляет canonical portal rating и не меняет user library/progress.

## Модели тегов

- `Tag` остаётся единственной global catalog taxonomy model и existing explicit `belongsToMany(CatalogTitle::class, 'catalog_title_tag')`. Numeric ID — relational identity, UUID — external identity, optional code — integration identity; localized accessor читает только заранее выбранные active/fallback values и не выполняет query.
- `TagTranslation`, `TagAlias`, `TagSlug`, `TagSynonym`, `TagProviderMapping`, `CatalogTitleTagSource` и `TagMergeEvent` имеют explicit foreign keys/typed enum casts. Они не образуют polymorphic assignment domain и не принимают arbitrary model class. `mergedInto/mergedTags` — bounded compatibility relation, не recursive public traversal.
- `UserTag` — независимая owner-scoped private model с SoftDeletes и explicit `catalog_title_user_tag`. Она не наследует `Tag`, не может изменить global classification и не имеет public slug/visibility/moderation relation. `User::personalTags()` и `CatalogTitle::personalTags()` используются только в authenticated query/service boundaries.
- Model scopes определяют `publiclyEligible` и `globallyAssignable`, но public visible-title requirement, localization, counts/search/related/popular остаются в `TagQuery/TagResolver`; mutation/normalization/merge/cache — в services. Blade/API получают eager-loaded model/resource/DTO и не вызывают lazy relations, resolver или cache.
- Enum storage: `TagType`, `TagVisibility`, `TagModerationStatus`, `TagSource`, `TagAliasSource`, `TagSynonymRelationship`, `TagProviderMappingStatus`. UI translation keys не являются stored values. Public-user/hierarchy/season/episode tag models отсутствуют намеренно.

## Profile models и DTO

- `UserProfile` is the one-to-one presentation/privacy record for `User`; it casts stable visibility/moderation enums and never replaces the authentication model.
- `UserProfileUsernameHistory` preserves old route aliases; `UserProfileReport` stores private moderation evidence. Neither is exposed directly to Blade/API.
- `PublicUserProfileData` is the explicit public-safe payload. `ResolvedUserProfile` separates current/history resolution. Full Eloquent graphs, user email/security columns, private activity and report notes are excluded from Livewire public state.

## Recommendation models и DTO

- `CatalogTitleRecommendation` remains calculated similarity storage with source/target, score, rank, reasons, breakdown, algorithm version and computed time. It is not user-specific and its score is not a display percentage.
- `CatalogTitleRelation` owns explicit source/target, `CatalogTitleRelationType`, `CatalogTitleRelationSource`, provider key, priority, lock/active state and inverse belongs-to relations. `CatalogTitle` exposes outgoing/incoming explicit relations; seasons are never recommendation titles.
- `CatalogTitleUserState` retains one unique owner/title aggregate and adds enum-cast nullable `recommendation_feedback` and `watch_status` plus monotonic versions. Watchlist/rating/progress/history are not duplicated.
- `CatalogRecommendationContext`, result/item/explanation/list-item DTOs are typed server-side presentation boundaries. Livewire public properties never contain these Eloquent/history graphs.
- Stable recommendation type/source/reason/relation/watch-status/feedback/popularity-period enums store untranslated codes. Unsupported creator/audio/subtitle-language/premium/region/franchise entities remain absent rather than represented by nullable fake models.
