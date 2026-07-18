# Постоянные multilingual-требования

Обновлено: 18.07.2026

Этот документ дополняет владельцев [`architecture.md`](../architecture.md), [`frontend.md`](../frontend.md), [`administration.md`](../administration.md) и [`caching.md`](../caching.md) только требованиями сопровождения переводов.

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
