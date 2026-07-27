# План: worker для полного пересчёта рекомендаций

> **Для исполнителя:** сначала написать failing tests, затем минимальную
> реализацию, после каждого шага запускать focused verification.

**Цель:** после общего Seasonvar import пересчитывать рекомендации в существующем
`seasonvar-import` worker и не блокировать `/library` долгим synchronous rebuild.

**Изменения:** job `RebuildCatalogRecommendations`, явный pipeline handoff,
queued-finalizer dispatch, config/docs/tests.

## Шаги

1. Добавить RED-тесты: job unique/queue/timeout и вызов full builder; sync
   pipeline не вызывает builder inline и ставит job; deferred queued finalizer
   ставит job.
2. Реализовать job с active-import release, lock/overlap, bounded retry window,
   gate validation и cache warm после activation.
3. Добавить `queueRecommendations` в pipeline и передать `true` из public
   command только для полного sync import; targeted/media-size режимы не меняются.
4. Dispatch full job из terminal queued finalizer, не меняя scoped stage.
5. Обновить config, importer/queue/performance docs, README visitor history,
   CHANGELOG и current-task compliance matrix.
6. Запустить focused PHPUnit, Pint, full PHPUnit/CI profile и проверить staged
   scope перед commit/push в `main`.

## Проверка

- `php artisan test --filter='RebuildCatalogRecommendations|SeasonvarImport'`
- `./vendor/bin/pint --dirty --format agent`
- `php artisan test`
- `bash scripts/ci-check.sh pre-push`
