# Task 104 evidence — player workspace redesign

## Реализованный контракт

- Единственный `CatalogTitlePlayer` и единственный keyed `wire:ignore`
  продолжают владеть существующим Plyr/HLS lifecycle.
- Контекстная строка показывает сезон, серию, перевод, качество и только
  фактически доступные субтитры; язык берётся из `subtitle_language`.
- Theatre mode расширяет существующий player, скрывает только вторичный
  контент, сохраняется после Livewire morph и выходит по `Escape` с возвратом
  фокуса. Открытый dialog, fullscreen и context selector имеют приоритет.
- Previous/next остаются обычными ссылками с описательными подписями.
- Loading, fallback и terminal error имеют отдельные состояния, retry,
  существующий выбор источника и существующий путь обращения.
- После бесшовной смены серии контекст и options обновляются без нового video,
  а резервная ссылка обращения теряет устаревшие encrypted `context` и
  `position`.
- Доступные media не ограничиваются на уровне SQL. Для компактного DOM
  отображаются не более 24 уникальных options после active-first сортировки,
  поэтому выбранный поздний source не теряется.

## Code review

Независимая проверка не нашла critical defects и выявила четыре important
edge case: media за первой сотней строк, язык субтитров, stale report context и
theatre state после Livewire morph. Все четыре исправлены и покрыты
регрессионными тестами. Замечание о nullsafe было снято после фактической
проверки поведения PHP 8.5. Native `<details>` сохраняет progressive fallback;
на мобильном он оформлен как bottom sheet и закрывается `Escape`, но намеренно
не объявляется вторым modal dialog.

## Проверки

| Команда | Результат |
| --- | --- |
| `./vendor/bin/pint --format agent <Task 104 PHP files>` | passed |
| focused `php artisan test` для query/transition/workspace/assets и translation parity | 33 passed, 80,915 assertions |
| production PHPStan по точному PHP scope | passed, 0 errors |
| Rector dry-run по точному PHP scope | passed, 0 changed, 0 errors |
| `composer validate` | passed |
| `php artisan route:list --path=titles` | passed, 21 routes |
| `npm run build` | passed, Vite 8.1.4, 28 modules |
| `npx playwright test tests/browser/player-workspace.spec.js` | 8 passed, 1 skipped |
| full PHPUnit с временной копией config и `memory_limit=1G` | executed: 2,176 tests; 2,148 passed, 11 skipped, 16 failed, 1 error |

Стандартный полный PHPUnit сначала честно остановился на установленном
`memory_limit=256M` в параллельно изменённом demo raster fixture. Временный
config не был добавлен в repository и удалён после прогона. Остаточные full
suite failures относятся к другим одновременно изменяемым workstreams:
offline layout rule, shared current-plan policy, account flash contract,
collection-quality class и nested Livewire season query state. Отдельный
повтор season-anchor test подтвердил последний regression; Task 104 не меняет
URL property/mount/season query.

## Schema, routes, security и rollback

- Migration, index, route, permission, cache key, dependency и production
  service не добавлены.
- Используется существующее nullable поле `licensed_media.subtitle_language`.
- Transition payload не содержит `source_url`, signed playback grant, cookie,
  token или client-trusted entitlement.
- Rollback: revert Task 104 commit. Данные и схема не требуют отката.

## Delivery

- Ветка: `main`.
- Implementation commit: `ed77c9eb` — `feat: redesign player workspace`.
- Commit создан через project hooks из отдельного одобренного index с ровно
  32 Task 104 paths; параллельные staged/unstaged изменения не включены.
- Ordinary push: `GIT_TERMINAL_PROMPT=0 git push origin main`.
- Результат push: `unresolved`; настроенный HTTPS remote ответил
  `fatal: could not read Username for 'https://github.com': terminal prompts disabled`.
  Force push, смена remote, обход hooks и переписывание истории не выполнялись.
