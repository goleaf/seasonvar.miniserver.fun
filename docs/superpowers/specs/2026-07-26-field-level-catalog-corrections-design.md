# Field-level исправления каталога — design

Дата: 26.07.2026

## Цель

Добавить на публичную карточку тайтла контекстные действия «Исправить
данные» для названия, года, жанров, тегов, стран, актёров, постера, описания,
переводов, серий и субтитров. Пользователь должен попадать в уже
существующий workflow заявок с безопасно предзаполненным контекстом,
поддерживать существующую заявку голосом, а модератор — принимать или
отклонять её через каноническую очередь.

## Выбранный подход

Расширяется существующий `ContentRequest`, а не создаются новые correction,
vote или moderation aggregates. Публичная страница строит обычные ссылки на
`requests.create` с `type`, `catalog_title_id`, allowlisted field и
необязательным target ID. `ContentRequestFormPage` передаёт эти указатели
доменному resolver; resolver заново проверяет видимость тайтла и
принадлежность taxonomy/episode, затем формирует:

- каноническое поле заявки;
- безопасное текущее значение;
- stable `correction_target_key`;
- season/episode IDs для episode-scoped исправления;
- безопасное начальное предлагаемое действие для удаления ошибочного тега.

`correction_target_key` хранится в `content_requests` и входит в exact
identity. Он различает, например, два ошибочных тега одного тайтла, но не
раскрывает URL источника. `correction_reason` хранит backed-enum код. Для
тега причина обязательна; для остальных полей она отсутствует.

## Почему не отдельный inline modal

Inline Livewire modal дублировал бы поиск target, валидацию, rate limiting,
duplicate detection, idempotency, голосование, уведомления и moderation.
Глубокая ссылка сохраняет progressive enhancement, browser navigation,
существующую verified-account policy и один источник истины. Форма остаётся
полной страницей и может принять доказательства и предлагаемое значение.

## Data model

Additive migration добавляет в `content_requests`:

- nullable `correction_reason` длиной 32;
- nullable `correction_target_key` длиной 191.

Обе колонки nullable для обратной совместимости со всеми прежними заявками.
Новый индекс не требуется: exact active duplicate уже защищён unique
`active_identity_key`, а административные списки продолжают использовать
существующие title/type/status/order indexes. `down()` удаляет только две
новые колонки. Migration не выполняет backfill и не меняет каталожные данные.

## Supported fields

Новые публичные stable codes:

- `title`;
- `year`;
- `genre`;
- `tag`;
- `country`;
- `actor`;
- `poster`;
- `description`;
- `translation`;
- `episode`;
- `subtitles`.

Legacy `cast` и `episode_list` сохраняются в validation/config и display
mapping. Новый `episode` shortcut сохраняется как каноническое
`episode_list`, чтобы не ломать существующий request contract.

## Причины для тега

- `not_related` — не относится к сериалу;
- `duplicate` — дубликат;
- `translation_error` — ошибка перевода;
- `too_broad` — слишком общий;
- `import_error` — ошибка импорта.

Причина является обязательной server-side только при
`correction_field=tag`. Переведённый label не хранится в базе и не
используется как identity.

## Presentation

Один query-free Blade component рендерит компактную keyboard-accessible
ссылку с локализованным текстом. Parent title page получает полностью
подготовленные scalar и taxonomy URL. Player получает episode/subtitle URL
в render data; ссылки не вложены в другие ссылки.

Для отсутствующего значения показывается field row и action, поэтому
пользователь может предложить недостающую страну, жанр, актёра, перевод или
тег. Для каждого существующего relation chip выводится собственная кнопка.
Постер не передаёт URL в заявку: resolver пишет только «установлен» или
«отсутствует».

Detail и moderation card показывают field/reason/current/proposed values.
Голосование остаётся существующим уникальным toggle. Существующие статусы
`approved` и `rejected` и rejection reason закрывают требование принять или
отклонить; catalog mutation автоматически не выполняется.

## Security и privacy

- verified account и `ContentRequestPolicy::create/vote` остаются
  обязательными;
- `catalog_title_id`, target ID и field повторно разрешаются server-side;
- Livewire target key заблокирован и дополнительно валидируется action
  boundary;
- user ID, status, priority, votes и moderator data не принимаются;
- Blade использует escaped output;
- public DTO не получает voter identities, private evidence и source/media
  URL;
- rate limits, CSRF, transaction, row lock, unique vote и optimistic
  moderation сохраняются.

## Performance

Title page не выполняет новые запросы: URL строятся из уже eager-loaded
relations. Form resolver делает один bounded lookup конкретной relation или
episode. Existing pagination/aggregates не меняются. Новая identity dimension
хешируется в PHP и использует существующий unique index.

## Rolling deployment и rollback

Порядок: backup/migrate-status assessment, additive migration, код, asset
build, focused smoke. До migration `ContentRequestSchema` возвращает
unavailable для write/read UI, вместо SQL exception. Откат кода безопасен с
оставленными nullable columns; schema rollback выполняется только после
возврата старого кода и проверки отсутствия новых writes. Новые заявки при
rollback к старому коду сохраняются, но старый код не будет показывать
reason/target identity.

## Проверка

- schema, nullable columns и reversible migration;
- prefill каждого scalar/relation/episode target;
- tampered и foreign target rejection;
- обязательные пять tag reasons и пустая/invalid reason;
- exact duplicate separation по двум tag targets;
- creator vote/follow и поддержка другим пользователем;
- guest/unverified/verified/moderator authorization;
- approved/rejected transitions и reason display;
- title/player HTML на desktop/mobile semantics;
- translation parity, Blade query-free scan, Pint, focused tests, full suite,
  build и diff/security review.
