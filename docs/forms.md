# Формы

Обновлено: 13.07.2026

## Публичные формы

- Основная форма поиска каталога работает через full-page `App\Livewire\CatalogSeries`, синхронизирует `q` с URL и проверяется через `App\Http\Requests\CatalogTitlesRequest`; без JavaScript она отправляет обычный `GET /titles`.
- Header search использует тот же маршрут и тот же параметр `q`; значение для layout готовит `App\View\ViewData\AppLayoutData`. На catalog listing routes он скрывается, чтобы страница содержала один search landmark; на остальных страницах доступное имя формы — `Поиск по всему каталогу`.
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
- Мобильные фильтры находятся в native `<dialog>`; кнопка показывает число активных фильтров, Escape закрывает диалог, а после `close` фокус возвращается на открывший control. На desktop тот же единственный GET form отображается как sticky sidebar.
- `npm run build` нужен после изменения Blade-компонентов форм, потому что классы Tailwind участвуют во frontend build.
