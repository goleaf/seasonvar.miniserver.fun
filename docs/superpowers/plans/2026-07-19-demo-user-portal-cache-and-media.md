# Реализация demo user portal cache и изображений

Дата: 19.07.2026

Статус: реализация завершена; production repair ожидает безопасного окна без активных writers.

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

- Dry-run production corpus: 100 известных пользователей; library/tags уже присутствуют у всех; requests отсутствуют у 100; profile images недействительны у 100; collection images недействительны у 1349.
- Focused cache/library/profile/demo repair tests пройдены; browser smoke подтвердил рабочие `/library` и `/library/tags/manage`, а до data repair зафиксировал ожидаемые битые legacy profile media.
- Production write не выполнялся при активном импорте и без согласованного backup/writer pause; это состояние остаётся `unresolved`, а не маскируется успешным repair.

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
