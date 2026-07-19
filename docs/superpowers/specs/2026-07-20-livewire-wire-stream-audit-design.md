# Livewire `wire:stream` — аудит progressive DOM streaming

Дата: 20.07.2026

## Контекст

Livewire 4 может отправлять части содержимого в именованную DOM-цель до завершения одного запроса. `$this->stream()` по умолчанию дописывает части, а `replace: true` или `.replace` заменяет содержимое цели. Официальная документация отдельно предупреждает о несовместимости с Laravel Octane.

В приложении нет чат-бота, генератора текста или другой пользовательской операции, которая уже получает безопасные частичные результаты и должна показывать их до завершения запроса. Импорт выполняется командами и очередями, состояние длительных работ ограниченно опрашивается только пока они активны, проигрыватель получает внешний media source, скачивание передаёт файл отдельным streamed responder, а sitemap/feed используют Laravel streamed responses.

## Решение

- Не добавлять `wire:stream` и `$this->stream()` без измеримого progressive-text use case.
- Не подменять им `response()->stream()` для sitemap/feed/download и не удерживать Livewire-request на время импорта, crawling, media checks или видео.
- Закрепить нулевой application inventory тестом и явно документировать append/replace и Octane compatibility boundary.
- Новый use case обязан определить ограниченный target, escaping/sanitization, cancellation/failure UX, timeout/runtime compatibility и окончательное server state до добавления директивы.

## Cross-feature impact

Authentication, authorization, translations, caching, search, notifications, SEO, player, imports, premium, regional/legal access, administration, routes, schema, dependencies и production services не меняются. Существующие streamed HTTP responders остаются отдельной транспортной границей.

Rollback: удалить только characterization test и уточнение документации; runtime-код не меняется.
