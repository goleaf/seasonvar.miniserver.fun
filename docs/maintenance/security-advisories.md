# Работа с dependency security advisories

Аудит registry: 18.07.2026; exact-lock tooling повторено 19.07.2026. Этот документ хранит workflow и проверенные результаты, но не публикует exploit details и не заменяет provider/security incident process.

## Обязательный workflow

1. Зафиксировать authoritative advisory source и время проверки.
2. Сопоставить advisory с точной installed/locked version.
3. Проверить, используется ли vulnerable functionality и доступна ли она извне.
4. Оценить project-context severity, exposed routes, data и trust boundaries.
5. Найти patched versions или документированную mitigation.
6. Прочитать official breaking/upgrade guidance.
7. Создать update decision с affected-feature map.
8. Спроектировать совместимость, rollout и rollback.
9. Выполнить smallest coherent update или mitigation.
10. Проверить affected routes, auth, data, cache/session/queue, frontend и production behavior.
11. Обновить deployment/runtime requirements.
12. Обновить этот registry, dependency inventory, compatibility matrix, plan и changelog.
13. Дать публичным/admin audiences только необходимую summary без secrets или exploit recipe.

Нельзя объявлять package скомпрометированным или безопасным по возрасту, successful install либо слухам. New telemetry, endpoints, providers, middleware, commands, scheduled tasks, jobs, public assets, environment variables и auto-discovery проходят отдельный privacy/security review.

## Проверенные результаты Task 29

| Record | Source / command | Scope | Result | Project interpretation | Action |
| --- | --- | --- | --- | --- | --- |
| SA-2026-07-18-C | Composer `audit` по exact lock, включая dev review | Composer lock | 0 advisories; 0 abandoned packages reported | Это dated tooling result, не гарантия отсутствия неизвестных проблем | Версии retained; uncontrolled update не выполнялся |
| SA-2026-07-18-N | npm `audit --json` по `package-lock.json` | 113 locked npm dependencies | 0 low/moderate/high/critical vulnerabilities | npm не предоставляет Composer-equivalent abandonment conclusion | Версии retained; `audit fix`/`--force` не применялись |
| SA-2026-07-18-P | Composer plugin policy inspection | `composer.json`, lock and installed package list | Два plugin names были pre-authorized, но не installed/locked | Лишнее permission surface, не vulnerability claim | Unused permissions удалены; lock untouched; rollback documented in UD-CFG-001 |
| SA-2026-07-19-C | Fresh Composer `audit --locked` | 125 exact locked Composer packages including dev | 0 advisories; 0 abandoned packages reported | Dated authoritative package-tool result only | Retain decisions; no broad update |
| SA-2026-07-19-N | Fresh npm `audit --json` | 113 exact locked npm dependencies | 0 low/moderate/high/critical vulnerabilities | npm provides no equivalent abandoned-package conclusion | Retain decisions; no `audit fix` or `--force` |

## Operational limitations

- `composer diagnose` сообщает отсутствующие Composer self-update public keys. Это host trust-setup gap (`TD-002`), а не package advisory.
- npm сообщает deprecated external global key `--init.module` (`DEP-001`/`TD-003`); repository config его не содержит.
- Private registry/advisory services и production provider consoles в этой среде не проверялись. Статус остаётся `requires review`, а не `safe`.
