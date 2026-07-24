# Бесшовное переключение сезонов, серий и переводов в плеере

Дата: 24.07.2026
Статус: согласовано; реализация ещё не начата

## Цель

Добавить в единственный существующий плеер меню выбора `Сезон → Серия → Перевод` и заменить переход после `ended` на немедленное бесшовное включение следующей доступной серии.

При ручном выборе серии или перевода и при автоматическом переходе должны сохраняться тот же `<video>`, тот же экземпляр Plyr, текущая fullscreen-оболочка и одна `CatalogPlayerSession`. Повторный рендер плеера, выход из стандартного fullscreen, отдельная player-страница и повторное открытие тайтла не допускаются.

## Подтверждённый baseline

- `CatalogTitlePlayer` владеет URL-state `season`, `episode`, `media`, `variant`, `quality`, `format`.
- `CatalogTitlePlaybackQuery` единолично определяет доступность и порядок серий, включая переход через границу сезона.
- `CatalogPlaybackSourceResolver` и `CatalogEntitlementService` повторно проверяют hierarchy, publication, audience, availability window и разрешённый источник.
- HTML получает только один короткоживущий same-origin signed grant; исходный URL поставщика не попадает в Livewire state, HTML или JSON.
- `resources/js/player.js` создаёт одну `CatalogPlayerSession`, один Plyr и при необходимости один HLS.js instance внутри единственного keyed `wire:ignore` media shell.
- Текущий `ended` запускает настраиваемый отсчёт `3..30` секунд и затем активирует обычную Livewire-ссылку следующей серии. Из-за замены keyed media shell этот путь не может сохранить fullscreen.
- Списки сезонов, серий и вариантов под плеером остаются доступным SSR/Livewire fallback.

## Выбранный подход

Единственная `CatalogPlayerSession` получает JavaScript-владельца меню и внутреннюю операцию hot-swap. Серверные Livewire `#[Json]` actions возвращают только перепроверенные bounded menu data и один подготовленный playback transition. JavaScript применяет подтверждённый transition к существующему video/Plyr/HLS lifecycle без morph или замены media shell.

Этот подход выбран, потому что он одновременно сохраняет fullscreen DOM identity, серверную авторизацию, обычный SSR fallback и один canonical player owner.

Отклонены:

1. Обычный `selectEpisode()`/`selectMedia()` с Livewire render: keyed media shell заменяется, поэтому браузер завершает fullscreen.
2. Предварительная выдача URL всех серий и переводов в HTML: увеличивает payload, раскрывает лишние short-lived grants и создаёт stale authorization.
3. Второй скрытый `<video>` или второй Plyr: дублирует lifecycle, Media Session, progress и listeners и нарушает единый playback-контур.
4. Fake CSS fullscreen: противоречит проектному browser-capability contract и не является настоящим fullscreen.

## Серверные границы

### Меню серий

Планируемый renderless JSON-action получает только positive `seasonId` и bounded `page`. Он:

- заново находит сезон внутри текущего `CatalogTitle`;
- применяет тот же viewer-aware watchability query;
- возвращает максимум 24 серии на страницу с номером страницы, общим числом страниц, opaque ID, локализованной подписью и количеством разрешённых вариантов;
- не возвращает source grant, provider URL, account ID, Eloquent graph или административное состояние;
- отклоняет неизвестную, чужую, скрытую, неопубликованную или недоступную сущность безопасным локализованным кодом ошибки.

Список сезонов остаётся bounded summary. Infinite scroll и внутренний scroll не добавляются: длинный сезон использует обычные кнопки страниц.

### Подготовка перехода

Планируемый `#[Json]` action принимает opaque `episodeId` и необязательный `mediaId`. Он:

1. нормализует ввод и применяет bounded rate limit;
2. повторно загружает текущий title и viewer;
3. проверяет `episode → season → title`, publication, audience, window, source health и entitlement;
4. при явном `mediaId` подтверждает принадлежность media выбранной серии;
5. без явного media выбирает preferred variant/quality/format, затем лучший разрешённый fallback;
6. создаёт ровно один короткоживущий same-origin grant;
7. создаёт новый progress-session token и начальную sequence boundary для выбранной серии;
8. вычисляет previous/next через существующий `CatalogTitlePlaybackQuery`;
9. возвращает безопасный typed payload.

Payload содержит:

- стабильные ID и локализованные подписи title/season/episode;
- выбранный media ID, фактически выбранный variant/quality/format и bounded список разрешённых вариантов этой серии;
- same-origin source URL, MIME/format и expiration timestamp;
- новый progress token;
- safe URL query для адресной строки;
- next/previous opaque ID и подписи;
- machine-readable notice code, если preferred перевод временно недоступен и применён fallback.

