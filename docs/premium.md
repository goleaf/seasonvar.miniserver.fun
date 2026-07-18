# Premium-доступ и биллинг

## Назначение и подтверждённое состояние

Этот документ — единственный владелец контрактов Premium, entitlement, планов, оплаты, промокампаний и provider events. Соседние документы описывают только точки интеграции и ссылаются сюда.

Аудит Task 22 от 18.07.2026 подтвердил, что до реализации в проекте не было:

- таблиц, маршрутов или сервисов Premium, subscription, payment, invoice, refund, dispute, coupon либо billing provider;
- поля `premium`, срока Premium, lifetime-флага, роли или permission, которые давали бы пользователю Premium;
- платёжного SDK, provider adapter, provider secret, product/price mapping, валюты или реальной цены;
- recurring billing, trial, grace policy, отмены, resume, refund API, invoice/receipt, tax collection, hosted billing portal либо account merge workflow;
- Premium-ограничений качества, источников, рекламы, скачиваний, комментариев, профиля или поддержки;
- выделенной модели региона/лицензии, которая могла бы разрешать территориальный доступ.

Поэтому текущая production-safe конфигурация намеренно имеет пустые `premium.providers` и `premium.supported_currencies`, не создаёт публичные тарифы и не показывает способ оплаты, цену, скидку, счёт, дату продления, автопродление или недоказанное преимущество. `/premium` честно сообщает о недоступности покупки. Billing boundary готов к подключению только реального provider adapter и реальных plan records отдельным проверяемым изменением.

## Каноническая архитектура

Источник истины для доступа — строки `premium_entitlements`. `PremiumAccessResolver` один раз на request собирает `PremiumAccessSummary`; UI, middleware или будущая policy должны запрашивать resolver/`PremiumFeature`, а не читать пользовательский boolean, provider status, session, query parameter или дату в Blade.

Основной поток:

```text
PremiumPlanQuery -> CreatePremiumCheckout -> hosted provider
                                      |
raw signed webhook -> gateway adapter -> PremiumBillingReconciler
                                      |
               payment/subscription/refund/dispute records
                                      |
                    PremiumEntitlementService
                                      |
        resolver invalidation + audit + notification
```

Границы ответственности:

- `PremiumFeatureRegistry` разрешает только реально поддерживаемые feature codes;
- `PremiumPlanQuery` выбирает активный публичный неlegacy plan и проверяет его цену, валюту, region allowlist, entitlements и provider capability;
- `Money` хранит integer minor units и ISO-код, сравнивает без float и использует ICU currency fraction digits только для локализованного отображения;
- `PremiumPaymentGatewayRegistry` содержит только явно зарегистрированные adapters;
- `CreatePremiumCheckout` создаёт локальный snapshot и вызывает hosted checkout;
- `PremiumWebhookResponder` передаёт adapter необработанный body и headers для signature verification;
- `PremiumBillingReconciler` является единственным processor подтверждённых provider events;
- `PremiumEntitlementService` выдаёт, продлевает и адресно отзывает access;
- `PremiumPromotionService` управляет campaign/coupon redemption;
- `PremiumAccountQuery` готовит owner-only UI и пагинацию без provider calls;
- `PremiumAccountService` готовит privacy export и защищает удаление account с незавершённой recurring relationship;
- `PremiumAuditService` пишет неизменяемые безопасные события без secret payload;
- `PremiumNotificationService` дедуплицирует database notifications;
- `PremiumSchema` обеспечивает безопасный inactive fallback до применения additive migration.

Provider objects, tokens, customer IDs и webhook payload не сериализуются в публичные Livewire properties. Blade получает только подготовленные массивы/DTO и ничего не вычисляет.

## Entitlement identity, типы и источники

Единственный активный feature code сейчас — `premium_access`. Registry сознательно не содержит `premium_quality`, `premium_sources`, `downloads`, `reduced_ads`, `premium_profile_badge`, `premium_comment_features` или `premium_support`: у портала нет подтверждённого Premium-поведения для этих возможностей.

Поддерживаемые source codes:

- `subscription` — только период, подтверждённый успешным provider payment;
- `one_time_purchase` — фиксированное число дней из immutable plan snapshot;
- `lifetime_purchase` — явная запись `is_lifetime=true`, `ends_at=null`;
- `manual_grant` — авторизованная ручная выдача;
- `promotion` — идемпотентная активация действующего купона;
- `account_migration` — сохранение проверенного исторического доступа;
- `support_compensation` — адресная компенсация поддержки.

