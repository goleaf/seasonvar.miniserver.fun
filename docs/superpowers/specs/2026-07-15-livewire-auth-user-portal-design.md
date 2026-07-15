# Livewire-аутентификация и пользовательский портал Seasonvar

Дата: 15.07.2026

Статус: утверждено пользователем по разделам 1–6 в диалоге; готово к implementation plan после письменного review gate.

## 1. Контекст и результат аудита

Проект уже имеет полноценный mobile API v1 для регистрации, входа, email verification, password reset, Sanctum-устройств, self-service `/me`, watchlist, личных оценок, прогресса, Continue Watching, истории, playback sessions и offline sync. Livewire уже обслуживает карточку тайтла, пользовательские действия проигрывателя и `/watching`, но браузер не имеет собственных маршрутов входа, регистрации, восстановления пароля, профиля и выхода.

Текущий `bootstrap/app.php` перенаправляет гостя на именованный маршрут `login`, которого нет. `/watching` не защищён route middleware и завершает `mount()` ответом 403. В шапке есть только условная ссылка «Мои просмотры» для уже аутентифицированной сессии; гость не получает путь входа, а пользователь — профиль и выход. Существующие verification/reset notifications ориентированы на API и не дают законченного браузерного сценария.

Цель проекта — добавить session-based браузерную аутентификацию и единый пользовательский портал на class-based Livewire 4, переиспользовать текущие доменные сервисы для Web и API, сохранить мобильный API v1 совместимым и покрыть весь жизненный цикл автоматическими и браузерными тестами.

## 2. Принятый продуктовый вариант

Один `User` является одним пользовательским профилем и владельцем всех private-данных. Отдельная таблица профилей не создаётся.

Профилю принадлежат:

- имя, email и статус подтверждения;
- пароль и security state;
- мобильные Sanctum-устройства;
- watchlist «Буду смотреть»;
- личные оценки;
- прогресс серий;
- Continue Watching;
- история просмотров;
- owner-scoped offline sync state.

Не входят в эту реализацию:

- household и несколько viewer profiles в одном аккаунте;
- детские профили, PIN и age rules;
- billing, подписки, покупки, trials и territory;
- concurrent-stream enforcement;
- отдельная сущность favorites: текущий watchlist остаётся канонической отметкой;
- ручная отметка «Просмотрено/Не просмотрено», отдельная от фактического playback progress;
- profile preferences языка, аудиодорожки, субтитров и autoplay, пока эти данные не нормализованы в доменной схеме;
- скачивание видео и offline playback.

## 3. Выбранная архитектура

Используется нативная Laravel 13 и Livewire 4 реализация без новой production dependency, Fortify, starter kit, Volt или SPA-фреймворка.

Транспортные границы:

- Web использует guard `web`, session cookie, CSRF и Livewire full-page components;
- API использует Sanctum bearer token и abilities `mobile:read`/`mobile:write`;
- Livewire не вызывает собственный API через HTTP;
- API не создаёт browser session;
- оба транспорта вызывают общие account/catalog services, policies и queries.

Альтернативы отклонены:

1. Livewire как HTTP-клиент `/api/v1` добавил бы ненужные browser tokens, внутренние HTTP-запросы и двойное состояние CSRF/session/token.
2. Fortify или starter kit добавили бы dependency и чужой UI/структуру в существующий проект без необходимости.

## 4. Матрица доступа

Гость:

- использует публичный каталог и public playback;
- открывает регистрацию, вход, forgot/reset password;
- при открытии private web route перенаправляется на `login`, затем возвращается на intended URL.

Авторизованный пользователь с неподтверждённым email:

- читает профиль, security state, существующую библиотеку и историю;
- получает доступ к `audience=authenticated`, потому что verification не является подпиской;
- не изменяет watchlist, rating, progress, history и offline state;
- может обновить профиль, пароль, повторно отправить verification и удалить аккаунт.

Пользователь с подтверждённым email:

