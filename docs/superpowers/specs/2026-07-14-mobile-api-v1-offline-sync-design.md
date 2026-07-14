# Offline-sync для mobile API v1

Дата: 14.07.2026

## Цель

Добавить к уже работающему `/api/v1` детерминированную offline-first синхронизацию. Мобильное приложение должно сохранять публичный каталог и личное состояние, после возвращения сети догружать только изменившиеся тайтлы и безопасно повторять очередь пользовательских операций.

Решение не скачивает видео, не раскрывает media/source URL и не строит параллельную бизнес-логику. Все записи переиспользуют `CatalogUserStateService`, playback grant/session validation, policies и существующие Resources.

## Выбранный подход

Используется server-side append-only journal с непрозрачным курсором. Каталог инвалидируется на уровне агрегата `CatalogTitle`: изменение названия, aliases, relations, сезона, серии, media profile, рекомендации или отзыва публикует одно событие `title.upsert`. Скрытие, soft delete или merge публикуют `title.delete` с публичным slug tombstone. Клиент повторно загружает канонический title detail через текущий API.

Это предпочтительнее дат по `updated_at`: физические удаления и merge иначе теряются, одинаковые timestamps дают пропуски, а child rows не обязаны обновлять timestamp тайтла. Полный payload в journal не копируется, поэтому изменение Resources не требует перезаписи истории.

## Хранение

Additive migration создаёт:

- `api_sync_changes`: monotonic `id`, `scope`, nullable `user_id`, `resource_type`, nullable `resource_key`, `operation`, `changed_at`; indexes `(scope, id)` и `(user_id, id)`;
- `api_sync_mutations`: `user_id`, UUID `mutation_id`, SHA-256 `payload_hash`, safe JSON `result`, HTTP-like `status`, timestamps; unique `(user_id, mutation_id)`;
- `catalog_title_user_states.watchlist_version` и `rating_version` — unsigned counters с default `0`, которые инкрементируются только при реальном изменении соответствующего desired state.

Journal не хранит user payload, email, token, playback grant, media URL или importer state. Публичная запись содержит только slug; private entry — тип ресурса и его public/owner-scoped key.

Изменения хранятся 30 дней. Ежедневная scheduled command удаляет только entries старше retention boundary небольшими `chunkById`. Mutation receipts хранятся 90 дней, чтобы retry после долгого offline-периода оставался идемпотентным.

## Cursor contract

Курсор — URL-safe signed/encrypted string с `version`, `scope`, nullable owner id и monotonic journal id. Клиент не может подменить scope/user или перейти к чужим изменениям. Курсор не содержит database credentials и не сериализует model.

Каждый pull-ответ возвращает:

```json
{
  "data": [],
  "meta": {
    "cursor": "opaque-next-cursor",
    "has_more": false,
    "retention_days": 30,
    "server_time": "2026-07-14T16:00:00Z"
  }
}
```

`cursor` всегда продвигается до последнего прочитанного journal id, даже если events одного resource позже сворачиваются в одно client action. Подменённый или scope-mismatched cursor даёт `validation_failed`/422. Курсор старше сохранённого journal window даёт `sync_cursor_expired`/410 и ссылку на bootstrap contract.

## Public bootstrap и pull

### `GET /api/v1/sync/manifest`

Гостевой endpoint возвращает:

- `sync_version=1`;
- текущий public cursor;
- `retention_days`, `max_pull_items`, `max_push_items`;
- canonical URLs для `/api/v1/catalog/filters`, directories, `/api/v1/titles`, changes и OpenAPI;
- русское краткое указание: сначала сохранить cursor, затем пагинировать текущие Resources, после чего догнать changes от сохранённого cursor.

Такой order закрывает race между началом полной загрузки и её завершением. Manifest не содержит importer/search-index/queue state.

### `GET /api/v1/sync/changes`

Гостевой endpoint принимает optional `cursor` и `limit` 1–200. Без cursor он возвращает пустую выдачу и текущую checkpoint, а не всю историю. Каждая change:

```json
{
  "type": "title",
  "key": "public-title-slug",
  "operation": "upsert",
  "changed_at": "2026-07-14T16:00:00Z",
  "links": {
    "self": "https://example.test/api/v1/titles/public-title-slug"
  }
}
```

