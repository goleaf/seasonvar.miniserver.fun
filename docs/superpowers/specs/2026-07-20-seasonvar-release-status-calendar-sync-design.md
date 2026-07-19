# Синхронизация статуса серий Seasonvar с календарём — дизайн

Дата: 20.07.2026

## Цель

Сделать нормализованную строку сезона Seasonvar вида `(19.07.2026 3 серия (Coldfilm) из 8)` постоянным источником фактических событий календаря, ежедневно обновляемым существующим импортёром. Одновременно предоставить безопасный bounded backfill последних 1000 serial URL из конца актуального XML без второй команды, второго pipeline или параллельного глобального импорта.

## Подтверждённая исходная проблема

- Живые страницы `serial-41165` и `serial-49406` содержат дату, номер серии, общее число и перевод.
- `SeasonvarCatalogParser` уже разбирает их в `latest_episode_released_at`, `episodes_released`, `episodes_total`, `translation_name` и `release_status_text`.
- Рабочая база отстаёт от источника, но даже сохранённые наблюдения не заполняют `Episode::released_at` и не создают `ReleaseScheduleEntry`.
- Это не ошибка HTML-парсинга: действующий calendar contract намеренно запрещал преобразовывать сводную строку сезона без явной semantic mapping.

## Семантика

Дата относится к названной в строке серии и фиксирует provider observation. Она не является прогнозом следующей серии и не порождает weekly recurrence.

- `(дата N серия (Студия) ...)` → `translation_release` для серии N и студии.
- Явное `Субтитры`/`Subtitles`/`Subs` → `subtitle_release` для серии N.
- `(дата N серия ...)` без варианта → provider `episode_release` для серии N.
- Импортёр не меняет `Episode::released_at`: поле остаётся датой канонического оригинального выпуска, а provider/translation observation живёт в календарном домене.
- Дата хранится как `exact_date`, без выдуманного времени или timezone shift. Прошедшая/сегодняшняя дата получает `released`, будущая явно указанная дата — `confirmed`.

## Архитектура

### Parser и catalog transaction

Parser DTO и сезонные поля уже достаточны, поэтому schema/parser migration не требуется. После `syncSeasons()` и bulk `syncEpisodes()` `SeasonvarCatalogImporter` передаёт только текущий сезон (`currentSeasonNumber`) в новый `SeasonvarReleaseObservationSynchronizer`.

Synchronizer принимает persisted `CatalogTitle`, `Season` и `SourcePage`. Он прекращает работу, если calendar schema выключена/не готова, дата невалидна, номер серии неположителен, raw status отсутствует либо canonical regular episode с таким номером не существует. Episode выбирается одним bounded query; Blade и client state в этом процессе не участвуют.

### Calendar write

`ReleaseScheduleIdentity` строит существующий stable logical key по event type, title/season/episode и translation. Synchronizer блокирует найденную запись `lockForUpdate`, сохраняет source priority (`editorial`, `portal`, `official`, `trusted_provider` выше `provider`) и `is_locked`.

При material change запись получает:

- `source=provider` и private-safe `source_reference=seasonvar:source-page:{id}` без raw URL;
- canonical title/season/episode IDs и номера;
- `precision=exact_date`, `date_value`, `status`, `released_at` только для фактической прошедшей даты;
- translation label только для `translation_release`;
- `is_public=true`, `notifications_enabled=true`.

Повтор одинакового payload не пишет строку и correction. Изменение даты того же события повышает revision и создаёт `ReleaseScheduleCorrection` с reason `seasonvar_release_status_sync`. Новый номер серии или другая студия имеют другую identity и сохраняют прежний event как исторический факт. Исчезновение сводной строки не отменяет подтверждённую историю автоматически.

Cache invalidation и notification dispatch регистрируются через `DB::afterCommit`. Календарь/home/sitemap/title cache меняются только при material write. Уведомление создаётся только для event внутри `release-calendar.recent_days` либо будущего event; старый backfill остаётся видимым по периоду без запоздалого notification flood.

### Bounded XML backfill

Единственная публичная команда получает option `--sitemap-tail=` с диапазоном `1..1000`. Режим разрешён только как:

```bash
php artisan seasonvar:import --queued --force --sitemap-tail=1000
```

Команда требует discovery и serial scope. Она несовместима с URL, `--no-discovery`, sync/forever/sleep, inventory/status/media-size modes и non-serial page types.

`SeasonvarQueuedImportDispatcher` сохраняет limit в safe run summary, зеркалирует robots/sitemap существующим `SeasonvarSitemapMirror`, сохраняет полную discovery как раньше, затем берёт последние N distinct serial URL из конца `mirror['urls']` с сохранением их XML-порядка. `SeasonvarRefreshPlanner` читает соответствующие `SourcePage` bounded hash chunks и отдаёт их через существующие claim/title-group/finalizer boundaries. Raw URL list не сохраняется в run summary или logs. Global single-flight не меняется: при активном full run новый backfill не создаётся.

После разового backfill постоянные cron/full imports продолжают обновлять эти события при обычном изменении HTML и 24-часовой serial refresh boundary; отдельный scheduler не добавляется.

## Ошибки и восстановление

- Отсутствующая calendar schema: catalog import продолжается без calendar write.
- Неполная/невалидная строка или отсутствующая серия: observation пропускается без выдуманного event.
- Database failure: catalog transaction откатывает catalog и calendar mutation вместе.
- Cache/notification failure: existing safe after-commit boundaries не повреждают committed event.
- Provider correction: same logical key получает revision/correction; locked/higher-priority entry сохраняется.
- Interrupted queued backfill: claims, retry window, watchdog и finalizers остаются существующим recovery path; queue clear/force state rewrite не используется.

## Cross-feature impact

- Affected: importer/parser application boundary, seasons/episodes, release calendar, calendar notifications, calendar/home/title/sitemap cache, queue status and operations docs.
- Preserved: authentication, authorization, privacy, public visibility, locale identity, search ranking, recommendations, playback/media URLs, premium, regional/legal restrictions, user progress/history, administration locks, API shape and public routes.
- No dependency, migration, `.env`, video download, service worker or new production service.

## Проверки

TDD покрывает:

1. Parser real-shape fixture (`3 серия (Coldfilm) из 8`) и range (`1-2 серия`) normalization.
2. Provider translation event creation for exact existing episode.
3. Subtitle classification and translation-less episode event.
4. Missing episode/invalid/incomplete observation skip.
5. Idempotent repeat, date correction, next episode/new translation identity.
6. Manual lock and higher-source preservation.
7. Recent notification vs historical backfill suppression and after-commit cache behavior.
8. Importer prepared-page integration.
9. CLI validation and exact XML-tail page selection through queued claims/groups.
10. Focused importer/calendar tests, broader regression, Pint and docs checks; frontend build only if visitor markup/assets change (not planned).

## Production и rollback

Перед backfill проверяются active global run, live claims, workers, queue health and database backup state. Schema/data migration отсутствует, но 1000-page import пишет catalog/calendar rows, поэтому запуск начинается только после terminal active run и использует current queue capacity.

Code rollback удаляет option, planner selection и synchronizer call. Созданные provider events остаются корректной историей; если их нужно убрать, отдельный reviewed data action скрывает только `source=provider` + `source_reference LIKE seasonvar:source-page:%` с correction/audit, не удаляя сезоны, серии, media или manual/higher-source events. Store-wide cache clear и queue clear не требуются.
