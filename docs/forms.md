# Формы

Обновлено: 13.07.2026

## Публичные формы

- Основная форма поиска каталога работает через full-page `App\Livewire\CatalogSeries`, синхронизирует `q` с URL и проверяется через `App\Http\Requests\CatalogTitlesRequest`; без JavaScript она отправляет обычный `GET /titles`.
- Header search использует тот же маршрут и тот же параметр `q`; значение для layout готовит `App\View\ViewData\AppLayoutData`. Доступное имя формы и placeholder прямо указывают на поиск только по названию.
- `CatalogSeriesFilters` хранит только нормализуемые scalar/list значения. Livewire actions сбрасывают страницу при изменении поиска, фильтров, сортировки или размера выдачи; скрытые поля сохраняют те же активные параметры для GET fallback.
- Multi-value свойства используют `#[Url(history: true)]`, поэтому выбранные значения сохраняются при сортировке, пагинации, обновлении страницы и browser back/forward. Удаление одного chip меняет только одно значение, групповой сброс очищает только указанную группу.
- Empty-state свойства формы допускают `null` на границе Livewire hydration, потому что Laravel преобразует пустые JSON-строки до lifecycle hooks; `CatalogSeriesFilters::toRequestInput()` отбрасывает `null`, а успешная нормализация возвращает UI-default `''`.
- Актёры и режиссёры подбираются через read-only `GET /api/catalog/people`: `CatalogPeopleLookupRequest` разрешает только типы `actor`/`director`, нормализует запрос 2–80 символов, а `CatalogPersonOptionResource` отдаёт максимум 20 публичных slug/name/count. Выбранный slug остаётся обычным повторяемым GET-параметром и после перезагрузки поднимается в начало группы.
- Карточка тайтла использует Livewire actions для watchlist/rating; rating принимает только 1–10 и показывает русскую ошибку в component error bag. Season/episode/media controls остаются ссылками с GET fallback и одновременно используют `wire:click.prevent` для обновления без полной перезагрузки.

## Компоненты

- Поисковые поля рендерит `x-form.search-field`.
- Ошибки валидации рендерит `x-form.input-error` через стандартный `@error`.
- Обычные GET-формы используют `old()` после redirect; full-page каталог показывает Livewire error bag на той же странице и не выполняет запрос выдачи по невалидному состоянию.
- Для selected/checked/error/old-состояний нельзя добавлять `@php`; используйте props, `old()`, `@error`, `@selected`, `@checked` и Form Request/ViewModel-данные.

## Административные формы

- `/admin/catalog` валидирует каждую nested form на сервере перед передачей explicit allowlist в `CatalogAdministrationService`; русские ошибки остаются в стандартном Livewire error bag.
- Title/season/episode/media IDs и version fingerprints заблокированы через Livewire `#[Locked]`, но сервис всё равно повторно проверяет ownership hierarchy и policy. Browser-supplied user/source/parent IDs не используются.
- Поиск сериалов ограничен 80 символами и 20 строками на страницу; actor/director/genre/country/translation options запрашиваются только после двух символов и ограничены 20 строками.
- Hide/unpublish actions имеют typed `wire:confirm`, а stale fingerprint возвращает ошибку формы и требует заново открыть актуальную запись.

## Проверки

- Ошибки публичных форм должны быть на русском языке.
- GET-формы должны иметь тест на успешную отправку и тест на отображение ошибки валидации с сохранением введенного значения.
- На странице каталога `Очистить поиск` удаляет только `q`; точечный сброс группы сохраняет остальные параметры, а `Сбросить все` возвращает поиск, фильтры, сортировку, вид и размер страницы к значениям по умолчанию.
- Все фильтры `/titles` находятся в одной GET/Livewire-форме внутри полноширинного `<details id="catalog-filters">`. Блок уже открыт в первом SSR независимо от наличия условий, а summary показывает их общее число и позволяет при желании свернуть длинную форму; отдельные mobile dialog и desktop sidebar отсутствуют.
- `npm run build` нужен после изменения Blade-компонентов форм, потому что классы Tailwind участвуют во frontend build.

## Формы коллекций