- получает все owner-scoped mutations библиотеки и playback progress.

API дополнительно требует:

- `mobile:read` для чтения `/me`, devices, library и owner sync;
- `mobile:write` для account/token/library mutations;
- `verified.api` для watchlist, rating, progress, history mutations и offline push.

Чужой private ID маскируется как 404. Web и API никогда не принимают `user_id` или profile ID для self-service.

## 5. Web-маршруты

Guest group:

- `GET /login` → `login` → `App\Livewire\Auth\LoginPage`;
- `GET /register` → `register` → `App\Livewire\Auth\RegisterPage`;
- `GET /forgot-password` → `password.request` → `App\Livewire\Auth\ForgotPasswordPage`;
- `GET /reset-password/{token}` → `password.reset` → `App\Livewire\Auth\ResetPasswordPage`.

Authenticated group:

- `GET /email/verify` → `verification.notice` → `App\Livewire\Auth\VerifyEmailPage`;
- `GET /confirm-password` → `password.confirm` → `App\Livewire\Auth\ConfirmPasswordPage`;
- `GET /profile` → `profile.show` → `App\Livewire\Profile\ProfilePage`;
- `GET /profile/security` → `profile.security` → `App\Livewire\Profile\SecurityPage`;
- `GET /library` → `library.index` → `App\Livewire\Library\UserLibraryPage`;
- `GET /library/{section}` → `library.section`, где `section` ограничен `watchlist|ratings|continue-watching|history`.

Signed handler:

- `GET /email/verify/{id}/{hash}` → `verification.verify`;
- проверяет временную подпись и email hash;
- не требует активной сессии, чтобы письмо работало на другом устройстве;
- идемпотентно подтверждает email и перенаправляет на русскую web-страницу результата.

Compatibility route:

- текущий `GET /watching` сохраняет имя `viewing-activity` и перенаправляет авторизованного пользователя на `/library/continue-watching`;
- гость проходит через `auth` middleware, а не получает 403 из `mount()`.

Protected account/library routes используют `auth` и `auth.session`. Guest routes используют `guest`. Verification и password confirmation сохраняют стандартные Laravel route names, нужные framework middleware.

## 6. Регистрация и вход

### 6.1 Регистрация

Форма принимает имя, email, пароль и confirmation. Имя очищается через `Str::squish`, email — через `Str::lower(Str::squish(...))`. Пароль использует тот же проектный сильный contract, что mobile API: не менее 12 символов и confirmation.

Общий `AccountRegistrationService`:

- создаёт `User` в транзакции;
- полагается на hashed model cast для пароля;
- отправляет queued verification notification после commit;
- возвращает созданного пользователя.

Web после регистрации выполняет `Auth::login($user)`, регенерирует сессию и переводит на `verification.notice`. `MobileAuthenticationService` вызывает тот же сервис и отдельно выдаёт device token.

### 6.2 Вход

Форма принимает email, пароль и remember flag. Вход:

- нормализует email;
- использует единое сообщение для неизвестного email и неверного пароля;
- ограничивается по нормализованному email и IP;
- вызывает `Auth::attempt` с remember flag;
- регенерирует session ID;
- возвращает на intended URL, fallback — `library.index`.

### 6.3 Выход

Выход выполняется state-changing Livewire/POST действием:

- `Auth::logout()`;
- invalidates session;
- regenerates CSRF token;
- redirect на `home`.

## 7. Email verification и password reset

### 7.1 Verification

`VerifyEmailPage` показывает status, повторную отправку и success state. Resend throttled и идемпотентен. Подтверждённый пользователь не получает новое письмо.

Notification становится универсальной для человека: кнопка открывает web completion route. Существующий `GET /api/v1/auth/email/verify/{id}/{hash}` остаётся доступен и документирован для API. После web verification мобильный клиент обновляет `/api/v1/me`.

### 7.2 Forgot password