Trial source и публичный trial plan отсутствуют, потому что нет provider/policy и защиты от повторного использования. Их нельзя рекламировать или создать через UI. Promotion даёт отдельный фиксированный entitlement и не изображает бесплатную оплату.

Каждое применение имеет уникальный `application_key = SHA-256(feature + source + trusted identity)`. Разные источники не перезаписывают друг друга. Активность требует `starts_at <= now`, отсутствия `revoked_at` и либо `is_lifetime`, либо `ends_at > now`.

Правила сочетания:

- lifetime остаётся активным после окончания или отмены subscription;
- фиксированная выдача начинается с более позднего из текущего активного expiry того же feature и server time, поэтому не сокращает доступ;
- provider period сохраняется точно и не строится из локального предположения о месяце;
- promotion/manual grant могут сосуществовать с платёжной выдачей;
- полный refund/chargeback отзывает только entitlements, связанные с соответствующим payment;
- административный revoke разрешён только для administrative/promotion source и не затрагивает посторонние записи;
- partial refund сохраняет entitlement до отдельного подтверждённого business rule;
- resolver возвращает максимальный expiry активных записей, `null` для lifetime и явные признаки manual/subscription/grace/cancellation.

Время хранится в UTC: application timezone — `UTC`, Eloquent timestamp casts используют `CarbonImmutable`, интерфейс форматирует даты в timezone пользователя.

## Plans, pricing и валюты

`premium_plans.code` — стабильная языконезависимая identity. Переводятся только `premium.plans.<code>.name|description`; код, type, duration, provider IDs, amount, currency и entitlement codes не переводятся.

Реализованные plan types:

- `one_time_duration`: однократная hosted payment, обязательный `duration_days`, без автопродления;
- `recurring_subscription`: только adapter с capability `recurring_checkout`, entitlement ровно на provider-confirmed period;
- `lifetime`: однократная hosted payment, explicit non-expiring entitlement.

Trial/promotional plan types не создаются; бесплатные кампании живут в отдельном promotion domain. Public query требует одновременно `is_active`, `is_public`, `is_legacy=false`, положительный `amount_minor`, allowlisted currency, реальный provider/price mapping, уникальный поддерживаемый feature set, согласованные duration/billing interval/region fields, редакционные name/description во всех поддерживаемых locales и capability `hosted_checkout` плюс capability type. Legacy plans остаются в истории, но не продаются.

Canonical pricing source — database snapshot, сопоставленный с `provider_product_id`/`provider_price_id`. Browser отправляет только plan code. Checkout копирует exact `amount_minor`/`currency`, а webhook обязан прислать точно совпадающую пару. Исторические payment amounts не пересчитываются после изменения плана.

`supported_currencies` — server allowlist, независимый от locale. Интерфейсный язык не меняет валюту. Валюта — три заглавные ISO-буквы; расчёты и сравнения целочисленные. Публичный plan не появится, пока реальная валюта и provider не зарегистрированы.

Plan comparison показывает только type, duration/interval, точную локализованную цену и registry-backed feature list. В системе нет «лучшего тарифа», выдуманной экономии, зачёркнутой цены или countdown.

## Checkout и safe return

Checkout доступен только verified authenticated account. `PremiumPricingPage` сохраняет только validated plan code и внутренний intended URL через существующую authentication boundary; после входа plan/price проверяются заново.

`CreatePremiumCheckout`:

1. проверяет verified account, формат request UUID, purchasable plan, provider/capabilities и текущий Premium summary;
2. не позволяет повторно покупать lifetime и не создаёт вторую незавершённую recurring relationship;
3. под user row lock создаёт уникальный idempotency key и не допускает второй живой checkout того же plan;
4. сохраняет локальный snapshot plan/user/amount/currency/locale/expiry;
5. передаёт gateway только локальный checkout и allowlisted internal success/cancel URLs;
6. принимает только HTTPS redirect без credentials, нестандартного порта и с точным host из `premium.providers.<code>.checkout_hosts`; provider expiry не может выйти за локальный checkout TTL больше чем на пять минут;
7. не выдаёт entitlement до trusted provider event.

