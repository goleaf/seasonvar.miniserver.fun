# Чёрный fullscreen и Facebook-палитра проигрывателя — design

Дата: 24.07.2026
Статус: `approved_for_inline_implementation` как Player Task 22 и System Task 45.

## Пользовательский результат

Область видео всегда имеет чистый чёрный фон, включая обычное состояние,
стандартный Fullscreen API, WebKit fullscreen, Plyr fallback и fullscreen
backdrop, которыми может управлять CSS приложения. Белая подложка больше не
просвечивает до загрузки кадра, при смене источника, при несовпадении
соотношения сторон или во время выхода в полноэкранный режим.

Внутри плеера используется самостоятельная Facebook-inspired палитра. Она не
перекрашивает страницы, карточки каталога, header, формы, сезоны или личный
кабинет и не имитирует бренд Facebook: логотип, название, typography и
композиция Facebook не копируются.

## Проверенная причина

В Desktop Chromium стандартный fullscreen уже получает чёрный фон из
скомпилированного CSS пакета Plyr. Однако application-owned `.plyr` и native
`<video>` до fullscreen имеют прозрачный фон, а приложение не задаёт явные
WebKit, fallback и backdrop rules. Поэтому результат зависит от package CSS и
browser/native presentation. Надёжная граница — явно покрасить сам media root,
video wrapper и video element в чёрный и повторить это для всех
CSS-контролируемых fullscreen variants.

Нативный системный video fullscreen на iOS остаётся browser/OS-owned: WebKit
не гарантирует применение author CSS к системному player UI. Приложение не
создаёт fake fullscreen и не обещает чёрную OS-owned оболочку без проверки на
реальном устройстве.

## Рассмотренные варианты

1. **Scoped semantic palette внутри player shell — выбран.** CSS custom
   properties получают понятные роли, а player shell, Plyr, menu, statuses и
   central controls используют их. Это выполняет запрос целиком и не нарушает
   постоянную светлую emerald-систему остального портала.
2. **Только заменить `--plyr-color-main` и добавить `background: #000`.**
   Изменение меньше, но не использует полную функциональную палитру и оставляет
   menu/status/focus в старых emerald/slate цветах.
3. **Перекрасить весь портал под Facebook.** Отклонено: это конфликтует с
   каноническим UI-направлением, расширяет scope на все страницы и требует
   отдельного полного redesign/accessibility acceptance.

## Каноническая палитра и роли

Все tokens scoped к `[data-player-shell]` и `.plyr`; глобальные Tailwind colors
и `@theme` не меняются.

| Token | Значение | Роль |
| --- | --- | --- |
| `--catalog-player-primary` | `#1877f2` | progress, selected, toggle, focus |
| `--catalog-player-primary-hover` | `#166fe5` | hover/active primary |
| `--catalog-player-primary-soft` | `#e7f3ff` | selected/ready soft surface |
| `--catalog-player-surface` | `#f0f2f5` | menu/status neutral surface |
| `--catalog-player-surface-strong` | `#e4e6eb` | secondary controls |
| `--catalog-player-border` | `#ccd0d5` | borders and separators |
| `--catalog-player-text` | `#1c1e21` | primary readable text |
| `--catalog-player-muted` | `#65676b` | secondary text |
| `--catalog-player-success` | `#42b72a` | ready/playing/ended |
| `--catalog-player-warning` | `#f7b928` | buffering/retrying/captions |
| `--catalog-player-danger` | `#fa383e` | error/fatal |
| `--catalog-player-white` | `#ffffff` | raised surface and text |
| `--catalog-player-fullscreen` | `#000000` | media and fullscreen background |

Цвет не является единственным state signal: существующие текст, icon,
`aria-live`, `role="alert"` и labels сохраняются.

## CSS и DOM architecture

- `[data-player-shell]`, `.plyr`, `.plyr__video-wrapper` и
  `video.js-catalog-player` получают application-owned black media
  background.
- Явные rules покрывают `.plyr:fullscreen`, `.plyr:-webkit-full-screen`,
  `.plyr--fullscreen-active`, `.plyr--fullscreen-fallback`,
  `video:fullscreen`, `video:-webkit-full-screen` и `::backdrop`.
- Plyr variables получают primary blue, menu/tooltip surface, focus и hover
  colors из scoped tokens.
- Existing `catalog-player-menu` и центральные controls используют те же
  tokens. Никакой второй CSS system, inline style или global theme override не
  создаётся.
- `data-player-state` задаёт semantic success/warning/danger color для
  существующего status icon и banner без изменения JavaScript lifecycle.

## Accessibility, responsive и compatibility

- Чёрный media background не меняет размеры, ratio, safe-area padding,
  pointer events или control visibility.
- White-on-blue/black controls, dark-on-light menu surfaces и visible blue
  focus сохраняют читаемый contrast; state по-прежнему назван текстом.
- Один `<video>`, Plyr, HLS.js, `CatalogPlayerSession`, keyed `wire:ignore`,
  hot swap, progress, History API, menu, keyboard и touch targets сохраняются.
- Routes, translations, schema, cache keys, permissions, dependencies,
  importer, API, SEO и service worker не меняются.
- Production deploy должен переключать Vite manifest и matching hashed assets
  одной совместимой единицей.

## Verification и rollback

TDD сначала фиксирует отсутствие application-owned tokens/fullscreen selectors
и прозрачный normal player root/video в Chromium. После GREEN:

- static PHPUnit contract проверяет полный token set и fullscreen selector set;
- Playwright проверяет normal и standard fullscreen computed black background,
  один video/Plyr и сохранение fullscreen identity при смене серии;
- existing player lifecycle matrix проверяет menu, touch, keyboard, HLS/MP4,
  recovery и cleanup;
- Vite production build подтверждает generated CSS/assets.

Rollback возвращает только CSS, static/browser regressions и документацию
вместе с предыдущими matching Vite assets. Migration, data repair, cache flush,
queue action, dependency rollback и `.env` change не нужны. Нативный iOS
fullscreen сохраняет статус `unresolved_device` до проверки на реальном
устройстве.
