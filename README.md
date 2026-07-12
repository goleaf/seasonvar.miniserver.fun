# Каталог сериалов Seasonvar

Laravel-приложение для локального каталога сериалов, сезонов, серий, постеров, связей каталога, отзывов и внешних видео-ссылок.

## Версии

- Laravel 13.19 на PHP 8.5.
- Laravel Boost 2.4 и Laravel MCP 0.8 используются для локальной документации и MCP-подсказок.
- Livewire 4.3 используется для интерактивного каталога `/titles`, выбора сезона/серии на карточке тайтла и live-страницы статистики `/stats`.
- PHPUnit 12.5 используется для тестов; Pest в проекте не установлен.

## Основные команды

```bash
composer install
composer setup
composer dev
npm install
php artisan seasonvar:import
php artisan seasonvar:import "https://seasonvar.ru/serial-615--Bez_sleda_pssmtlk-1-season.html" --force
php artisan seasonvar:import --forever
php artisan seasonvar:import --queued
php artisan seasonvar:import --status
php artisan integrations:doctor
php artisan project:docs-refresh
php artisan test --compact
vendor/bin/pint --dirty --format agent
npm run dev
npm run build
```

Полная локальная установка, MCP и правила разработки описаны в `docs/development.md` и `docs/mcp.md`. Внешние интеграции с Google, GitHub и другими MCP/app connectors документируются в `docs/integrations/`.

## Импорт

`seasonvar:import` скачивает карту сайта Seasonvar, сохраняет найденные страницы, обновляет карточки, сезоны, серии, связи, рейтинги, отзывы и видео. Команда продолжает работу после ошибки отдельной страницы, пишет подробные события в базу и может работать постоянно через `--forever`.

Команда защищена от параллельного запуска, постепенно проверяет старые видео-ссылки без статуса доступности, дополняет старые медиа качеством/форматом/стабильным ключом, нормализует статусы уже разобранных страниц и отключает некорректные склеенные ссылки источника.

Видео-файлы на сервер не скачиваются. Импортер хранит внешние URL воспроизведения, качество, формат, перевод, субтитры и состояние доступности.

### Обычный запуск

Полный последовательный импорт в текущем терминале:

```bash
cd /www/wwwroot/seasonvar.miniserver.fun
php artisan seasonvar:import
```

Обновление одной страницы, даже если сохраненный HTML hash не изменился:

```bash
php artisan seasonvar:import "https://seasonvar.ru/serial-615--Bez_sleda_pssmtlk-1-season.html" --force
```

Режим `--forever` предназначен для одного долгоживущего процесса и не нужен при production-запуске через десять Redis workers:

```bash
php artisan seasonvar:import --forever
```

### Параллельный импорт в 10 потоков

Для production используется `php artisan seasonvar:import --queued`. Команда только находит и закрепляет подходящие страницы и ставит jobs в Redis. Десять фоновых workers разбирают эти страницы параллельно.

Первичная подготовка:

```bash
cd /www/wwwroot/seasonvar.miniserver.fun
redis-cli ping
php artisan migrate --force
sudo cp deploy/systemd/seasonvar-import-worker@.service /etc/systemd/system/
sudo systemctl daemon-reload
sudo systemctl enable --now seasonvar-import-worker@{1..10}.service
```

`redis-cli ping` должен вернуть `PONG`. После запуска workers поставить подходящие страницы в очередь:

```bash
php artisan seasonvar:import --queued
```

Проверка workers, очереди, ошибок и журнала:

```bash
systemctl --no-pager --type=service 'seasonvar-import-worker@*'
php artisan seasonvar:import --status
php artisan queue:failed
journalctl -u 'seasonvar-import-worker@*' -f
```

Остановить workers без удаления очереди и claims:

```bash
sudo systemctl stop 'seasonvar-import-worker@*.service'
```

Продолжить обработку сохраненной очереди:

```bash
sudo systemctl start seasonvar-import-worker@{1..10}.service
```

После обновления PHP-кода workers необходимо перезапустить, потому что `queue:work` является долгоживущим процессом:

```bash
php artisan queue:restart
```

