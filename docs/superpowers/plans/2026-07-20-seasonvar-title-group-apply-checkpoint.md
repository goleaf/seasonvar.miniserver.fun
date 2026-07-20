# Seasonvar title-group apply checkpoint implementation plan

**Goal:** сделать применение большой sibling group конечным через durable per-page checkpoint без изменения schema и публичных contracts.

**Architecture:** `SeasonvarImportPreparedPage` хранит normalized `_application_result`; `FinalizeSeasonvarImportTitleGroup` пропускает applied rows, checkpoint-ит каждый новый result транзакционно и финализирует group отдельно.

## Task 1 — RED

- [x] Добавить regression в `SeasonvarImportTitleGroupFinalizerTest` с одной applied и одной prepared row.
- [x] Mock importer должен ожидать вызов только для prepared row.
- [x] Проверить cumulative media counters и итоговые applied/group statuses.
- [x] Запустить focused test и сохранить наблюдаемый failure до production code.

## Task 2 — GREEN

- [x] Добавить normalized application-result accessor/checkpoint в staging model.
- [x] В finalizer восстановить prior counters, применять только prepared rows и checkpoint-ить каждый result.
- [x] Удалить group-at-once row marking и повторный media increment из final transaction.
- [x] Запустить RED test, весь finalizer class и importer affected suite.

## Task 3 — production recovery и delivery

- [x] Pint/Larastan/Rector/docs/diff и affected PHPUnit gates.
- [x] Full PHPUnit gate: исходный снимок прошёл 1 445/1 434/11/123 056; свежий общий снимок после bounded merge — 1 453/1 442/11/123 094.
- [x] Frontend gate: Vite собрал 23 модуля.
- [x] Не прерывать worker, начавший применение до патча: дождаться штатного timeout и обычного retry; queue/cache не очищать.
- [ ] Мониторить exact `#964` до terminal, claims/groups/nonterminal rows `0` и `failed=0`.
- [x] Обновить owner/current plan/README/CHANGELOG evidence.
- [ ] Commit/push только `main`, проверить exact remote SHA и все GitHub Actions jobs.