Web и API используют Laravel Password Broker. Для существующего и отсутствующего email возвращается одинаковый пользовательский результат, исключающий enumeration. Notification queued и после commit, ссылка ведёт на `/reset-password/{token}?email=...`.

### 7.3 Reset password

Общий `AccountPasswordResetService` получает email, token и новый пароль. Успешная операция:

- меняет password hash;
- ротирует `remember_token`;
- удаляет reset tokens;
- отзывает все Sanctum tokens;
- отправляет `PasswordReset`;
- делает старые browser sessions недействительными через password hash и `auth.session`.

Web перенаправляет на `login` со status. API сохраняет текущий JSON endpoint и envelope.

## 8. Профиль и security

`ProfilePage` показывает имя, email, verification state, дату регистрации и сводку библиотеки. Он изменяет имя/email через общий `AccountService`.

При изменении email:

- проверяется case-insensitive uniqueness после normalization;
- `email_verified_at` становится `null`;
- reset tokens старого и нового адреса удаляются;
- новое verification письмо отправляется после commit;
- текущая session сохраняется;
- library mutations блокируются до verification.

`SecurityPage` предоставляет:

- смену пароля с `current_password`;
- список mobile API devices без hashes и abilities;
- отзыв одного owner-scoped token;
- отзыв всех mobile tokens;
- завершение других browser sessions через `Auth::logoutOtherDevices`;
- удаление аккаунта после password confirmation.

Обычная смена пароля сохраняет текущую browser session, отзывает остальные mobile tokens, очищает reset tokens и ротирует `remember_token`. Reset password отзывает все API tokens.

Удаление аккаунта транзакционно удаляет tokens/reset state и `User`. Foreign keys cascade удаляют title state, progress, user sync changes и mutation receipts; `SeasonvarImportRun.requested_by_user_id` становится `null`. Каталоговые provider reviews не принадлежат `User` и не затрагиваются. Текущая session инвалидируется после успешного удаления.

## 9. Личная библиотека

Один `UserLibraryPage` обслуживает обзор и четыре route sections. Фильтры и сортировка хранятся в URL через Livewire Form Object; пагинация сбрасывается при изменении фильтра.

### 9.1 Overview

Показывает aggregate counts, последние Continue Watching, watchlist, ratings и history без загрузки полных списков. Неподтверждённый пользователь видит verification status и доступную resend action.

### 9.2 Watchlist

Поддерживает add/remove, поиск по названию, фильтр type/year и сортировку updated/title/year. Повтор desired state идемпотентен. Источник истины — `catalog_title_user_states.in_watchlist`.

### 9.3 Ratings

Поддерживает значение 1–10, изменение и удаление. Список фильтруется аналогично watchlist и сортируется по updated/title/year/personal rating. Личная оценка отделена от импортных provider ratings и общего user aggregate.

### 9.4 Персональные карточки

Авторизованные карточки в каталоге и библиотеке показывают watchlist, personal rating, latest progress и primary action. Данные подготавливаются query service/eager aggregate, не через Blade queries и не N+1. Гость не получает private state.

### 9.5 Primary action

Существующий `CatalogPrimaryAction` остаётся общим контрактом: `start`, `continue`, `next`, `replay`, `title-media` или `unavailable`. Web и API не дублируют выбор действия.

### 9.6 Progress

Progress записывается на start, периодическом heartbeat, pause, episode change и ended. Запись требует verified owner, playback session, exact hierarchy/media ownership, monotonic event sequence и допустимые position/duration. Позднее событие не откатывает более новый progress.

Completion rule остаётся 95%, 15 секунд или trusted `ended`. Ручной watched flag не добавляется.

### 9.7 Continue Watching и history

Continue Watching возвращает один актуальный target на тайтл и выбирает current/next episode. History содержит первую/последнюю активность, position/duration/percent/completion и accessibility. Скрытая или удалённая серия остаётся исторической строкой, но не становится playable.

