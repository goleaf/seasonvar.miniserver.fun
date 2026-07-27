# Видео первым в режиме «Театр» — дизайн

Дата: 27.07.2026
Задача: Task 114

## Контекст

Текущий режим «Театр» уже скрывает hero, sidebar, глобальную шапку, нижнюю
навигацию и подвал, но над видео остаются:

- breadcrumbs страницы;
- заголовок панели «Просмотр»;
- строка контекста с сезоном, эпизодом, переводом и качеством;
- элементы выбора источника и кнопка выхода из театра.

Из-за этого видео начинается заметно ниже верхней границы viewport. В обычном
режиме эта иерархия полезна и должна остаться без изменений.

## Цель

Только в активном режиме «Театр» сделать видео первым визуальным элементом
страницы и приблизить его к верхней safe-area границе. Функциональные выборы
источника должны сохраниться сразу под видео, а кнопка «Свернуть театр» —
оставаться доступной поверх правого верхнего угла видео.

## Выбранная модель

1. Глобальные breadcrumbs получают стабильный presentation marker и
   скрываются только под `body.player-theatre-active`.
2. В player workspace добавляются отдельные маркеры для текстовой сводки,
   действий источника и video region.
3. Theatre CSS меняет только визуальный порядок существующих DOM-узлов:
   video region идёт первым, context actions — следом; `<video>` не
   перемещается JavaScript и не клонируется.
4. Заголовок панели «Просмотр» и текстовая сводка скрываются только в theatre.
5. Кнопка theatre toggle становится absolute-элементом относительно основной
   панели и располагается поверх правого верхнего угла video shell с учётом
   safe-area. Это не fullscreen и не fixed overlay.
6. Перевод, качество и субтитры остаются реальными существующими controls,
   расположенными под видео. Источник и player state не дублируются.
7. Перед входом существующий theatre lifecycle запоминает только в памяти
   compact-state глобальной шапки. Перед восстановлением normal layout он
   возвращает это presentation-state, чтобы скрытая на scroll `0` шапка не
   раскрывалась на один frame и не сдвигала сохранённую позицию на `16 px`.

## Responsive и accessibility

- Workspace остаётся обычным document flow без вложенного scroll container.
- Верхний отступ theatre равен safe-area inset и не получает дополнительный
  декоративный padding.
- Toggle сохраняет минимум `44×44`, текущие `aria-pressed`,
  `aria-controls`, label/icon lifecycle и keyboard/Escape behavior.
- На viewport уже `768 px` и в низком landscape toggle становится
  icon-only `44×44`, сохраняя полную текущую подпись в `aria-label`; на
  desktop видимый текст остаётся.
- На mobile и landscape video остаётся ограничено текущей viewport-height
  формулой; горизонтальный overflow не появляется.
- Обычный режим сохраняет breadcrumbs, «Просмотр», контекст и текущий порядок.
- Новые пользовательские строки и translation keys не добавляются.

## Совместимость

Сохраняются:

- `GET /titles/{catalogTitle}`, локализованные URL и query keys проигрывателя;
- `CatalogTitlePlayer` public properties/actions и Livewire lifecycle;
- один `<video>`, один полный keyed `wire:ignore` и `wire:ignore.self` root;
- signed playback, entitlement, progress, source recovery и episode navigation;
- seasons/episodes, translations, permissions и PWA media exclusions;
- normal-mode DOM content и функциональность всех source controls.
- точное восстановление document scroll после выхода кнопкой и `Escape`.

Layout Blade становится источником player release fingerprint, поскольку
новый marker участвует в theatre presentation contract.

## Проверка

TDD и browser regression должны подтвердить:

- markers breadcrumbs, context summary/actions и video region существуют;
- CSS скрывает breadcrumbs, title и summary только в active theatre;
- normal mode показывает прежние элементы;
- в theatre верх video shell находится у safe-area границы;
- toggle пересекает верхнюю правую область video и остаётся `44×44`;
- source controls находятся ниже video;
- video DOM identity, Livewire refresh, scroll restoration и Escape не
  регрессируют;
- нет horizontal overflow на desktop, mobile, tablet и landscape;
- Vite build и player release fingerprint включают layout source.

## Rollout и rollback

Изменение не требует migration, data write, cache flush, queue restart,
permissions или provider coordination. Rollout — единый Blade/CSS/test/docs
commit и штатная Vite-сборка. Rollback — согласованный revert этого release
unit и повторная сборка assets; backup и data rollback не требуются.
