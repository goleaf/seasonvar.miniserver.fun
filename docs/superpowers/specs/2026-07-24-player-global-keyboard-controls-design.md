# Глобальное управление плеером с клавиатуры

Дата: 24.07.2026
Статус: согласовано; implementation discovery о существующей подсказке отражено 24.07.2026

## Цель

Сделать основные команды текущего видео доступными с клавиатуры на всей странице тайтла: `Space` или `K` переключают воспроизведение и паузу, `ArrowLeft` перематывает на 10 секунд назад, `ArrowRight` — на 10 секунд вперёд.

Поведение действует только пока существует активная и не уничтоженная `CatalogPlayerSession`. Оно расширяет единственный существующий контур `CatalogTitlePlayer → player.js → Plyr/HLS` и не создаёт второй player или глобальный keyboard-controller.

## Подтверждённый baseline

- `resources/js/player.js` единолично владеет Plyr/HLS lifecycle и хранит активные сессии в `WeakMap`.
- Каждая `CatalogPlayerSession` уже регистрирует один `document.keydown` listener через собственный `AbortController`.
- `destroy()` снимает listeners и уничтожает Plyr/HLS при смене media, Livewire navigation, `pagehide` и удалении media shell.
- Plyr сейчас обрабатывает `Space`, `K` и стрелки только внутри своего контейнера, потому что `keyboard.focused` включён, а `keyboard.global` выключен.
- Проектный `handleKeyboard()` обрабатывает scoped-команды `Escape`, `?`, `P`, `Shift+P` и `Shift+N`, но отклоняет события вне player shell/tools.
- Канонический playback owner сейчас прямо фиксирует scoped keyboard behavior. Запрос пользователя является явным изменением именно этого постоянного правила.

## Рассмотренные варианты

### 1. Расширить текущий `CatalogPlayerSession` — выбран

Существующий document listener принимает четыре глобальные playback-команды, а остальные portal/Plyr-команды сохраняют текущий scope.

Преимущества:

- один lifecycle owner и автоматический cleanup;
- точный scope без побочного включения всех shortcut-команд Plyr;
- явная защита форм, интерактивных элементов, диалогов и системных сочетаний;
- нет новой зависимости, singleton или второго набора listeners.

### 2. Включить `Plyr keyboard.global`

Отклонено: вместе с требуемыми клавишами глобальными становятся volume, mute, fullscreen, captions, цифры и другие команды Plyr. Их захват шире запроса и сложнее ограничивается проектными dialog/Livewire boundaries.

### 3. Добавить отдельный глобальный keyboard-controller

Отклонено: controller дублировал бы определение активной player session и cleanup, хотя эта ответственность уже принадлежит `CatalogPlayerSession`.

## Поведение

`CatalogPlayerSession.handleKeyboard(event)` сначала сохраняет существующие guards:

- keyboard shortcuts включены в account/device preferences;
- session не уничтожена, video и root подключены;
- `Ctrl`, `Alt` и `Meta` не нажаты;
- событие не пришло из editable или интерактивного control;
- открытый dialog не должен передавать playback-команды фоновому видео.

После guards:

- `Space` и `K` вызывают ровно одно переключение play/pause и отменяют browser default, включая прокрутку страницы пробелом;
- `ArrowLeft` вызывает существующую bounded перемотку на `-10` секунд;
- `ArrowRight` вызывает существующую bounded перемотку на `+10` секунд;
- перемотка не уходит ниже `0` и выше известной `duration`;
- auto-repeat не должен многократно переключать play/pause, но удержание стрелки может последовательно перематывать по 10 секунд;
- scoped-команды `Escape`, `?`, `P`, `Shift+P` и `Shift+N` сохраняют существующее поведение только внутри player/tools.

Внутри контейнера Plyr уже останавливает propagation обработанных `Space`/`K`/arrow events. Поэтому document handler обслуживает глобальный fallback вне плеера, не выполняя ту же команду второй раз.

## Accessibility и browser safety

