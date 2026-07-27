# Task 113 — compliance matrix современных PHP-практик

Дата: 27.07.2026

| Требование | Статус | Evidence |
|---|---|---|
| Root `AGENTS.md`, requirements index и применимые owners прочитаны | `completed` | Повторно прочитаны до edits |
| Фактические PHP/Laravel/package/frontend/database версии проверены | `completed` | PHP `8.5.8`, Laravel `13.22.0`, SQLite, Livewire `4.3.3`, Tailwind `4.3.2`, PHPUnit `12.5.32` |
| Работа только в существующей `main` | `completed` | `main...origin/main [ahead 6]`, HEAD `cb2cc4e8` |
| Exact workspace lease и NUL manifest | `completed` | `task-113-modern-php-practices`, paths declared |
| Статья разобрана по первичному URL | `completed` | Восемь рекомендаций сопоставлены с repository |
| Подавление ошибок `@` отсутствует в `app` | `completed` | AST contract: исходные 16 нарушений устранены, итоговый inventory: 0 |
| `die`/`exit` отсутствуют в application layer | `already_compliant` | AST inventory: 0 |
| Runtime `include`/`require` отсутствуют в `app` | `already_compliant` | AST inventory: 0; два data includes остаются только в migration |
| Raw SQL использует bindings и безопасные identifiers | `already_compliant` | Manual review `whereRaw`/`selectRaw`/`DB::select`; `DB::unprepared` в `app`: 0 |
| Controllers остаются thin, бизнес-логика в existing boundaries | `already_compliant` | 34 API controllers; HTML routes — Livewire; новый architecture layer не нужен |
| Type declarations, enums и readonly применяются по текущему стилю | `completed` | `strict_types` добавлен в два затронутых legacy-класса; итоговый inventory: 94 legacy-файла без него |
| Composer/autoload/lock используются, manual libraries отсутствуют | `already_compliant` | `composer.lock` tracked, `/vendor` ignored |
| PHPStan/Larastan выполняется в CI без baseline | `completed` | Existing level 6 расширен новым support boundary; `0` errors |
| Exceptions/domain outcomes заменяют hidden warnings | `completed` | `NativeCall` преобразует warnings в `ErrorException`; callers сопоставляют их с domain outcomes |
| PHPUnit/TDD фиксируют новый contract | `completed` | RED подтверждён; GREEN: новый AST/runtime набор `4 passed`, `5 assertions`; полный suite `2294` tests |
| Security: credentials, paths, URLs и native messages не раскрываются | `completed` | Google/DNS/upload/cache mappings fail closed или возвращают sanitized domain errors; secret/debug scan clean |
| Cache/import/upload/DNS/Google public behavior сохраняется | `completed` | Focused regression suites и полный backend gate прошли |
| Migrations/routes/translations/cache keys/permissions | `not_applicable` | Contracts не меняются |
| README checked, owners и русский CHANGELOG обновлены | `completed` | Visitor history, code standards, testing, audit и changelog обновлены |
| Full verification, legacy/dead/debug/secret scans | `completed` | Backend/frontend gates exit `0`; итоговый AST inventory clean |
| Exact staged review, commit и push | `in_progress` | Pending verification |

## Cross-feature impact

| Domain | Impact | Evidence / стратегия |
|---|---|---|
| Importer | `affected` | proc reads, sitemap gzip и compact payload сохраняют текущие return/error contracts |
| Cache | `affected` | page gzip miss и composer-lock timestamp fallback сохраняются без format/key bump |
| Uploads/profile/technical issues | `affected` | corrupt raster остаётся validation failure; private paths не логируются |
| External poster DNS | `affected` | resolver по-прежнему fail closed для недоступных/private hosts |
| Google read-only integration | `affected` | invalid private key остаётся sanitized `GoogleIntegrationException`; token не кэшируется |
| Database/query performance | `unaffected` | SQL и schema не меняются |
| Authentication/authorization/privacy | `unaffected` | Policies, gates, sessions и ownership не меняются |
| Livewire/UI/mobile/accessibility/translations | `unaffected` | Нет presentation change |
| Search/SEO/sitemap routes | `unaffected` | Sitemap decoder internals меняются, document contract и routes остаются прежними |
| Queue/jobs/scheduler | `unaffected` | Payloads, names, retries и serialization не меняются |
| Deployment | `affected` | Code-only rollout; no migration/build; rollback exact commit |

## Unresolved

- Full-project Larastan остаётся отдельным техническим долгом: текущий
  канонический gate намеренно bounded и не расширяется на весь `app` одним
  рискованным изменением.
- Push зависит от внешней Git-аутентификации; фактический результат будет
  зафиксирован после commit.
