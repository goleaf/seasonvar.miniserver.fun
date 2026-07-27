# Адаптивная панель действий фильтров каталога

Дата: 27.07.2026.

Статус: вариант 1 утверждён пользователем; спецификация подготовлена для
проверки перед планированием реализации.

## Проблема

Форма фильтров `/titles` располагается на `lg+` в колонке шириной `18rem`.
Текущая панель действий использует `sm:flex-row`, поэтому ориентируется на
ширину viewport, а не на фактическую ширину боковой панели. Динамическая
подпись вида «Показать 33 005 сериалов» вместе с действиями «Отменить
изменения» и «Сбросить фильтры» не помещается в одну строку и создаёт
непригодную для использования компоновку.

## Утверждённое решение

Три действия располагаются вертикально во всех viewport:

1. полноширинная зелёная кнопка применения фильтров;
2. полноширинная нейтральная кнопка отмены черновых изменений;
3. полноширинная спокойная ссылка сброса фильтров.

Каждый control сохраняет минимальную высоту 44 px. Контейнер и содержимое
получают `min-width: 0`, а текст кнопок может переноситься и остаётся
центрированным. Компоновка не переключается в горизонтальный ряд на
viewport-breakpoint, поэтому одинаково помещается в `18rem` sidebar,
полноширинной мобильной форме, при длинном RU/EN-тексте и увеличении текста.

## Поведение и совместимость

Сохраняются существующие действия и их порядок:

- submit вызывает прежний `applyFilters`;
- cancel использует прежний `data-catalog-filter-cancel`, возвращает draft и
  закрывает compact `<details>`;
- reset остаётся обычной ссылкой на `titles.index` с
  `wire:click.prevent="resetAll"` и `rel="nofollow"`;
- динамический точный result count продолжает обновляться существующим
  `mobile-runtime.js`;
- keyboard, touch, no-JavaScript GET fallback, browser history и URL-state не
  меняются.

Новые routes, Livewire state/actions, JavaScript, translations, database
queries, migrations, API fields, cache keys, permissions или packages не
добавляются.

## Визуальный и accessibility-контракт

Primary CTA сохраняет `emerald-700` и белый текст. Cancel остаётся
нейтральным, reset — визуально менее приоритетным. Все действия видимы без
hover, доступны по Tab и имеют существующие focus/disabled states. Длинный
счётчик не обрезается и не выталкивает соседние controls; на уровне страницы
не появляется горизонтальная прокрутка.

## Проверка

Реализация начинается с RED-регрессии, проверяющей вертикальный
полноширинный action stack и отсутствие прежнего `sm:flex-row`. После GREEN:

- focused PHPUnit проверяет Blade contract, тексты и сохранение действующих
  action attributes;
- Vite build подтверждает наличие Tailwind-классов;
- Playwright проверяет desktop sidebar и compact viewport `320×568` и
  `390×844`;
- browser assertions подтверждают containment каждого control внутри панели,
  отсутствие пересечений и page-level horizontal overflow, высоту не меньше
  44 px, keyboard focus, работу cancel/reset/apply.

## Production impact и rollback

Изменение затрагивает только Blade presentation и собранный frontend asset.
Migration, backup, data write, cache schema conversion и worker restart не
требуются. Deployment публикует согласованный Blade/build unit и проверяет
живой `/titles` без очистки пользовательских данных.

Rollback — revert Blade/tests/docs и повторная сборка assets. Routes, данные,
cache payloads и пользовательское состояние восстанавливать не требуется.
