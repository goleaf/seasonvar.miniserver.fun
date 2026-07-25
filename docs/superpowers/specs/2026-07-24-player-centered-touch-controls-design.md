# Центральные touch-controls проигрывателя — design

Дата: 24.07.2026
Статус: `implemented_and_verified_local` как Task 21 безлимитного player roadmap; commit/push и production activation остаются отдельными gate.

## Пользовательский результат

В центре видео находится один горизонтальный ряд из трёх крупных кнопок:

`−10 секунд` → `Воспроизвести / Пауза` → `+10 секунд`.

Центр средней кнопки совпадает с центром области видео по горизонтали и
вертикали. Боковые кнопки находятся на одной горизонтальной оси. Кластер
доступен на телефоне, планшете и desktop, не создаёт отдельного плеера и не
перекрывает кликами остальную поверхность видео.

## Выбранная архитектура

Кластер создаёт существующий `CatalogPlayerSession` после успешной
инициализации единственного Plyr. DOM размещается внутри текущего
`this.plyr.elements.container`, то есть остаётся JavaScript-owned потомком
единственного `wire:ignore` player shell. Новый Blade control tree, второй
initializer, отдельный controller, Alpine/Livewire state или новый route не
создаются.

Действия переиспользуют текущие canonical methods:

- назад — `seekMediaBy(-10)`;
- вперёд — `seekMediaBy(10)`;
- play/pause — `this.plyr.togglePlay()`.

Native `play`, `pause` и `ended` events обновляют иконку и доступное имя
средней кнопки. Все listeners принадлежат существующему `AbortController`;
`destroy()` удаляет кластер до разрушения Plyr. Click каждого control
останавливает всплытие, чтобы Plyr `clickToPlay` не выполнял второе действие.

## Размеры и responsive geometry

- Боковые кнопки: `clamp(3.5rem, 17vw, 5rem)` — от `56×56` до `80×80` CSS px.
- Центральная кнопка: `clamp(4.25rem, 21vw, 6rem)` — от `68×68` до `96×96` CSS px.
- Горизонтальный gap: `clamp(0.5rem, 3vw, 1.25rem)`.
- Кластер использует `position: absolute; inset: 0; display: flex`,
  `align-items: center` и `justify-content: center`.
- Внешняя оболочка имеет `pointer-events: none`, только три кнопки —
  `pointer-events: auto`.
- Размеры примерно втрое увеличивают фактическую площадь текущих компактных
  player controls и заметно превышают обязательный minimum `44×44`.
- Размеры ограничены сверху, поэтому три кнопки помещаются в player на narrow
  phone и не создают horizontal page overflow.

Кластер следует штатному Plyr control-visibility lifecycle: при
`.plyr--hide-controls` он становится невидимым и некликабельным, а при pause,
показанных controls или keyboard focus снова доступен. Это сохраняет чистое
видео во время просмотра и полный touch baseline после обычного tap по player.

## Визуальный и accessibility contract

- Кнопки круглые, контрастные, без gradient; боковые — slate, центральная —
  project emerald.
- Используются уже загруженные локальные FontAwesome glyphs:
  `fa-rotate-left`, `fa-play|fa-pause`, `fa-rotate-right`.
- На боковых кнопках дополнительно видимо число `10`.
- Все controls — native `button type="button"`.
- Доступные имена и `title` берутся только из существующего RU/EN
  `CatalogPlayerCopy.controls`, с заменой `{seektime}` на `10`.
- Play/pause label и icon отражают фактическое native media state; цвет не
  является единственным носителем смысла.
- `focus-visible` остаётся заметным, `touch-action: manipulation` не блокирует
  page zoom, reduced-motion убирает необязательные transitions.
- Существующий Plyr `play-large` скрывается только после marker успешного
  создания нового кластера. При ошибке инициализации прежний fallback остаётся.

## Совместимость

Без изменений остаются:

- `titles.show`, `playback.source`, mobile API и signed grants;
- `CatalogTitlePlayer`, `CatalogTitlePlaybackQuery`, entitlement и source resolver;
- episode/media identity, progress token/sequence, resume/completion/history;
- HLS/MP4 hot swap, menu, fullscreen/PiP, Media Session и global keyboard;
- RU/EN translation key set, cache keys, service worker, importer/admin;
- schema, migrations, database rows, queue, environment и dependencies.

## Failure и rollback

Если Plyr container недоступен, кластер не создаётся и стандартный
`play-large` остаётся рабочим. Повторная initialization не создаёт duplicate:
session ищет существующий `[data-player-center-controls]`. Rollback возвращает
только JS/CSS/browser-contract/docs и предыдущие matching Vite assets.
Migration, data repair, cache flush, queue action и `.env` change не нужны.