Systemd автоматически поднимет завершившиеся процессы. Полная диагностика и ручной foreground-вариант приведены в `docs/deployment.md`.

### Cron: 10 запусков в сутки

Workers должны постоянно работать под systemd, а cron только добавляет в Redis страницы, которые уже разрешено обновить. Откройте crontab пользователя `www`:

```bash
sudo crontab -u www -e
```

Добавьте две строки: первая запускает dispatcher десять раз в сутки, вторая каждые пять минут проверяет backlog и создаёт throttled warning при превышении порога:

```cron
0 0,2,5,7,10,12,14,17,19,22 * * * cd /www/wwwroot/seasonvar.miniserver.fun && /usr/bin/php artisan seasonvar:import --queued >> storage/logs/seasonvar-cron.log 2>&1
*/5 * * * * cd /www/wwwroot/seasonvar.miniserver.fun && /usr/bin/php artisan queue:monitor redis:seasonvar-import --max=5000 >> storage/logs/seasonvar-queue-monitor.log 2>&1
```

Проверить установленное расписание:

```bash
sudo crontab -u www -l
```

Частый cron не означает повторное скачивание страницы десять раз. Атомарный lease не позволяет поставить уже закрепленную страницу второй раз, Redis lock не дает разным сезонным URL одновременно изменять один тайтл, а успешно импортированная страница становится подходящей для новой проверки только после настроенного freshness interval — по умолчанию через 24 часа. При изменении `content_hash` повторно разбираются название, описание, постер, сезоны, серии, связи и внешние видео. Неизмененная страница безопасно пропускается, кроме случаев, когда требуется дозаполнить или перепроверить отсутствующие медиа-данные.

После завершения импорта команда обновляет серверный снимок статистики, который использует live-страница `/stats`.

## Интерфейс

Интерфейс каталога ведется на русском языке. Нельзя добавлять на страницы рекламные или заглушечные описания проекта.

Frontend собирается через Vite 8 и Tailwind CSS 4. Основная точка входа — `resources/js/app.js`; подробности по командам и asset rules описаны в `docs/frontend.md`.

Страница `/titles` — full-page Livewire-компонент. Поиск (`q`), multi-select фильтры, allowlisted `sort`, вид, размер и `page` синхронизируются с URL и browser history; malformed значения безопасно нормализуются, а страница за последней границей восстанавливается автоматически. Публичное состояние содержит только нормализованные скаляры и небольшие массивы, а duplicate-free выдача строится общим `CatalogTitleQuery` на каждом запросе. Значения внутри одной группы объединяются через OR, разные группы — через AND. Поддерживаются годы, типы публикации, актеры, режиссеры, жанры, страны, возрастные рейтинги, production statuses, озвучка/перевод, качество видео и наличие субтитров; актеры и режиссеры ищутся на сервере с ограниченной выдачей.

Страница `/stats` построена на Livewire 4, обновляет видимые блоки примерно раз в 15 секунд через `wire:poll.15s.visible` и читает подготовленный snapshot из cache, чтобы не пересобирать тяжелые агрегаты на каждый browser poll и не опрашивать сервер в скрытой вкладке.

Страница `/titles/{catalogTitle:slug}` сохраняет server-side route binding и статическую metadata-оболочку, а выбор сезона, серии и варианта воспроизведения обслуживает вложенный Livewire 4 компонент. Первый ответ получает только summaries и точные counts сезонов, затем загружает серии и playable media одного активного сезона. Для авторизованного пользователя отдельно хранятся watchlist, оценка и прогресс; primary action продолжает незавершенную серию, открывает следующую после завершенной или начинает первый доступный выпуск. Все действия повторно применяют publication window/audience boundary, а URL-параметры `season`, `episode`, `media`, `variant`, `quality` и `format` остаются shareable.

## Медиа

Видео не скачивается на сервер. В базе сохраняются внешние ссылки, качество, формат, перевод и результат проверки доступности; на странице сериала показываются все найденные варианты.

