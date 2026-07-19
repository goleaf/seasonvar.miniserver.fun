# Livewire `#[Async]` — аудит параллельных actions

Дата: 20.07.2026

## Контекст

Livewire 4 `#[Async]` запускает action немедленно и параллельно другим запросам, не помещая работу в queue. Возможность предназначена для fire-and-forget side effects, результат которых не меняет страницу. Аналогичный modifier `.async` может включить такой режим только для отдельного вызова. Официальная документация прямо предупреждает о races и потерянных обновлениях при изменении component state.

## Решение

- Сохранить нулевой inventory `#[Async]` и `.async`: текущие actions валидируют и сохраняют данные, меняют status/form/pagination/player/import state, зависят от ответа либо должны соблюдать транзакционный порядок.
- Аналитика и fire-and-forget external integration в текущем Livewire UI не реализованы; не создавать фиктивный event только ради применения attribute.
- Queue jobs, post-commit notifications и конечный polling остаются своими application boundaries и не заменяются параллельным HTTP-action.
- Новый async action допустим только для идемпотентного pure side effect без UI state, ordering dependency и trusted client payload; он требует явного failure/observability contract.

## Cross-feature impact

Authentication, authorization, validation, translations, caching, search, notifications, SEO, privacy, mobile, administration, audit, imports, premium, regional/legal access, routes, schema, queue, dependencies и production services не меняются. Аудит предотвращает гонки, не изменяя runtime.

Rollback: удалить characterization test и уточнение документации; данных и runtime-кода для отката нет.