Payload не содержит upstream URL, credentials, provider response, private entitlement reason, user ID или необработанный exception text.

`#[Async]` не применяется: соседние выборы должны иметь строгий порядок, а stale response не может заменить более новый выбор. Browser присваивает каждому запросу монотонный generation; применим только ответ последней generation.

### Предпочтение и фактический перевод

Preferred profile и фактический source разделяются:

- preference продолжает жить в существующем `user_account_settings`/`seasonvar.account-preferences.v1`;
- временный fallback не стирает preferred variant;
- меню отмечает фактически играющий вариант;
- при следующей серии resolver снова сначала пробует preferred profile;
- названия студий и переводов остаются source identity и не переводятся как интерфейсный текст.

Отдельные audio tracks, языки дорожек или subtitle-файлы не выдумываются: пункт «Перевод» представляет существующие `LicensedMedia` groups, а не отсутствующую track model.

## Player menu

### Размещение и владение DOM

В Plyr controls добавляется кнопка «Серии». `Shift+E` открывает и закрывает то же меню.

Меню создаётся `player.js` через DOM API и `textContent` из allowlisted `CatalogPlayerCopy` и safe server data. Оно является JavaScript-owned потомком существующего `wire:ignore` media shell; отдельный `wire:ignore`, inline JavaScript, `innerHTML` с remote data и Livewire-owned control внутри ignored subtree не добавляются.

При стандартном Fullscreen API открытый dialog временно помещается внутрь фактического `document.fullscreenElement`, а после закрытия возвращается в media shell. Ни fullscreen element, ни `<video>`, ни Plyr root не заменяются.

### Desktop и mobile

На desktop меню показывает три колонки:

1. сезоны;
2. bounded страницу серий выбранного сезона;
3. переводы/варианты текущей играющей серии.

На телефоне используется одно и то же семантическое дерево с последовательными экранами и кнопкой «Назад». Отдельного mobile component, внутреннего scroll и дублированного state нет.

Текущие сезон, серия и фактический перевод имеют `aria-current`. Все интерактивные цели не меньше 44 px на coarse pointer. Длинные названия переносятся, safe-area учитывается.

### Поведение выбора

- Выбор сезона только обновляет страницу списка серий и не останавливает текущее видео.
- Выбор серии сразу закрывает меню и запускает серию с `0`.
- Выбор перевода сразу закрывает меню и запускает текущую серию с `0`.
- Автоматический переход также начинает следующую серию с `0`.
- Технический fallback отказавшего source той же серии сохраняет текущую позицию, как и сейчас; это не ручной выбор перевода.
- Пока пользователь только просматривает меню, текущее видео продолжает играть.

Списки под плеером остаются обычным доступным fallback без JavaScript. После реализации они вызывают тот же server transition contract, когда активна `CatalogPlayerSession`, и сохраняют существующие `href` для обычной навигации без JavaScript.

### Клавиатура и focus

- `Shift+E` открывает/закрывает меню.
- `Escape` закрывает меню без действия и возвращает focus на кнопку, которая его открыла.
- `Tab`/`Shift+Tab` остаются внутри открытого modal dialog.
- Стрелки перемещают focus в текущем списке; `Enter` и `Space` выбирают пункт.
- На mobile-клавиатуре кнопка «Назад» возвращает предыдущий уровень.
- Пока focus находится в меню или dialog открыт, глобальные playback shortcuts не управляют фоновым видео.
- При reduced motion меню открывается и закрывается без обязательной анимации.

## In-place source transition

Применение подтверждённого transition выполняется в таком порядке:

1. Старая session синхронно создаёт immutable progress event со старым episode ID, token и sequence; сетевой flush не задерживает уже подготовленный переход.
2. Более старые pending transition generations помечаются stale.
3. Текущее воспроизведение приостанавливается, старый HLS.js instance и только его listeners уничтожаются.
4. Существующие `<video>`, Plyr instance, Plyr/fullscreen root и player menu сохраняют DOM identity.
5. Новый source устанавливается в тот же video/Plyr; для HLS создаётся один новый HLS.js instance, для progressive используется тот же video.
6. Player session атомарно принимает новые episode/media IDs, grant expiration, navigation, Media Session metadata, progress token и sequence `0`.
7. URL-state синхронизируется с `CatalogTitlePlayer` без render, затем существующая history boundary обновляет адрес.
8. После `loadedmetadata` позиция остаётся `0`; volume, mute, speed, autoplay и keyboard preferences сохраняются.
9. `play()` вызывается только если transition должен продолжить просмотр. Если browser policy отклоняет promise, выбранный source остаётся активным и показывается доступная кнопка воспроизведения.