Create/edit/report/cover/membership/reorder используют Livewire forms с localized `ru/en` labels/errors, server enum rules и повторной service validation. Create form не принимает owner/moderation/feature; default visibility берётся из config и равна `private`. Editor передаёт locked UUID и optimistic `contentVersion`; stale save не затирает более новое изменение.

Title-page selector держит existing membership и draft UUID set раздельно. Apply выполняет одну canonical batch transaction, Cancel/закрытие ничего не записывает, create-and-add использует тот же create service и одну outer transaction. Submit controls имеют scoped loading/disabled labels; delete/permanent delete/cover removal/item removal требуют явного подтверждения. Dialog lifecycle восстанавливает focus и закрывается Escape через Vite module, без inline application JavaScript.

## Формы обсуждений

Comment/reply/edit/report forms — Livewire forms с visible label, escaped draft, whole-comment spoiler checkbox, локальным Unicode-счётчиком для create/reply/edit, server/client maximum, scoped loading/disabled state и localized error/status live region. Счётчик обновляет Vite-модуль без запроса на каждое нажатие, а canonical maximum повторно проверяет PHP value object. Enter в multiline textarea остаётся переносом строки; публикация происходит только submit button/form action. Composer очищается и получает новый UUID token только после success; recoverable validation/rate/target failure сохраняет текст. При смене title/season/episode scope максимум три draft сохраняются только в locked текущем component state, не localStorage/public cache.

Body и spoiler повторно валидируют canonical actions, report category/reaction/moderation/restriction/sort/target — enums. Client не передаёт author/status/deletion reason/moderator/target class. Edit передаёт expected version: materially stale body не перезаписывает новую row, а lost-response retry уже сохранённого exact body/spoiler возвращается semantic no-op без ложной edited/cache mutation. Reply/edit/report mode имеют Cancel; delete/moderation/restriction используют translated confirmation. Dialog закрывается Escape и возвращает focus через Vite module. Persistent draft sync, Markdown toolbar, mention picker и premium control не рендерятся.

## Формы отзывов

`CatalogTitleReviews` composer has visible title/body labels, optional labelled 1–10 rating select, whole-body spoiler checkbox, character guidance, submit/cancel and localized live error/success/loading. Create draft is opaque-account/target-scoped; edit draft is opaque-account/review-scoped, stored in `sessionStorage` for at most 24 hours, restored after recoverable failure and cleared only after confirmed success/cancel. This prevents cross-account draft reuse in one browser session; browser storage failure does not block submission.

Edit never exposes author/target/status/verified fields; expected version and stable review ID are revalidated. Delete/restore/vote/report/preferences and moderation actions have explicit buttons/confirmations/loading locks; destructive GET routes do not exist. Client maxlength/disabled/select are fallback UX, while policy, value objects, canonical rating service, anti-spam, restriction and transaction enforce server truth.

## Формы тегов

Personal create/edit form принимает только label, optional plain description и optional original-content locale из allowlist. Owner/type/visibility/moderation/code/public slug отсутствуют; service повторно нормализует/валидирует данные. Edit передаёт locked UUID + optimistic version, delete/restore имеют explicit translated confirmation, status/error live region и scoped loading lock. Original Unicode label не меняется при interface locale switch.

Title personal selector разделяет persisted assignments и bounded draft UUID list. Search использует debounced Livewire model, checkbox/listbox остаётся keyboard/touch native, create-and-select вызывает тот же `PersonalTagService`. Apply transactionally reconciles полный authorized set; Cancel заново читает persisted IDs и не сохраняет draft. Assignment/remove не используют GET и не меняют другие library fields.

Global admin form принимает только enum-backed type/visibility/moderation/source, safe canonical label/slug, optional code, one supported translation locale, alias/synonym/provider stable IDs и explicit merge target. Create imported/hidden public, code mutation, lifecycle state spoofing, alias conflict/self synonym/invalid merge блокируются service. Merge/archive/title assignment требуют confirmation/impact и per-action loading state; private personal tags не являются options.

Все видимые strings/errors/confirmations/ARIA находятся в exact-parity `lang/{ru,en}/tags.php`. Form state остаётся небольшим scalar/UUID payload: Eloquent graphs, owner IDs, counts, aliases/translations/provider rows и SEO data формируются server-side query/DTO, не public Livewire properties.
