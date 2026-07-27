# Завершение режима «Театр» — дизайн

Дата: 27.07.2026
Задача: Task 112

## Контекст и причина

Текущий `player-theatre-active` скрывает hero, sidebar и последующие секции,
но оставляет прежний `window.scrollY`. После схлопывания тысяч пикселей
документа browser не привязывает viewport к player workspace:

- desktop `1440×1200`: workspace оказывался на `-363 px`, player — на
  `-98 px`, toggle — на `-252 px`;
- mobile `390×844`: workspace оказывался на `-955 px`, player — на
  `-567 px`, toggle — на `-777 px`.

Пользователь видел нижнюю часть страницы или светлую панель сезонов вместо
проигрывателя и не мог немедленно свернуть театр. Существующий browser test
проверял только class/ширину и поэтому не обнаруживал потерю viewport.

## Цель

Режим «Театр» должен оставаться временным presentation-only состоянием той же
страницы, но после включения сразу показывать player и кнопку выхода, а после
выключения возвращать пользователя в прежнее место документа.

## Выбранная модель

1. При первом входе browser сохраняет только в памяти closure точные
   `scrollX` и `scrollY`.
2. После применения theatre-маркеров следующий `requestAnimationFrame`
   вычисляет новое положение `data-player-workspace-region` и выполняет
   мгновенный `window.scrollTo`.
3. При выходе layout сначала восстанавливается, затем в следующем frame
   возвращается исходная позиция документа и toggle получает focus через
   `preventScroll`.
4. Lifecycle cleanup отменяет pending frame, снимает theatre-маркеры и не
   переносит старую позицию на другую Livewire-навигацию.
5. Video shell не перемещается, не клонируется и не пересоздаётся. Существующие
   `wire:ignore.self` workspace и единственный полный keyed `wire:ignore`
   media shell сохраняются без расширения client authority.

## Визуальная и responsive-модель

- Theatre остаётся обычным document flow, не становится `position: fixed`,
  overlay или вложенным scroll container.
- В активном состоянии скрываются site header, mobile bottom navigation,
  footer, sidebar, hero и вторичные player-действия.
- Player workspace получает цельную `slate-950` сцену. Основная панель и
  панель сезонов используют согласованные тёмные поверхности и контрастный
  текст.
- Context/source controls, recovery, portal controls, предыдущая/следующая
  серия, сезоны и серии остаются доступны.
- Video shell центрируется и ограничивается доступной высотой viewport,
  чтобы landscape не превращал сцену в обрезанный широкоформатный кадр.
- Toggle меняет видимую подпись и FontAwesome-иконку
  `fa-expand ↔ fa-compress`, сохраняет `aria-pressed` и получает
  `aria-controls="player"`.

## Keyboard и модальности

- Первый `Escape` остаётся за открытым native dialog, раскрытым context
  control или fullscreen.
- Следующий `Escape` закрывает theatre, возвращает прежний document scroll и
  focus на toggle.
- Повторный click выполняет тот же безопасный выход.
- Декоративная анимация не добавляется; scroll всегда `behavior: "auto"`.

## Совместимость

Сохраняются:

- `GET /titles/{catalogTitle}` и локализованные URL;
- public Livewire properties/actions и query keys player;
- один `<video>` и один полный `wire:ignore`;
- player session key, playback authorization, progress и source resolution;
- сезоны/серии внутри одного `CatalogTitle`;
- PWA denylist для video/HLS/playback/download;
- русский и английский translation contract без новых строк.

## Проверка

Browser regression обязан подтвердить на desktop, mobile, tablet и extended
landscape:

- toggle и player пересекают viewport после входа;
- document не получает horizontal overflow;
- прежний scroll возвращается после click и `Escape`;
- `<video>` остаётся тем же DOM-узлом после enter, Livewire refresh и exit;
- label/icon/`aria-pressed`, dialog priority и focus корректны;
- глобальная оболочка скрыта только в theatre;
- seasons surface имеет тёмный контраст;
- все touch targets остаются не меньше `44×44`.

## Rollback

Rollback — согласованный возврат Blade-маркеров, theatre CSS, scroll lifecycle
в `player-navigation.js`, тестов и документации с повторным Vite build.
Маршруты, schema, cache, player authorization и persistent data не меняются,
поэтому data rollback не требуется.