Удаление одной строки и clear history owner-scoped, verified и публикуют sync changes. Полная очистка требует UI confirmation.

## 10. Livewire и Blade структура

PHP components:

```text
app/Livewire/Auth/LoginPage.php
app/Livewire/Auth/RegisterPage.php
app/Livewire/Auth/ForgotPasswordPage.php
app/Livewire/Auth/ResetPasswordPage.php
app/Livewire/Auth/VerifyEmailPage.php
app/Livewire/Auth/ConfirmPasswordPage.php
app/Livewire/Auth/LogoutButton.php
app/Livewire/Profile/ProfilePage.php
app/Livewire/Profile/SecurityPage.php
app/Livewire/Library/UserLibraryPage.php
```

Form Objects:

```text
app/Livewire/Forms/Auth/LoginForm.php
app/Livewire/Forms/Auth/RegistrationForm.php
app/Livewire/Forms/Auth/ForgotPasswordForm.php
app/Livewire/Forms/Auth/ResetPasswordForm.php
app/Livewire/Forms/Auth/ConfirmPasswordForm.php
app/Livewire/Forms/Library/LibraryFilters.php
```

Views повторяют namespace components в `resources/views/livewire`. Простая форма профиля/security может оставаться properties соответствующей страницы, если отдельный Form Object не даёт переиспользования.

Вложенные Livewire-компоненты не создаются только ради разметки. Query-free presentation переиспользует `x-ui.panel`, `x-ui.section-title`, `x-ui.icon`, `x-ui.poster-card`, `x-ui.poster-frame` и `x-form.input-error`. Допустимы общие `x-form.field`, `x-form.password-field`, `x-form.checkbox`, `x-form.status-message`.

## 11. UI и доступность

Интерфейс следует `docs/UI_STANDARDS.md`:

- только светлая slate/white тема и emerald action color;
- русский видимый текст;
- локальный FontAwesome только через `x-ui.icon`;
- no CDN, marketing copy, fake content, gradients, glassmorphism и decorative motion;
- один `h1`, один `main`, skip-link и noindex/no-follow для private/auth pages;
- labels всегда видимы, autocomplete корректен, ошибки рядом с полем;
- controls и touch targets не меньше 44×44 px;
- loading/status доступен assistive technologies;
- текст не truncate/line-clamp;
- табы библиотеки переносятся по строкам, внутренний scroll запрещён;
- layout проверяется на 390×844, 768×1024 и 1440×1200;
- auth/profile/library links в первой реализации не используют `wire:navigate`, поэтому Plyr/HLS lifecycle и progress flush не меняются.

Шапка:

- guest: «Войти», «Регистрация»;
- user: «Моя библиотека», «Профиль», «Безопасность», «Выйти»;
- admin links остаются под gates;
- mobile navigation не использует вложенную прокрутку.

## 12. API v1

Существующие URL, envelopes, error codes, abilities, primary action values, sync operation types и bearer authentication сохраняются.

Новый endpoint:

- `GET /api/v1/me/library/summary`, `mobile:read`, `private, no-store`;
- возвращает `watchlist_count`, `ratings_count`, `continue_watching_count`, `history_count`, `last_watched_at` и canonical links;
- использует aggregate query service и query-budget test.

Расширяются `GET /api/v1/me/watchlist` и `/ratings` параметрами:

- `q`;
- `type`;
- `year`;
- `sort=updated|title|year|rating`, где `rating` допустим только ratings;
- `direction=asc|desc`;
- существующие `page`/`per_page`.

Invalid filter возвращает `validation_failed`, а default order остаётся `updated desc, id desc`. Pagination limits не меняются: library 1–50, history 1–48, Continue Watching 1–24.

Private responses всегда `Cache-Control: private, no-store`. API не раскрывает raw media URLs, session payload, token hashes, secrets, user IDs других владельцев или stack traces.

OpenAPI 3.1 в `resources/api/openapi.json` и `docs/api.md` обновляются одновременно с endpoints.

