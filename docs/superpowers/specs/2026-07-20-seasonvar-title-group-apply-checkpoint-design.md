# Resumable checkpoint применения title group Seasonvar

Дата: 20.07.2026

## Подтверждённая причина

Recovery run `#964` дошёл до одной group `#43428` из 30 sibling pages. Catalog apply выполнял полезную работу и удерживал SQLite writer, но queue worker достиг `timeout=900`. Staging rows помечаются `applied` только общей финальной транзакцией после всех страниц, merge и manifest comparison, поэтому durable state остался `prepared=30`, а повторная доставка должна была начать все страницы снова.

Увеличение timeout не даёт конечной границы и отклонено. Разделение sibling pages по разным groups ломает один canonical `CatalogTitle`, manifest comparison и merge. Новая таблица или колонка избыточны: staging row уже владеет status и server-owned JSON payload.

## Решение

После каждого успешного `SeasonvarCatalogImporter::applyPreparedPage()` финализатор выполняет короткую транзакцию:

1. row и group перечитываются с блокировкой;
2. переход `prepared → applied` сохраняет в payload внутренний `_application_result` с четырьмя неотрицательными media counters;
3. `group.applied_pages` синхронизируется по фактическим applied rows;
4. run получает эти counters и heartbeat ровно один раз для выполненного перехода.

При retry все `prepared|applied` rows по-прежнему проходят parser/hash/source/group validation и входят в общий source manifest. Уже `applied` rows не передаются importer повторно, а их media counters восстанавливаются из `_application_result`. Pending rows применяются и checkpoint-ятся по одной. Финальная транзакция сохраняет только group terminal state, manifest/warning/failure summary и visitor-run state, не повторяя row/media counters.

Crash после catalog commit, но до checkpoint, может повторить одну страницу; importer остаётся идемпотентным. Crash после checkpoint продолжает со следующей row. Legacy payload без `_application_result` читается как нулевые counters; terminal legacy groups повторно не открываются.

## Совместимость и rollback

- Публичная команда, queue payload, unique/Redis locks, schema, migrations, cache и routes не меняются.
- Internal JSON key имеет bounded integer shape и не содержит URL, provider body, identity или secret.
- Group ordering, canonical-title merge, sync publication, cache invalidation и final manifest сохраняются.
- Rollback code-only; additive JSON key безопасно остаётся в исторических rows.

## Verification

- RED/GREEN подтверждает, что applied row не вызывает importer и cumulative media counters не дублируются.
- Existing shuffled/partial/invalid/merge-failure/finalizer contracts остаются зелёными.
- Production `#964` завершается обычной повторной доставкой без direct DB mutation, queue/cache clear или искусственного увеличения timeout.
