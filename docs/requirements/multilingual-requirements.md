# Постоянные multilingual-требования

Обновлено: 19.07.2026

Этот документ дополняет владельцев [`architecture.md`](../architecture.md), [`frontend.md`](../frontend.md), [`administration.md`](../administration.md) и [`caching.md`](../caching.md) только требованиями сопровождения переводов.

Каждый будущий Codex prompt обязан считать multilingual integration обязательной, даже когда пользователь не повторяет это требование.

- Всегда проверяется и переиспользуется текущая translation architecture; второй translation system не создаётся.
- Каждый поддерживаемый locale сохраняется. User-facing strings не hardcode-ятся, а каждый новый interface key одновременно добавляется во все supported locale.
- Сохраняются текущий PHP/JSON format, UTF-8, placeholders, pluralization и translation-key stability. Active/fallback locale выбираются безопасно и предсказуемо.
- Переведённые labels никогда не хранятся как database status/identity, route identity или cache identity.
- Interface locale отделён от metadata language, original audio language, preferred audio language, subtitle language, translation studio, region и country.
- Dates, times, numbers, currencies, plural forms, SEO metadata и accessibility labels локализуются через канонические formatters/translations.
- Locale сохраняется при Livewire hydration. Locale switch сохраняет текущий совместимый route и только безопасный state; translated joins не дублируют database rows.
- Read/query boundary загружает только active и fallback translations, если feature owner не доказывает другой bounded workflow.
- User-generated content, advertiser content и legal submissions автоматически не переводятся. Editorial translations требуют документированного human review/publication workflow и не называются проверенными без него.

- Обновления dependencies не сокращают набор поддерживаемых locale.
- Locale-файлы остаются синтаксически валидными после package/framework changes.
- Изменения загрузки переводов сохраняют PHP- и JSON-translation behavior; package translations не могут молча перекрывать project wording.
- Locale identifiers не меняются без полного migration/compatibility layer.
- После framework- или `intl`-related update проверяются dates, numbers, currency, pluralization и validation messages.
- JavaScript localization остаётся совместимой с Vite и Livewire navigation; браузеру передаётся только необходимый словарь.
- Обновлённые validation rules сохраняют локализованные сообщения.
- Mail и notifications сохраняют locale пользователя.
- Обновления administration packages сохраняют переведённую навигацию и permission semantics.
- После major framework, Livewire, validation, mail, notification или frontend upgrade обязателен translation completeness audit всех поддерживаемых locale, ключей, placeholders и plural forms.
- Operational UI, когда он реально существует, переводит system status, database/cache/session/storage/mail/provider/queue/scheduler/service-worker/backup/restore/deployment/rollback/incident, loading/empty/error и accessibility labels во всех поддерживаемых locale. В persistence/API/audit остаются только стабильные codes (`healthy|degraded|unavailable|misconfigured|not_installed|unknown`), не переведённый текст.
- Interface locale не становится media-language, provider, status, cache или persisted identity.
- После крупного cross-system изменения обязателен полный translation completeness audit. Он подтверждает наличие каждого supported locale и каждого нового key, совпадение placeholders и pluralization structures, отсутствие duplicate keys/raw rendered keys/hardcoded user-facing text, запрет translated identity, рабочий fallback locale, сохранение locale при Livewire hydration и валидность localized routes/`hreflang`.
- Тот же audit отдельно покрывает search без duplicate translated records, administration, email, notifications и translated accessibility labels. User-generated, advertiser-created и legal-submission content автоматически не переводится.
