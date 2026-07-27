# Task 112 — evidence завершения режима «Театр»

Дата: 27.07.2026

## Результат

«Развернуть театр» после изменения layout сразу показывает player и toggle,
а выход кнопкой или `Escape` возвращает прежнюю прокрутку и focus. Site shell
скрывается только во время режима, main/seasons surfaces образуют одну тёмную
сцену, и исходный media DOM не заменяется.

## Root cause и TDD

- До исправления `window.scrollY` переживал скрытие больших секций:
  desktop toggle находился на `-252 px`, mobile — на `-777 px`.
- RED PHPUnit: `2 failed` из-за отсутствующих frame и Blade-marker contracts.
- RED Playwright: Desktop Chromium не находил
  `data-player-theatre-icon`.
- GREEN focused PHPUnit: `4 tests`, `70 assertions`.

## Проверки

- `./vendor/bin/pint --dirty --format agent` — успешно.
- `node --check resources/js/player-navigation.js` — успешно.
- `npm run build` — успешно, `28 modules`.
- `player:release-check` — `30 sources`, `19 assets`, fingerprint
  `ad32cffb80a933a549898fe30268801bcd5456960aa802e87c407b8e0737ee92`.
- Связанный backend/security/player PHPUnit — `91 tests`,
  `1220 assertions`.
- Финальный полный PHPUnit — `2286 tests`, `2275 passed`,
  `11 expected skips`, `208335 assertions`.
- Основная Playwright matrix — `8 passed`, `1 expected skip`.
- Расширенная desktop/mobile/tablet/narrow/landscape/TV-like matrix —
  `19 passed`, `2 expected skips`.
- Chromium/Firefox player lifecycle — `16 passed`, `6 expected skips`.
- `git diff --check` и JavaScript syntax — успешно.

Первый широкий запуск был начат до записи обязательных registry sections и
успел прочитать прежний current plan: единственным падением при
`2274 passed` оказался `CurrentPlanPolicyScriptTest`. На сохранённом
окончательном registry focused policy прошёл `18 tests`/`41 assertions`,
docs-gate — успешно, а повторный полный набор дал приведённый выше зелёный
результат.

Визуально проверены desktop `1440×1200`, mobile `390×844` и landscape:
toggle/player видимы, seasons остаются тёмными, site shell не перекрывает
сцену, horizontal overflow отсутствует. Предупреждения impeccable
gray-on-color классифицированы как context false positives: базовый серый
находится на белой/прозрачной поверхности, а цветные hover-background в тех
же utility strings сопровождаются соответствующим hover-text.

## Совместимость и production

- Сохранены один keyed full `wire:ignore`, root `wire:ignore.self` и тот же
  `<video>` до/после Livewire refresh.
- Routes, API, schema, migrations, cache keys, translations, permissions,
  importer, premium/region/legal rules и persistent data не менялись.
- Финальный repository scan подтвердил одного владельца theatre lifecycle в
  `player-navigation.js` и scoped selectors в `app.css`; duplicate
  route/service/store, legacy persistence и dead theatre control не найдены.
- Playback grants, progress, provider URL privacy и PWA media exclusions
  остаются в существующих server/release boundaries.
- Backup и data rollback не требуются. Code rollback должен согласованно
  вернуть Blade/CSS/JS/tests/docs и повторить Vite build.
- Physical iOS и OS-owned native fullscreen остаются
  `unresolved_device` из общей playback-матрицы; theatre geometry проверена
  responsive Chromium и desktop Firefox.

## Release

Exact staged snapshot, commit и push фиксируются в compliance matrix после
выполнения Git workflow.
