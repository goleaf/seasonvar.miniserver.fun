<?php

declare(strict_types=1);

return [
    'categories' => [
        ['code' => 'getting_started', 'position' => 10, 'ru' => ['slug' => 'nachalo-raboty', 'title' => 'Начало работы', 'description' => 'Основные возможности портала и быстрые ответы.'], 'en' => ['slug' => 'getting-started', 'title' => 'Getting started', 'description' => 'Core portal features and quick answers.']],
        ['code' => 'watching_video', 'position' => 20, 'ru' => ['slug' => 'prosmotr-video', 'title' => 'Просмотр и плеер', 'description' => 'Запуск видео, буферизация, качество, полный экран и автозапуск.'], 'en' => ['slug' => 'watching-video', 'title' => 'Watching and player', 'description' => 'Starting video, buffering, quality, fullscreen and autoplay.']],
        ['code' => 'audio_subtitles', 'position' => 30, 'ru' => ['slug' => 'audio-i-subtitry', 'title' => 'Аудио, переводы и субтитры', 'description' => 'Дорожки, варианты перевода, языки и синхронизация.'], 'en' => ['slug' => 'audio-and-subtitles', 'title' => 'Audio, translations and subtitles', 'description' => 'Tracks, translation variants, languages and synchronization.']],
        ['code' => 'account_security', 'position' => 40, 'ru' => ['slug' => 'akkaunt-i-bezopasnost', 'title' => 'Аккаунт и безопасность', 'description' => 'Регистрация, вход, почта, пароль, сессии и связанные способы входа.'], 'en' => ['slug' => 'account-and-security', 'title' => 'Account and security', 'description' => 'Registration, sign-in, email, password, sessions and linked sign-in methods.']],
        ['code' => 'profile_settings', 'position' => 50, 'ru' => ['slug' => 'profil-i-nastroyki', 'title' => 'Профиль и настройки', 'description' => 'Приватность, уведомления и персональные предпочтения.'], 'en' => ['slug' => 'profile-and-settings', 'title' => 'Profile and settings', 'description' => 'Privacy, notifications and personal preferences.']],
        ['code' => 'library_community', 'position' => 60, 'ru' => ['slug' => 'biblioteka-i-soobschestvo', 'title' => 'Библиотека и сообщество', 'description' => 'Закладки, коллекции, история, комментарии и рецензии.'], 'en' => ['slug' => 'library-and-community', 'title' => 'Library and community', 'description' => 'Bookmarks, collections, history, comments and reviews.']],
        ['code' => 'releases_discovery', 'position' => 70, 'ru' => ['slug' => 'relizy-i-rekomendatsii', 'title' => 'Релизы и рекомендации', 'description' => 'Календарь, уведомления о выпусках и подборки.'], 'en' => ['slug' => 'releases-and-recommendations', 'title' => 'Releases and recommendations', 'description' => 'Calendar, release notifications and discovery.']],
        ['code' => 'support_requests', 'position' => 80, 'ru' => ['slug' => 'zaprosy-i-podderzhka', 'title' => 'Запросы и поддержка', 'description' => 'Когда нужен запрос контента, технический тикет или жалоба.'], 'en' => ['slug' => 'requests-and-support', 'title' => 'Requests and support', 'description' => 'When to use a content request, technical ticket or report.']],
        ['code' => 'devices_accessibility', 'position' => 90, 'ru' => ['slug' => 'ustroystva-i-dostupnost', 'title' => 'Устройства и доступность', 'description' => 'Браузеры, мобильные устройства, клавиатура и специальные возможности.'], 'en' => ['slug' => 'devices-and-accessibility', 'title' => 'Devices and accessibility', 'description' => 'Browsers, mobile devices, keyboard and accessibility.']],
        ['code' => 'premium_availability', 'position' => 100, 'ru' => ['slug' => 'premium-i-dostupnost', 'title' => 'Premium и доступность контента', 'description' => 'Фактические ограничения доступа, региона и возможностей аккаунта.'], 'en' => ['slug' => 'premium-and-availability', 'title' => 'Premium and content availability', 'description' => 'Actual account, regional and content availability limits.']],
    ],
    'articles' => [
        [
            'code' => 'help-center-basics', 'category' => 'getting_started', 'type' => 'faq', 'feature' => 'general', 'owner' => 'support', 'featured' => true, 'priority' => 100,
            'primary' => 'return_to_feature', 'secondary' => 'technical_ticket', 'issue_type' => 'other_technical_issue',
            'aliases' => ['ru' => ['помощь', 'частые вопросы', 'как пользоваться сайтом'], 'en' => ['help', 'frequently asked questions', 'how to use the portal']],
            'ru' => ['slug' => 'osnovnye-voprosy', 'title' => 'Основные вопросы о портале', 'summary' => 'Краткая карта каталога, просмотра, аккаунта и правильных способов обращения.', 'keywords' => 'faq помощь портал сериал поиск просмотр аккаунт', 'body' => <<<'MD'
## Как найти сериал или выпуск?

Используйте общий поиск в шапке или каталог с фильтрами. Поиск справки на этой странице ищет только инструкции и не заменяет поиск каталога.

## Нужна ли регистрация для просмотра?

Доступ зависит от конкретной страницы и опубликованного источника. Аккаунт необходим для синхронизации истории, прогресса, библиотеки, настроек и частных технических тикетов.

## Чем закладка отличается от истории?

Закладка — явное решение сохранить тайтл. История появляется после воспроизведения, а прогресс хранит позицию серии. Удаление элемента из коллекции не очищает закладку или прогресс.

## Куда сообщить о проблеме?

Если уже представленный ролик не запускается или интерфейс работает неверно, создайте частный технический тикет. Если отсутствует сериал, сезон, серия, перевод, субтитры или улучшение качества, используйте запрос контента. Для оскорблений, спама и другого пользовательского материала нажмите кнопку жалобы у самого материала.

## Какие данные нельзя отправлять поддержке?

Никогда не отправляйте пароль, код восстановления, ссылку сброса, OAuth-код, cookie, токен, платёжные данные или защищённый адрес видео. Официальные формы не запрашивают эти сведения.
MD],
            'en' => ['slug' => 'portal-basics', 'title' => 'Portal basics', 'summary' => 'A quick map of the catalog, playback, accounts and the correct support workflows.', 'keywords' => 'faq help portal series search watching account', 'body' => <<<'MD'
## How do I find a series or episode?

Use the global header search or the catalog filters. Help search only finds instructions; it does not replace catalog search.

## Do I need an account to watch?

Access depends on the page and published source. An account is required to synchronize history, progress, library, settings and private technical tickets.

## How is a bookmark different from history?

A bookmark is an explicit choice to save a title. History appears after playback, while progress stores an episode position. Removing a title from a collection does not clear its bookmark or progress.

## Where should I report a problem?

Use a private technical ticket when an existing video fails or the interface behaves incorrectly. Use a content request for a missing title, season, episode, translation, subtitles or quality upgrade. Use the report control next to user-generated content for abuse or spam.

## What must I never send to support?

Never send a password, recovery code, reset link, OAuth code, cookie, token, payment data or protected video URL. Official forms do not ask for them.
MD],
        ],
        [
            'code' => 'video-does-not-start', 'category' => 'watching_video', 'type' => 'troubleshooting', 'feature' => 'player', 'owner' => 'player', 'featured' => true, 'priority' => 100,
            'primary' => 'technical_ticket', 'secondary' => 'content_request', 'issue_type' => 'video_unavailable', 'request_type' => 'broken_content_restoration',
            'aliases' => ['ru' => ['видео не работает', 'черный экран', 'бесконечная загрузка', 'плеер не запускается'], 'en' => ['video not working', 'black screen', 'endless loading', 'player will not start']],
            'ru' => ['slug' => 'video-ne-zapuskaetsya', 'title' => 'Видео не запускается', 'summary' => 'Безопасное дерево проверки для чёрного экрана, ошибки запуска или бесконечной загрузки.', 'keywords' => 'видео плеер ошибка загрузка черный экран серия источник', 'body' => <<<'MD'
## 1. Проверьте выбранный выпуск

Убедитесь, что выбраны нужные сезон и серия. Один раз перезагрузите страницу и снова нажмите воспроизведение.

## 2. Сравните с другим вариантом

Если на странице показаны другие варианты перевода или качества, выберите один из них. Затем откройте другой выпуск этого же тайтла и любое другое видео портала. Так можно понять, относится ли сбой к одному источнику.

## 3. Проверьте среду

Используйте актуальную поддерживаемую версию обычного браузера. Временно проверьте страницу в чистом или приватном окне, если расширение может вмешиваться. Не отключайте защиту постоянно и не устанавливайте неизвестное программное обеспечение.

## 4. Зафиксируйте безопасные признаки

Запишите выбранные сезон, серию и вариант, примерное время сбоя и видимый публичный код ошибки. Не копируйте адрес источника, cookie или данные с вкладки разработчика.

## 5. Выберите правильное обращение

Если существующий выпуск не воспроизводится, создайте технический тикет с подготовленным контекстом. Если выпуска или варианта вообще нет в каталоге, отправьте запрос контента. Портал не предлагает обход региональных или иных ограничений.
MD],
            'en' => ['slug' => 'video-does-not-start', 'title' => 'Video does not start', 'summary' => 'A safe decision tree for a black screen, startup error or endless loading.', 'keywords' => 'video player error loading black screen episode source', 'body' => <<<'MD'
## 1. Check the selected episode

Confirm the intended season and episode. Reload the page once and try playback again.

## 2. Compare another available variant

If the page shows other translation or quality variants, try one. Then test another episode of the same title and one unrelated portal video. This distinguishes a source-specific failure from a wider problem.

## 3. Check the environment

Use a current supported browser. If an extension may interfere, temporarily test a normal private or clean window. Do not permanently disable protection and do not install unknown software.

## 4. Record safe symptoms

Note the season, episode, selected variant, approximate failure time and any public error code. Do not copy source addresses, cookies or developer-tool data.

## 5. Choose the correct workflow

Create a technical ticket when an existing episode fails to play. Submit a content request when the episode or variant is absent from the catalog. The portal does not provide ways to bypass regional or other restrictions.
MD],
        ],
        [
            'code' => 'buffering-and-quality', 'category' => 'watching_video', 'type' => 'troubleshooting', 'feature' => 'quality', 'owner' => 'player', 'featured' => true, 'priority' => 95,
            'primary' => 'technical_ticket', 'secondary' => 'content_request', 'issue_type' => 'excessive_buffering', 'request_type' => 'quality_upgrade',
            'aliases' => ['ru' => ['видео тормозит', 'буферизация', 'низкое качество', 'нет fullhd'], 'en' => ['video keeps buffering', 'low quality', 'quality unavailable']],
            'ru' => ['slug' => 'buferizatsiya-i-kachestvo', 'title' => 'Буферизация и качество видео', 'summary' => 'Причины остановок и честное объяснение доступных меток качества.', 'keywords' => 'буферизация тормозит качество auto hd сеть источник', 'body' => <<<'MD'
## Почему видео останавливается?

Причиной может быть временная нестабильность сети, высокая выбранная версия, ограничение устройства или браузера, параллельные загрузки либо деградация конкретного источника. Это не всегда проблема интернет-соединения пользователя.

## Что попробовать

1. Поставьте воспроизведение на паузу ненадолго.
2. Выберите автоматический или более низкий вариант качества, если он доступен на странице.
3. Остановите конкурирующие загрузки и проверьте другое обычное видео.
4. Перезапустите браузер и сравните другой выпуск или вариант перевода.

## Как читать метки качества

Портал показывает только варианты, зарегистрированные для конкретного источника. Метка описывает заявленное разрешение варианта и не обещает Full HD или 4K там, где такого источника нет. Автоматический выбор и фактическая плавность зависят от браузера, устройства и сети.

## Когда обращаться

Для постоянной буферизации одного существующего варианта создайте технический тикет. Если нужен отсутствующий вариант качества, используйте запрос на улучшение качества. Укажите реальную метку, не отправляя URL источника.
MD],
            'en' => ['slug' => 'buffering-and-video-quality', 'title' => 'Buffering and video quality', 'summary' => 'Causes of interruptions and an honest explanation of available quality labels.', 'keywords' => 'buffering slow quality auto hd network source', 'body' => <<<'MD'
## Why does playback stop?

Possible causes include temporary network instability, a demanding selected variant, device or browser limits, competing downloads, or degradation of one source. It is not always the viewer's connection.

## What to try

1. Pause playback briefly.
2. Select automatic or a lower available quality on the page.
3. Stop competing downloads and test another ordinary video.
4. Restart the browser and compare another episode or translation variant.

## Understanding quality labels

The portal only shows variants registered for that source. A label describes the declared resolution; it does not promise Full HD or 4K where no such source exists. Automatic selection and smooth playback still depend on the browser, device and network.

## When to escalate

Create a technical ticket for persistent buffering of an existing variant. Use a quality-upgrade content request for a missing quality. Include the visible label, never the protected source URL.
MD],
        ],
        [
            'code' => 'audio-and-translation-selection', 'category' => 'audio_subtitles', 'type' => 'player_help', 'feature' => 'audio', 'owner' => 'player', 'priority' => 90,
            'primary' => 'technical_ticket', 'secondary' => 'content_request', 'issue_type' => 'audio_missing', 'request_type' => 'translation',
            'aliases' => ['ru' => ['нет звука', 'не тот перевод', 'рассинхрон звука', 'озвучка'], 'en' => ['no sound', 'wrong translation', 'audio out of sync', 'dub']],
            'ru' => ['slug' => 'audio-i-vybor-perevoda', 'title' => 'Звук и выбор перевода', 'summary' => 'Проверка громкости, варианта перевода и проблем синхронизации.', 'keywords' => 'звук mute громкость перевод студия язык рассинхрон', 'body' => <<<'MD'
## Если звука нет

Проверьте кнопку выключения звука и громкость плеера, системную громкость, отключение звука вкладки браузера и выбранное внешнее аудиоустройство. Сравните другое видео.

## Как выбрать перевод

Доступные варианты перевода показываются рядом с выпуском, если для него зарегистрировано несколько источников. Это отдельный выбор и он не меняет язык интерфейса. Название студии или языка отражает метаданные конкретного варианта.

## Неверный язык или рассинхронизация

Сравните другой вариант. Для существующего варианта с неправильным звуком создайте технический тикет и укажите примерную отметку времени. Не загружайте аудиофайл и не отправляйте адрес источника.

Если нужного перевода вообще нет, используйте запрос контента типа «перевод». Технический тикет не создаёт отсутствующую дорожку.
MD],
            'en' => ['slug' => 'audio-and-translation-selection', 'title' => 'Audio and translation selection', 'summary' => 'Checking volume, translation variants and synchronization problems.', 'keywords' => 'audio mute volume translation studio language sync', 'body' => <<<'MD'
## If there is no sound

Check the player mute control and volume, system volume, browser-tab mute state and the selected external audio device. Compare another video.

## Choosing a translation

Available translation variants appear near the episode when multiple sources are registered. This choice is separate from the interface language. Studio and language labels reflect that variant's metadata.

## Wrong language or synchronization

Compare another variant. For incorrect audio on an existing variant, create a technical ticket and include an approximate timestamp. Do not upload audio files or send source addresses.

If the required translation is completely absent, use a translation content request. A technical ticket does not create a missing track.
MD],
        ],
        [
            'code' => 'subtitle-troubleshooting', 'category' => 'audio_subtitles', 'type' => 'troubleshooting', 'feature' => 'subtitles', 'owner' => 'player', 'priority' => 88,
            'primary' => 'technical_ticket', 'secondary' => 'content_request', 'issue_type' => 'subtitles_missing', 'request_type' => 'subtitles',
            'aliases' => ['ru' => ['нет субтитров', 'субтитры отстают', 'не тот язык субтитров'], 'en' => ['subtitles missing', 'subtitles delayed', 'wrong subtitle language']],
            'ru' => ['slug' => 'problemy-s-subtitrami', 'title' => 'Субтитры: включение и устранение проблем', 'summary' => 'Доступность, язык, синхронизация и отличие от языка интерфейса.', 'keywords' => 'субтитры captions язык синхронизация стиль кодировка', 'body' => <<<'MD'
## Включение и язык

Кнопка субтитров появляется только когда выбранный вариант содержит доступную дорожку. Выбор языка субтитров не меняет язык интерфейса или озвучки. Предпочтение языка в настройках влияет на желаемый выбор, но не создаёт отсутствующую дорожку.

## Если субтитры не загружаются

Перезагрузите страницу один раз, проверьте другой вариант выпуска и другой браузер. Если дорожка обозначена, но не загружается, это техническая проблема.

## Синхронизация и текст

При отставании, опережении, неверной кодировке или ошибке текста укажите примерную временную отметку и язык в техническом тикете. Не прикладывайте полный файл субтитров.

Если нужных субтитров на странице нет, отправьте запрос контента типа «субтитры». Доступность вынужденных субтитров зависит от опубликованного варианта и не гарантируется.
MD],
            'en' => ['slug' => 'subtitle-troubleshooting', 'title' => 'Subtitles: enabling and troubleshooting', 'summary' => 'Availability, language, synchronization and the distinction from interface locale.', 'keywords' => 'subtitles captions language sync style encoding', 'body' => <<<'MD'
## Enabling and choosing a language

The captions control only appears when the selected variant includes an available track. Subtitle language does not change the interface or audio language. A preferred language setting can guide selection but cannot create a missing track.

## If subtitles fail to load

Reload once, compare another episode variant and try another supported browser. A listed track that fails to load is a technical problem.

## Synchronization and text errors

For delayed, early, incorrectly encoded or wrong text, include the approximate timestamp and language in a technical ticket. Do not attach a complete subtitle file.

If the desired subtitles are not listed at all, submit a subtitle content request. Forced subtitles depend on the published variant and are not guaranteed.
MD],
        ],
        [
            'code' => 'fullscreen-autoplay-and-controls', 'category' => 'watching_video', 'type' => 'player_help', 'feature' => 'player', 'owner' => 'player', 'priority' => 86,
            'primary' => 'technical_ticket', 'secondary' => 'none', 'issue_type' => 'player_controls_problem',
            'aliases' => ['ru' => ['не работает полный экран', 'автозапуск запрещен', 'горячие клавиши плеера'], 'en' => ['fullscreen not working', 'autoplay blocked', 'player keyboard shortcuts']],
            'ru' => ['slug' => 'polnyy-ekran-avtozapusk-i-upravlenie', 'title' => 'Полный экран, автозапуск и управление плеером', 'summary' => 'Ограничения браузера, реальные элементы управления и клавиатурные команды.', 'keywords' => 'fullscreen autoplay клавиатура mute captions скорость pip', 'body' => <<<'MD'
## Полный экран и автозапуск

Полный экран требует поддержки браузера и может быть ограничен встроенным режимом или устройством. Сначала взаимодействуйте со страницей, затем нажмите кнопку полного экрана. Настройка автозапуска выражает намерение портала, но браузер может запретить запуск без жеста пользователя, особенно со звуком. Портал не обходит эту политику.

## Реальные элементы управления

Плеер предоставляет воспроизведение, полосу прогресса, время, громкость, настройки, картинку в картинке или AirPlay там, где их поддерживает среда, и полный экран. Скорость доступна от 0,5× до 2×. Наличие системных функций зависит от браузера.

## Клавиатура при фокусе на плеере

- `Пробел` или `K` — воспроизведение и пауза.
- `←` и `→` — перемотка; `↑` и `↓` — громкость.
- `M` — звук, `F` — полный экран, `C` — субтитры.
- `0`–`9` — переход к доле длительности; `L` — повтор.
- `Escape` — выход из ненативного полного экрана.

Глобальные сочетания не используются: сначала переведите фокус на плеер. Настройка клавиатурного управления может отключить эту возможность.
MD],
            'en' => ['slug' => 'fullscreen-autoplay-and-controls', 'title' => 'Fullscreen, autoplay and player controls', 'summary' => 'Browser restrictions, real controls and keyboard commands.', 'keywords' => 'fullscreen autoplay keyboard mute captions speed pip', 'body' => <<<'MD'
## Fullscreen and autoplay

Fullscreen requires browser support and may be limited by embedded mode or device policy. Interact with the page first, then use the fullscreen control. The autoplay preference expresses portal intent, but the browser may still block playback without a user gesture, especially with sound. The portal cannot bypass that policy.

## Actual controls

The player provides playback, progress, time, volume, settings, picture-in-picture or AirPlay where supported by the environment, and fullscreen. Playback speed ranges from 0.5× to 2×. System features depend on browser capability.

## Keyboard commands while the player is focused

- `Space` or `K`: play and pause.
- `Left` and `Right`: seek; `Up` and `Down`: volume.
- `M`: mute, `F`: fullscreen, `C`: captions.
- `0`–`9`: jump to a fraction of duration; `L`: loop.
- `Escape`: leave non-native fullscreen.

Shortcuts are not global: focus the player first. The account keyboard-control preference may disable them.
MD],
        ],
        [
            'code' => 'progress-history-and-continue-watching', 'category' => 'library_community', 'type' => 'feature_guide', 'feature' => 'progress', 'owner' => 'support', 'priority' => 92,
            'primary' => 'technical_ticket', 'secondary' => 'none', 'issue_type' => 'progress_not_saved',
            'aliases' => ['ru' => ['не сохраняется прогресс', 'продолжить просмотр не та серия', 'очистить историю'], 'en' => ['progress not saved', 'continue watching wrong episode', 'clear history']],
            'ru' => ['slug' => 'progress-istoriya-i-prodolzhit-prosmotr', 'title' => 'Прогресс, история и «Продолжить просмотр»', 'summary' => 'Когда сохраняется позиция, как работает завершение и чем история отличается от закладок.', 'keywords' => 'прогресс история продолжить просмотр синхронизация серия', 'body' => <<<'MD'
## Когда сохраняется позиция

Для аккаунта плеер начинает отправлять ограниченные обновления после фактического запуска. Текущий клиент отправляет heartbeat примерно раз в 30 секунд и не записывает каждую секунду; пауза или уход со страницы запускают финальную попытку. Короткий запуск может не стать значимым просмотром.

## Завершение и продолжение

Выпуск считается завершённым около 95% длительности или когда остаётся примерно 15 секунд. «Продолжить просмотр» выбирает последнюю подходящую незавершённую серию из канонического прогресса. Новая опубликованная серия может снова сделать завершённый сериал доступным для продолжения.

## История, закладки и устройства

История отражает просмотр, закладка — намерение сохранить тайтл. Очистка истории удаляет выбранный прогресс и может изменить «Продолжить просмотр», но не удаляет закладки или коллекции. Для синхронизации между устройствами требуется один аккаунт и успешная передача обновления; небольшая задержка возможна.

Если позиция стабильно неверна, создайте технический тикет с тайтлом, серией и примерным временем, не отправляя URL видео.
MD],
            'en' => ['slug' => 'progress-history-and-continue-watching', 'title' => 'Progress, history and Continue Watching', 'summary' => 'When position is saved, how completion works and how history differs from bookmarks.', 'keywords' => 'progress history continue watching synchronization episode', 'body' => <<<'MD'
## When position is saved

For an account, the player starts bounded updates after real playback begins. The current client sends a heartbeat about every 30 seconds and does not write every second; pausing or leaving triggers a final attempt. A very short start may not count as meaningful viewing.

## Completion and resume

An episode is completed near 95% of duration or with about 15 seconds remaining. Continue Watching selects the latest eligible unfinished episode from canonical progress. A newly published episode can make a completed title eligible again.

## History, bookmarks and devices

History reflects playback; a bookmark reflects an explicit intent to save. Clearing history removes selected progress and may change Continue Watching, but it does not remove bookmarks or collections. Cross-device sync requires the same account and a successful update; a short delay can occur.

If the position remains wrong, create a technical ticket with the title, episode and approximate time, never the video URL.
MD],
        ],
        [
            'code' => 'registration-login-email-password', 'category' => 'account_security', 'type' => 'account_help', 'feature' => 'authentication', 'owner' => 'account_security', 'featured' => true, 'priority' => 98,
            'primary' => 'account_support', 'secondary' => 'none', 'issue_type' => 'account_problem',
            'aliases' => ['ru' => ['не могу войти', 'письмо подтверждения не пришло', 'забыл пароль', 'ссылка сброса истекла'], 'en' => ['cannot sign in', 'verification email missing', 'forgot password', 'reset link expired']],
            'ru' => ['slug' => 'registratsiya-vhod-pochta-i-parol', 'title' => 'Регистрация, вход, подтверждение почты и пароль', 'summary' => 'Безопасные шаги для создания аккаунта, доставки письма и восстановления доступа.', 'keywords' => 'регистрация вход email подтверждение пароль сброс', 'body' => <<<'MD'
## Регистрация и вход

Используйте только официальные формы портала. Адрес почты нормализуется, а пароль должен соответствовать показанным требованиям. Сообщение об ошибке входа намеренно не раскрывает лишние сведения об аккаунте.

## Письмо подтверждения не пришло

Проверьте введённый адрес, папки «Спам» и «Нежелательная почта», свободное место ящика и подождите короткое разумное время. Затем используйте официальный повторный запрос. Успешное сообщение означает, что портал принял запрос, но не гарантирует доставку почтовым провайдером.

## Восстановление пароля

Запросите новую ссылку через форму восстановления. Истёкшая или уже использованная ссылка не восстанавливается — запросите следующую. Изменение пароля отзывает другие мобильные токены согласно политике безопасности.

## Безопасность

Поддержка никогда не просит пароль, ссылку сброса, код подтверждения, OAuth-код, MFA-секрет или recovery code. При проблеме с доставкой письма войдите, если это возможно, и создайте приватный тикет типа «Проблема аккаунта»; для такого случая подтверждение почты не требуется.
MD],
            'en' => ['slug' => 'registration-login-email-and-password', 'title' => 'Registration, sign-in, email verification and password', 'summary' => 'Safe steps for account creation, email delivery and access recovery.', 'keywords' => 'registration login email verification password reset', 'body' => <<<'MD'
## Registration and sign-in

Use only official portal forms. Email identity is normalized, and the password must satisfy the displayed requirements. Sign-in errors intentionally avoid revealing unnecessary account information.

## Verification email did not arrive

Check the entered address, spam or junk folders, mailbox capacity and wait a short reasonable period. Then use the official resend control. A success message means the portal accepted the request; it does not guarantee delivery by the mail provider.

## Password recovery

Request a new link through the recovery form. An expired or used link cannot be revived—request another. Changing the password revokes other mobile tokens according to the security policy.

## Security

Support never asks for your password, reset link, verification code, OAuth code, MFA secret or recovery code. If email delivery fails, sign in when possible and create a private Account problem ticket; email verification is not required for this case.
MD],
        ],
        [
            'code' => 'social-login-linked-accounts-and-sessions', 'category' => 'account_security', 'type' => 'account_help', 'feature' => 'sessions', 'owner' => 'account_security', 'priority' => 82,
            'primary' => 'account_support', 'secondary' => 'none', 'issue_type' => 'account_problem',
            'aliases' => ['ru' => ['социальный вход', 'связать аккаунты', 'выйти со всех устройств', 'подозрительный вход'], 'en' => ['social login', 'link accounts', 'sign out all devices', 'suspicious login']],
            'ru' => ['slug' => 'sotsialnyy-vhod-svyazannye-akkaunty-i-sessii', 'title' => 'Социальный вход, связанные аккаунты и сессии', 'summary' => 'Фактическое поведение способов входа и защита при потере доступа.', 'keywords' => 'oauth социальный вход провайдер связь сессия устройство безопасность', 'body' => <<<'MD'
## Доступные способы входа

Показываются только реально настроенные провайдеры. Если кнопки провайдера нет, этот способ не поддерживается. Язык интерфейса не меняет учётную запись провайдера.

## Связывание и отвязывание

Связь выполняется через официальный OAuth-поток. Портал не принимает OAuth-коды вручную. Нельзя удалять последний рабочий способ входа, если это лишит аккаунт доступа. При сообщении, что внешний аккаунт уже связан, не создавайте обходной профиль — используйте защищённую поддержку аккаунта.

## Управление сессиями

На странице безопасности можно завершить другие web-сессии и отозвать мобильные устройства. После смены пароля или подозрительного входа завершите остальные сессии, проверьте почту и способы входа.

Для захвата аккаунта, неизвестного входа или потери всех способов доступа используйте только приватный workflow. Не публикуйте проблему в комментариях и не передавайте коды или токены в URL.
MD],
            'en' => ['slug' => 'social-login-linked-accounts-and-sessions', 'title' => 'Social login, linked accounts and sessions', 'summary' => 'Actual sign-in method behavior and protection when access is at risk.', 'keywords' => 'oauth social login provider link session device security', 'body' => <<<'MD'
## Available sign-in methods

Only configured providers are displayed. If a provider button is absent, that method is not supported. Interface language does not change the provider account.

## Linking and unlinking

Linking uses the official OAuth flow. The portal never accepts OAuth codes manually. A final working sign-in method cannot be removed when that would lock the account. If an external account is already linked, do not create a workaround profile—use secure account support.

## Session management

The security page can end other web sessions and revoke mobile devices. After a password change or suspicious sign-in, end other sessions and review email and sign-in methods.

For takeover, unknown access or loss of every sign-in method, use only the private workflow. Do not post the problem publicly or place codes and tokens in a URL.
MD],
        ],
        [
            'code' => 'profile-privacy-settings-notifications', 'category' => 'profile_settings', 'type' => 'feature_guide', 'feature' => 'privacy', 'owner' => 'account_security', 'priority' => 86,
            'primary' => 'technical_ticket', 'secondary' => 'return_to_feature', 'issue_type' => 'account_problem',
            'aliases' => ['ru' => ['приватный профиль', 'настройки уведомлений', 'скрыть историю', 'аватар'], 'en' => ['private profile', 'notification settings', 'hide history', 'avatar']],
            'ru' => ['slug' => 'profil-privatnost-nastroyki-i-uvedomleniya', 'title' => 'Профиль, приватность, настройки и уведомления', 'summary' => 'Что можно изменить и какие данные просмотра остаются приватными.', 'keywords' => 'профиль имя аватар биография приватность уведомления настройки', 'body' => <<<'MD'
## Профиль

В настройках профиля можно управлять отображаемым именем, username, аватаром и биографией в пределах действующих правил. Публичность профиля и отдельных разделов задаётся явными настройками.

## Приватность просмотра

Подробная история, точная позиция и персональные сигналы рекомендаций приватны по умолчанию. Публичные коллекции, комментарии и рецензии — отдельные материалы; открытие одного раздела не публикует точный прогресс. Блокировка и mute влияют на взаимодействие с пользователями, а жалоба отправляется у конкретного профиля или материала.

## Настройки и уведомления

Автозапуск, управление клавиатурой, язык медиа и субтитров выражают предпочтение, но доступность источника и политика браузера остаются главными. Категории уведомлений можно включать отдельно; отключение уведомления не отменяет закладку, подписку или календарную запись.

Если сохранение настройки не работает, перезагрузите страницу и повторите один раз, затем создайте технический тикет. Не очищайте все данные браузера первым шагом.
MD],
            'en' => ['slug' => 'profile-privacy-settings-and-notifications', 'title' => 'Profile, privacy, settings and notifications', 'summary' => 'What can be changed and which viewing data remains private.', 'keywords' => 'profile name avatar biography privacy notifications settings', 'body' => <<<'MD'
## Profile

Profile settings manage display name, username, avatar and biography within current rules. Profile and section visibility are explicit settings.

## Viewing privacy

Detailed history, exact position and personal recommendation signals are private by default. Public collections, comments and reviews are separate content; exposing one section does not publish exact progress. Block and mute affect interaction, while reports are submitted next to the relevant profile or content.

## Settings and notifications

Autoplay, keyboard control, media language and subtitle language express preferences, but source availability and browser policy remain authoritative. Notification categories can be switched separately; disabling a notification does not remove a bookmark, subscription or calendar entry.

If a setting does not save, reload and retry once, then create a technical ticket. Do not clear all browser data as the first step.
MD],
        ],
        [
            'code' => 'library-bookmarks-and-collections', 'category' => 'library_community', 'type' => 'feature_guide', 'feature' => 'library', 'owner' => 'support', 'priority' => 84,
            'primary' => 'technical_ticket', 'secondary' => 'none', 'issue_type' => 'other_technical_issue',
            'aliases' => ['ru' => ['закладки', 'хочу посмотреть', 'коллекции', 'черный список'], 'en' => ['bookmarks', 'want to watch', 'collections', 'blacklist']],
            'ru' => ['slug' => 'biblioteka-zakladki-i-kollektsii', 'title' => 'Библиотека, закладки и коллекции', 'summary' => 'Различия статусов, персональных списков и независимого прогресса.', 'keywords' => 'закладка библиотека watching completed paused dropped коллекция', 'body' => <<<'MD'
## Состояния библиотеки

Закладка сохраняет тайтл. Статусы «хочу посмотреть», «смотрю», «завершено», «пауза» и «брошено» описывают ваше решение. «Не интересно» и blacklist влияют на персональные рекомендации и видимость, но не являются публичной оценкой.

## Коллекции

Коллекция — отдельный упорядоченный список. Её можно сделать публичной, приватной или доступной по ссылке, если такая видимость разрешена формой. Удаление тайтла из коллекции не удаляет закладку, историю, рейтинг или прогресс. Удаление самой коллекции также не меняет состояние тайтлов.

## Индикаторы обновлений

Новые серии и изменения определяются каноническими данными каталога и прогрессом. Ручная отметка статуса не создаёт выпуск и не гарантирует уведомление.

Если действие не сохраняется, проверьте вход в аккаунт и повторите после перезагрузки. Для ошибки существующей функции используйте технический тикет, а не запрос контента.
MD],
            'en' => ['slug' => 'library-bookmarks-and-collections', 'title' => 'Library, bookmarks and collections', 'summary' => 'The distinction between statuses, personal lists and independent progress.', 'keywords' => 'bookmark library watching completed paused dropped collection', 'body' => <<<'MD'
## Library states

A bookmark saves a title. Want to watch, watching, completed, paused and dropped describe your choice. Not interested and blacklist affect personal recommendations and visibility; they are not public ratings.

## Collections

A collection is a separate ordered list. It may be public, private or unlisted when that visibility is offered by the form. Removing a title from a collection does not remove its bookmark, history, rating or progress. Deleting the collection also leaves title state unchanged.

## Update indicators

New episodes and updates come from canonical catalog data and progress. A manual status cannot create a release or guarantee a notification.

If an action does not save, confirm that you are signed in and retry after one reload. Use a technical ticket for a broken existing function, not a content request.
MD],
        ],
        [
            'code' => 'comments-reviews-and-moderation', 'category' => 'library_community', 'type' => 'feature_guide', 'feature' => 'community', 'owner' => 'support', 'priority' => 76,
            'primary' => 'moderation_report', 'secondary' => 'technical_ticket', 'issue_type' => 'other_technical_issue',
            'aliases' => ['ru' => ['жалоба на комментарий', 'спойлер', 'рецензия', 'модерация'], 'en' => ['report comment', 'spoiler', 'review', 'moderation']],
            'ru' => ['slug' => 'kommentarii-retsenzii-i-zhaloby', 'title' => 'Комментарии, рецензии и жалобы', 'summary' => 'Разница форматов, спойлеры, редактирование и правильная жалоба.', 'keywords' => 'комментарий рецензия спойлер голос жалоба модерация block mute', 'body' => <<<'MD'
## Комментарий и рецензия

Комментарий относится к обсуждению страницы или ответа. Рецензия — самостоятельное мнение о тайтле с отдельными правилами. Полезность рецензии не заменяет жалобу.

## Спойлеры и редактирование

Отмечайте раскрывающие сюжет фрагменты как спойлер. Возможность редактирования или удаления зависит от авторства, состояния и срока, показанных интерфейсом. Удалённый автором материал и модерационное решение обрабатываются разными правилами.

## Жалоба

Для оскорблений, спама, нарушения правил или скрытого спойлера используйте кнопку жалобы рядом с конкретным комментарием, рецензией, коллекцией или профилем. Это сохраняет правильную цель и не раскрывает личность автора жалобы публично. Не отправляйте такой случай в техническую поддержку.

Технический тикет нужен только когда сама кнопка, форма или страница не работает. Внутренние заметки и действия модерации не публикуются.
MD],
            'en' => ['slug' => 'comments-reviews-and-reports', 'title' => 'Comments, reviews and reports', 'summary' => 'Format differences, spoilers, editing and the correct report workflow.', 'keywords' => 'comment review spoiler helpful report moderation block mute', 'body' => <<<'MD'
## Comment and review

A comment belongs to a page discussion or reply. A review is a standalone opinion about a title with separate rules. Helpful voting does not replace a report.

## Spoilers and editing

Mark plot-revealing content as a spoiler. Editing or deletion depends on authorship, state and the period shown by the interface. Author deletion and a moderation action follow different rules.

## Reporting content

For abuse, spam, rule violations or an unmarked spoiler, use the report control next to the exact comment, review, collection or profile. This preserves the correct target and keeps reporter identity private. Do not send it to technical support.

A technical ticket is only for a broken button, form or page. Internal moderation notes and actions are never public.
MD],
        ],
        [
            'code' => 'release-calendar-and-recommendations', 'category' => 'releases_discovery', 'type' => 'feature_guide', 'feature' => 'calendar', 'owner' => 'content_operations', 'priority' => 80,
            'primary' => 'technical_ticket', 'secondary' => 'content_request', 'issue_type' => 'calendar_problem', 'request_type' => 'metadata_correction',
            'aliases' => ['ru' => ['дата серии', 'календарь релизов', 'почему рекомендовано', 'не интересно'], 'en' => ['episode date', 'release calendar', 'why recommended', 'not interested']],
            'ru' => ['slug' => 'kalendar-relizov-i-rekomendatsii', 'title' => 'Календарь релизов и рекомендации', 'summary' => 'Типы дат, задержки, часовой пояс и честные сигналы подборок.', 'keywords' => 'календарь дата estimated confirmed delayed рекомендации история жанры', 'body' => <<<'MD'
## Даты календаря

Оригинальный релиз, публикация на портале и появление перевода — разные события. «Ожидается» означает оценку, «подтверждено» — подтверждённую дату, а delayed, postponed и cancelled не являются обещанием новой даты. Обратный отсчёт останавливается на задержке и не показывает отрицательное время. Точное время форматируется в часовом поясе аккаунта.

## Уведомления

Подписка и категории уведомлений управляются отдельно. Отключение уведомления не удаляет календарную запись. Портал не обещает доставку в конкретную минуту и не придумывает даты перевода из наличия дорожки.

## Рекомендации

Публичные подборки и персональные рекомендации различаются. Для нового аккаунта используются доступные общие и жанровые сигналы; позже могут учитываться библиотека, история и отметка «не интересно». Blacklist исключает тайтл. Объяснение использует реальные причины и не заявляет AI или процент совпадения.

Неверная существующая дата — запрос исправления метаданных. Не работающая страница календаря или действие рекомендации — технический тикет.
MD],
            'en' => ['slug' => 'release-calendar-and-recommendations', 'title' => 'Release calendar and recommendations', 'summary' => 'Date types, delays, timezone and honest discovery signals.', 'keywords' => 'calendar date estimated confirmed delayed recommendations history genres', 'body' => <<<'MD'
## Calendar dates

Original release, portal publication and translation availability are separate events. Estimated is not guaranteed, confirmed represents confirmed data, and delayed, postponed and cancelled do not promise a replacement date. A countdown stops when delayed and never runs negative. Exact times use the account timezone.

## Notifications

Subscriptions and notification categories are separate. Disabling a notification does not delete the calendar entry. The portal promises no exact delivery minute and never invents translation dates from track availability.

## Recommendations

Public discovery and personal recommendations differ. A new account starts with available general and genre signals; later the library, history and Not interested feedback may be used. Blacklist excludes a title. Explanations use real reasons and do not claim AI or match percentages.

Use a metadata correction request for a wrong existing date. Use a technical ticket for a broken calendar page or recommendation action.
MD],
        ],
        [
            'code' => 'content-request-or-technical-ticket', 'category' => 'support_requests', 'type' => 'support_entry', 'feature' => 'requests', 'owner' => 'support', 'featured' => true, 'priority' => 100,
            'primary' => 'content_request', 'secondary' => 'technical_ticket', 'issue_type' => 'other_technical_issue', 'request_type' => 'other_content_request',
            'aliases' => ['ru' => ['тикет или запрос', 'нет сериала', 'сломано видео', 'исправить описание'], 'en' => ['ticket or request', 'missing series', 'broken video', 'correct metadata']],
            'ru' => ['slug' => 'zapros-kontenta-ili-tehnicheskiy-tiket', 'title' => 'Запрос контента или технический тикет?', 'summary' => 'Как не дублировать Task 19 и Task 20 и сразу выбрать правильный workflow.', 'keywords' => 'запрос контента тикет серия перевод субтитры ошибка дубликат', 'body' => <<<'MD'
## Используйте запрос контента

Он нужен для отсутствующего сериала, сезона, серии, перевода, субтитров, улучшения качества или исправления метаданных. Сначала найдите тайтл и существующие запросы. Каноническая форма повторно проверит дубликаты; справка ничего не отправляет автоматически.

## Используйте технический тикет

Он нужен для приватной диагностики уже существующей функции: видео не запускается, плеер или страница сломаны, прогресс неверен, вход или настройка не работают. Тикет может содержать разрешённую диагностику и безопасный скриншот; он не публикуется.

## Используйте жалобу у материала

Оскорбления, спам, нарушения и пользовательский контент направляются через кнопку жалобы у цели. Техническая поддержка не заменяет модерацию.

Один workflow не преобразуется в другой скрыто. Диагностика, вложения и частные заметки не копируются в публичный запрос. Перед отправкой пользователь проверяет подготовленные данные.
MD],
            'en' => ['slug' => 'content-request-or-technical-ticket', 'title' => 'Content request or technical ticket?', 'summary' => 'How to choose the correct Task 19 or Task 20 workflow without duplication.', 'keywords' => 'content request ticket episode translation subtitles error duplicate', 'body' => <<<'MD'
## Use a content request

It covers a missing title, season, episode, translation, subtitles, quality upgrade or metadata correction. Search the catalog and existing requests first. The canonical form checks duplicates again; help never submits automatically.

## Use a technical ticket

It covers private diagnosis of an existing feature: video fails, a player or page is broken, progress is wrong, or account settings fail. A ticket may contain consented diagnostics and a safe screenshot; it is private.

## Use the report control next to content

Abuse, spam, rule violations and user-generated content go through the report control on the target. Technical support does not replace moderation.

One workflow is never silently converted into another. Diagnostics, attachments and private notes are not copied into a public request. The user reviews prepared data before submission.
MD],
        ],
        [
            'code' => 'safe-technical-ticket', 'category' => 'support_requests', 'type' => 'how_to', 'feature' => 'tickets', 'owner' => 'support', 'priority' => 94,
            'primary' => 'technical_ticket', 'secondary' => 'none', 'issue_type' => 'other_technical_issue',
            'aliases' => ['ru' => ['создать тикет', 'что приложить к ошибке', 'скриншот в поддержку'], 'en' => ['create ticket', 'what to include in bug report', 'support screenshot']],
            'ru' => ['slug' => 'bezopasnyy-tehnicheskiy-tiket', 'title' => 'Как создать безопасный технический тикет', 'summary' => 'Тип проблемы, временная отметка, диагностика, скриншоты и приватность вложений.', 'keywords' => 'технический тикет диагностика скриншот timestamp приватность', 'body' => <<<'MD'
## До отправки

Пройдите подходящую инструкцию, повторите действие один раз и проверьте похожий выпуск или страницу. Выберите точный тип проблемы и каноническую цель. Поиск похожих тикетов не показывает чужие сообщения или вложения.

## Полезные сведения

Кратко опишите ожидаемое и фактическое поведение, воспроизводимые шаги, примерную временную отметку и видимый публичный код ошибки. Диагностика браузера собирается только после отдельного согласия и хранит allowlisted семейство/major браузера, ОС, тип устройства и размеры viewport — без IP и raw user-agent.

## Скриншоты

Допускаются только проверенные PNG, JPEG и WebP с ограничением размера и пикселей. Файл переименовывается и перекодируется в приватном хранилище. Перед загрузкой скройте email, имя, уведомления и другие личные данные.

Никогда не прикладывайте пароль, токен, cookie, reset/verification/OAuth-код, платёжные данные, developer console, сетевой лог или URL источника. После решения можно подтвердить результат или безопасно переоткрыть тикет.
MD],
            'en' => ['slug' => 'safe-technical-ticket', 'title' => 'How to create a safe technical ticket', 'summary' => 'Issue type, timestamp, diagnostics, screenshots and attachment privacy.', 'keywords' => 'technical ticket diagnostics screenshot timestamp privacy', 'body' => <<<'MD'
## Before submitting

Follow the relevant guide, retry once and compare a similar episode or page. Choose the exact issue type and canonical target. Similar-ticket search never shows another user's messages or attachments.

## Useful details

Briefly describe expected and actual behavior, reproducible steps, an approximate timestamp and any public error code. Browser diagnostics require separate consent and store only allowlisted browser family/major, OS, device category and viewport—never IP or raw user-agent.

## Screenshots

Only validated PNG, JPEG and WebP images with byte and pixel limits are accepted. Files are renamed and re-encoded in private storage. Hide email, name, notifications and other personal data first.

Never attach a password, token, cookie, reset/verification/OAuth code, payment data, developer console, network log or source URL. After resolution, verify the result or safely reopen the ticket if needed.
MD],
        ],
        [
            'code' => 'premium-and-regional-availability', 'category' => 'premium_availability', 'type' => 'known_limitation', 'feature' => 'premium', 'owner' => 'premium', 'priority' => 80,
            'primary' => 'premium_support', 'secondary' => 'technical_ticket', 'issue_type' => 'premium_access_problem',
            'aliases' => ['ru' => ['premium не работает', 'недоступно в регионе', 'оплата подписки'], 'en' => ['premium not recognized', 'unavailable in region', 'subscription billing']],
            'ru' => ['slug' => 'premium-i-regionalnaya-dostupnost', 'title' => 'Premium и региональная доступность', 'summary' => 'Только подтверждённые возможности: без вымышленных льгот, оплаты и способов обхода.', 'keywords' => 'premium регион доступ entitlement billing quality restriction', 'body' => <<<'MD'
## Premium

Premium определяется каноническими правами доступа с отдельным источником и сроком. Поддерживаются точечные административные, компенсационные, миграционные, промо- и бессрочные выдачи. Активные права и история показываются в разделе «Настройки → Premium и оплаты»; окончание одного источника не удаляет другое право и не затрагивает прогресс, закладки или коллекции.

Платёжный провайдер и публичные платные тарифы сейчас не настроены, поэтому портал не показывает цену, способ оплаты, автопродление, возврат, счёт или дату следующего платежа. Premium пока не обещает отдельное качество, загрузки, специальные источники, отключение рекламы, публичный значок или приоритет поддержки. Если подтверждённое право не распознаётся, создайте приватный тикет «Проблема Premium» и никогда не отправляйте номер карты, CVV, платёжный токен или реквизиты провайдера.

## Региональная доступность

Наличие контента и отдельных источников может различаться по региону. Дата оригинального релиза, наличие записи в каталоге и возможность воспроизведения — разные факты. Выбранный пользователем регион не переопределяет серверное решение, а лицензионные подробности могут не публиковаться.

Портал не даёт инструкции по VPN или обходу ограничений. Если ограничение кажется ошибочным для существующего источника, создайте технический тикет с тайтлом и видимым сообщением, без защищённого URL. Для отсутствующего контента используйте канонический запрос.
MD],
            'en' => ['slug' => 'premium-and-regional-availability', 'title' => 'Premium and regional availability', 'summary' => 'Verified capabilities only, with no invented benefits, billing or bypass guidance.', 'keywords' => 'premium region availability entitlement billing quality restriction', 'body' => <<<'MD'
## Premium

Premium is resolved from canonical entitlements with an explicit source and access period. Targeted administrative, compensation, migration, promotion, and lifetime grants are supported. Active entitlements and history appear under Settings → Premium and billing; one source expiring never removes another entitlement or affects progress, bookmarks, or collections.

No payment provider or public paid plan is currently configured, so the portal shows no price, payment method, automatic renewal, refund, invoice, or next billing date. Premium currently makes no promise about video quality, downloads, special sources, advertising removal, a public badge, or priority support. If a confirmed entitlement is not recognized, create a private Premium access ticket and never send a card number, CVV, payment token, or provider credentials.

## Regional availability

Content and source availability can vary by region. Original release, catalog presence and actual playability are different facts. A user-selected region cannot override server-side access, and licensing detail may not be public.

The portal gives no VPN or restriction-bypass instructions. If a restriction appears wrong for an existing source, create a technical ticket with the title and visible message, not the protected URL. Use the canonical request for absent content.
MD],
        ],
        [
            'code' => 'supported-browsers-and-devices', 'category' => 'devices_accessibility', 'type' => 'known_limitation', 'feature' => 'devices', 'owner' => 'player', 'featured' => true, 'priority' => 90,
            'primary' => 'technical_ticket', 'secondary' => 'none', 'issue_type' => 'browser_compatibility',
            'aliases' => ['ru' => ['поддерживаемый браузер', 'телевизор', 'мобильный плеер', 'casting'], 'en' => ['supported browser', 'smart tv', 'mobile player', 'casting']],
            'ru' => ['slug' => 'podderzhivaemye-brauzery-i-ustroystva', 'title' => 'Поддерживаемые браузеры и устройства', 'summary' => 'Проверенная web-совместимость и честные ограничения телевизоров, casting и системных API.', 'keywords' => 'chromium firefox safari ios android mobile tv casting mse hls fullscreen', 'body' => <<<'MD'
## Поддерживаемая web-среда

Используйте актуальные стабильные версии Chromium-браузеров, Firefox и Safari. На iPhone и iPad используется текущий Mobile Safari; на Android — актуальный Chromium-браузер. Точные номера версий намеренно не закреплены и пересматриваются вместе с интерфейсом плеера.

## Требуемые возможности

JavaScript и cookies нужны для интерактивного интерфейса и входа. HLS использует hls.js при доступном Media Source Extensions либо нативное воспроизведение браузера. Fullscreen, picture-in-picture, AirPlay, captions и локальное сохранение предпочтений появляются только при поддержке среды. Пользователь видит понятное состояние, а не сырой capability dump.

## Телефоны, планшеты и большие экраны

Адаптивные страницы поддерживают touch, portrait/landscape и внешние мониторы. Фоновое воспроизведение, удержание экрана, автозапуск и полный экран могут ограничиваться платформой. Отдельное приложение Smart TV, дистанционное управление, Chromecast или гарантированный casting в проекте не подтверждены; AirPlay/PiP отображаются только когда их предоставляет браузер.

Для специфичной ошибки укажите семейство и major браузера, тип устройства и результат проверки другого поддерживаемого браузера.
MD],
            'en' => ['slug' => 'supported-browsers-and-devices', 'title' => 'Supported browsers and devices', 'summary' => 'Verified web compatibility and honest limits for televisions, casting and system APIs.', 'keywords' => 'chromium firefox safari ios android mobile tv casting mse hls fullscreen', 'body' => <<<'MD'
## Supported web environment

Use current stable Chromium-based browsers, Firefox and Safari. Current Mobile Safari is used on iPhone and iPad; use a current Chromium browser on Android. Exact version numbers are intentionally not frozen and are reviewed with player changes.

## Required capabilities

JavaScript and cookies are required for interactive UI and sign-in. HLS uses hls.js when Media Source Extensions is available, or native browser playback. Fullscreen, picture-in-picture, AirPlay, captions and local preference storage only appear when the environment supports them. Users receive a safe explanation, not a raw capability dump.

## Phones, tablets and large screens

Responsive pages support touch, portrait/landscape and external monitors. Background playback, wake lock, autoplay and fullscreen can be platform-limited. A dedicated Smart TV app, remote navigation, Chromecast or guaranteed casting is not verified; AirPlay and PiP only appear when the browser provides them.

For a device-specific problem, include browser family/major, device category and the result from another supported browser.
MD],
        ],
        [
            'code' => 'mobile-playback-guidance', 'category' => 'devices_accessibility', 'type' => 'player_help', 'feature' => 'devices', 'owner' => 'player', 'priority' => 78,
            'primary' => 'technical_ticket', 'secondary' => 'none', 'issue_type' => 'mobile_device_problem',
            'aliases' => ['ru' => ['плеер на телефоне', 'поворот экрана', 'мобильный интернет'], 'en' => ['player on phone', 'screen rotation', 'mobile data']],
            'ru' => ['slug' => 'prosmotr-na-mobilnom-ustroystve', 'title' => 'Просмотр на телефоне и планшете', 'summary' => 'Touch, ориентация, трафик и ограничения мобильных браузеров.', 'keywords' => 'mobile touch orientation fullscreen data subtitles autoplay background', 'body' => <<<'MD'
## Управление и ориентация

Используйте touch-элементы плеера с минимальной целью нажатия. Поворот в landscape даёт больше места, но автоматическая ориентация зависит от системной блокировки. Полный экран запускается только через доступный элемент управления браузера.

## Трафик и качество

Высокое качество расходует больше мобильных данных и ресурсов устройства. Выберите автоматический или более низкий вариант перед длительным просмотром через мобильную сеть. Портал не может измерить точную стоимость трафика у оператора.

## Ограничения платформы

Автозапуск со звуком, фоновое воспроизведение, picture-in-picture и удержание экрана регулируются системой и браузером. Субтитры и варианты перевода доступны только для выбранного источника. Не устанавливайте неизвестные «кодеки» или приложения.

При постоянной проблеме сравните Wi-Fi и обычную сеть только как диагностический шаг, другой выпуск и поддерживаемый браузер, затем создайте мобильный технический тикет.
MD],
            'en' => ['slug' => 'mobile-playback-guidance', 'title' => 'Watching on a phone or tablet', 'summary' => 'Touch, orientation, data use and mobile browser restrictions.', 'keywords' => 'mobile touch orientation fullscreen data subtitles autoplay background', 'body' => <<<'MD'
## Controls and orientation

Use the player's touch controls with adequate tap targets. Landscape provides more space, but automatic rotation depends on the system orientation lock. Fullscreen only starts through a control available to the browser.

## Data and quality

Higher quality consumes more mobile data and device resources. Select automatic or a lower variant before long viewing on mobile data. The portal cannot know the exact carrier charge.

## Platform limits

Autoplay with sound, background playback, picture-in-picture and wake lock are controlled by the system and browser. Subtitles and translation variants only exist when included with the selected source. Do not install unknown codecs or applications.

For a persistent issue, compare Wi-Fi and ordinary network only as a diagnostic step, test another episode and supported browser, then create a mobile technical ticket.
MD],
        ],
        [
            'code' => 'accessibility-and-keyboard-help', 'category' => 'devices_accessibility', 'type' => 'accessibility_help', 'feature' => 'accessibility', 'owner' => 'accessibility', 'featured' => true, 'priority' => 95,
            'primary' => 'technical_ticket', 'secondary' => 'none', 'issue_type' => 'accessibility_problem',
            'aliases' => ['ru' => ['доступность сайта', 'screen reader', 'клавиатура', 'reduced motion', 'масштаб'], 'en' => ['site accessibility', 'screen reader', 'keyboard', 'reduced motion', 'zoom']],
            'ru' => ['slug' => 'dostupnost-i-upravlenie-klaviaturoy', 'title' => 'Доступность и управление с клавиатуры', 'summary' => 'Навигация, focus, субтитры, масштаб, reduced motion и сообщение о барьере.', 'keywords' => 'accessibility screen reader keyboard focus zoom contrast captions reduced motion', 'body' => <<<'MD'
## Навигация

В начале страницы есть ссылка перехода к основному содержимому. Заголовки, breadcrumbs, формы, результаты поиска, аккордеоны и действия имеют семантические подписи; важная информация не должна зависеть только от hover или цвета. Используйте `Tab`, `Shift+Tab`, `Enter` и `Space` согласно стандартному поведению элемента.

## Чтение и движение

Поддерживается масштаб браузера и длинные подписи. Видимый focus и контраст сохраняются в светлой теме. Настройка reduced motion уменьшает необязательное движение; системная настройка также учитывается. Субтитры зависят от источника и могут быть выбраны независимо от языка интерфейса.

## Плеер

Клавиатурные команды работают только при фокусе на плеере и могут быть отключены настройкой аккаунта. Полный список находится в статье об управлении плеером. Screen reader получает подписи состояния, но доступность нативных media API может различаться по браузеру.

Чтобы сообщить о барьере, создайте приватный тикет «Проблема доступности» и опишите действие, ожидаемый результат, браузер и вспомогательную технологию только если вы сами хотите её указать. Медицинские сведения не требуются.
MD],
            'en' => ['slug' => 'accessibility-and-keyboard-help', 'title' => 'Accessibility and keyboard help', 'summary' => 'Navigation, focus, captions, zoom, reduced motion and reporting a barrier.', 'keywords' => 'accessibility screen reader keyboard focus zoom contrast captions reduced motion', 'body' => <<<'MD'
## Navigation

A skip link leads to main content. Headings, breadcrumbs, forms, search results, accordions and actions have semantic labels; essential information must not depend only on hover or color. Use `Tab`, `Shift+Tab`, `Enter` and `Space` according to the control's standard behavior.

## Reading and motion

Browser zoom and long labels are supported. Visible focus and contrast remain in the light theme. Reduced motion settings reduce optional movement and the system preference is respected. Subtitles depend on the source and are independent of interface locale.

## Player

Keyboard commands only work while the player is focused and may be disabled by the account preference. The player-control article lists the real shortcuts. Screen readers receive state labels, but native media API accessibility can differ by browser.

To report a barrier, create a private Accessibility problem ticket and describe the action, expected result, browser and assistive technology only if you choose to share it. Medical information is never required.
MD],
        ],
        [
            'code' => 'cookies-storage-and-safe-refresh', 'category' => 'devices_accessibility', 'type' => 'troubleshooting', 'feature' => 'settings', 'owner' => 'account_security', 'priority' => 72,
            'primary' => 'technical_ticket', 'secondary' => 'none', 'issue_type' => 'browser_compatibility',
            'aliases' => ['ru' => ['cookie не работают', 'настройки сбрасываются', 'очистить кеш'], 'en' => ['cookies not working', 'settings reset', 'clear cache']],
            'ru' => ['slug' => 'cookies-hranilische-i-bezopasnoe-obnovlenie', 'title' => 'Cookies, локальное хранилище и безопасное обновление', 'summary' => 'Последовательность без разрушительной очистки всех данных браузера.', 'keywords' => 'cookies local storage cache reload login preferences browser', 'body' => <<<'MD'
## Для чего нужны данные сайта

Authentication cookie поддерживает вход и защищённую сессию. Локальное хранилище может сохранять анонимные предпочтения плеера и помогает перенести их в аккаунт. Это разные данные; блокировка cookies может нарушить вход, а запрет storage — локальные предпочтения.

## Безопасный порядок действий

1. Перезагрузите только страницу.
2. Повторите действие один раз.
3. Выйдите и войдите снова только для проблемы авторизации.
4. Сравните другой поддерживаемый браузер.
5. Очищайте данные только этого портала, только если предыдущие шаги не помогли.

Очистка данных сайта завершит локальную сессию и может удалить анонимные предпочтения и несинхронизированный прогресс. Не очищайте всю историю браузера первым шагом. Не отключайте защиту постоянно.

Если ошибка повторяется, создайте технический тикет, описав конкретную функцию. Не отправляйте содержимое cookies или local storage.
MD],
            'en' => ['slug' => 'cookies-storage-and-safe-refresh', 'title' => 'Cookies, local storage and safe refresh', 'summary' => 'A sequence that avoids destructive clearing of all browser data.', 'keywords' => 'cookies local storage cache reload login preferences browser', 'body' => <<<'MD'
## Why site data is used

An authentication cookie maintains sign-in and the protected session. Local storage may retain anonymous player preferences and help migrate them into an account. They are different: blocking cookies can break sign-in, while blocking storage can affect local preferences.

## Safe troubleshooting order

1. Reload only the page.
2. Retry the action once.
3. Sign out and in only for an authentication problem.
4. Compare another supported browser.
5. Clear data for this portal only, and only after the earlier steps fail.

Clearing site data ends the local session and can remove anonymous preferences and unsynchronized progress. Do not clear all browser history first and do not permanently disable protection.

If the error persists, create a technical ticket naming the exact feature. Never send cookie or local-storage contents.
MD],
        ],
        [
            'code' => 'official-support-channels', 'category' => 'support_requests', 'type' => 'support_entry', 'feature' => 'tickets', 'owner' => 'support', 'priority' => 88,
            'primary' => 'technical_ticket', 'secondary' => 'content_request', 'issue_type' => 'other_technical_issue', 'request_type' => 'other_content_request',
            'aliases' => ['ru' => ['связаться с поддержкой', 'почта поддержки', 'чат поддержки', 'правообладатель'], 'en' => ['contact support', 'support email', 'live chat', 'rights holder']],
            'ru' => ['slug' => 'ofitsialnye-kanaly-podderzhki', 'title' => 'Официальные каналы поддержки', 'summary' => 'Только реально доступные формы, без вымышленного чата, часов и сроков ответа.', 'keywords' => 'поддержка контакт тикет запрос модерация безопасность правообладатель', 'body' => <<<'MD'
## Технические проблемы

Канонический канал — приватная форма технического тикета для вошедшего пользователя. Она хранит переписку, разрешённую диагностику и скриншоты отдельно от публичных страниц.

## Отсутствующий контент

Канонический канал — публичный каталог запросов контента и его форма. Справка только объясняет выбор и не отправляет запрос автоматически.

## Модерация и безопасность аккаунта

Жалоба на пользовательский материал отправляется у конкретной цели. Проблема захвата аккаунта или потери доступа направляется через приватный тип проблемы аккаунта. Публичное обсуждение для этого не подходит.

В проекте не подтверждены публичный email поддержки, live chat, офисные часы, гарантированное время ответа, billing-form или отдельная форма правообладателя. Поэтому они не показываются. Используйте только видимые канонические формы и никогда не отправляйте секреты или платёжные данные.
MD],
            'en' => ['slug' => 'official-support-channels', 'title' => 'Official support channels', 'summary' => 'Only real forms, with no invented live chat, office hours or response promises.', 'keywords' => 'support contact ticket request moderation security rights holder', 'body' => <<<'MD'
## Technical problems

The canonical channel is the private technical-ticket form for a signed-in user. It keeps conversation, consented diagnostics and screenshots separate from public pages.

## Missing content

The canonical channel is the public content-request directory and its form. Help only explains the choice and never submits automatically.

## Moderation and account security

Report user-generated content next to the exact target. Account takeover or loss of access belongs in the private Account problem workflow, never public discussion.

No public support email, live chat, office hours, guaranteed response time, billing form or separate rights-holder form is verified in this project, so none is displayed. Use only visible canonical forms and never send secrets or payment data.
MD],
        ],
    ],
    'relations' => [
        'video-does-not-start' => ['buffering-and-quality', 'audio-and-translation-selection', 'subtitle-troubleshooting', 'supported-browsers-and-devices'],
        'buffering-and-quality' => ['video-does-not-start', 'supported-browsers-and-devices', 'mobile-playback-guidance'],
        'audio-and-translation-selection' => ['subtitle-troubleshooting', 'content-request-or-technical-ticket'],
        'subtitle-troubleshooting' => ['audio-and-translation-selection', 'content-request-or-technical-ticket'],
        'fullscreen-autoplay-and-controls' => ['supported-browsers-and-devices', 'mobile-playback-guidance', 'accessibility-and-keyboard-help'],
        'progress-history-and-continue-watching' => ['library-bookmarks-and-collections', 'profile-privacy-settings-notifications'],
        'registration-login-email-password' => ['social-login-linked-accounts-and-sessions', 'cookies-storage-and-safe-refresh', 'official-support-channels'],
        'content-request-or-technical-ticket' => ['safe-technical-ticket', 'official-support-channels'],
        'supported-browsers-and-devices' => ['mobile-playback-guidance', 'accessibility-and-keyboard-help', 'fullscreen-autoplay-and-controls'],
    ],
    'contextual' => [
        ['feature' => 'player', 'context' => 'error', 'article' => 'video-does-not-start'],
        ['feature' => 'player', 'context' => 'buffering', 'article' => 'buffering-and-quality'],
        ['feature' => 'player', 'context' => 'controls', 'article' => 'fullscreen-autoplay-and-controls'],
        ['feature' => 'audio', 'context' => 'selector', 'article' => 'audio-and-translation-selection'],
        ['feature' => 'subtitles', 'context' => 'selector', 'article' => 'subtitle-troubleshooting'],
        ['feature' => 'quality', 'context' => 'selector', 'article' => 'buffering-and-quality'],
        ['feature' => 'progress', 'context' => 'general', 'article' => 'progress-history-and-continue-watching'],
        ['feature' => 'authentication', 'context' => 'general', 'article' => 'registration-login-email-password'],
        ['feature' => 'sessions', 'context' => 'general', 'article' => 'social-login-linked-accounts-and-sessions'],
        ['feature' => 'privacy', 'context' => 'general', 'article' => 'profile-privacy-settings-notifications'],
        ['feature' => 'library', 'context' => 'general', 'article' => 'library-bookmarks-and-collections'],
        ['feature' => 'community', 'context' => 'general', 'article' => 'comments-reviews-and-moderation'],
        ['feature' => 'calendar', 'context' => 'general', 'article' => 'release-calendar-and-recommendations'],
        ['feature' => 'requests', 'context' => 'form', 'article' => 'content-request-or-technical-ticket'],
        ['feature' => 'tickets', 'context' => 'form', 'article' => 'safe-technical-ticket'],
        ['feature' => 'devices', 'context' => 'general', 'article' => 'supported-browsers-and-devices'],
        ['feature' => 'accessibility', 'context' => 'general', 'article' => 'accessibility-and-keyboard-help'],
        ['feature' => 'premium', 'context' => 'general', 'article' => 'premium-and-regional-availability'],
    ],
];
