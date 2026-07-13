# План внедрения responsive-системы иконок

**Цель:** унифицировать иконки на всём портале и предотвратить возврат вертикальных смещений.

1. Зафиксировать RED-тесты для общего icon-компонента, CSS-геометрии, отсутствия сырых `<i>` и удаления текста каталога.
2. Создать `x-ui.icon` с декоративной семантикой, передачей `wire:*`/`data-*` и режимом first-line alignment.
3. Добавить responsive CSS в `resources/css/app.css` на относительных единицах.
4. Перевести общие UI, form, layout, card, pagination и catalog-компоненты.
5. Перевести страницы `/`, `/titles`, `/titles/{slug}`, `/stats`, `/watching` и административные Livewire-шаблоны.
6. Добавить недостающие контекстные иконки к footer headings, mobile filters, году выпуска, текущей серии, настройкам и активному сезону.
7. Обновить `docs/UI_STANDARDS.md` и `docs/frontend.md`.
8. Запустить `BladeTemplateTest`, `FrontendAssetContractTest`, `CatalogVisualSystemTest`, `CatalogBladeComponentTest`, Pint и `npm run build`.
9. Выполнить browser QA на desktop/tablet/mobile: status, h1, overflow, console/page errors, local asset failures, icon box ratios и screenshots.
10. Запустить полный `php artisan test`, проверить `main`, зафиксировать разрешённые изменения и оставить чистое дерево.

