# Датированный отчёт по зависимостям

Проверено: 18.07.2026. Это evidence snapshot Task 29, а не второй registry. Канонические записи поддерживаются в:

- [`../maintenance/dependency-inventory.md`](../maintenance/dependency-inventory.md);
- [`../maintenance/runtime-compatibility.md`](../maintenance/runtime-compatibility.md);
- [`../maintenance/update-decisions.md`](../maintenance/update-decisions.md);
- [`../maintenance/security-advisories.md`](../maintenance/security-advisories.md);
- [`../maintenance/deprecations.md`](../maintenance/deprecations.md).

## Tooling evidence

| Boundary | Фактический результат |
| --- | --- |
| Composer manifest/lock | Strict validation и platform requirements passed до final diff gate; 16 direct dependencies inventoried |
| Composer audit | 0 advisories и 0 abandoned packages по inspected lock |
| Composer outdated direct | Только PHPUnit `12.5.31` → `13.2.4` major candidate |
| Composer diagnose | Connectivity/runtime OK; self-update tag/dev public keys отсутствуют (`TD-002`) |
| npm lock | npm lock v3, 10 direct dependencies, 113 total locked dependencies |
| npm audit | 0 vulnerabilities across all severities |
| npm outdated | FontAwesome `7.3.1`, Tailwind/plugin `4.3.3`, Vite `8.1.5` patch candidates; `concurrently 10.0.3` major |
| npm environment | External global `--init.module` deprecation warning (`DEP-001`) |

## Decisions

Ни один package version не изменён. Candidates retained/deferred из-за отсутствия advisory/defect и невозможности честно объединить unrelated compatibility groups. Unused package не найден после namespace/config/provider/route/command/job/Blade/Livewire/JS/CSS/docs/deploy search.

Удалены только две stale Composer plugin permissions для packages, которых нет в installed или lock graph (`UD-CFG-001`). `composer.lock`, `package.json` и `package-lock.json` не переписаны.

## Ограничения evidence

- Tooling не доказывает отсутствие unknown vulnerabilities или functional compatibility всех portal modules.
- Production MySQL/Redis/provider/server settings не подтверждаются локальными extensions/binaries.
- Browser/player claims требуют фактического smoke/device evidence; build alone недостаточен.
- Tests в Task 29 запрещены и не запускались; testing packages и infrastructure только инспектировались.
