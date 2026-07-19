# Livewire `wire:offline` для отправки технического обращения

Дата: 20.07.2026

## Контекст

Livewire 4 скрывает элемент с `wire:offline` до browser `offline` event, снова скрывает после `online` и поддерживает `.class`, `.class.remove` и `.attr`. Проект уже имеет общий layout-owned connectivity runtime: Vite-модуль слушает `online`/`offline`, показывает локализованный `role="status"`, отдельно сообщает о восстановлении и работает за пределами корня конкретного Livewire-компонента.

Длинная форма технического обращения сохраняет в DOM summary, expected/actual behavior, steps и выбранные вложения до отправки. Её submit уже блокируется на время `submit,screenshots`, но не имеет локального browser-offline guard.

## Решение

- Сохранить общий Vite banner как единственного владельца видимого offline/restored уведомления. `wire:offline` не переносится в layout вне component root и не создаёт второй `aria-live` текст.
- Добавить `wire:offline.attr="disabled"` только на submit длинной формы создания технического обращения. Пока браузер offline, отправка невозможна; введённые поля остаются на месте, а после `online` кнопка снова доступна.
- Сохранить существующий `wire:loading.attr="disabled"` и точный target `submit,screenshots`: offline и in-flight блокировки независимы и не меняют server-side validation/authorization.
- Закрепить разделение ответственности статическим PHPUnit-контрактом: global layout/runtime остаются, layout не получает `wire:offline`, а technical-issue form содержит ровно одну offline-директиву на submit.

## Отклонённые варианты

1. **Заменить global runtime на `wire:offline`.** Отклонено: layout banner находится вне корня конкретного component, охватывает обычный shell и сообщает отдельное restored state.
2. **Добавить второй локальный offline alert.** Отклонено: он дублировал бы глобальный `aria-live` announcement.
3. **Отключить все поля формы.** Отклонено: пользователь должен иметь возможность продолжать редактировать локальный черновик, пока сети нет.
4. **Массово добавить директиву ко всем Livewire actions.** Отклонено: scope и риск различаются; текущая страница выбрана из-за длинного пользовательского ввода и вложений.

## Совместимость и эксплуатация

Маршруты, policies, validation, uploads, temporary storage, schema, translations, cache, queues, API offline sync, service-worker/PWA status и dependencies не меняются. Browser `navigator.onLine` остаётся только UX hint и не становится trusted authorization/network proof. Rollback — удалить одну Blade-директиву, тест и документационные записи; данные и конфигурация не требуют восстановления.
