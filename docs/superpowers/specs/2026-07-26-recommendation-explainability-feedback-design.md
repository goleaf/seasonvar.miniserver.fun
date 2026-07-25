# Объяснимые рекомендации и двусторонний feedback

Дата: 26.07.2026.

Статус: approved через прямое указание пользователя реализовать
рекомендованные улучшения без остановки на согласовании; design
self-reviewed до production-кода.

Канонический владелец постоянного personalization contract:
[`2026-07-16-recommendation-personalization-exploration-design.md`](2026-07-16-recommendation-personalization-exploration-design.md).

## Контекст

Seasonvar уже разделяет content discovery на «Для вас», trending, popular,
top rated, recently added, recently updated, upcoming, editorial и random.
Персональная ветка имеет отдельные candidate, preference-profile, diversity,
quality и repeat-suppression boundaries. Поэтому добавление ещё одного
generic-блока «похожие сериалы» не улучшает систему: пользователь должен
понимать, почему конкретная карточка показана, и иметь возможность дать как
отрицательный, так и положительный явный сигнал.

Проверенный baseline:

- `CatalogRecommendationService` остаётся единственной canonical
  orchestration boundary;
- `CatalogRecommendationPresenter` уже строит до четырёх broad reason labels,
  но карточка не обозначает их как объяснение;
- `CatalogDiscoveryPage` и `CatalogTitleDetail` поддерживают только
  `not_interested` и `blacklisted`, success/error notice и undo;
- `CatalogUserStateService` уже обеспечивает policy, verified-email,
  optimistic version, transaction, 30/minute rate limit и cache invalidation;
- feedback хранится в nullable string column длиной 32, поэтому additive
  `more_like_this` не требует column/data DDL;
- `CatalogRecommendationExclusionService` исключает любой обработанный
  feedback title из результата;
- negative builders ошибочно трактовали бы любое новое non-null значение как
  отрицательное, если не разделить feedback semantics явно;
- authenticated recommendation result и owner-state API уже имеют
  `private, no-store`, shared public cache не получает private reasons.

## Цель

Дать пользователю понятную и обратимую двухстороннюю настройку рекомендаций:

1. Каждая карточка с reason labels явно говорит «Почему это показано».
2. Авторизованный пользователь может выбрать «Больше похожего»,
   «Не интересует» или «В чёрный список».
3. Интерфейс до записи кратко и честно объясняет scope каждого действия.
4. `more_like_this` становится сильным bounded personalization evidence.
5. Положительный feedback никогда не превращается в negative demotion,
   hidden-library entry или notification suppression.
6. Текущие routes, query strings, pagination, ranking, public API shapes,
   authorization и cache isolation остаются совместимыми.

## Рассмотренные варианты

### 1. Только улучшить подписи двух отрицательных действий

Плюсы: минимальный diff, нет нового enum value.

Минусы: пользователь по-прежнему может только скрывать контент; система не
получает явного сигнала о том, что рекомендация удачна. Основная цель
двустороннего feedback не достигается.

Статус: отклонён.

### 2. Additive `more_like_this` в существующем user-title state

Плюсы:

- переиспользуются policy, transaction, rate limit, optimistic sync, export,
  undo и invalidation;
- одна пара user/title сохраняет один понятный текущий intent;
- нет column/data migration, новой таблицы, retention policy или внешней
  инфраструктуры; отдельный reversible index replacement добавляется только
  по фактическому `EXPLAIN` ниже;
- сигнал естественно входит в оба существующих personalized query paths.

Минусы:

- хранится только текущий intent, а не история всех кликов;
- feedback по тайтлу заменяет предыдущее значение.

Статус: выбран.

### 3. Отдельная feedback-event/impression table

Плюсы: история действий, аналитика position/exposure, возможность offline
evaluation.

Минусы: новая high-volume privacy-sensitive модель, retention/consent,
deduplication, race, aggregation, admin/export/deletion и production
operations. Для пользовательской настройки текущей выдачи это
непропорционально.

