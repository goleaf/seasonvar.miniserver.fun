# Реализация demo user portal cache и изображений

Дата: 19.07.2026

Статус: реализация и production repair завершены; полная repository suite и отдельная Git-доставка ограничены параллельно изменяемым общим деревом.

## План

1. Зафиксировать TDD regressions для content requests, personal tags/library corpus, responder-compatible collection/profile covers и WebP profile upload.
2. Исправить deterministic demo assets и добавить аудит фактического MIME, размеров и разрешённых path prefixes.
3. Добавить fail-closed fallback старых collection cover rows и bounded production demo repair command с dry-run.
4. Добавить `user-portal` cache domain, owner snapshot service, targeted version invalidation, unique warm job и команду single/multi-user warming.
5. Подключить к cache агрегаты `/profile`, `/library`, personal tags и ID pagination безопасных owner pages; security/session/token data оставить bypass.
6. Добавить WebP processor с MIME/pixel/EXIF/crop/resize проверками и интегрировать его в profile media transaction lifecycle.
7. Обновить canonical docs, README, CHANGELOG и production rollback/worker notes; выполнить repository-wide legacy/duplicate scan.
8. Выполнить focused tests, Pint, полный PHPUnit, build/docs/diff checks, затем отдельно оценить безопасный production repair с backup.

## Текущее evidence

- Начальный production dry-run: 100 известных пользователей; library/tags уже присутствовали у всех; requests отсутствовали у 100; profile images были недействительны у 100; collection images — у 1349.
- Перед записью остановлены cron и 13 writer workers, создана закрытая SQLite-копия и архив private uploads с правами `0600`, проверены размер, checksum, `quick_check` и foreign keys. Первый bulk-проход исправил изображения, но остановился на конкурентном SQLite lock; после диагностики выполнен ограниченный roll-forward.
- Requests-only roll-forward создал 633 заявки и связанные votes/followers/history/source links/identifiers/clarifications. Итоговый dry-run дал ноль по всем шести audit counters, включая владельцев коллекций, рабочая база повторно прошла integrity checks, а повторный force-run завершился быстрым no-op с пустыми `stage_counters`. Отдельная проверка фактических image bytes подтвердила 100/100 WebP-avatar `320×320`, 100/100 WebP-cover профиля `1280×360` и 1349/1349 WebP-cover коллекций `960×540`.
- `cache:warm-user-portal --all-demo --refresh` поставил 100 owners в `cache-warm-v2`; systemd journal подтверждает ровно 100 `WarmUserPortalCache ... DONE` и ни одного `FAIL`. Повторная payload-сверка очереди подтвердила отсутствие duplicate owner jobs, а итоговая сверка — отсутствие оставшихся `WarmUserPortalCache` jobs. Desktop/mobile HTTPS smoke двух demo accounts подтвердил `200`, наполненные requests/library/tags, 2/2 profile images и 36/36 collection images без broken resources или overflow.
- Свежая task-scoped verification на неизменившихся файлах задачи: 39 тестов, 6 473 утверждения, targeted Pint, targeted PHPStan, `project:docs-refresh --check` и task-scoped `git diff --check`; ранее тот же контракт проходил отдельными наборами 37/6 462 и 34/6 577 вместе с Vite build. Regression отдельно закрепляет exact `--all-demo` allowlist, обнаружение owner без коллекций и `NULL` cover, а также grouped card-count hydration без derived join/correlated `withCount`; PID-уникальные fake disks сохраняют достоверность asset tests при параллельных проверках. Production query observation сократился с 7 353 до 915 мс, повторный mobile `/library` — с 56,4 до 7,9 секунды под активной нагрузкой; это не p95/SLA. Полный repository-прогон на движущемся admin/importer snapshot дал 1 365 успешных тестов и 122 255 утверждений, но 3 failure и 6 error только в одновременно переписываемом admin contract (`403` и отсутствующий `selectTitle`), поэтому не объявляется финально зелёным.

## Verification checklist

```bash
php artisan test --filter=DemoContentRequestStageTest
php artisan test --filter=DemoCatalogCorpusStageTest
php artisan test --filter=UserProfileMediaTest
php artisan test --filter=UserPortalCache
./vendor/bin/pint --dirty --format agent
php artisan test
npm run build
php artisan project:docs-refresh --check
git diff --check
```

## Ограничения выполнения

- Не запускать `db:seed`/полный demo orchestrator на production.
- Не кэшировать authenticated HTML, CSRF, session, token, notification action state или raw private URLs.
- Не очищать cache store целиком и не запускать destructive database commands.
- Не изменять pending unrelated migrations и существующие пользовательские незакоммиченные изменения.
