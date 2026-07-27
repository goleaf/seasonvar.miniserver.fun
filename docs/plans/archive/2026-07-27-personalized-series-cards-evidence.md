# Task 115 — evidence заметных карточек персональных рекомендаций

Дата: 27.07.2026.

## Результат

На `/discover/personalized` секция «Сериалы каталога» использует
светло-зелёный фон и белые карточки. Общий recommendation layout показывает
постоянную primary-ссылку «Открыть сериал» на `titles.show`.

## TDD

### CTA RED

```text
CatalogCompactTitleCardTest: 1 failed, 17 assertions
CatalogRecommendationListTest: 1 failed, 13 assertions
```

Ожидаемые причины: отсутствуют «Открыть сериал» и
`title-card-action-primary`.

### CTA GREEN

```text
14 tests, 81 334 assertions, exit 0
```

Покрыты compact recommendation-card, title recommendation list и RU/EN
translation parity.

### Layout RED

```text
CatalogDiscoveryLayoutTest: 1 failed, 2 assertions
```

Ожидаемая причина: отсутствует
`data-personalized-series-surface`.

### Layout GREEN

```text
36 tests, 263 assertions, exit 0
```

Покрыты discovery layout, Livewire interactions и title recommendation
list.

## Финальные automated checks

```text
npm run build
28 modules transformed
player release: ready=true, source_count=31, asset_count=19

44 tests, 81 536 assertions, exit 0

Pint: passed

Focused Playwright:
Desktop Chromium passed
Mobile Chromium passed
Tablet Chromium passed
3 passed
```

Focused Playwright проверяет зелёный section surface, белую bordered card,
видимую primary CTA, высоту не меньше 44 px, keyboard focus и отсутствие
horizontal overflow.

## Production browser evidence

URL: `https://seasonvar.miniserver.fun/discover/personalized`.

| Viewport | Section | Card | CTA | Grid | Overflow |
| --- | --- | --- | --- | --- | --- |
| `1440×1200` | `emerald-50`, border `emerald-200` | white, border `emerald-100` | `Открыть сериал`, white on `emerald-700`, `44 px` | `659 px 659 px` | `0` |
| `390×844` | `emerald-50` | white, `332 px` | `Открыть сериал`, `230×44 px` | `332 px` | `0` |

Production page содержит 24 result cards. Фокус CTA имеет видимый solid
outline. Нажатие Enter открыло
`/titles/cvetok-zlathe-flower-of-evil`, затем browser back вернул
`/discover/personalized`.

Screenshots сохранены Playwright как transient artifacts:

- `task-115-personalized-series-desktop.png`;
- `task-115-personalized-series-mobile.png`.

## Совместимость

- routes, API, schema, migrations и persisted data не менялись;
- ranking, recommendation reasons, feedback и Livewire actions сохранены;
- cache keys/invalidation, policies, permissions, premium/region/legal
  boundaries не менялись;
- новых queries, JavaScript, CSS rules, packages и external calls нет;
- другие discovery modes сохраняют нейтральный results shell.

## Unresolved external state

- Полный существующий `recommendation-ui.spec.js` после прохождения всех
  UI assertions останавливается на трёх прежних
  `404 /pwa/posters/browser-smoke`; focused Task 115 scenario проходит
  `3/3`. PWA fixture/route не изменялись и не маскировались.
- Полный `check-changelog-policy.sh` останавливается на английском слове
  `breadcrumbs` в ранее существующем unstaged Task 114 hunk. Task-scoped
  `project:docs-refresh --check`, current-plan, README и diff checks проходят.
- Общий Git index уже содержит staged Task 113, а рабочее дерево — Task 114.
  Task 115 нельзя безопасно commit/push до штатного handoff без включения
  чужого scope.

Delivery update 27.07.2026: Task 113 зафиксирован отдельным commit
`a7322a91`. User-authorized Tasks 114–116 подготовлены одним exact reviewed
snapshot; непроверенное обновление `composer.lock` исключено и сохраняет
push как `unresolved` до отдельного maintenance review или штатного handoff.
