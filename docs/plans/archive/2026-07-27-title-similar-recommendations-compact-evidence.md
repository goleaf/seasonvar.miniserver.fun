# Task 107 — evidence компактных похожих сериалов

## Результат

На canonical странице тайтла первые шесть похожих сериалов видны сразу, а
следующие не более шести раскрываются native `<details>` без второго HTTP или
SQL запроса. Каждая строка сохраняет один title link, отдельную ссылку
«Подробнее», bounded metadata, до трёх проверяемых причин и описание не длиннее
180 Unicode-символов в двух строках.

Авторизованный пользователь получает компактные действия «Больше похожего» и
«Не похоже». Последнее передаёт allowlisted `not_similar` через существующую
server-side feedback boundary. Полная форма настройки рекомендаций на
`/discover/*` не заменена и не урезана.

## Verification

- Focused component/list matrix: `16` тестов, `143` утверждения.
- Related recommendation matrix: `135` тестов, `771` утверждение.
- Browser desktop/mobile/tablet подтвердил `6 + 6`, отсутствие второго
  запроса при раскрытии, максимум три видимые причины, bounded two-line
  description, 44px controls, keyboard focus, feedback result и отсутствие
  horizontal overflow.
- Первые browser RED выявили два stale assertions и реальную потерю
  screen-reader group name. Locator ограничен первой строкой и точным
  fixture-name; production markup снова содержит локализованный `aria-label`.
- Итоговый feature scenario проходит все Task 107 assertions. Его общий
  first-party error guard остаётся красным только из-за прежнего
  `404 /pwa/posters/browser-smoke` на каждом viewport; это та же соседняя
  PWA-регрессия, уже воспроизведённая discovery browser suite.

## Совместимость

- `titles.show`, route model binding, public API и recommendation DTO
  contracts сохранены.
- Ranking, stored feedback enum values, undo, authentication и admin-only
  correction boundary не менялись.
- Schema, migrations, indexes, routes, cache keys, dependencies, queue jobs,
  importer, player и premium access не затронуты.

## Delivery

Exact commit и push выполняются общим живым owner после повторной проверки
полного staged path set. Постороннее изменение `composer.lock` не относится к
Task 107 и в delivery не включается.