Для `delete` `links.self` равен `null`. Ответ не содержит title payload, BM25, source identity или причину скрытия.

Из-за high-cardinality cursor ответы `manifest`/`changes` не помещаются в shared response cache. Они используют `private, no-store`, но остаются guest-accessible.

## Private pull

### `GET /api/v1/me/sync`

Требует Bearer token с `mobile:read`. Принимает optional `cursor` и `limit` 1–200. Без cursor возвращает owner checkpoint; полный initial state клиент берёт из уже пагинированных watchlist, ratings, Continue Watching и history endpoints. Затем pull возвращает owner-scoped invalidations:

- `title_state.upsert` с title slug и URL `/me/titles/{slug}/state`;
- `progress.upsert` с title slug, episode id и URL title state/history;
- `history.delete` с owner progress id;
- `history.clear` без resource key.

Для title state canonical Resource добавляет additive `versions.watchlist` и `versions.rating`. Они не являются глобальным sequence и используются только для optimistic conflict check конкретного desired state.

Private sync никогда не возвращает `user_id`, email, token id, чужой progress id или shared-cache validators.

## Idempotent batch push

### `POST /api/v1/me/sync`

Требует `auth:sanctum`, `mobile:write` и verified email. Принимает от 1 до 50 operations. Каждая operation имеет UUID `mutation_id` и один из allowlisted типов:

- `watchlist.set`: `title_slug`, boolean `value`, non-negative `expected_version`;
- `rating.set`: `title_slug`, nullable integer `value`, non-negative `expected_version`;
- `progress.set`: `title_slug`, `episode_id`, opaque `playback_session`, `event_sequence`, `position_seconds`, `duration_seconds`, `ended`;
- `history.delete`: owner `progress_id`;
- `history.clear`: без resource payload.

Form Request отклоняет unknown keys/types и ограничивает весь request до 50 operations. Каждая operation выполняется изолированно через существующий domain service. Ответ `200` содержит один result на operation в исходном порядке:

```json
{
  "mutation_id": "4f58d3b4-bf96-4a44-94a9-3424f74c1024",
  "status": "applied",
  "resource_version": 3,
  "data": {}
}
```

Допустимые statuses: `applied`, `duplicate`, `conflict`, `rejected`, `not_found`. Domain/validation failure одной operation не откатывает уже применённые соседние operations и не превращает batch в 500.

### Idempotency

Первый request атомарно сохраняет receipt. Повтор того же `mutation_id` с тем же payload возвращает сохранённый safe result со status `duplicate` и не повторяет mutation. Тот же UUID с другим payload возвращает operation-level `conflict`; ни старый, ни новый payload не выполняется повторно.

Playback grant/session в receipt не сохраняется: хэш строится из canonical request, а result содержит только safe progress Resource. Если grant истёк, operation получает `rejected`; batch не ослабляет текущую playback boundary.

### Conflicts

`watchlist.set` и `rating.set` сравнивают `expected_version` под row lock. Несовпадение не меняет state и возвращает `conflict` с canonical current state и его версией. Это не позволяет старому offline device молча затереть более новое изменение другого device. Клиент показывает конфликт или создаёт новую mutation с обновлённой version.

Progress сохраняет действующий session-id/event-sequence conflict contract. History delete/clear остаются idempotent desired deletions.

## Публикация changes

`CatalogSyncChangePublisher` и `UserSyncChangePublisher` создают entries только after commit. Вызовы встраиваются в текущие write boundaries:

- успешно изменившийся Seasonvar import/backfill публикует title upsert один раз после всех child writes;
- admin title/relation/media write публикует upsert;
- merger публикует canonical upsert и duplicate delete tombstone;
- visibility/soft-delete boundary публикует upsert или delete по итоговой public visibility;
- watchlist/rating/progress/history services публикуют owner event только после успеха.

Публикация не должна превращать успешную доменную запись в 500 из-за secondary sync journal. Ошибка journal репортится с sanitized context; full bootstrap остаётся recovery path.

## Безопасность и privacy