Metadata contract adapter ограничен opaque local checkout reference и необходимыми stable references. Password, session/token, watch history, payment credentials, provider secrets и пользовательские предпочтения передавать запрещено.

`/premium/return/{checkout}` и локализованный аналог owner-constrained, private, `noindex` и `no-store`. Browser query `result=cancelled` меняет только сообщение. Ни success redirect, ни cancel redirect не изменяют payment или entitlement. Повторное открытие показывает локальный reconciled status; задержка webhook отображается как pending.

## Payment provider и webhook trust boundary

Provider adapter реализует `PremiumPaymentGateway`: стабильные `code()`/`environment()`, capabilities, hosted checkout и `verifyAndParseWebhook(rawBody, headers)`. Криптографическую проверку следует выполнять официальным SDK провайдера внутри adapter до создания `PremiumProviderEventData`; самописная подпись и отключение validation запрещены.

`POST /billing/webhooks/{provider}`:

- не использует browser CSRF только для этого точного provider route, но имеет отдельный throttle и 256 KiB payload limit;
- возвращает JSON `no-store`, 404 для незарегистрированного provider, 400 для invalid signature/event, 500 для retryable internal failure;
- сверяет adapter environment и normalized event environment;
- не рендерит HTML, не принимает browser session и не возвращает exception detail.

Provider event имеет unique `(provider_code, provider_event_id)`, SHA-256 raw payload, environment, state, attempts и safe error category. Повтор с тем же ID и payload является логически no-op; другой payload отклоняется. Processing идёт в transaction с row locks, states `received|processing|processed|ignored|failed`; failed event допускает безопасный повтор. Notification/audit/entitlement имеют собственные deterministic identities.

Поддерживаемые normalized event types: `payment.succeeded`, `payment.failed`, `subscription.updated`, `refund.succeeded`, `refund.failed`, `dispute.opened`, `dispute.closed`, `chargeback.created`. Неизвестный подписанный event фиксируется как ignored. Provider-specific status обязан быть отображён adapter на стабильный enum.

Out-of-order protection сравнивает `occurred_at` с `provider_updated_at`; более старое событие не перезаписывает новое. Subscription/refund/dispute без локального trusted payment dependency завершается retryable failure, а не создаёт вымышленную связь. Adapter может дополнительно запросить canonical provider object до нормализации, когда его API это поддерживает.

## Payments, subscriptions и жизненный цикл

Payment statuses: `created`, `pending`, `requires_action`, `succeeded`, `failed`, `cancelled`, `refunded`, `partially_refunded`, `disputed`, `chargeback`. Subscription statuses: `pending`, `trialing`, `active`, `past_due`, `grace_period`, `cancellation_scheduled`, `cancelled`, `expired`, `unpaid`, `suspended`. В БД остаются codes, UI показывает переводы.

Успешная one-time payment создаёт payment один раз и продлевает каждое разрешённое entitlement от более позднего current expiry/now. Lifetime создаёт `ends_at=null`. Успешная recurring payment требует provider subscription ID, period start/end и создаёт ровно один period entitlement на payment/feature. Renewal происходит только новым `payment.succeeded`; ожидание автопродления ничего не продлевает.

`payment.failed` записывает failure и notification, но не создаёт новый период и не отзывает ранее оплаченный действующий access. `subscription.updated` обновляет только существующую subscription, cancellation flag и provider-provided grace end. Grace существует только при явном конечном `grace_ends_at`; локальная система его не придумывает и не продлевает.

Cancellation-at-period-end не отзывает оплаченный period entitlement раньше `ends_at`. Immediate cancellation, resume, payment-method change, upgrade/downgrade и proration не показаны: текущий registry не содержит provider capabilities/actions и business policy. Их следует добавлять к тому же gateway/service contract после подключения реального provider, без GET mutation и без второго subscription.

## Refunds, disputes, invoices и billing details

Provider-confirmed refund хранит exact amount/currency и unique refund identity. Несколько confirmed refunds суммируются, но не могут превысить original payment. Только полный refund переводит payment в `refunded` и адресно отзывает связанные payment entitlements; partial ставит `partially_refunded` и не сокращает срок без утверждённой политики. Failed refund не может перезаписать succeeded.

Dispute хранит private provider identity/status/amount и связывается с payment. Обычный dispute отмечает payment как `disputed`; `chargeback.created` отзывает только entitlements этого payment. Account не блокируется автоматически, unrelated lifetime/manual/promotion доступ не стирается, доказательства спора публично не показываются.

