# Восстановление default-входа discovery

Дата: 27.07.2026.

Статус: согласовано прямым запросом вернуть `/discover/` без дополнительных
вопросов.

## Контекст

Полный активный справочник из пяти категорий и 31 подкатегории уже
подключён одним `CatalogCollectionExplorer` ко всем девяти
`/discover/{type}`. Однако прежний default route был удалён, поэтому
`/discover/` и localized aliases отвечают `404`, хотя исторически вели к
популярному режиму.

## Решение

- вернуть именованный `/discover` как временный `302` redirect на
  `/discover/popular#collections`;
- вернуть `/{locale}/discover` с тем же locale и fragment;
- сохранить `CatalogDiscoveryPage` единственным full-page owner;
- не возвращать `/collections`, `/recommendations`, `/lists`,
  `/selections`, `/my/lists` или `/admin/collections`;
- не менять category tree, query state, public eligibility, quality gate,
  database или collection content.

`302` сохраняет историческую семантику временного default-направления:
каноническим индексируемым адресом остаётся параметризованный
`/discover/popular`.

## Совместимость и безопасность

Redirect target полностью формируется приложением из named route и
allowlisted locale, поэтому внешний URL и open redirect не принимаются.
Сохраняются route names `discover.index` и `localized.discover.index`,
добавляются прежние `discover.default` и `localized.discover.default`.
Дерево, Livewire keys, pagination, cache dimensions, SEO, API, sitemap,
permissions и imports не меняются.

## Проверка

- RED/GREEN PHPUnit для default, trailing-slash и localized redirects;
- существующие тесты подтверждают дерево категорий на landing target;
- route inventory/cache, Pint и focused discovery suite;
- Playwright на `/discover/`: конечный URL `popular#collections`, дерево
  категорий и отсутствие horizontal overflow/browser errors.

## Rollback

Удалить два entry routes и вернуть прежние assertions `404`. Миграции,
data restore, cache flush, asset rollback и dependency rollback не нужны.
