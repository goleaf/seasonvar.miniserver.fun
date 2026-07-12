# Seasonvar queue status: dominant active run

Дата: 13.07.2026

## Проблема

`php artisan seasonvar:import --status` показывает queued run с максимальным ID. При большом backlog более поздние cron-запуски могут добавить небольшие recovery-пакеты, поэтому последний run не отражает реальный прогресс основной очереди. В production run `#5` обрабатывает десятки тысяч страниц, но статус показывает run `#7` с 18 выбранными и 0 обработанными страницами.

## Решение

`SeasonvarQueueStatus` получает running queued runs вместе с количеством их живых claims. Основным считается run с максимальным количеством живых claims; при равенстве выбирается больший ID. DTO дополнительно передаёт число активных queued runs.

Если running runs отсутствуют, статус сохраняет существующее fallback-поведение и показывает последний queued run независимо от его финального статуса.

Консольные подписи явно различают количество активных runs и основной active/last run. Общие queue counters и общее число живых claims не меняются.

## Проверка

- Более старый run с большим числом claims выбирается вместо последнего маленького run.
- DTO возвращает точное число active queued runs.
- При отсутствии running runs показывается последний completed/failed queued run.
- `--status` остаётся read-only и не создаёт import runs или jobs.