Refund-request action, invoice/receipt access, hosted billing portal, tax и billing-address collection не реализованы, потому что provider и юридическая политика отсутствуют. UI не показывает эти controls. При будущем подключении нужны ownership policy, recent authentication, provider confirmation, pagination, protected provider-hosted URL и минимальная retention; card number, CVV и raw credentials никогда не сохраняются.

## Promotions и coupons

Promotion — stable code, фиксированный положительный срок, optional UTC window, total limit, per-user limit и active flag. Coupon содержит только HMAC-SHA-256 code hash с `APP_KEY`, безопасный last-four hint и optional redemption limit. Полный code показывается администратору один раз после генерации и не восстанавливается из БД.

Redemption требует verified account, нормализует code, работает под user/promotion row locks, проверяет campaign/coupon active state, dates и все limits, имеет unique user/coupon и idempotency key. Он создаёт отдельный `promotion` entitlement и audit event; повтор не продлевает доступ. Coupon attempts и administrative actions ограничены rate limiter.

Текущие coupons дают только Premium period и не уменьшают денежную цену. Percentage/fixed discounts, plan applicability, minimum amount, region/currency discount и provider coupons не реализованы, поэтому интерфейс не показывает fake discount или crossed-out price.

## Premium features, player и free fallback

Registry содержит только общий `premium_access`, используемый для статуса account. Он не меняет `CatalogEntitlementService`, playback source resolver, `CatalogPlaybackSourceResponder`, download responder, комментарии, отзывы, профиль, рекламу, технические обращения или Help Center.

Следствия:

- Premium не обходит publication/audience/availability или будущие regional/licensing rules;
- quality/source URLs не становятся доступны из-за Premium;
- authenticated direct-file download остаётся прежней legal/technical delivery boundary и не рекламируется как Premium;
- портал не обещает удаление внешней рекламы, badge, дополнительные комментарии, moderation privilege или приоритет поддержки;
- free-user browsing, player fallback, progress, bookmarks, collections, comments, reviews и recommendations не меняются;
- expiry/revoke не удаляет пользовательский контент или историю.

Если реальная Premium-функция появится, её stable code сначала добавляется в `PremiumFeature`, registry и серверную policy/source boundary с free fallback и region rule; только затем — в plan и переводы.

## Account, privacy и notifications

В `/settings/premium` отображаются owner-only summary, источник, период/lifetime/cancellation/grace, реальные entitlements, paginated payment history и coupon form. Customer/subscription/payment provider IDs, private notes и audit context не передаются. Private account middleware добавляет `noindex, nofollow` и `private, no-store`.

Account export включает safe entitlement, subscription, payment/refund и redemption history с stable codes, суммами и валютами, но без provider customer IDs, payment tokens, payload hashes, private notes и secret URLs. Account deletion после strong password confirmation блокируется, если есть active/pending/past-due/grace/cancellation-scheduled recurring relationship: оператор должен сначала безопасно урегулировать provider billing. При разрешённом удалении identity-sensitive grants удаляются по существующему lifecycle, а payment/subscription history сохраняет nullable user reference для retention.

В проекте нет account merge или social provider-customer domain. Поэтому Premium не переносится и периоды не складываются автоматически; два accounts с billing relationship требуют будущего отдельного review workflow. `account_migration` предназначен только для подтверждённой административной коррекции, не для client-driven merge.

Database notifications используют type `premium.activity`, stable event code, public resource reference и optional expiration. Deterministic notification UUID исключает повтор для одного meaningful event. Реализованы activation/payment success/failure/renewal/cancellation/refund/dispute/manual grant/revoke/promotion события. Payload не содержит card details, customer ID, webhook data или private note. Точного scheduled expiry reminder нет: проект не получает новую обязательную очередь/cron.

## Administration, authorization и аудит

`/admin/premium` требует auth, входной `view-premium-administration` и отдельные action gates:

- `manage-premium-grants` — поиск account, duration/lifetime grant и exact-source revoke;
- `manage-premium-promotions` — campaigns и one-time coupon code generation;
- `view-premium-billing-audit` — safe audit view и provider health;
- `reconcile-premium` — зарезервированная отдельная boundary для будущего explicit reconciliation control.