Статус: отклонён; возможен только отдельным privacy-reviewed проектом.

## Data contract

`CatalogRecommendationFeedback` получает:

- `more_like_this`;
- `not_interested`;
- `blacklisted`.

Enum предоставляет явные `negativeCases()`, `negativeValues()` и
`isNegative()`. Это предотвращает semantic bugs от `whereNotNull()` в
negative-only запросах.

Существующая колонка:

```text
catalog_title_user_states.recommendation_feedback VARCHAR(32) NULL
```

уже вмещает новое значение. Foreign key, unique `(user_id,
catalog_title_id)` и optimistic version сохраняются. Production `EXPLAIN`
после RED/GREEN показал, что existing
`(user_id, recommendation_feedback, catalog_title_id)` находит нужные rows,
но создаёт temp B-tree для нового semantic activity order; production
aggregate достигает 1 156 feedback rows на пользователя. Поэтому новый
reversible migration заменяет этот индекс тем же именем на
`(user_id, recommendation_feedback, recommendation_feedback_updated_at, id,
catalog_title_id)` без duplicate index и без изменения строк. Data backfill
не нужен. Rollback к старому коду требует сначала
удалить/нормализовать `more_like_this` rows отдельной reversible
operations-командой либо временно сохранить новый enum case; поэтому
рекомендуемый rollback — code revert, сохраняющий enum recognition, и
отключение UI/source weighting. Migration `down()` восстанавливает прежний
индекс. Перед production deploy нужен обычный backup и maintenance window
для bounded index rebuild; деструктивный DML автоматически не выполняется.

## Backend flow

### Запись

1. Livewire принимает scalar title ID и feedback value.
2. `tryFrom()` отклоняет неизвестное значение до service call.
3. Видимый тайтл загружается через `CatalogTitleQuery::visibleTo()`.
4. `CatalogUserStateService::setRecommendationFeedback()` повторно проверяет
   `CatalogTitlePolicy::interact`, schema readiness и rate limit.
5. Existing transaction атомарно обновляет feedback, semantic timestamp,
   optimistic version и related cache versions.
6. Livewire показывает локализованное подтверждение и сохраняет только ID для
   undo.

### Персонализация

- v2 profile: `more_like_this` добавляет
  `CatalogPersonalEvidence::RecommendationFeedback`,
  `CatalogRecommendationReason::BecausePositiveFeedback` с высоким, но
  bounded raw weight и recency decay;
- legacy profile: тот же тайтл добавляется как
  `CatalogRecommendationSource::UserFeedback`;
- scorer переводит новую reason в `UserFeedback`;
- clicked title остаётся exact-excluded из candidates, но его outgoing
  similarity rows могут поддерживать другие candidates;
- fallback и cold-start остаются прежними, если active similarity build не
  даёт подходящих кандидатов.

### Отрицательная семантика

Только `not_interested`, `blacklisted` и `dropped`:

- удаляют source signal;
- участвуют в minimum-three feature demotion;
- входят в hidden library;
- подавляют релизные уведомления в существующих boundaries.

`more_like_this` не должен попадать ни в один из этих запросов.

### Merge/API/export

- merge duplicate titles сохраняет existing deterministic strength
  precedence:
  `blacklisted > not_interested > more_like_this`;
- owner-only API resources и account export возвращают stable raw value;
- OpenAPI enum расширяется additively;
- публичный recommendation API не получает новое private поле.

## Frontend flow

Reason labels остаются внутри existing recommendation card и получают:

- видимый локализованный заголовок «Почему это показано»;
- `aria-label` для группы;
- максимум четыре broad labels;
- отсутствие private source title, exact activity и internal score.

Повторяющийся feedback markup выносится в query-free Blade component. Он
принимает только `titleId` и имя уже существующего Livewire action:

- `setFeedback` на discovery;
- `setRecommendationFeedback` на title detail.

