# Восстановление публичных редакционных подборок — дизайн

Дата: 27.07.2026.

Статус: `approved_for_implementation` прямым запросом пользователя вернуть
прежние подборки с категориями и подкатегориями.

## Подтверждённая причина

В рабочей SQLite сохранены все прежние записи:

- 501 collection остаётся `public + approved`, но canonical
  `publiclyListed()` возвращает 0;
- 447 записей принадлежат exact demo corpus и содержат от 1 527 до 4 201
  элементов — они не являются редакционными подборками и не возвращаются;
- 54 ownerless collection имеют точную HDRezka provenance, но не имеют
  категории;
- 39 source rows пусты, ещё 5 непустых строк имеют ошибочное или
  дублирующее сопоставление;
- 10 source rows имеют непустой bounded состав, актуальный score 76–77,
  единственную открытую причину `missing_category` и проверяемое соответствие
  теме.

Empty state вызван не удалением данных и не Livewire/route/UI ошибкой, а
правильным fail-closed public scope после появления обязательной
классификации.

## Решение

Добавить отдельную one-time-compatible recovery boundary:

- immutable allowlist связывает только exact SHA-256 `source_key` десяти
  вручную проверенных source records со stable slug существующей
  подкатегории;
- поиск дополнительно требует provider `hdrezka`, ownerless editorial/manual
  identity, доступный source record и от 1 до 500 membership;
- dry-run является режимом по умолчанию и возвращает только агрегаты;
- запись требует `--force`, а в production ещё
  `--backup-confirmed --writers-paused`;
- active Seasonvar import, collection sync или незавершённая recommendation
  build блокируют запись;
- category conflict, empty/oversized/missing source и unknown key никогда не
  перезаписываются;
- mutation назначает категорию, возвращает `public + approved + published`,
  снимает feature, увеличивает `content_version`, затем пересчитывает
  quality;
- строка появляется в directory только если после пересчёта проходит
  существующий `publiclyListed()` без обхода quality issues или score.

Allowlist использует source hashes и category slugs, а не numeric IDs,
mutable names или raw provider URLs. Обычная синхронизация продолжает
создавать новые строки `private + archived`; recovery не вызывается из
schedule/importer и не принимает произвольный ресурс.

## Выбранная классификация

Проверенный набор распределяется по девяти подкатегориям:

- `new-and-premieres`;
- `animation-and-anime`;
- `netflix`;
- `amazon`;
- `apple-tv-plus`;
- `disney-plus`;
- `hbo-and-max`;
- `other-platforms`;
- `tense-stories`.

Две записи относятся к `animation-and-anime`; остальные назначения
одиночны. Пустые фильмовые source rows и сомнительные одноэлементные
совпадения не включаются.

## Совместимость и cross-feature impact

- routes, Livewire URL state, API Resources, sitemap format, translations,
  schema, migrations, packages и cache keys не меняются;
- detail/API/search/sitemap/title relations получают записи только через
  уже существующий canonical public scope;
- category assignment и quality refresh используют targeted collection
  invalidation; global cache flush запрещён;
- source provenance, membership, translations, comments, reports,
  recommendation signals и stable public identity сохраняются;
- существующая exact quarantine больше не считает классифицированные rows
  кандидатами;
- новые source rows, demo collections и пользовательские коллекции не
  затрагиваются.

## Operations и rollback

До production DML обязательны verified SQLite backup, integrity check,
остановка writers и dry-run. Rollback кода — revert. Data rollback —
восстановление verified backup при остановленных writers; безопасный
roll-forward — вернуть exact rows в private/archived через существующую
moderation/reconciliation boundary. Hard delete, migration rollback и
широкий SQL отсутствуют.

## Acceptance

- dry-run не пишет и сообщает exact restorable/already/conflict/ineligible
  aggregates;
- force изменяет только exact allowlisted rows и идемпотентен;
- active writers и production без confirmations fail closed;
- category conflict и неизвестный source key сохраняются без изменения;
- membership/source identity не меняются;
- после quality refresh рабочий directory показывает 10 карточек и
  ненулевые counts в соответствующих категориях/подкатегориях;
- demo, empty и неподтверждённые source rows остаются скрыты;
- focused/broad PHPUnit, Pint, static analysis, docs checks и production
  desktop/mobile browser smoke проходят либо независимый blocker
  фиксируется как `unresolved`.