Просмотр `/admin/premium` использует существующий `SEASONVAR_IMPORT_ADMIN_EMAILS`. Опасные capabilities дополнительно требуют отдельных comma-separated allowlists: `PREMIUM_GRANT_ADMIN_EMAILS`, `PREMIUM_PROMOTION_ADMIN_EMAILS`, `PREMIUM_BILLING_AUDIT_EMAILS` и `PREMIUM_RECONCILIATION_ADMIN_EMAILS`. Они пусты по умолчанию, поэтому общий администратор не получает billing mutation или audit автоматически. Каждый Livewire action повторно authorizes, rate-limits и валидирует typed state. User ID, source, lifetime, dates, reason, private note и coupon rules не принимаются из публичного account UI.

Manual grant требует stable reason: `support_compensation`, `partner_access`, `migration_correction`, `staff_policy`; testing разрешён только вне production. Revoke указывает конкретный public entitlement ID и сохраняет unrelated sources. Payment entitlement нельзя отозвать ручным action.

Audit хранит actor/user, stable action/resource, unique idempotency key, UTC time и bounded scalar context. Password, token, signature, raw payload, card data, адрес и полный coupon не записываются. Обычный администратор не видит provider secrets; provider summary показывает только зарегистрированные codes, а environment остаётся закрытой server configuration.

## Cache, performance и schema

Premium не создаёт второй cache domain. Public plans пока читаются локально и имеют низкий объём; user summary memoized только внутри request. Payment history, invoices, refunds, disputes, customer/subscription identity, checkout и webhook payload никогда не попадают в shared cache. Поэтому active status не переживает expiry и не может утечь другому пользователю. Все mutations вызывают resolver `forget()` в текущем request.

При будущем public-plan cache ключ обязан включать locale, trusted region, allowlisted currency, plan/promotion/provider/feature version. User cache, если он станет измеримо нужен, обязан включать user ID/version/region и иметь TTL не дольше ближайшего `ends_at`/`grace_ends_at`; email/provider IDs/tokens в ключ запрещены. Инвалидация адресная, полный cache flush запрещён.

`PremiumAccessResolver` загружает все активные entitlement features одним indexed query и eager-load только нужных subscription fields. Settings history пагинируется по 15 строк с deterministic order `(user_id, created_at, id)`, которое обслуживает `premium_payments_user_time_idx`; сумма возврата читается из reconciled payment snapshot. Provider API на render не вызывается; только checkout, provider mutation или explicit reconciliation могут обращаться наружу.

Additive migration создаёт 12 таблиц: plans, promotions, coupons, checkouts, subscriptions, payments, refunds, disputes, redemptions, entitlements, provider events и audit. Unique constraints защищают plan/provider mappings, checkout/event/payment/subscription/refund/dispute identities, coupon use и entitlement application. Composite indexes соответствуют resolver, owner history, event retry и admin audit queries. SQLite и текущая database abstraction поддерживаются; старые migrations и user columns не изменяются.

До миграции legacy normalization не требуется: подтверждённых legacy premium rows/booleans/provider events не найдено. Если исторические данные появятся до включения constraints, нужен read-only duplicate report и idempotent mapping через `account_migration`; payment history фабриковать или valid access отзывать нельзя.

Rollback migration удаляет только новые пустые/явно выведенные Premium tables в обратном FK-порядке. На production rollback после реальных платежей запрещён без экспорта, отключения checkout/webhook и отдельного retention plan.

## Routes, localization, SEO и представление

Маршруты:

- public `GET /premium` и `GET /{locale}/premium`;
- private owner `GET /premium/return/{checkout}` и localized alias;
- provider `POST /billing/webhooks/{provider}`;
- private `GET /settings/premium` через существующую settings page;
- admin `GET /admin/premium`.

Ни один legacy Premium route не существовал, поэтому alias не нужен. Checkout IDs не входят в canonical/OG/structured metadata. Private return/settings/admin и webhook исключены из sitemap и structured data. Public pricing page `noindex`, пока нет purchasable plan; sitemap также её не публикует. После реального provider/plan deployment SEO inclusion требует актуальной locale canonical/hreflang policy и реальных offer values — пользовательские скидки и private checkout URL в schema запрещены.

Новые UI-строки находятся в `lang/ru/premium.php` и `lang/en/premium.php`; stable codes не переводятся. Plan editorial keys добавляются в оба locale до публикации plan. Невидимый raw key считается deployment error. Price использует locale only for formatting, не для выбора валюты.

