# Реестр deprecated API и behavior

Аудит: 18.07.2026; повторно сверен 19.07.2026. Запись появляется только при подтверждённом предупреждении или официальном version-specific guidance. Рекомендация использовать другой стиль сама по себе не объявляется deprecation.

## DEP-001 — неподдерживаемый глобальный npm config key

1. Stable ID: `DEP-001`.
2. Framework/package: npm CLI `12.0.1` и будущий npm major.
3. Deprecated API/behavior: глобальная настройка `--init.module`; npm сообщает, что она перестанет работать в следующем major.
4. Current usage location: внешняя global npm configuration текущего оператора; repository `.npmrc` и package scripts ключ не содержат.
5. Replacement: удалить устаревшую global key либо заменить только на документированную npm config option, если первоначальная цель будет установлена.
6. Affected features: `npm install`, `npm audit`, `npm outdated`, Vite/Tailwind build diagnostics; browser runtime не затронут.
7. Urgency: medium before the next npm major, low for current locked npm 12 workflow.
8. Security impact: no vulnerability asserted; unknown config may obscure operator intent.
9. Production impact: build hosts may turn the warning into a future error or ignore intended initialization behavior.
10. Migration phase: operator build-runtime maintenance, not repository package update.
11. Compatibility adapter: none; npm currently ignores/accepts the key with a warning.
12. Removal condition: `npm audit --json`, `npm outdated --json` and `npm run build` complete without the warning under the approved package-manager runtime.
13. Status: open / external environment; fresh `npm ls`, `npm audit`, `npm outdated` and `npm run build` on 19.07.2026 still emit the warning.
14. Verification method: inspect effective npm configuration without recording credentials, remove only this exact key, repeat the three read/build checks.

## Confirmed clean version-specific searches

| Boundary | Officially relevant deprecated/changed surface | Repository result |
| --- | --- | --- |
| Livewire 4 | `$wire.$js(...)`, unprefixed `$js(...)`, `commit`/`request` hooks, old `setUpdateRoute` signature, `wire:scroll`, transition modifiers | No deprecated JS actions/hooks/scroll/transition usage. `setUpdateRoute($handle, $path)` preserves hashed v4 endpoints. Package default SFC generation was a project-architecture drift risk, not a deprecation; config now pins `type=class`. |
| Livewire 4.1+ | `.blur`/`.change` synchronization semantics | Existing `.blur` inputs intentionally commit client/server state on blur before later submit/action; no code relies on per-keystroke `$wire` state. Retained, not deprecated. |
| Livewire routes | `Route::livewire()` is preferred for full-page components | Existing `Route::get(..., Component::class)` is still supported and preserves a large public/localized route contract. It is not marked deprecated and is not mass-rewritten. |
| Laravel 13 | bootstrap/middleware/exception/provider structure and named-argument risk | Project uses `Application::configure`, configuration callbacks, public service-provider APIs and current attributes. No old HTTP/console Kernel structure or confirmed deprecated framework call found. |
| Tailwind 4 | v3 directives/config/content scanning | Project already uses CSS-first `@import 'tailwindcss'`, `@source`, `@theme` and Vite plugin. No v3 `@tailwind` directives or JS config dependency found. |
| Vite 8 | Node runtime floor, manifest/build API | Vite config uses current public plugin/`defineConfig` API. Node 26 satisfies the engine floor; its non-LTS lifecycle is maintenance risk, not API deprecation. |
| PHP 8.5 | dynamic properties, implicit nullable signatures, removed functions/runtime behavior | Repository searches found no newly actionable PHP 8.5 deprecation in changed scope. Final Rector evidence is recorded in the Task 29 plan; maximum modernization findings remain technical debt unless they represent a confirmed deprecated API. |
| Composer 2.10 | legacy `config.audit` versus `config.policy` | Repository has no `config.audit`; no migration is required. Missing self-update pubkeys are operational security setup, not a deprecated project API. |
| Rector maximum profile | 1,337 files receive proposed diffs; analyzer errors `0`; dry-run exit `2` | Suggestions are predominantly strict-types, readonly, naming, helper and facade-injection modernization. They are not classified as framework deprecations without official API evidence and are tracked under `TD-005`; no proposal was applied automatically. |

## Resolution rule

A record becomes `resolved` only when old usage/config is removed or intentionally isolated, the stated replacement exists, affected modules are verified and removal evidence is recorded. Empty searches are dated evidence, not a claim that future package versions contain no deprecations.