Компонент показывает три кнопки с описанием эффекта, 44px touch targets,
точный `wire:target` с обоими action parameters, disabled/loading state и
локализованный label. JavaScript и новая frontend dependency не нужны.

## Валидация, authorization и безопасность

- unknown/empty/zero/negative title IDs отклоняются существующей Livewire
  boundary;
- unknown feedback value отклоняется до write;
- guest получает существующий login redirect;
- invisible/foreign audience title не проходит canonical visibility и
  policy;
- CSRF обеспечивает Livewire POST boundary;
- 30/minute limit защищает write amplification;
- Blade использует escaped `{{ }}`; action name не поступает от HTTP request,
  а задаётся server-rendered template;
- feedback не включается в shared cache, URL/query string, logs или public
  explanation;
- secrets, source URLs и PII не добавляются.

## SQL и производительность

Column/data DDL не нужен. Один existing index заменяется после фактического
query-plan evidence:

- exact exclusion и positive activity query используют composite index
  `(user_id, recommendation_feedback, recommendation_feedback_updated_at,
  id, catalog_title_id)`;
- positive profile читает уже выбранные state rows, дополнительного per-title
  query не появляется;
- legacy profile добавляет один bounded state query в существующий signal
  aggregation path;
- negative builder использует indexed `whereIn` по двум stable values вместо
  broad `whereNotNull`;
- hidden library и count используют те же negative values;
- UI component не выполняет queries и не добавляет HTTP requests до клика.

Focused tests обязаны проверить constant query budget и SQLite `EXPLAIN QUERY
PLAN`; production engine остаётся repository-configured SQLite, поэтому
MySQL-only hints/raw SQL не вводятся.

## Ошибки и observability

- rate limit и schema-unavailable сохраняют отдельные локализованные notices;
- validation errors не заменяются generic exception;
- unexpected failure показывает безопасное сообщение без stack trace;
- exception logging остаётся в глобальной Laravel boundary, новый код не
  пишет user/title IDs или feedback в custom logs;
- undo использует существующее предсказуемое сообщение и повторную
  authorization.

## Cross-feature compatibility

| Domain | Решение |
| --- | --- |
| Authentication/authorization | Existing verified-email policy/gate/service |
| Translations | RU primary + EN parity, stable enum values не переводятся |
| Caching/privacy | Authenticated results `private, no-store`; shared keys без изменений |
| Search/filters/pagination | Не меняются; discovery query string сохраняется |
| Notifications/calendar | Только exact negative values suppress |
| SEO/public routes/API | Routes/SEO/public recommendation response unchanged |
| Mobile owner API | Additive enum value в existing field + OpenAPI |
| Administration/import | Нет нового admin/import contract |
| Premium/region/legal | Canonical visibility до write и candidate load |
| Audit/export/deletion | Existing state/export/account deletion boundary |
| Queue/schedule | Не затрагиваются |
| Migration/data | Reversible index replacement; no row/backfill/column change |
| Dependencies/env | Not applicable |

## Acceptance criteria

- RU/EN recommendation cards visibly identify explanation labels.
- Both Livewire surfaces render three explained actions from one component.
- Authorized click stores `more_like_this`, shows success and supports undo.
- Unknown feedback/ID and guest/invisible-title cases remain safe.
- Positive feedback produces candidate reason
  `because_positive_feedback` in active v2 and legacy-compatible paths.
- Positive feedback is excluded from negative demotion, hidden library and
  notification suppression.
- Negative behavior and exact exclusions regressions remain green.
- No N+1/query-budget regression; one justified reversible index replacement
  and no row/package/env change.
- Focused/full PHPUnit, Pint, Larastan/PHPStan, docs checks, Vite build and
  browser flow pass or exact independent blocker is recorded.
- README, canonical docs, OpenAPI and CHANGELOG reflect the visitor-visible
  change.
- Only task-related changes are committed in `main`; push result is reported
  exactly.
