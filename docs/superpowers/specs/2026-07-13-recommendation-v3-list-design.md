# Recommendation v3 и единый список на странице сериала

## Цель

Заменить рекомендационный алгоритм `v2`, в котором широкий жанр и общая заполненность кандидата могут перевесить реальное сходство, на объяснимый content-aware алгоритм `v3`. На странице тайтла блок «Советуем посмотреть» должен стать одним вертикальным списком без grid-вариантов и вложенных карточек.

Пользователь делегировал выбор реализации формулировкой «сделай всё по своей рекомендации». Выбран вариант без внешнего inference API и без новой production dependency: каталог уже содержит достаточно описаний и нормализованных связей для детерминированного offline ranking.

## Проверенный baseline

- В публичном каталоге 34 179 тайтлов и у каждого есть описание.
- `catalog_title_recommendations` содержит 410 131 строку `v2`; coverage формально равен 100%, поэтому проблема состоит в relevance, а не в пустых блоках.
- У `Именно так/Aynen Aynen` есть один широкий жанр `комедия`, страна `Турция`, два актёра, режиссёр, возраст `18+`, рейтинги и описание отношений друзей, но нет тегов, эпизодов или доступного видео.
- Текущий `sourceSignalScore()` добавляет кандидату баллы за его собственные положительные сигналы. Из-за этого общие `rating` и `page_quality` повышают сходство даже без общего содержательного признака.
- Текущая выдача включает тематически далёкие «Конь БоДжек», «Бессмертные» и анимационные сериалы. Более подходящие записи каталога — турецкие relationship/romantic-comedy тайтлы «Повсюду ты», «Любовь напрокат», «Ранняя пташка», «Постучись в мою дверь» и «Первый и последний (2021)».
- Desktop-разметка смешивает одну крупную grid-card, четыре compact-card во вложенной рамке и ещё одну grid-секцию. Полноразмерный снимок показывает, что постеры доминируют над объяснениями и создают длинные пустые вертикальные зоны. На mobile topology меняется ещё раз.
- Механический layout scan не нашёл запрещённых произвольных spacing/z-index значений. Проблема структурная: одинаковые карточки и разные topology внутри одного блока.

## Интернет-исследование