- Public cursor не даёт доступ к private scope; private cursor привязан к authenticated owner.
- Pull/push ответы имеют `private, no-store`, без ETag/Last-Modified; `Authorization` никогда не допускает shared cache.
- Batch push не обходит verified email, Sanctum abilities, policies, title/season/episode/media ownership и playback availability.
- Responses и receipts не хранят/не отдают Bearer token, password, email, playback grant, raw media URL, source URL, source path, importer state и stack trace.
- Unknown operation, extra key, oversized batch, tampered cursor и cross-user identifier отклоняются до domain write или owner-scoped query.
- Sync не добавляет wildcard CORS, device tracking, push notifications и analytics.

## Производительность

- Pull читает index range `id > cursor` с hard limit 200 и не делает offset pagination.
- Journal хранит invalidations, а не дублированные title graphs.
- Import публикует один event на изменившийся title, а не на каждую серию/media row.
- Retention pruning ограничен по chunks и не запускается параллельно с самим собой.
- `api_sync_mutations` и journal имеют indexes по курсору, owner и retention timestamp.

## Ошибки

- 401 `unauthenticated`: private pull/push без валидного Bearer token;
- 403 `forbidden` / `email_not_verified`: ability, policy и verification boundary;
- 410 `sync_cursor_expired`: cursor вне retention window;
- 422 `validation_failed`: cursor shape/signature/scope, limit, batch и operation schema;
- 503 `sync_unavailable`: additive schema ещё не применена; остальной `/api/v1` продолжает работать;
- operation-level `conflict`, `rejected`, `not_found`: ожидаемый domain result внутри валидного batch;
- 500 `server_error`: только неожиданная infrastructure failure, без exception message.

## Тесты

1. Manifest не раскрывает infrastructure и возвращает bootstrap checkpoint.
2. Public pull проверяет upsert/delete, stable order, limit/has_more, empty checkpoint, tamper/scope mismatch и expired cursor.
3. Import/admin/merge публикуют одно title-level event after commit; unchanged import не шумит.
4. Private pull проверяет guest/invalid token, abilities, owner isolation, state/progress/delete/clear и private no-store.
5. Batch Form Request проверяет 1–50, UUID uniqueness в batch, exact keys/types/ranges и unknown operations.
6. Watchlist/rating batch проверяет applied, duplicate, payload collision, expected-version conflict, hidden title denial и cross-user isolation.
7. Progress batch повторяет всю текущую grant/session/event-sequence matrix и не хранит grant в receipt.
8. History delete/clear остаются owner-scoped и idempotent.
9. Privacy scan проверяет journal, receipts и JSON на source/media/importer/token/password/email markers.
10. Query-delta сравнивает 1 и 200 changes; pull остаётся одним bounded keyset query плюс metadata.
11. OpenAPI описывает все routes, schemas, cursor expiry и batch result statuses.
12. Полный PHPUnit, Pint, docs refresh и production migration preflight остаются обязательными.

## Документация и rollout

- `docs/api.md` владеет routes, cursor/bootstrap/push contract и mobile recovery flow.
- `docs/architecture.md` владеет journal publishers, aggregate invalidation и service reuse.
- `docs/authorization.md` и `docs/security.md` фиксируют owner/ability/verified/idempotency/privacy boundaries.
- `docs/DATA_RELATIONS.md` фиксирует additive tables, versions и retention.
- `docs/testing.md`, `README.md`, `CHANGELOG.md` и `resources/api/openapi.json` обновляются в том же commit series.

Миграция не запускается параллельно активным Seasonvar imports, pending/delayed/reserved jobs или live claims. Код безопасен в окне между deploy и migrate: publishers проверяют `Schema::hasTable()` и не меняют доменный result, а sync controllers отвечают `sync_unavailable`/503. Остальной `/api/v1` продолжает работать. После migration readiness отдельного feature flag не требуется. Откат сначала убирает routes/publishers, затем обратимая migration удаляет только sync tables/columns.

## Не входит

- скачивание или offline-воспроизведение видео;
- изменение title/review/catalog content из mobile app;
- admin/import/queue controls и diagnostics;
- push notifications, background mobile OS scheduling и mobile repository implementation;
- cross-device automatic conflict winner: конфликт возвращается клиенту явно;
- бессрочная история journal/receipts.
