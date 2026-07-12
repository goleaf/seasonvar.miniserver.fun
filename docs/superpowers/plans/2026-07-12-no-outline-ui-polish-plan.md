# Seasonvar no-outline UI polish plan

**Дата:** 12.07.2026  
**Ветка:** только существующая `main`; новые ветки не создавать.  
**Режим:** без создания test-файлов и без запуска PHPUnit/PHP test suite.

## Цель

Убрать визуальную обводку вокруг простых текстовых ссылок, chips, пунктов меню и query-навигации. Оставить рамки только там, где они работают как структура: панели, карточки, формы, постеры, media containers, layout-разделители и ошибки валидации.

## Уже сделано

- Общий `x-ui.taxonomy-chip` переведен с `border`-стиля на плоские светлые фоны.
- Общий `x-ui.status-pill` больше не добавляет `ring-1`.
- `x-catalog.episode-link` больше не рисует кольцо вокруг ссылки серии.
- `/titles`:
  - боковые фильтры годов и связей без `ring`;
  - мобильная ссылка «К фильтрам» без обводки;
  - сортировка, переключатель вида, размер страницы и алфавит без outlined chips;
  - empty-state действия без outlined text buttons.
- Страница сериала:
  - ссылка «К каталогу» без outline;
  - переключатели сезонов без outline;
  - настройки вариантов просмотра без outline;
  - reason badges и счетчики связей без outline;
  - боковой блок «Быстрый доступ» без outline у пунктов «Сезоны», «Сезон N», «О сериале».
- Главная:
  - hero quick links без `ring`;
  - левое меню «Навигация», страны и годы без outlined text links;
  - мелкие мета-бейджи новых серий без outline.
- Общие карточки:
  - `x-title-card` и `x-title-list-row` выводят счетчики сезонов/серий/видео без `ring`.
- Header и pagination:
  - активные пункты header без `ring`;
  - пагинация каталога без outlined links.
- SEO/public layout:
  - оглавление, related links, key topics, query navigation, long-tail links, semantic hub chips, action links, audience paths, also-searches, query matrix, русские query variants, semantic clusters и popular searches без outlined chips.
- `/stats`:
  - status badges снимка, служебные badges, health icons, issue icons, progress bar и recent-run status icons без декоративных `ring`.
- Документация:
  - `docs/UI_STANDARDS.md` теперь прямо запрещает decorative `ring-*`/`border-*` для текстовых ссылок/chips/menu-items.

## Что улучшать дальше

### 1. Визуально пройти все публичные маршруты

- Проверить desktop и mobile для:
  - `/`
  - `/titles`
  - `/titles?genre=...`
  - `/titles?quality=1080p&video=available`
  - `/titles/{slug}`
  - `/stats`
- Искать только реальные проблемы:
  - текстовые chips снова выглядят как outlined pills;
  - active state недостаточно заметен без outline;
  - hover state потерял кликабельность;
  - карточка перестала читаться как карточка.

### 2. Уточнить focus-visible стиль

- Для ссылок без outline нельзя терять keyboard navigation.
- Если браузерный focus выглядит слишком слабым, добавить единый project utility через Tailwind classes:
  - `focus-visible:outline-none`
  - `focus-visible:bg-emerald-50`
  - `focus-visible:text-emerald-700`
  - при необходимости `focus-visible:shadow-sm`, но не `ring`.
- Не добавлять `ring-*` как focus fallback для text links.

### 3. Свести link-chip стили к одному месту

- Создать или расширить общий Blade-компонент/класс для flat text-chip ссылок, если появится третье повторение после этой правки.
- Кандидаты:
  - сортировка `/titles`;
  - алфавит `/titles`;
  - pagination item;
  - SEO query chip;
  - sidebar menu item.
- Цель: один style contract, чтобы outline не вернулся через копипасту.

### 4. Разделить структурные и текстовые рамки

- Сохранить рамки у:
  - `x-ui.panel`;
  - `x-title-card`;
  - `x-title-poster`;
  - form inputs/selects/checkbox labels;
  - player/media containers;
  - dashboard cards.
- Не использовать рамки у:
  - простых `<a>` с коротким текстом;
  - taxonomy/status chips;
  - sidebar menu items;
  - SEO query links;
  - pagination page links.

### 5. Улучшить контраст active/hover без outline

- Active state:
  - `bg-emerald-50`
  - `text-emerald-700`
  - `font-bold`
- Inactive state:
  - `bg-slate-50` или `bg-transparent`
  - `text-slate-600`
  - hover: `bg-emerald-50 text-emerald-700`
- Для опасных/предупреждающих состояний использовать `bg-amber-50`, `bg-rose-50`, но без `ring`.

### 6. Проверять новые UI-правки статическим поиском

- После Blade/Tailwind правок запускать:
  - `rg -n "ring-1|hover:ring|<a[^\\n]*border border|<span[^\\n]*ring-1" resources/views app/View/Components`
- Каждый результат классифицировать:
  - допустимо: структурная карточка/форма/постер/error state;
  - исправить: text link/chip/menu/pagination/query link.

### 7. Не ухудшать мобильную читаемость

- Проверять ширины 360–390 px.
- Длинные русские названия должны переноситься.
- Чипы должны переноситься строками, без горизонтального скролла.
- Touch target для ссылок действий и меню держать не меньше 44 px высотой, даже без outline.

### 8. Не возвращать «ghost-card» стиль

- Не объединять одновременно:
  - тонкую обводку;
  - мягкую широкую тень;
  - rounded pill/card;
  - короткий текст внутри.
- Если элемент является простой ссылкой, он должен жить через фон/типографику, не через рамку.

### 9. Проверять сборку после изменений

- После Blade/Tailwind/UI правок запускать `npm run build`.
- Для PHP-компонентов запускать `php -l` и `./vendor/bin/pint --dirty --format agent`.
- В этом пользовательском режиме не запускать `php artisan test`, `./vendor/bin/phpunit` и не создавать новые файлы в `tests/`.

### 10. Поддерживать документацию

- При любых изменениях UI-правил обновлять:
  - `docs/UI_STANDARDS.md`
  - `docs/MAINTENANCE_LOG.md`
  - этот план, если меняется стратегия.
- После управляемых docs-блоков запускать `php artisan project:docs-refresh`.
