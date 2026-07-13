# Авторизация

Обновлено: 13.07.2026

## Правила

- Публичные страницы каталога, карточек, sitemap, RSS, OpenSearch и `llms.txt` доступны гостям.
- Операционные и диагностические write/import-control endpoints не должны оставаться публичными.
- `/stats` доступен гостям как read-only Livewire-страница состояния каталога; чувствительные raw URLs, stack traces и внутренние технические имена не выводятся.
- `/admin/imports` защищён route middleware и gate `manage-seasonvar-imports`; allowlist берётся из `SEASONVAR_IMPORT_ADMIN_EMAILS`. Каждый Livewire action повторно применяет gate и не принимает user ID от browser.
- `/admin/catalog` защищён route middleware и gate `manage-catalog` из того же allowlist. Livewire повторяет gate при mount/render, а `CatalogAdministrationService` применяет `CatalogTitlePolicy` до каждой записи; episode/media IDs всегда разрешаются внутри выбранной title hierarchy.
- В проекте пока нет role/permission и admin-login пакета, поэтому email allowlist — узкая операционная граница, а не новая RBAC-система. Пустой allowlist закрывает доступ всем.
- Blade-шаблоны не принимают решений авторизации. Допустимы только простые display checks: `@can`, `@cannot`, `@auth`, `@guest`.
- Список просмотра, user rating и progress карточки доступны только authenticated user и проходят `CatalogTitlePolicy::interact` внутри write-сервиса; policy повторно применяет SQL-ограничение `CatalogEntitlementService` независимо от видимости controls. Идентификатор владельца берётся только из authenticated session. Скрытый, неопубликованный или недоступный тайтл нельзя добавить в список или оценить.
- `/watching` доступен только authenticated user. `CatalogViewingActivityQuery` начинает обе выборки с `whereBelongsTo($user)`, а `EpisodeViewProgressPolicy` отдельно защищает удаление одной записи и полную очистку; чужой progress ID возвращает 403 и не изменяется.
- Playable source не является публичным полем модели. Livewire получает только `PlaybackSourceData` с короткоживущим signed URL; `/playback/{licensedMedia}` сверяет подпись, viewer с текущей сессией и повторно получает entitlement decision для всей publication hierarchy, поэтому прямой URL не обходит снятие с публикации или смену audience.
- Admin source editor также не возвращает сохранённый playback URL в HTML/Livewire snapshot. Новый URL проходит HTTPS/provider allowlist, а существующий может только сменить разрешённые метаданные или reversible publication state.

## Реализация

- Route `/stats` доступен без авторизации, потому что отдаёт только очищенную read-only сводку.
- Livewire update route для stats-polling использует стандартный `web` middleware stack; отдельная авторизация не добавляется, потому что компонент только читает уже очищенный snapshot.
- Тесты `AuthorizationTest` покрывают гостевой доступ к основным страницам каталога и странице статистики.
- Implicit binding `CatalogTitle` скрывает authenticated-audience карточку от гостя; Livewire дополнительно держит `catalogTitleId` locked и разрешает episode/media IDs только внутри доступной и playable иерархии выбранного тайтла.
- Текущая схема поддерживает audience `public/authenticated`; текущий `User` является единственным активным профилем, поэтому все private actions используют его напрямую и не принимают profile ID. Отдельных profile ownership/age/PIN, role/admin preview, territory, subscription/purchase/trial и concurrent-stream сущностей пока нет. `CatalogEntitlementDecision` задаёт однозначные user-facing состояния для будущих отказов, но сервис не имитирует отсутствующие правила. Их нужно подключать к нему одновременно с появлением реальной доменной модели; PIN в таком расширении должен храниться только как hash.