Manual selection и auto-next создают history entry тем же способом, что существующие ссылки `data-catalog-history`: предыдущий URL остаётся доступен кнопке Back, новый URL становится текущим без полной навигации. `popstate` сохраняет существующий путь восстановления Livewire state.

Принятый переход также обновляет существующий discussion target событиями текущего компонента; комментарии к серии не должны оставаться привязанными к предыдущей серии.

## Немедленный auto-next

Отсчёт, кнопки «Смотреть сейчас» и «Отменить» после `ended` удаляются. Канонической точкой отмены остаётся настройка autoplay до окончания серии.

Когда длительность известна и до конца остаётся не больше 60 секунд, session один раз запрашивает подготовленный transition к server-resolved next episode. Если пользователь перемотал назад, повторный запрос не создаётся без истечения/инвалидации grant.

На `ended`:

- сначала формируется completion flush старой серии;
- если autoplay выключен, плеер остаётся в ended state;
- если готовый transition действителен, он применяется немедленно без countdown, timeout или искусственной паузы;
- если подготовка ранее не удалась или grant истёк, выполняется одна немедленная bounded повторная подготовка; виден честный loading state;
- фактическое ожидание сети/media metadata не маскируется и не называется задержкой;
- при отсутствии следующей серии показывается «Вы посмотрели последнюю доступную серию»;
- при сетевой ошибке сохраняются fullscreen и текущий video shell, показываются ошибка и «Повторить»;
- unrelated recommendation не запускается;
- переход с последней regular серии сезона идёт к первой разрешённой regular серии следующего сезона; special lane не смешивается с regular lane.

Если пользователь вручную выбрал другую серию, выключил autoplay, открыл новый URL или уничтожил session, подготовленный transition инвалидируется.

## Fullscreen и платформенные ограничения

Гарантия сохранения fullscreen относится к стандартному Fullscreen API/Plyr: реализация не заменяет `document.fullscreenElement` и не вызывает `exitFullscreen()`.

Нативный iOS video fullscreen управляется WebKit/OS и не допускает произвольный HTML поверх системного player UI. Поэтому:

- in-place auto-next и сохранение одного `<video>` остаются целевым поведением;
- HTML-меню сезонов/серий/переводов не может быть гарантированно показано поверх нативного iOS fullscreen;
- после возврата в inline/Plyr меню снова доступно;
- fake fullscreen и device-specific обход не добавляются;
- сохранение нативного iOS fullscreen при source swap требует проверки на реальном устройстве и до такой проверки остаётся `unresolved`, а не обещается как подтверждённое.

## Ошибки, конкуренция и cleanup

- Последний выбор побеждает; stale JSON response не меняет source, URL, focus или progress context.
- Ошибка меню не останавливает текущее видео.
- Ошибка prefetch не влияет на текущий playback.
- Ошибка source load проходит существующий bounded retry/fallback и не создаёт бесконечный цикл.
- Новый переход сбрасывает failed-media set только для новой серии; failure history старой серии не переносится как запрет.
- `livewire:navigating`, `pagehide`, удаление shell и session destroy отменяют menu, pending generations, timers, HLS и listeners единым `AbortController`.
- Второй document listener, polling и request на каждый `timeupdate` не добавляются.

## Security, privacy, cache и performance

- Все client IDs считаются недоверенными и повторно разрешаются сервером.
- Grant остаётся viewer-bound, короткоживущим, `private, no-store` и same-origin.
- Raw provider URL отсутствует в HTML, Livewire public state, JSON action payload, history, local/session storage, logs и error copy.
- Новый public route/API endpoint не нужен.
- Новая migration, table, cache domain/key, queue, service worker или dependency не нужны.
- Menu page и media choices bounded; full catalog graph не сериализуется.
- Prefetch ограничен одной следующей серией и выполняется только вблизи конца.
- Progress и account preferences не попадают в shared cache.
- Vite manifest и hashed assets публикуются одной совместимой release boundary.

## Cross-feature impact

Affected:

- `CatalogTitlePlayer`, `CatalogTitlePlaybackQuery`, source/progress resolution;
- player JavaScript, Livewire bridge, Blade data contract и CSS;
- RU/EN player copy с exact key/placeholder parity;
- history/back-forward и discussion episode target;
- Media Session next/previous metadata;
- mobile/fullscreen/accessibility behavior;
- visitor documentation, canonical playback/frontend/architecture owners и changelog.

Unchanged:

- public routes, route model binding, API shapes и SEO canonical;
- database schema, imported media identity и importer behavior;
- authentication/session identity, authorization policies и entitlement precedence;
- premium, region и legal restrictions;
- recommendation autoplay;
- cache keys/invalidation, queues, storage и dependencies.