Плеер не получает сохраненный provider URL из Blade или Livewire. `CatalogPlaybackSourceResolver` повторно проверяет тайтл, сезон, серию и media, выбирает доступный вариант и выдает только короткоживущий signed URL `/playback/{media}`. Endpoint привязан к текущему пользователю, повторяет проверки непосредственно перед выдачей и не принимает произвольный URL от браузера. Provider hosts задаются allowlist `PLAYBACK_ALLOWED_HOSTS`; проверки доступности не следуют редиректам и не буферизуют тело ответа.

Каждый отрендеренный источник получает отдельную browser-session Plyr/HLS. Она сообщает первый play, затем сохраняет bounded progress раз в 30 секунд только во время просмотра и фиксирует позицию при паузе, стабильном завершении перемотки, скрытии вкладки и уходе со страницы. Сервер принимает opaque expiring session token и возрастающий event sequence, повторно проверяет доступность серии/media и атомарно обновляет единственную строку `(user_id, episode_id)`; duplicate, out-of-order и более старые browser sessions не меняют историю. Длительность и процент берутся только из `licensed_media.duration_seconds`, а completion сохраняется необратимо для обычных progress events. Маркеры и ресурсы освобождаются при Livewire morph, `wire:navigate`, `pagehide` и bfcache restore.

Completion rule задаётся `PLAYBACK_PROGRESS_COMPLETION_PERCENT` (по умолчанию 95%) и `PLAYBACK_PROGRESS_COMPLETION_REMAINING_SECONDS` (15 секунд); событие `ended` также завершает выпуск при отсутствии trusted duration. Replay обновляет текущую позицию, не снимая исторический `completed_at`, а primary action продолжает незавершённый повторный просмотр. Отдельного profile model и пользовательского действия «не просмотрено» сейчас нет, поэтому состояние канонически принадлежит user и episode, а completion снимается только будущим явным product action.

Для стабильного обновления каждая запись медиа получает `source_media_key`. Если старые записи были созданы без него, `seasonvar:import` дозаполняет ключи небольшими пакетами.

Если на странице найден HLS master playlist, импорт пробует сохранить отдельные варианты качества для той же серии.

Трейлеры и анонсы сохраняются как медиа карточки или сезона даже тогда, когда у них нет номера серии.

## Laravel 13

Проект уже обновлен и проверен на Laravel 13. Подробности по зависимостям, MCP и проверкам описаны в `docs/upgrade.md`.

## Конфигурация и деплой

Обязательные переменные окружения, безопасные значения `.env.example` и команды `config:cache` описаны в `docs/deployment.md`. Код приложения читает настройки через `config()`, прямой `env()` допустим только в `config/*.php`.

Google Search Console и Google Analytics подготовлены как выключенные read-only интеграции через `.env.example` и `config/services.php`; OAuth/ADC-секреты должны задаваться вне Git.

## CI

GitHub Actions проверяет Composer, npm build, тесты, Pint, dependency audits и доступную статическую проверку PHP-синтаксиса. Команды и кеширование описаны в `docs/ci.md`.

## Git workflow

Работа ведется только в существующей ветке `main`. Не создавайте feature branches, временные ветки, worktree-ветки, PR-ветки или дополнительные `main`-подобные ветки без прямого нового указания пользователя. Перед commit/push проверяйте `git status --short --branch` и не отправляйте изменения из веток, отличных от `main`. Рабочее дерево должно оставаться закоммиченным: версионируемые хуки `.githooks/pre-commit` и `.githooks/pre-push` через `core.hooksPath=.githooks` блокируют частичные коммиты с unstaged/untracked файлами и push с dirty tree.

<!-- project-docs:start -->
## Автоматически обновляемое состояние

- Обновлено командой `php artisan project:docs-refresh`: 12.07.2026.
- Основная карта сайта портала: `https://seasonvar.miniserver.fun/sitemap-index.xml`.
- Совместимый адрес `/sitemap.xml` отдает индекс карты сайта, чтобы поисковые системы получали все разделы карты.
- `public/robots.txt` объявляет стабильный индекс карты сайта без ручного перечисления страниц `sitemap-titles-*` и `sitemap-videos-*`.
- Git-хук `.githooks/post-commit` запускает обновление файлов документации и при изменениях делает отдельный коммит документации; автоматическая отправка в Git включается только через `SEASONVAR_DOCS_AUTO_PUSH=1`.
<!-- project-docs:end -->
