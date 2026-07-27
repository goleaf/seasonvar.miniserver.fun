# План восстановления default-входа discovery

Дата: 27.07.2026.

**Цель:** вернуть `/discover/` и localized aliases к существующему полному
каталогу категорий и подкатегорий через `popular#collections`.

**Стек:** PHP 8.5, Laravel 13.22, Livewire 4.3, PHPUnit 12.5, Playwright.

## Task 1: Постоянный contract и evidence

- [x] Перечитать mandatory requirements и последние Task 109/110 specs.
- [x] Воспроизвести `404` и сравнить с последним рабочим Git route.
- [x] Зафиксировать default entry route в canonical owners.
- [x] Подтвердить отсутствие schema/data/cache/permission изменений.

## Task 2: RED route contract

**Files:**

- Modify: `tests/Feature/UnifiedDiscoveryCollectionsTest.php`

- [x] Проверить `/discover`, `/discover/`, `/ru/discover` и
  `/en/discover`.
- [x] Требовать `302` и exact locale-aware `popular#collections`.
- [x] Сохранить `404` для остальных удалённых aliases.
- [x] Запустить focused test и подтвердить ожидаемый RED: route ответил
  `404` вместо `302`.

## Task 3: Минимальная реализация

**Files:**

- Modify: `routes/web.php`

- [x] Добавить `discover.default` через Laravel redirect route.
- [x] Добавить `localized.discover.default` через allowlisted locale и
  existing named route.
- [x] Не добавлять controller, Livewire page, query или data mutation.
- [x] Запустить focused GREEN: `1` test, `12` assertions.

## Task 4: Browser и compatibility

**Files:**

- Modify: `tests/browser/discovery-collections.spec.js`

- [x] Добавить browser contract перехода `/discover/` к
  `popular#collections`.
- [x] Добавить проверки root/child hierarchy и mobile width.
- [x] Выполнить route cache/check, Pint, focused discovery matrix и Vite.

## Task 5: Документация и delivery

- [x] Обновить README, русский CHANGELOG, compliance и archive evidence.
- [x] Перечитать requirements и выполнить legacy/dead-route scan; обновить
  найденные deployment/integration/compatibility/audit owners, сохранив
  historical планы и changelog как историю прежнего решения.
- [x] Проверить exact staged diff, branch `main` и отсутствие
  `composer.lock` в разрешённом snapshot.
- [ ] Commit разрешённого scope в `main` и non-force push; внешний отказ
  зафиксировать как `unresolved`.