## Testing strategy

Реализация выполняется TDD.

PHPUnit RED/GREEN должен проверить:

1. bounded episode-page response и отсутствие недоступных серий;
2. hierarchy/publication/audience/window/media revalidation;
3. один safe same-origin grant без provider URL;
4. preferred translation и временный fallback без стирания preference;
5. deterministic next через границу сезона и раздельные regular/special lanes;
6. отсутствие next на последней доступной серии;
7. новый progress token/sequence на каждой серии;
8. stale/invalid IDs и rate limit закрываются безопасно;
9. URL/profile и discussion target остаются совместимыми;
10. RU/EN copy parity.

Playwright должен проверить:

1. кнопку «Серии», `Shift+E`, focus trap, возврат focus и подавление фоновых shortcuts;
2. desktop три колонки и mobile последовательные уровни без overflow/internal scroll;
3. выбор сезона не останавливает видео;
4. ручная серия и перевод стартуют с `0`;
5. тот же `<video>`, тот же Plyr root и тот же `document.fullscreenElement` после standard-fullscreen transition;
6. auto-next без countdown и искусственного timer;
7. autoplay off, последнюю серию, cross-season next, translation fallback;
8. network failure/retry и blocked `play()`;
9. last-selection-wins при быстрых кликах;
10. отдельные progress payload/token/sequence до и после перехода;
11. URL history, Back/Forward, Media Session и cleanup после Livewire navigation.

Проверка на реальном iOS устройстве является отдельным production/browser evidence gate; эмуляция Chromium не доказывает native WebKit fullscreen.

## Expected implementation files

- `app/Livewire/CatalogTitlePlayer.php`
- `app/Services/Catalog/CatalogTitlePlaybackQuery.php`
- `app/DTOs/PlaybackTransitionData.php` или эквивалентный typed DTO в существующем `app/DTOs`
- `app/View/ViewData/CatalogPlayerCopy.php`
- `app/View/ViewModels/CatalogShowViewModel.php`
- `resources/views/livewire/catalog-title-player.blade.php`
- `resources/js/player.js`
- `resources/js/player-navigation.js`
- `resources/css/app.css`
- `lang/ru/catalog.php`
- `lang/en/catalog.php`
- `tests/Feature/CatalogPageTest.php`
- `tests/Feature/CatalogTitlePlaybackQueryTest.php`
- `tests/Unit/CatalogPlayerCopyTest.php`
- `tests/browser/player-lifecycle.spec.js`
- canonical docs, implementation plan, `README.md` и `CHANGELOG.md`

Routes, migrations, config, package manifests и lock files не ожидаются. Точный DTO и разделение PHP helpers должны следовать соседним patterns после RED tests; новый architecture layer не создаётся ради одного payload.

## Compatibility и rollback

Совместимыми остаются:

- `/titles/{slug}` и текущие episode/media query parameters;
- `CatalogTitlePlayer` как owner URL-state;
- existing previous/next links и SSR fallback;
- signed playback route и grant validation;
- `seasonvar.account-preferences.v1`;
- progress row identity `(user_id, episode_id)`;
- source failure fallback, restart, Media Session, browser history и discussion target;
- один full `wire:ignore`, один `<video>`, один Plyr и не более одного HLS.js instance.

Rollback не требует migration или data repair: вернуть прежний countdown/navigation path, удалить JSON transition/menu additions и восстановить совместимый Vite manifest с его hashed assets. Cache flush, queue clear, dependency reinstall и storage cleanup не нужны. Старые assets должны оставаться доступными до завершения переключения manifest.

## Acceptance criteria

1. В стандартном fullscreen пользователь открывает меню, выбирает сезон, серию и перевод без выхода из fullscreen.
2. Ручной выбор и auto-next не заменяют `<video>`, Plyr root или fullscreen element.
3. Следующая серия запускается на `ended` без countdown и искусственной задержки.
4. Autoplay off, финальная серия, failure, retry и browser-blocked play имеют честные доступные состояния.
5. Каждая смена заново проходит server-side hierarchy/access/source checks и выдаёт только один same-origin grant.
6. Preferred перевод сохраняется отдельно от временного fallback.
7. Progress старой и новой серии не смешивается.
8. Long seasons bounded и paginated; keyboard, touch, focus, safe-area и reduced-motion contracts соблюдены.
9. Ни один public route, migration, cache key, dependency или второй player lifecycle не добавлен.
10. Focused RED/GREEN tests, player browser matrix, PHPUnit, Pint, Vite build, documentation gates и legacy scan проходят.
11. Ограничение native iOS fullscreen проверено на реальном устройстве либо честно остаётся `unresolved`.