- [IMDb](https://www.imdb.com/title/tt10841904/) классифицирует `Aynen Aynen` как турецкую comedy/romance 2019–2021 с короткими эпизодами около 10 минут.
- [TV+](https://tvplus.com.tr/blog/kategori/dizi/aska-dair-en-iyi-8-romantik-dizi) описывает сериал как лёгкую романтическую комедию о повседневной динамике пары, с короткими эпизодами, быстрым темпом и современным юмором.
- Исследование [Content Recommendation System Application with TF-IDF](https://dergipark.org.tr/en/pub/ajit-e/article/1012354) подтверждает применимость текстовых признаков к фильмам и сериалам. В проекте используется bounded тематический профиль с inverse-frequency весами вместо полного словаря: это сохраняет объяснимость и существующий memory budget.
- Оригинальная работа [The Use of MMR](https://www.cs.cmu.edu/~jgc/publication/The_Use_MMR_Diversity_Based_LTMIR_1998.pdf) задаёт принцип relevance + novelty. `v3` применяет небольшой greedy redundancy penalty только после вычисления relevance, чтобы разнообразие не поднимало слабый результат выше сильного.

## Рассмотренные варианты

### 1. Только перенастроить фиксированные веса `v2`

Самый маленький diff, но один широкий жанр по-прежнему остаётся главным источником кандидатов. Вариант не различает романтическую, абсурдистскую, анимационную и бытовую комедию и поэтому отклонён.

### 2. Content-aware `v3` на локальных данных — выбран

Нормализованные relations дополняются ограниченным тематическим профилем из русского title/description. Редкие признаки получают больший вес, общие — меньший. Сходство, качество и diversity остаются отдельными buckets. Решение детерминировано, объяснимо, не требует сети во время rebuild и укладывается в текущий importer boundary.

### 3. Внешние embeddings/LLM или сторонний movie API

Может дать более богатую семантику, но добавляет production dependency, стоимость, rate limits, лицензионные условия и недетерминированность массового rebuild. Без отдельного запроса на такую интеграцию вариант отклонён.

## Архитектура

### Тематический профиль

Новый `CatalogRecommendationThemeExtractor` принимает готовые plain-text `title`, `original_title` и `description` и возвращает bounded map theme key → Russian label. Первая версия содержит только проверяемые доменные темы с Unicode-aware patterns:

- `romance`, `relationships`, `friendship`, `family`, `youth`;
- `workplace`, `school`, `medical`, `legal`, `crime`, `mystery`;
- `fantasy`, `supernatural`, `science_fiction`, `historical`, `military`;
- `adventure`, `sports`, `music`, `show_business`, `everyday_life`.

Extractor не выполняет запросов и не хранит provider prose. Он ограничивает число тем на тайтл, нормализует `е/ё`, регистр и пробелы, а unit tests фиксируют positive и negative examples. Для `Именно так` ожидаются как минимум `romance`, `relationships` и `friendship`.

### Candidate generation

`CatalogTitleRecommendationBuilder` сохраняет текущий packed inverted index для relations и добавляет bounded packed maps для:

- отдельной темы;
- `theme + country`;
- `theme + genre`.

Composite keys нужны, чтобы popular theme `romance` сначала привёл к турецкой романтической комедии, а не к случайной мелодраме из всего каталога. Все candidate scans остаются детерминированно ограниченными конфигурацией; current title никогда не входит в свой pool.

### Relevance scoring

Алгоритм хранит три существующих buckets, но меняет их смысл:

- `metadata_score`: общие relations, тип, близость года и темы описания;
- `source_score`: только реально общие provider recommendation signals из allowlist. Собственная заполненность кандидата, `rating`, `page_quality` и дубли taxonomy не являются сходством;
- `quality_score`: обязательное опубликованное видео, затем небольшой bounded bonus за рейтинг, отзывы и количество доступных media.

Relation/theme contribution умножается на bounded inverse-frequency factor. Редкий общий актёр, режиссёр или конкретная тема значит больше, чем `комедия`, `драмы` или `Турция`. Quality не может самостоятельно провести кандидата через minimum relevance gate.

Сохранённые `reasons` включают contribution каждого признака. Theme-reasons хранят стабильные keys, а модель переводит их в короткие русские badges: `Романтика`, `Отношения`, `Дружба`, затем `Актёры`, `Режиссёр`, `Страна`, `Год`, `Видео` по убыванию вклада.

### Diversity reranking

После relevance sort greedy re-ranker выбирает первый самый сильный результат, затем вычитает небольшой penalty за Jaccard-overlap dominant themes/relations с уже выбранными строками. Penalty ограничен и не может компенсировать большой relevance gap. В БД остаётся исходный `score`; `rank` отражает итоговый порядок. Версия алгоритма — `v3`.

### Import lifecycle

Публичная команда не меняется: `php artisan seasonvar:import`. Full и queued-finalizer cycles продолжают запускать один массовый recommendation rebuild после catalog/media maintenance. Rebuild остаётся локальным и не вызывает интернет. Summary сохраняет coverage, stored count, version и duration, как сейчас.

## UI

`CatalogTitlePageBuilder` возвращает одну коллекцию presentation-safe list items. Precomputed rows и fallback нормализуются в одной PHP boundary; Blade не объединяет коллекции и не выполняет запросы.

Блок «Советуем посмотреть» рендерит один `<ol>`:

- одна строка на рекомендацию, порядок DOM равен `rank`;
- широкий poster frame слева: около 112 px на телефоне и 176–192 px начиная с `sm`;
- landscape frame заполняется по ширине через `object-cover`, без 2% overscan; допустима обрезка только по высоте;
- справа: номер, название, original title, год/type, до четырёх причин и полное читаемое описание без CSS-обрезания;
- строки разделяются обычным `divide-y`, без отдельных card borders, shadow и nested panels;
- на узком телефоне сохраняется двухколоночная строка, поэтому список не превращается обратно в grid;
- весь row имеет один основной stretched link; taxonomy links в recommendation row не выводятся, чтобы не создавать конкурирующие tab stops.

Новый `recommendation` poster/title layout используется только в этом блоке. Глобальные catalog grid cards не меняются: это предотвращает незапрошенную регрессию `/titles`, главной и истории просмотра.

Если precomputed rows отсутствуют, genre/year fallback объединяется, дедуплицируется и выводится тем же `<ol>`. Если нет и fallback, остаётся одно полезное русское empty state без второго пустого panel.

## Ошибки и границы

- Candidate без опубликованного и видимого media отбрасывается до ranking.
- Missing description даёт пустой theme profile, но metadata scoring продолжает работать.
- Неизвестный theme key не попадает в публичный badge.
- Missing recommendation table не ломает title page; fallback остаётся доступным.
- Builder начинает все title/candidate queries с существующей public visibility boundary.
- Новых HTTP-запросов, пользовательского profiling и скачивания видео нет.

## Проверка

TDD фиксирует:

1. extractor themes и negative matches;
2. generic candidate quality больше не считается source similarity;
3. broad genre без theme/strong relation не проходит gate;
4. турецкая relationship comedy ранжируется выше тематически далёкого сериала с общим актёром;
5. candidate без published media не сохраняется;
6. MMR сохраняет лучший первый результат и только мягко разводит последующие;
7. `v3`, score breakdown, ranks, reasons и max-per-title сохраняются корректно;
8. title page выводит один ordered list без recommendation grid classes и в rank order;
9. fallback использует тот же list layout и не дублирует тайтлы;
10. publication boundary сохраняется.

После реализации выполняются focused PHPUnit tests, Pint, полный `php artisan test`, `npm run build`, production rebuild, SQL-метрики и Playwright desktop/tablet/mobile. Browser QA проверяет отсутствие horizontal overflow, console/page errors, current-title duplicates, пустого блока и локальных asset failures.
