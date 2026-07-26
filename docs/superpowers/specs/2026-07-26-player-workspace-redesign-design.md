# Task 104 design — рабочее пространство player

## Цель

Сделать просмотр главным действием страницы тайтла, сохранив светлую систему
портала, один media lifecycle и все server-side границы доступа.

## Композиция

Player становится одной самостоятельной `rounded-xl` panel с `slate-200`
border. Сверху находится компактная context-bar: слева сезон, серия,
перевод и качество, справа реальные controls перевода, качества и субтитров.
Ниже — один 16:9 media surface, короткие runtime states, previous/current/next
navigation и компактная строка основных действий. Полные source details,
personal controls и help не конкурируют с видео.

Theatre расширяет текущий player в том же document flow. Title sidebar и
secondary main sections скрываются scoped CSS, workspace получает
`slate-950`, player использует доступную ширину. Сезоны, серии, navigation,
hotkeys/recovery и primary controls остаются. Video DOM не клонируется и не
перемещается.

## Interaction

- Toggle доступен мышью, touch и клавиатурой, отражает `aria-pressed`.
- `Escape` закрывает theatre только если нет открытого dialog и fullscreen.
- Focus возвращается на toggle; cleanup снимает body class.
- Context controls используют обычные публичные query links как fallback и
  opaque media IDs для бесшовного перехода; короткоживущий signed grant
  запрашивает только существующий media lifecycle после server resolution.
- Previous/next имеют реальные `href`; JS улучшает переход in-place.
- Mobile menu остаётся одним dialog, визуально становится bottom sheet.
- Все controls ≥44 px, next action приоритетен, layout не перекрывается при
  320 px и landscape.

## Runtime states

`connecting` показывает skeleton и «Подключаем источник». При bounded fallback
последовательно показываются «Основной источник не ответил» и «Открываем
резервный источник». Terminal error показывает «Видео не удалось открыть»,
retry, выбор другого источника и существующий report action. Ready/playing не
держат лишнюю status panel.

## Data и безопасность

Server готовит context labels и реальные option rows. `subtitle_language`
добавляется в узкую media projection; это metadata availability, не subtitle
body/URL. Client не решает entitlement/health и не видит raw provider URL.
Новых routes, migrations, cache keys, dependencies и report services нет.

## Accessibility и fallback

Status использует polite/assertive semantics по состоянию. Dialog сохраняет
focus trap/return; essential actions не hover-only. Reduced motion отключает
shimmer. Links и source selection имеют server fallback без JS. Fullscreen,
PiP, orientation и iOS остаются browser-governed.

## Acceptance

Focused PHP/source tests должны доказать один `wire:ignore`, truthful context,
safe projection/payload и fallback links. Playwright проверяет theatre
enter/exit/focus, modal/fullscreen Escape priority, desktop/mobile/landscape
geometry, 44 px targets, recovery actions, отсутствие overflow/console errors
и сохранение одного video/Plyr instance.
