# Единая светлая визуальная система Seasonvar

Статус: `approved_by_user`.

Дата: 26.07.2026.

## Цель

Обновить существующий Blade/Livewire-интерфейс без смены frontend-stack и
без изменения публичных маршрутов, данных или пользовательских сценариев.
Новая система должна сделать название и основной контент заметнее служебной
метаинформации, убрать эффект «карточка внутри карточки» и закрепить
контрастную светлую палитру.

## Подтвержденные исходные условия

- PHP `8.5.8`, Laravel `13.22.0`, Laravel Boost `2.4.13`,
  Livewire `4.3.3`, Tailwind CSS `4.3.2`, Vite `8.1.4`,
  PHPUnit `12.5.32`, Node.js `26.4.0`, npm `12.0.1`.
- UI строится Blade-компонентами и full-page Livewire-компонентами;
  JavaScript используется только для общих browser/player helpers.
- Локальная и тестовая БД — SQLite; эта задача не меняет схему, данные или
  запросы.
- `resources/css/app.css` уже является CSS-first владельцем Tailwind-токенов.
- `x-ui.panel`, `x-ui.section-title`, `x-stat`, `x-ui.poster-card`,
  `x-ui.status-pill` и `x-ui.taxonomy-chip` являются существующими
  переиспользуемыми presentation boundaries.
- В общем working tree есть незавершенные изменения других задач. Они не
  являются частью этого дизайна и не должны быть удалены, переписаны или
  случайно добавлены в commit.

## Канонический визуальный контракт

### Палитра

| Роль | Значение |
| --- | --- |
| Фон страницы | `slate-50` |
| Основная поверхность | `white` |
| Вторичная поверхность | `slate-100` |
| Граница | `slate-200` |
| Главный текст | `slate-900` |
| Обычный текст | `slate-700` |
| Вторичный текст | `slate-600` |
| Акцент | `emerald-700` |
| Акцент hover | `emerald-800` |
| Успех | `emerald` |
| Предупреждение | `amber` |
| Ошибка | `red` |
| Информация | `sky` |
| Нейтральное состояние | `slate` |

`text-slate-400` не используется для labels, metadata, placeholder,
инструкций, значений или другого значимого текста на белом либо светло-сером
фоне. Он остается допустимым только для декоративных иконок, skeleton,
очевидно disabled-состояния и необязательной графики.

### Радиусы и границы

- `rounded-xl` — самостоятельная большая панель, dialog или крупная
  структурная поверхность.
- `rounded-lg` — input, button, dropdown и компактный интерактивный control.
- `rounded-full` — только status, tag и компактный filter.
- Обычная секция не получает внешний карточный shell: heading, отступ,
  `border-b`, content, следующий divider.
- Внешняя самостоятельная панель не содержит повторяющую её белую панель
  без отдельной функциональной причины.

Существующие semantic aliases сохраняются для обратной совместимости:
`rounded-panel` становится равен `rounded-xl`, `rounded-control` —
`rounded-lg`.

### Тени

Тень означает реальное перекрытие document flow и допускается только у:

- sticky header;
- dropdown/autocomplete;
- native dialog;
- player menu;
- toast/fixed status;
- skip-link в момент keyboard focus.

Обычные панели, карточки, фильтры, навигация, результаты и form fields
используют `border-slate-200` без тени. Старый `shadow-panel` сохраняется как
совместимый utility, но визуально становится `shadow-none`; новые raised
элементы используют отдельный `shadow-elevated`.

### Типографика

- `h1`: `30–36 px`, `font-semibold`, `slate-900`;
- `h2`: `22–26 px`, `font-semibold`, `slate-900`;
- `h3`: `17–20 px`, `font-semibold`, `slate-900`;
- обычный текст: `15–16 px`, `slate-700`;
- metadata: `13–14 px`, `slate-600`;
- micro-label: `12 px`, без постоянного `uppercase`;
- системный sans-serif остается без новых font dependencies.

Название сериала является самым заметным текстом строки или карточки.
Жанры, год, рейтинг, описание и счетчики визуально ему подчинены.

## Рассмотренные варианты

### A. CSS-only compatibility layer

Изменить только токены цветов, радиусов и теней. Вариант быстро охватил бы
весь портал, но оставил бы вложенные белые поверхности, неверную heading
иерархию и слабый контраст конкретной metadata. Отклонен как неполный.

### B. Механическая замена всех utility-классов

Переписать сотни Blade-фрагментов одной массовой заменой. Вариант способен
формально унифицировать классы, но повышает риск случайно затронуть
незавершенные изменения, semantic player palette, dialogs и disabled
состояния. Отклонен как недостаточно безопасный для общего working tree.

### C. Токены + shared components + route-family migration

Сначала обновить канонические токены и общие компоненты, затем исправить
основные visitor surfaces и техническую `/stats`, где аудит уже подтвердил
проблемы контраста и вложенных карточек. Сохранить compatibility aliases и
проверить остальные места repository-wide guard-тестом. Этот вариант
выбран: он дает системный эффект, устраняет разметочную причину и не требует
смены архитектуры.

## Границы реализации

Изменяются только CSS, Blade presentation, view-component class maps,
presentation tests и документация. Не изменяются:

- routes и route names;
- Livewire public properties/actions/query string;
- controllers, requests, policies, gates и middleware;
- Eloquent models, relations, scopes и запросы;
- API Resources и JSON schema;
- migrations, indexes, factories, seeders и production data;
- translations и user-generated identity values;
- cache keys, TTL, tags и invalidation;
- importer, scheduler, jobs, notifications и player lifecycle;
- npm/composer dependencies и environment configuration.

Scoped player palette остается отдельным функциональным исключением:
черная media surface и документированные player tokens не заменяются общей
светлой палитрой.

## Проверка и rollback

Проверка включает TDD-контракты токенов/shared components, focused feature
tests, полный релевантный PHPUnit-набор, Pint для затронутых PHP-файлов,
Vite production build и Playwright на `390×844`, `768×1024` и
`1440×1200` для `/`, `/titles`, одной title page и `/stats`.

Rollback не требует БД, cache flush или восстановления данных: достаточно
вернуть CSS/Blade/documentation commit и пересобрать Vite assets. При
прерванной сборке действующий manifest остается предыдущей завершенной
версией; публикация должна заменять assets и manifest как один release.
