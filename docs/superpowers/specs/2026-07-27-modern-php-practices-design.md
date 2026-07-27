# Design: безопасные практики современного PHP

Дата: 27.07.2026

## Контекст

Внешняя статья рекомендует отказаться от подавления ошибок, unsafe raw SQL,
толстых контроллеров, нетипизированного кода, ручных library includes,
отсутствия static analysis, `die`/`exit` и разработки без тестов. Аудит
Seasonvar подтвердил, что семь границ уже реализованы либо не имеют
подтверждённого дефекта. Единственный систематический разрыв — 16 операторов
`@` вокруг нативных PHP-функций.

## Почему простого удаления `@` недостаточно

Laravel обычно преобразует PHP errors в exceptions, но reliance на глобальный
handler делает поведение utility и unit boundary неявным. Нативные функции
здесь обслуживают разные домены: corrupt gzip, исчезнувший `/proc`, DNS
failure, invalid service-account key, raster validation, EXIF и temporary
cleanup. Один общий catch либо утечёт native message/path, либо сломает
штатный fail-closed outcome.

## Выбранная граница

`App\Support\NativeCall::withWarningsAsExceptions()`:

- принимает типизированный `Closure`;
- временно обрабатывает только `E_WARNING` и `E_USER_WARNING`;
- создаёт `ErrorException` с оригинальным severity/file/line;
- возвращает точный generic result callback;
- восстанавливает прежний error handler в `finally`;
- не логирует, не нормализует и не скрывает ошибку самостоятельно.

Caller обязан явно выбрать domain outcome:

- corrupt/untrusted payload → существующее sanitized exception или cache miss;
- DNS/proc race → fail-closed `null`;
- invalid credential → существующий `GoogleIntegrationException`;
- invalid raster/EXIF → validation exception либо безопасный no-orientation
  fallback;
- temporary cleanup failure → sanitized operational report без private path;
- missing composer timestamp → deterministic timestamp fallback и sanitized
  operational report.

## Rejected alternatives

- Простое удаление `@`: implicit reliance на process-global Laravel handler и
  разные непредсказуемые outcomes для CLI/unit/runtime.
- Один `try/catch (Throwable)` вокруг всего метода: скрывает programming и
  domain exceptions.
- Новый Composer package: задача мала, текущего runtime API достаточно.
- Injected service в девять constructors: лишняя state-free abstraction и
  больший compatibility surface.
- Массовое добавление `strict_types` ко всему `app`: не связано напрямую с
  дефектом и требует отдельного verified batch.

## Защитные тесты

1. Runtime unit test проверяет result, warning conversion и restoration
   предыдущего handler.
2. AST contract сканирует весь `app` и запрещает:
   `ErrorSuppress`, `Exit_`, `Include_` и static `unprepared`.
3. Existing focused tests сохраняют gzip limits, corrupt cache miss, DNS
   fail-closed, Google signing, profile/technical image validation, proc
   inspection и cache behavior.
4. Bounded PHPStan анализирует новый support boundary без baseline.

## Public contracts

Routes, API shapes, Livewire properties, command names/exit codes, database
schema, cache keys/formats, queue payloads, translations, policies, external
URL allowlists и visitor UI не меняются.

## Production и rollback

Изменение code-only. Оно не требует migration, backup, cache flush,
dependency installation, Vite build либо restart отдельного worker protocol.
Обычный deployment reloads PHP processes. Rollback — exact revert commit;
данные и существующие cache entries совместимы в обе стороны.
