# Livewire `wire:transition` для формы создания подборки — дизайн

Дата: 20.07.2026

Статус: реализован через RED/GREEN; task-scoped verification завершена, full-suite и Git delivery ограничения зафиксированы в current-task compliance matrix.

## Цель

Добавить короткую, необязательную анимацию появления и закрытия формы создания подборки в `CatalogCollectionDashboard`, применив официальный контракт Livewire 4 `wire:transition` к уже существующей границе `showCreate`.

## Выбранная граница

`resources/views/livewire/collections/catalog-collection-dashboard.blade.php` уже условно добавляет и удаляет `x-ui.panel` формы через `@if ($showCreate && $canCreate)`. К корневому элементу этой панели добавляется безымянный `wire:transition`.

Существующие действия остаются источником состояния:

- кнопка открытия вызывает `$toggle('showCreate')`;
- кнопка отмены вызывает `$set('showCreate', false)`;
- успешный `create()` сохраняет подборку и выполняет существующее перенаправление;
- `x-ui.panel` передаёт неизвестные атрибуты через `$attributes->merge()` на корневой `section`.

Новые Livewire properties, methods, Alpine state, JavaScript listeners, CSS transitions и route contracts не нужны.

## Поведение и доступность

- В браузере с View Transitions API Livewire выполняет стандартный короткий crossfade при добавлении или удалении панели.
- В браузере без поддержки View Transitions API DOM обновляется сразу без ошибки и без изменения функциональности.
- Встроенная реализация Livewire отключает переход при `prefers-reduced-motion: reduce`; пользовательская анимация, способная обойти это предпочтение, не добавляется.
- Фокус, `aria-expanded`, заголовок панели, сообщения валидации, поля и кнопки сохраняют текущую семантику.
- Переход не используется для списков, статусов или ошибок, где движение могло бы мешать чтению либо создавать множественную анимацию.

## Рассмотренные варианты

1. Анимировать строки подборок после удаления или восстановления. Отклонено: одна операция может менять несколько элементов и пагинацию, а движение списка ухудшает предсказуемость.
2. Анимировать сообщения валидации и статусы. Отклонено: для них важнее немедленное объявление assistive technologies, чем декоративный переход.
3. Добавить именованный transition, custom CSS или transition types. Отклонено как избыточное: стандартного безымянного crossfade достаточно, а проект ограничивает интенсивность motion.

## Cross-feature impact

| Domain | Статус | Обоснование |
| --- | --- | --- |
| Collections UI, mobile и accessibility | affected | Меняется только presentation lifecycle существующей responsive формы; DOM, touch targets и доступные подписи сохраняются |
| Authentication, authorization, validation и privacy | already_compliant | `canCreate`, policy и серверная валидация не меняются; transition не является security boundary |
| Translations | already_compliant | Нового пользовательского текста нет; существующие RU/EN ключи сохраняются |
| Caching, search, notifications, SEO, sitemap и imports | not_applicable | Чтение, запись, индексация, фоновые задания и публичные маршруты не меняются |
| Administration, audit, premium, payments, regional и legal access | not_applicable | Staff, entitlement, финансовые и ограничительные решения не затрагиваются |
| Database, storage, dependencies и runtime configuration | not_applicable | Миграции, persistent data, packages, assets entrypoints и environment не меняются |

## Production impact и откат

Изменение не требует миграции, резервной копии, cache flush, queue restart или изменения production services. Сборка frontend проверяет совместимость Blade/Vite snapshot. Откат состоит из удаления одного атрибута `wire:transition` и соответствующего contract test/documentation evidence; создание и редактирование подборок остаются работоспособными в любом состоянии отката.

## Проверка

Новый feature test рендерит реальный `CatalogCollectionDashboard` от имени подтверждённого пользователя и проверяет три состояния:

1. закрытая форма не содержит `wire:transition`;
2. после `showCreate=true` условная панель содержит безымянный `wire:transition`, форму `wire:submit="create"` и `aria-expanded="true"`;
3. после `showCreate=false` панель снова отсутствует.

Тест сначала должен упасть на отсутствии директивы, затем пройти после минимальной Blade-правки. После GREEN выполняются targeted collection regressions, `Pint` для нового PHP-теста, `npm run build`, docs gates, repository scan и полный test suite с честной фиксацией любых несвязанных с задачей сбоев общего snapshot.
