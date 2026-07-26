# UX-редизайн каталога `/titles`

Дата: 26.07.2026
Статус: approved_by_explicit_user_brief

## Контекст и цель

Текущий каталог объединяет в одной верхней панели поиск, все варианты
сортировки, размер страницы, постоянно раскрытый алфавит и полный набор
фильтров. Возможности работают, но имеют одинаковый визуальный вес и
затрудняют первый выбор. Новый интерфейс должен оставить существующую
поисковую и фильтрующую модель без потери параметров, но разделить
сценарий на поиск, основные фильтры, управление выдачей и сами результаты.

## Зафиксированное решение

### Desktop

- Заголовок и точное число результатов образуют компактную строку.
- Поиск по названию занимает всю ширину следующей строки.
- Ниже используется двухколоночный layout: sticky sidebar шириной около
  18rem и основная выдача. Sidebar не получает собственного scroll
  container: прокручивается один документ.
- Над результатами остаются компактные controls: сортировка с видимым
  активным значением, grid/list, размер страницы и раскрываемый алфавит.
- Активные условия всегда видимы над карточками и удаляются по одному либо
  одной командой «Сбросить всё».

### Mobile

- Заголовок, число результатов и поиск идут первым потоком.
- Над карточками показаны «Фильтры N» и активная сортировка.
- Фильтры раскрываются как полноширинная страница в document flow с
  мобильной шапкой, reset и итоговой кнопкой. Это сохраняет правило
  проекта о единственном scroll container и остаётся usable без JavaScript.
- Алфавит является отдельной раскрываемой секцией фильтров.
- Активные chips видны до результатов и переносятся на новые строки.

### Сортировка

Основная группа: популярность, недавно обновлённые, новые сначала,
высокий рейтинг. При поиске relevance остаётся доступным и видимым, когда
активен. Остальные существующие значения помещаются в группу «Другие
варианты»: А–Я, Я–А, больше сезонов, серий и видео, старые сначала и второй
источник рейтинга. Значения query string и backend enum не переименовываются.

### Алфавит и вид

- Алфавит по умолчанию закрыт. После раскрытия отдельно показаны кириллица,
  латиница и служебные символы.
- Web-каталог получает параметр `view=grid|list`. По умолчанию используется
  `grid`; default не записывается в URL. API этот UI-only параметр не
  принимает и не меняет форму ответа.
- Grid-карточка использует постер `2:3`, название, оригинальное название,
  год, не более двух жанров, рейтинг и одну строку сезон/серия. Полные
  описания в grid не загружаются и не отображаются.
- List остаётся компактным совместимым представлением.

## State, validation и compatibility

- Источником истины остаётся `CatalogTitlesRequest`; новый `CatalogView`
  нормализует и валидирует только `grid|list`.
- Livewire URL state сохраняет поиск, фильтры, sort, per-page, letter и
  view при browser back/forward и pagination.
- OR внутри одного taxonomy и AND между группами, пустые параметры,
  нулевые границы, диапазоны, reset и существующие route model contracts
  не меняются.
- `/api/v1/titles`, публичные routes, JSON shape, cache keys, policies,
  importer, player, recommendations и database schema не меняются.

## Query, performance и security

- Сохраняются существующие bounded pagination, eager loading, grouped
  counts и lazy facets.
- `description` исключается из select для grid, но остаётся для list и
  существующего API.
- Новый view state не формирует raw SQL; sort остаётся enum-backed, фильтры
  проходят Form Request и binding.
- Blade остаётся query-free, escaped и без client-trusted authorization.
- Новые controls имеют 44px target, label/aria, `focus-visible` и русские
  тексты; важный текст не использует `text-slate-400`.

## Ошибки, empty/loading и progressive enhancement

- Validation summary остаётся server-rendered.
- Lazy facets сохраняют loading state.
- Пустая выдача, search suggestions и reset actions сохраняются.
- Mobile open-trigger является ссылкой на `#catalog-filters`; JavaScript
  только раскрывает и фокусирует страницу фильтров, поэтому отсутствие JS
  не блокирует доступ.
- При Livewire loading submit блокируется, предотвращая повторный submit.

## Database, rollout и rollback

Migration, index, data backfill, dependency, environment variable, cache
key и production service не требуются. Rollout состоит из PHP/Blade/CSS/JS
и Vite build. Rollback — revert task commit; schema/data/cache recovery не
требуются. При interrupted asset build сохраняется предыдущий manifest,
после чего build запускается повторно до deployment.

## Verification

- PHPUnit: request normalization, view state, sort grouping, active chips,
  filter combinations, reset, pagination and legacy contracts.
- Pint, PHPStan (если установлен), full PHPUnit и `npm run build`.
- Playwright: desktop 1440px, mobile 390px, tablet; filter page, sort menu,
  alphabet, grid/list, keyboard focus, history, no overflow/console errors.
- Финальный repository scan: legacy always-open alphabet, duplicate sort
  controls, old view prohibition, Blade queries, debug code and secrets.