- `input`, `textarea`, `select`, `[contenteditable]`, native buttons/links и ARIA controls сохраняют собственное keyboard behavior.
- При открытом native dialog фоновое видео не реагирует на глобальные playback-команды.
- Сочетания с `Ctrl`, `Alt` или `Meta` не перехватываются. `Shift` остаётся доступным существующим episode shortcuts и не превращает требуемые playback keys в новый contract.
- Изменение не добавляет видимый control или новый translation key. Обнаруженная при реализации существующая RU/EN подсказка о scoped-only поведении обновляется на точное описание global playback/seek и оставшихся scoped-команд; pointer/touch controls остаются полным альтернативным способом управления.
- Mobile/touch presentation, fullscreen/PiP capability detection, reduced motion и Media Session не меняются.

## Testing strategy

Реализация выполняется через TDD в существующем `tests/browser/player-lifecycle.spec.js`.

RED regression на Desktop Chromium должен доказать:

1. после фокуса на обычном не-player элементе `Space`/`K` переключают активное видео;
2. `ArrowLeft`/`ArrowRight` изменяют mocked current time ровно на 10 секунд и clamp-ятся у начала/конца;
3. input и открытый shortcut dialog не передают команды видео;
4. modifier shortcut не перехватывается;
5. после Livewire navigation/destroy удалённая session больше не реагирует;
6. одно событие даёт одно действие без duplicate listener.

После GREEN выполняются focused Desktop Chromium case, полная player browser matrix при необходимости, `npm run build`, related PHPUnit/static contracts и общие проектные documentation gates.

## Cross-feature impact

Affected:

- player JavaScript lifecycle и keyboard behavior;
- desktop browser accessibility;
- канонический playback owner;
- visitor documentation и changelog;
- deterministic Playwright regression.

Unchanged:

- routes, Livewire public state и Blade structure;
- authentication, authorization, entitlement, premium, region и legal boundaries;
- playback grants, provider URLs, source resolution и CSP;
- progress cadence, completion, history и Media Session;
- database schema, migrations, queries и indexes;
- translation keys и структура catalogs; текст существующего `keyboard_shortcuts_hint` обновляется синхронно в RU/EN;
- cache keys, invalidation, service worker и API;
- npm/Composer dependencies и lock files.

## Expected files

- `resources/js/player.js`
- `tests/browser/player-lifecycle.spec.js`
- `docs/audits/video-playback-report.md`
- `docs/frontend.md`
- `docs/plans/current-task-plan.md`
- `lang/ru/catalog.php`
- `lang/en/catalog.php`
- `README.md`
- `CHANGELOG.md`
- implementation plan under `docs/superpowers/plans/`

No Blade, PHP, route, migration, config, translation-key, package or lock-file change is expected. Существующие RU/EN значения `keyboard_shortcuts_hint` входят в scope после обнаружения устаревшего scoped-only обещания.

## Compatibility and rollback

Public URLs, persisted preference keys, event names, DOM markers and player source/progress contracts remain compatible. Users who disabled keyboard shortcuts keep them disabled.

Rollback is code-and-documentation only: restore the former scoped branches in `handleKeyboard()` and remove the new browser assertions. No database rollback, cache flush, queue action, storage cleanup, dependency reinstall or provider coordination is required. Existing hashed assets remain valid until the normal Vite release swap.

## Acceptance criteria

1. With an active video and keyboard shortcuts enabled, `Space`/`K` toggle play/pause from outside the player.
2. `ArrowLeft`/`ArrowRight` seek exactly 10 seconds with safe bounds.
3. Editable/interactive controls, dialogs and system modifier combinations retain their normal keyboard behavior.
4. Player-focused behavior is not duplicated.
5. Destroyed/replaced sessions do not react to later key events.
6. Existing portal shortcuts, player preferences, progress, source fallback and Livewire lifecycle remain compatible.
7. Focused RED/GREEN browser evidence, Vite build and relevant repository gates pass.
8. Canonical playback/frontend docs, Russian README visitor history, Russian CHANGELOG and completed compliance evidence match the delivered behavior.
9. Only task-owned files are committed on the existing `main`; the pre-existing `composer.lock` change remains excluded.