## 13. Доменные сервисы

Добавляются/формируются transport-neutral boundaries:

- `AccountRegistrationService` — создание пользователя и verification notification;
- `AccountService` — profile/password/delete operations;
- `AccountPasswordResetService` — общий Password Broker reset;
- общий email verification operation/handler без дублирования mark/event semantics;
- `UserLibrarySummaryQuery` — aggregate counts;
- расширенный `UserLibraryQuery` — Web/API filters and pagination.

`MobileAuthenticationService` сохраняет token-specific login/issuance и делегирует регистрацию. Все API consumers переводятся с `MobileAccountService` на общий `AccountService`, после чего `MobileAccountService` удаляется. Дублирование account rules запрещено.

Catalog mutations продолжают проходить `CatalogUserStateService`, viewing mutations — `CatalogViewingActivityService`, progress — существующую playback boundary. Sync publications остаются внутри этих сервисов, поэтому Web mutations видны mobile pull.

## 14. Policies, middleware и ошибки

`CatalogTitlePolicy::interact` требует verified email и entitlement. `EpisodeViewProgressPolicy` требует owner и verified email для delete/deleteAny. API сохраняет `verified.api`, чтобы вернуть `email_not_verified`; Livewire показывает verification notice до action, а policy остаётся server-side защитой.

Web validation errors и status messages — русские. API сохраняет:

- `validation_failed`/422;
- `unauthenticated`/401;
- `forbidden`/403;
- `email_not_verified`/403;
- `not_found`/404;
- `rate_limited`/429;
- очищенный `server_error`/500;
- sync-specific 410/503.

Registration/login/verification/reset получают отдельные rate limiters. Enumeration исключается одинаковыми ответами login и forgot-password. Livewire public properties не содержат `user_id`; owner всегда берётся через `Auth::user()`.

## 15. Сессии и security state

Session driver по умолчанию — Redis, поэтому удаление только database `sessions` не является общей стратегией.

Применяется `auth.session` на protected routes и стандартный Laravel `Auth::logoutOtherDevices` для других browser sessions. Password hash/remember token rotation делает старые sessions недействительными. Database session rows очищаются дополнительно, когда выбран database driver; Redis keys не сканируются вручную.

Production configuration должна иметь trusted host на web-server/`TrustHosts`, Secure/HttpOnly/SameSite cookies и корректный `APP_URL`. `.env` не редактируется агентом; при необходимости меняются `.env.example`, config и operational docs.

## 16. Схема и производительность

Новая profile table не нужна. Существующие foreign keys cascade удаляют user state/progress/sync rows. Sanctum/reset state удаляется явно.

Новая additive reversible migration добавляет индексы, а query-plan/query-budget tests подтверждают их применение:

- `(user_id, in_watchlist, updated_at, id)`;
- `(user_id, updated_at, id)`;
- `(user_id, rating, updated_at, id)`.

Эти индексы обслуживают новые library filters/sorts; existing progress indexes сохраняются.

Локальная SQLite на момент аудита не содержит columns `watchlist_version`/`rating_version`, хотя соответствующая migration есть в репозитории. Реализация проверяет `migrate:status` и не использует destructive migration commands. Текущий graceful pre-sync-schema режим сохраняется.

## 17. Тестовая стратегия

Все production behavior выполняется TDD: сначала focused failing PHPUnit/Livewire test, затем минимальный код, затем refactor.

### 17.1 Web auth tests

- guest/auth redirect matrix и standard route names;
- registration normalization, uniqueness, strong password, session regeneration и queued verification;
- login success, remember, intended redirect, generic failure и throttling;
- logout invalidates session/CSRF;
- verification valid/expired/tampered/already-used/resend/throttle;
- forgot-password enumeration parity;
- reset valid/invalid/expired token, API token revocation и old session invalidation;
- password confirmation timeout.

### 17.2 Account tests

