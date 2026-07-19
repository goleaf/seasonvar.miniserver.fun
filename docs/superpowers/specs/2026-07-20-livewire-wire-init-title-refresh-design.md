# Livewire `wire:init` title refresh design

Дата: 20.07.2026

## Цель

Не отправлять post-render Livewire request со страницы тайтла, когда существующее серверное состояние заранее доказывает, что `startRefresh` ничего не поставит в очередь. Полный SSR, SEO, очередь, operational cache и polling-контракт должны сохраниться.

## Официальный контракт и текущая граница

Livewire 4 выполняет action из `wire:init` сразу после первого render; директива принимает имя action и не имеет модификаторов. Документация рекомендует lazy loading для обычной отложенной загрузки данных. Страница тайтла — осознанное исключение: её основной контент и SEO уже полностью подготовлены на сервере, а `wire:init` запускает только необязательный targeted refresh после отображения.

Сейчас корневой узел всегда содержит `wire:init="startRefresh"`. Coordinator безопасно подавляет повторный dispatch под distributed lock, но браузер всё равно отправляет Livewire request для трёх заведомо пустых случаев: refresh активен, успешный refresh ещё fresh, source URL отсутствует.

## Рассмотренные варианты

1. Выбранный: coordinator публикует `shouldRequest(CatalogTitle, CatalogTitleRefreshState): bool`; component передаёт boolean в view, Blade условно добавляет `wire:init`, а `request()` повторяет тот же predicate под lock. Это убирает лишний transport request без ослабления server authority.
2. Оставить безусловный `wire:init`. Поведение корректно и идемпотентно, но сохраняет измеримый лишний request в состояниях, где dispatch невозможен.
3. Перевести detail component на lazy loading. Это соответствует общей рекомендации для deferred data, но задержит основной публичный контент и изменит SSR/SEO contract ради фоновой side effect; вариант отклонён.

## Поток данных

`CatalogTitleDetail::render()` получает уже используемые `CatalogTitle` и `CatalogTitleRefreshState`. Coordinator определяет eligibility: непустой source URL, неактивный state, отсутствие свежего completed state. В view передаётся только `refreshShouldInitialize`; provider URL и cache payload не сериализуются.

Если boolean true, браузер один раз вызывает `startRefresh`. `CatalogTitleRefreshCoordinator::request()` берёт существующий distributed lock, повторно читает state и снова применяет predicate до `queued()` и dispatch. Поэтому устаревший HTML или гонка между render и request не создают второй job. Активный state продолжает включать независимый `wire:poll.3s.visible="refreshCatalog"` даже без `wire:init`.

Failed, partial, expired-active и stale-completed состояния остаются eligible для retry. Empty state с valid source также eligible. Fresh completed, live queued/running и отсутствующий source не eligible.

## Cross-feature, безопасность и production impact

- Authentication/authorization и visibility не меняются; title по-прежнему разрешается существующими query/page boundaries.
- Boolean — оптимизационная подсказка, а не клиентское решение доступа или записи.
- Cache store, keys, TTL, fresh window и lock store не меняются; broad flush не требуется.
- Queue job, unique identity, retry/import pipeline и player event не меняются.
- Search, sitemap, canonical URL, SEO и полный SSR остаются синхронными.
- Новых строк, CSS, JavaScript, dependencies, migration, environment/config или production services нет.
- На mobile/desktop видимое поведение одинаково; сокращается только post-render network work.

## Ошибки, rollback и восстановление

Если operational state недоступен, существующий store возвращает empty state; страница сохраняет контент, а init может выполнить прежнюю безопасную server-side попытку. Если state меняется после render, authoritative recheck под lock прекращает duplicate dispatch. Rollback локален: вернуть безусловный Blade attribute, убрать render boolean и публичный predicate; данные и cache migration не требуются.

## Проверки

- Stale refreshable title содержит `wire:init`, не содержит poll до action и после action ставит один job.
- Active title не содержит `wire:init`, но содержит visible poll и status.
- Fresh completed title не содержит ни init, ни poll.
- Title без source URL не содержит init/poll.
- Coordinator tests подтверждают concurrency/fresh window, expired recovery, no-source и dispatch failure.
- Pint, focused PHPUnit, Vite build, managed docs, diff/legacy scans и полный suite выполняются до завершения.

## Самопроверка дизайна

Решение покрывает stale, active, fresh, failed/partial, expired и no-source states; не вводит вторую бизнес-формулу в Blade; сохраняет authoritative lock recheck и официальный контракт без модификаторов. Неразрешённых design gaps нет.
