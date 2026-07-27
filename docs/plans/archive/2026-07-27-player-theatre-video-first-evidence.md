# Task 114 — evidence video-first theatre

Дата: 27.07.2026
Ветка: `main`

## Результат

Только в active theatre:

- breadcrumbs, heading «Просмотр» и повторная context summary скрыты;
- существующий media frame начинается у верхней safe-area границы;
- перевод, качество и субтитры идут сразу после video;
- toggle расположен поверх правого верхнего угла;
- narrow/low-landscape использует доступный icon-only toggle `44×44`;
- normal mode сохраняет прежнюю разметку и порядок.

Один `<video>`, `wire:ignore.self`, единственный keyed полный `wire:ignore`,
Livewire public API, routes, grants, progress, source recovery, schema, cache
keys, permissions, translations и PWA media exclusions не менялись.

## Discovery и TDD

1. Первый focused RED: `4 tests`, `1 passed`, `3 expected failures`,
   `49 assertions` — отсутствовали layout/player markers и CSS contracts.
2. После первого GREEN browser обнаружил точный scroll shift `16 px`.
   Diagnostics подтвердили: theatre scroll к `0` переводил скрытую шапку из
   compact `55 px` в expanded `71 px`; после выхода она снова сжималась и
   browser anchoring менял `491 → 475`.
3. Отдельный RED зафиксировал отсутствующий
   `theatreHeaderCompactState`; memory-only restore устранил root cause без
   ослабления scroll assertion.
4. Visual review обнаружил перекрытие status длинной кнопкой на mobile/
   landscape. Отдельный RED зафиксировал dynamic `aria-label` и upper bound
   compact toggle; GREEN дал icon-only `44×44`.

## Verification

- Pint exact PHP test targets: PASS.
- JS syntax для navigation/browser spec: PASS.
- Focused PHPUnit: `4 tests`, `83 assertions`, PASS.
- Current plan policy + focused player: `22 tests`, `124 assertions`, PASS.
- Extended Playwright workspace:
  - desktop, mobile, tablet, narrow phone, phone landscape, tablet landscape,
    TV-like;
  - `19 passed`, `2 expected skips`.
- Visual Playwright matrix: `7/7`; screenshots проверены в
  `output/playwright/task-114-theatre-*.png`.
- Firefox RU/EN lifecycle retry после одного transient source-selection:
  `2/2`, PASS.
- Финальный полный PHPUnit:
  - `2294 tests`;
  - `2283 passed`;
  - `11 expected skips`;
  - `208369 assertions`.
- Случайный factory duplicate предыдущего full run не воспроизвёлся:
  `CatalogTitleSuggestionQueryTest` прошёл `5/5` изолированных запусков.
- `npm run build`: `28 modules`, PASS.
- `player:release-check`:
  - `31 sources`;
  - `19 assets`;
  - fingerprint
    `336fae52fc497e43ce048abefc6c25363bfd64275993abb5477046afad46d152`.
- `project:docs-refresh --check`, `git diff --check`: PASS.
- Impeccable detector warnings для `gray-on-color` классифицированы как false
  positives: base `text-slate-*` сопоставлен с hover background, но тот же
  hover state уже задаёт `hover:text-emerald-*`/`hover:text-amber-*`.

## Production и rollback

- migrations, data writes, backup/restore, cache flush, queue restart:
  `not_applicable`;
- asset/player fingerprint меняется атомарно с layout source;
- rollout: application commit + штатная Vite build;
- rollback: revert Task 114 frontend release unit и повторная Vite build;
- provider coordination и persistent data rollback не нужны.

## Delivery

`unresolved`: до Task 114 в общем индексе уже находился отдельный завершённый
Task 113 и незастейдженный `composer.lock`. Task 114 не включает и не изменяет
чужой application scope. Exact commit/push оцениваются отдельно после
безопасной проверки staged index; pre-push требует чистое worktree.

Delivery update 27.07.2026: Task 113 зафиксирован отдельным commit
`a7322a91`. User-authorized Tasks 114–116 подготовлены одним exact reviewed
snapshot; непроверенное обновление `composer.lock` не включено и сохраняет
push как `unresolved` до отдельного maintenance review или штатного handoff.

Final delivery update 27.07.2026: Tasks 114–116 зафиксированы в `main`
combined commit `cc77728a`. `composer.lock` был адресно сохранён перед
non-force push и восстановлен без изменения после того, как GitHub HTTPS
запросил username при отсутствии credential helper. Данные не отправлены;
remote delivery остаётся `unresolved`.