- profile show/update и owner isolation;
- email change resets verification, removes reset tokens и sends notification;
- password change requires current password and revokes correct tokens;
- device list/revoke/foreign 404/logout-all;
- account deletion cascades private data, nulls import requester и invalidates session.

### 17.3 Library tests

- auth/verified matrix for every mutation;
- watchlist/rating list, filters, sorting, pagination and idempotency;
- summary counts and query budget;
- personal markers without N+1;
- Continue Watching current/next/replay/inaccessible semantics;
- progress session, monotonic sequence, completion thresholds and hierarchy denial;
- history pagination, deletion, clear, owner 404 and sync publications;
- Web mutation becomes visible through API/offline pull.

### 17.4 API/OpenAPI tests

- no URL/envelope/error regression for existing endpoints;
- summary schema/security/cache;
- list query validation and pagination limits;
- abilities and verified matrix;
- OpenAPI operation IDs, parameters, schemas, security and response codes;
- no raw URL, token hash, user ID or stack trace leakage.

### 17.5 UI/browser tests

- Russian labels, single `h1`/`main`, noindex and canonical behavior;
- keyboard form submission, focus, error association and loading state;
- header guest/user/admin navigation;
- responsive geometry at 390, 768 and 1440 widths;
- no horizontal/internal overflow;
- touch targets at least 44×44;
- console/network errors absent;
- real registration → verification notice → profile → library → logout flow;
- authenticated player progress → Continue Watching flow;
- `npm run build`.

HTTP notification tests use Notification/Mail fakes. External HTTP in catalog/playback tests uses `Http::fake()` and `Http::preventStrayRequests()` where applicable.

## 18. Документация

Поведение обновляется в владельцах тем из `docs/README.md`:

- `README.md` — overview/quick start;
- `docs/authorization.md` — Web/API auth and owner matrix;
- `docs/api.md` — additive API contract;
- `docs/frontend.md` — Livewire routes/components and browser behavior;
- `docs/DATA_RELATIONS.md` — unchanged one-user-profile ownership and indexes;
- `docs/UI_STANDARDS.md` — только если появляется новое устойчивое UI rule;
- `CHANGELOG.md` — единая запись изменения.

Управляемые `project-docs` блоки вручную не редактируются. После тематической документации запускается `php artisan project:docs-refresh` и проверяется generated diff.

## 19. Этапы поставки

1. Общие account services и regression tests существующего API.
2. Web routes, registration/login/logout и header navigation.
3. Verification, forgot/reset и session invalidation.
4. Profile/security/devices/delete account.
5. Library query, summary, filters and API contract.
6. Livewire library UI and verified mutation parity.
7. OpenAPI/docs and migration/index review.
8. Focused tests, full PHPUnit, Pint, frontend build and Playwright QA.

Каждый этап заканчивается focused green tests и отдельным commit на существующей `main`. Feature branches, worktrees и PR branches не создаются по проектному `AGENTS.md`.

## 20. Критерии приёмки

Работа завершена, когда:

- новый пользователь может зарегистрироваться, войти, подтвердить email, восстановить пароль и выйти через web UI;
- пользователь может просмотреть/изменить профиль и пароль, управлять API-устройствами и удалить аккаунт;
- private route корректно возвращает гостя на login и затем intended URL;
- verified пользователь управляет watchlist/rating/progress/history через Livewire;
- неподтверждённый пользователь читает свои данные, но не выполняет catalog state mutations;
- Web и API наблюдают одно и то же owner state и offline sync events;
- существующий API v1 остаётся совместимым;
- private data не кешируется публично и не раскрывается другому пользователю;
- focused и полный PHPUnit проходят;
- Pint не оставляет style changes;
- Vite/Tailwind build проходит;
- Playwright подтверждает desktop/tablet/mobile auth and library flows без console/network/layout ошибок;
- тематическая документация, OpenAPI и changelog соответствуют фактическому коду;
- все разрешённые изменения закоммичены на `main`, рабочее дерево чистое.
