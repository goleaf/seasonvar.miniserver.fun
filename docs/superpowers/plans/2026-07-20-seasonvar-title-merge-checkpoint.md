# Seasonvar title-family merge checkpoint implementation plan

**Goal:** сделать объединение больших сезонных семейств конечным в пределах существующего worker timeout без увеличения timeout и без новой schema.

**Architecture:** существующий `SeasonvarTitleMerger` фиксирует каждый полностью объединённый duplicate season отдельной транзакцией; последний season и удаление duplicate title образуют одну финальную транзакцию, чтобы targeted retry не потерял family identity.

## Task 1 — RED

- [x] Добавить regression с двумя duplicate seasons и принудительным failure на втором удалении.
- [x] Подтвердить, что текущая монолитная транзакция откатывает первый season.
- [x] Проверить повторный вызов после удаления failure trigger.
- [x] Добавить RED на failure удаления duplicate title и сохранить последний season как discoverability boundary.

## Task 2 — GREEN

- [x] Разделить targeted/global merge на bounded per-season transactions внутри одного canonical merger.
- [x] Зафиксировать последний season, idempotent title-level retarget, relation union и удаление duplicate title одной транзакцией.
- [x] Запустить focused title-merge, finalizer, parallel-import и maintenance suites: 113 тестов, 652 утверждения.

## Task 3 — production recovery и delivery

- [x] Выполнить Pint, Larastan, Rector, docs checks, full PHPUnit и frontend build. После исправления order-dependent lazy-loading regression направленная матрица прошла 62/395, свежий общий full — 1 453/1 442 passed/11 skipped/123 094, Vite — 23 modules.
- [ ] Выполнить graceful worker reload после commit.
- [ ] Мониторить run `#964` до terminal invariants без queue/cache clear и direct DB mutation.
- [x] Обновить owner docs, README/CHANGELOG review и compliance matrix.
- [ ] Commit/push только `main`, затем сверить remote SHA.
