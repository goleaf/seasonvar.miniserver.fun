# Checklist удаления package

Удаление начинается только после update decision со смыслом, owner, data/public-contract review и rollback. Для каждого пункта сохраняется путь или поисковый evidence.

- [ ] Поисканы namespace imports и runtime class references.
- [ ] Поисканы configuration files/options и package manifests.
- [ ] Проверены providers, auto-discovery и conditional registration.
- [ ] Проверены facades, aliases и service-container bindings.
- [ ] Проверены routes, route macros и public URLs.
- [ ] Проверены middleware и ordering.
- [ ] Проверены package migrations, published schema и persisted identities.
- [ ] Проверены commands, Composer scripts и operator runbooks.
- [ ] Проверены jobs, events, listeners, scheduler и pending serialized payloads.
- [ ] Проверены Blade directives/components и Livewire components/public state.
- [ ] Проверены JavaScript imports, dynamic imports и global objects.
- [ ] Проверены CSS imports, icons/fonts и generated asset references.
- [ ] Проверены storage files, provider identifiers и migration/retention needs.
- [ ] Проверены cache keys, serializers, locks и stale-key strategy.
- [ ] Проверены sessions, cookies, local-storage keys и service-worker caches.
- [ ] Проверены environment variable names без вывода values.
- [ ] Проверены deployment/build/server/PHP extension requirements.
- [ ] Проверены documentation, translations, admin and support workflows.
- [ ] Определена canonical replacement или подтверждено отсутствие dependants.
- [ ] Persisted data migration идемпотентна и обратима, где возможно.
- [ ] Public routes/events/statuses/cache identities имеют compatibility path.
- [ ] Removal constraint change минимален и понятен.
- [ ] Lock diff содержит только объяснимые package/transitive changes.
- [ ] Configuration/providers/assets/env docs удалены после migration, не раньше.
- [ ] Production deployment, stale process/cache и rollback описаны.
- [ ] Repository-wide search подтверждает отсутствие unintended references.
- [ ] Inventory, decisions, adapters, debt, requirements и changelog обновлены.

Revert commit недостаточен, если package владел persisted data, sessions, cache serialization, pending jobs, provider callbacks или public contracts.
