# Resumable checkpoint объединения сезонного семейства Seasonvar

Дата: 20.07.2026

## Подтверждённая причина

Production group `#43428` run `#964` применила 30 prepared pages за 11 секунд, затем дважды оставалась внутри `SeasonvarTitleMerger::mergeForCanonicalSlug()` до принудительного завершения worker по `timeout=900`. Exact duplicate содержит 29 сезонов, 930 эпизодов и 957 media; канонический title содержит 29 сезонов, 930 эпизодов и 979 media. Все изменения merge обёрнуты в одну транзакцию, поэтому timeout откатывает весь полезный прогресс.

Увеличение timeout отклонено: размер семейства не ограничен, а долгий SQLite writer ухудшает доступность. Per-page apply checkpoint не решает эту фазу, потому что merge начинается уже после применения страниц.

## Решение

Существующий `SeasonvarTitleMerger` сохраняет одну canonical identity и прежние доменные merge services, но делает targeted family merge resumable:

1. все duplicate seasons, кроме последнего, полностью объединяются с canonical season в отдельных транзакциях;
2. удаление каждого такого duplicate season является durable checkpoint;
3. ошибка или timeout откатывает только текущий season;
4. последний duplicate season, title-level retarget, relations, aliases, ratings, reviews, user data, slugs, collections, tags и удаление duplicate title фиксируются одной финальной транзакцией;
5. поэтому crash после последнего season не оставляет title без общего season hash, который targeted retry уже не способен обнаружить;
6. retry заново обнаруживает только оставшиеся duplicate seasons, а duplicate без seasons из legacy group безопасно проходит сразу в финальную транзакцию;
7. global merge использует ту же bounded boundary и не создаёт второй merger.

Title loading больше не гидратирует episodes всего семейства заранее: текущий season загружает свои episodes по требованию, а relation освобождается после успешного checkpoint. Это удерживает memory boundary пропорциональной одному season, не меняя identity или порядок merge.

Публичные routes, schema, queue payload, cache keys, source/media identity и translations не меняются. Частично обработанный duplicate остаётся существующим title до завершения всех его seasons; уже перенесённые seasons доступны через canonical title и не теряются.

## Проверка и rollback

Laravel Boost version-aware documentation для установленного `laravel/framework 13.20.0` подтверждает автоматический commit/rollback `DB::transaction()` и public `attempts` retry contract; runtime internals и ручное управление transaction state не используются.

Первый RED‑тест принудительно ломает удаление второго duplicate season: первый обязан остаться перенесённым после exception, второй — остаться у duplicate. Второй RED ломает удаление duplicate title после единственного season и требует, чтобы последний season откатился вместе с title-level финализацией; иначе retry потерял бы обнаруживаемость duplicate. Повторы без triggers должны завершить merge. Existing merge/search/import tests подтверждают compatibility.

Rollback code-only, но выполнять его во время незавершённой production group нельзя: прежняя монолитная транзакция снова потеряет прогресс следующего timeout.
