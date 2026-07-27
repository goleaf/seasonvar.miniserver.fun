# Task 117 — evidence восстановления публичных редакционных подборок

Дата: 27.07.2026.

## Результат

`/discover/popular#collections` снова показывает 10 сохранённых
редакционных подборок. Они распределены по девяти существующим
подкатегориям:

- «Сериалы 2026» — «Новинки и премьеры»;
- «Аниме 2026» и «Мультфильмы про друзей» — «Анимация и аниме»;
- Netflix, Amazon, Apple TV+, Disney+, HBO и HULU — соответствующие
  подкатегории платформ;
- «Про месть» — «Напряжённые истории».

Итоговый состав содержит от 1 до 30 сериалов на подборку. Quality score
равен 86–87; все 10 записей проходят canonical `publiclyListed()`.

## Root cause и scope

До изменения в базе оставалась 501 `public + approved` запись, но public
scope возвращал 0 из-за обязательной категории и quality boundary.
Подтверждены:

- 447 public demo rows с oversized составом;
- 54 ownerless HDRezka source rows без категории;
- 39 пустых source rows;
- 5 непустых, но сомнительных или дублирующих сопоставлений;
- 10 проверенных rows с bounded составом и единственной исходной quality
  причиной `missing_category`.

Recovery использует immutable exact source hash/category manifest и не
принимает ID, URL, name или category из CLI. Demo, empty, oversized,
missing, conflicting и unknown source fail closed.

## TDD и code verification

- начальный RED: 5 тестов, 0 passed — service/command отсутствовали;
- focused и broad collection/discovery/import GREEN: 176 тестов,
  1 258 утверждений;
- полный PHPUnit: 2 301 тест, 2 290 passed, 208 462 утверждения,
  11 ожидаемых пропусков;
- `Pint --dirty --format agent`: passed;
- focused PHPStan: 0 errors;
- `composer rector:check`: 0 changes, 0 errors;
- `npm run build`: 28 modules; `player:release-check` подтвердил 31 source и
  19 asset;
- documentation policies, managed links и `git diff --check`: passed.

## Production apply

Первоначальный dry-run:

- reviewed/matched/restorable: `10/10/10`;
- missing/conflict/ineligible/listed: `0/0/0/0`.

Операционная последовательность:

1. Точная aaPanel import cron и Laravel scheduler временно сняты с
   расписания с private recovery copies.
2. Активный importer получил поддерживаемый приложением `SIGTERM`, завершил
   безопасную границу и записал `cancelled`.
3. Остановлены 16 systemd queue-writers: три общих, четыре import, восемь
   title-refresh и один cache-warm.
4. Пустая orphaned recommendation build после канонического stale timeout
   закрыта существующим `CatalogRecommendationBuildPruner`.
5. Создан финальный согласованный SQLite backup размером
   31 423 373 312 байт; checksum сохранён рядом в private operational
   storage. Backup вернул `PRAGMA quick_check=ok` и нулевой
   `PRAGMA foreign_key_check`.
6. Recovery восстановила 10 rows и пересчитала quality 10 rows.
7. Existing quarantine перевела в private review 897 demo и 44
   неподтверждённые source collections, удалила 5 недействительных signal
   rows и инвалидировала 160 производных рекомендаций.
8. Live DB повторно вернула `quick_check=ok` и нулевой
   `foreign_key_check`.
9. Maintenance снят; обе cron-строки и все 16 services восстановлены ровно
   по одному экземпляру.

Финальные dry-run:

- recovery: `restorable=0`, `already_restored=10`, `publicly_listed=10`;
- quarantine: demo/source candidates `0/0`, invalid source signals `0`;
- HTTP: discovery, collection API и collection sitemap — `200`; API и
  sitemap содержат ровно 10 публичных подборок;
- readiness: `ready=true`; прежние Memcached/full-warm degradation signals
  не вызваны задачей.

## Production browser

Managed Chromium fallback использован после того, как CLI daemon не нашёл
системный Google Chrome. На `1440×1200` и `390×844` подтверждены:

- H1 «Популярные» и H2 «Подборки сериалов»;
- 10 уникальных collection cards и отсутствие прежнего empty state;
- 5 root categories и 31 child category;
- девять заполненных child filters активны; counts составляют восемь раз
  `1` и один раз `2`;
- detail link отвечает `200`, cursor — `pointer`;
- horizontal overflow `0`, page errors и failed first-party requests `0`;
- визуальные снимки сохранены в `output/playwright/`.

Отдельное существующее ограничение: `/service-worker.js` отвечает `404` и
создаёт один console error при регистрации. Collection HTML, Vite/Livewire
assets, API, sitemap и detail page отвечают успешно; PWA boundary этой
задачей не менялась.

## Compatibility и rollback

Не изменены routes, API response shape, sitemap XML, migrations, schema,
packages, translations, permissions, source/membership identity,
comments/reports, cache key format и обычный sync contract
`private + archived`.

Rollback при data error — только проверенный private SQLite backup при
остановленных writers. Альтернатива — авторизованный exact roll-forward в
`private + archived`. Broad SQL, hard delete, migration rollback, queue
clear и global cache flush не применялись.