Livewire pages не используют Volt, `@php`, inline CSS, inline billing JavaScript или provider objects. Pricing/settings/admin cards используют существующие Tailwind components, responsive grids, visible focus, достаточные touch targets, `wire:loading`, disabled duplicate actions, `role=status|alert`, responsive lists без вложенной прокрутки и длинные локализованные labels. Все mutations имеют localized validation/error states.

## Provider onboarding и rollout

Подключение реальной оплаты выполняется отдельным reviewable change:

1. установить официальный SDK только после явного согласования production dependency;
2. реализовать adapter с official signature verification и capabilities;
3. добавить secrets только в environment/config, не в БД, UI, Markdown или logs;
4. зарегистрировать adapter в `PremiumPaymentGatewayRegistry` и проверить environment;
5. задать allowlisted currencies, точные `checkout_hosts` и реальные plan/provider mappings, не меняя исторические rows;
6. настроить provider webhook на точный route и проверить test/live isolation;
7. выполнить additive migration/backup, static schema/route inspection и signed event replay rehearsal;
8. опубликовать plan только после end-to-end payment/refund/cancellation policy verification;
9. включить public SEO/sitemap только когда цена и доступность стабильны;
10. контролировать failed provider events, duplicate identities и entitlement discrepancies.

Не добавляются обязательные queues, scheduler, Supervisor или polling. Entitlement correctness синхронна с transaction обработки webhook. Provider retry является механизмом доставки; failed event остаётся наблюдаемым.

## Известные ограничения

- платёжный provider, checkout method, цены и валюты не настроены;
- нет пользовательских cancel/resume/refund/payment-method/invoice controls;
- нет automatic renewal без provider events;
- trial, recurring grace policy, upgrade/downgrade, proration и tax collection отсутствуют;
- coupons дают access period, но не денежную скидку;
- нет account merge billing workflow;
- нет region resolver, поэтому restricted plan fail-closed, а Premium никогда не трактуется как region bypass;
- нет Premium quality/source/download/ad/badge/comment/profile/support features;
- нет scheduled expiry reminders без существующей фоновой инфраструктуры;
- admin replay/reconciliation остаётся отдельным gate/service extension и не представлен dead control.

## Ручная проверка и acceptance

- [x] Проверены все существующие Markdown, routes, guards, User, settings, player/download, notifications, deletion/export, localization, caches, sitemap и provider dependencies.
- [x] Подтверждено отсутствие competing/legacy Premium и provider configuration.
- [x] Active access разрешается только через `PremiumAccessResolver` и explicit entitlements.
- [x] Duration extension не сокращает access; lifetime explicit; sources не перезаписываются.
- [x] Browser не выбирает amount/currency/user/provider/status и return не подтверждает payment.
- [x] Gateway contract требует raw signed webhook; event/environment/amount/currency/ownership проверяются.
- [x] Event/payment/subscription/refund/dispute/entitlement operations имеют database idempotency/uniqueness.
- [x] Старые events не перезаписывают новые; missing dependencies остаются retryable.
- [x] Full refund/chargeback отзывают только linked entitlements; partial не применяет выдуманное правило.
- [x] Coupon limits проверяются в transaction; raw codes не хранятся.
- [x] User state не кэшируется глобально и не переживает expiry.
- [x] Private routes noindex/no-store, отсутствуют в sitemap; public pricing без plan noindex.
- [x] RU/EN labels и prepared Livewire states присутствуют; fake controls/features отсутствуют.
- [x] Account export безопасен; deletion не оставляет recurring billing молча.
- [x] Admin grants/revokes/promotions/audit отдельно authorized, rate-limited и audited.
- [x] Additive migration и индексы проверены на disposable SQLite database.
- [x] Targeted PHPStan, PHP syntax, Pint, route/schema/translation inspection выполнены без запуска automated tests.
- [ ] Реальный hosted checkout, подписанный webhook fixture, cancellation/refund API и provider portal проверяются только после подключения фактического provider adapter.
- [ ] Real-device и assistive-technology проверка остаётся release gate; browser smoke не заменяет её.

При отклонении production provider state от локального автоматическое разрушительное исправление запрещено. Reconciliation должен сначала сформировать discrepancy report, затем применить точечную идемпотентную коррекцию с audit; valid historical access и unrelated grants сохраняются.
