# Design: Livewire `wire:dirty` для draft состава подборок

Дата: 19.07.2026

Статус: реализовано по TDD и прошло task-scoped verification; delivery заблокирован общим Git snapshot

## Цель

Показывать вошедшему пользователю локализованное и доступное сообщение, когда checkbox-draft состава подборок отличается от последнего серверного снимка, не отправляя дополнительный запрос и не меняя сохранение.

## Выбранная граница

`CatalogCollectionMembershipManager` уже загружает persisted membership в `selectedCollectionPublicIds` при `openSelector()`, связывает checkbox через deferred `wire:model` и сохраняет draft только через `apply()`. Внутри этой отдельной формы добавляется элемент с `wire:dirty` и точным `wire:target="selectedCollectionPublicIds"`.

Livewire скрывает элемент при совпадении canonical и reactive state, показывает после локального изменения checkbox и снова скрывает после успешной синхронизации. `closeSelector()` закрывает диалог и очищает draft; других запросов внутри membership-формы между редактированием и Apply нет.

## Отвергнутые варианты

1. Глобальный `wire:dirty` в редакторе центра помощи отклонён: промежуточные Livewire actions могут синхронизировать component state без сохранения статьи в БД и дать ложное сообщение о чистом состоянии.
2. Отдельный Alpine/Vite dirty tracker отклонён как дублирование нативного Livewire 4 API.
3. Автоматическое сохранение checkbox через `wire:model.live` отклонено: оно разрушает существующий Apply/Cancel contract и создаёт лишние mutations.

## UI и accessibility

- Новый текст берётся из `collections.membership.unsaved` во всех поддерживаемых locale.
- Элемент имеет `role="status"`, `aria-live="polite"` и текстовую подпись; цвет не является единственным сигналом.
- Layout, dialog focus, touch targets, responsive wrapping и существующий мгновенный `wire:text`-счётчик не меняются.
- Без JavaScript элемент остаётся в server HTML, но deferred draft без Livewire отсутствует; основное управление сохраняет обычный SSR fallback и server actions.

## Security, data и production impact

Browser dirty state не используется для authorization, ownership, validation или persistence. `apply()` продолжает повторно разрешать пользователя и тайтл, а `CatalogCollectionItemService` остаётся единственной mutation boundary. Routes, policies, schema, cache keys, queues, search, SEO, sitemap, notifications, imports, Premium, regional/legal access, dependencies и runtime configuration не меняются.

Rollback удаляет один presentation-element, два translation values и один focused contract test; data rollback, backup, migration, cache flush и service restart не требуются.

## Проверка

- Feature test рендерит реальный component после `openSelector()` и проверяет `wire:dirty`, точный property target, локализованный текст и сохранение deferred `wire:model`.
- Translation syntax/parity, focused collection tests, Pint для теста, Vite build, managed docs и repository legacy scan входят в final gate.
- Все task-scoped gates прошли. Полный suite выполнил 1 351 тест: 1 334 passed, 11 skipped, четыре assertion failures и один error относятся к параллельно меняемым administration/catalog-island contracts; `LivewireWireDirtyContractTest` в полном и отдельном прогонах зелёный.
