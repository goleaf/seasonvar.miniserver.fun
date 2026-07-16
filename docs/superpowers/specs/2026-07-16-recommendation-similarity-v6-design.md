# Измеримое качество и content similarity v6

## Цель

Подготовить безопасную замену сохранённых рекомендаций `v4`/текущего кода `v5` на объяснимый `v6`, который не принимает совпадения внутри посторонних слов за тему, учитывает специфичность и долю пересечения признаков, сохраняет точные причины и переключается в production только после измеримого сравнения с действующей выдачей.

Это первый из двух последовательных recommendation-проектов. Персонализация использует его результат и описана отдельно; embeddings, collaborative filtering и внешние inference API в этот этап не входят.

## Проверенный baseline на 16.07.2026

- `catalog_titles`: 32 926 строк.
- `catalog_title_recommendations`: 383 929 строк, 32 453 source-title, среднее 11,83 строки; локальная БД пока содержит `v4`.
- 473 тайтла не имеют сохранённых рекомендаций, среди них есть популярные и хорошо заполненные записи.
- Только 19,93% направленных пар имеют обратную пару.
- В качестве кандидатов встречаются 28 933 тайтла; 152 тайтла рекомендуются не менее 100 раз, максимум — 222 раза.
- Полный rebuild занимает примерно 514–607 секунд.
- В `catalog_title_recommendation_signals` около 626 тысяч generic-сигналов, но builder читает только `provider_recommendation` и `related_title`; таких строк в локальной БД нет.
- `catalog_title_relations` не содержит явных related-связей.
- Theme extractor назначает хотя бы одну тему 31 077 тайтлам, то есть 94,38% каталога.

Подтверждённые коллизии текущих substring-regex:

| Технический match | Ошибочное значение | Число тайтлов |
| --- | --- | ---: |
| `актер` внутри `характер` | шоу-бизнес | 1266 |
| `маг` внутри `магазин` | фэнтези | 368 |
| `лице` как форма слова «лицо» | учёба | 365 |
| `войн` внутри `двойной` | военная тема | 209 |
| самостоятельное `семь` | семья | 196 |
| `спорт` внутри `паспорт` | спорт | 27 |

Theme сейчас является strong gate, поэтому такие совпадения могут провести нерелевантную пару через `min_score`. Это блокирует прямое включение `v5`.

## Рассмотренные варианты

### 1. Перенастроить веса `v5`

Маленький diff, но ложный feature остаётся сильным независимо от веса. Вариант не даёт контролируемого rollout и отклонён.

### 2. Измеримый deterministic `v6` — выбран

Точный tokenizer/phrase matcher, overlap-aware scorer, faithful reasons, offline golden set и shadow comparison сохраняют объяснимость, не добавляют сеть или production dependency и соответствуют текущему importer boundary.

### 3. Embeddings или внешний inference

Могут улучшить семантику, но до появления baseline-метрик невозможно доказать пользу. Они добавляют стоимость, versioning и operational boundary. Вариант откладывается до измеримого провала deterministic `v6` на конкретных golden cases.

## Архитектурные границы

Нынешний `CatalogTitleRecommendationBuilder` остаётся публичным orchestrator существующего import-cycle, но перестаёт владеть всей логикой. Новые внутренние сервисы имеют по одной ответственности:

- `CatalogRecommendationFeatureExtractor` создаёт immutable/bounded content profile из уже загруженных полей и relations;
- `CatalogRecommendationCandidateGenerator` строит deterministic bounded pool из inverted indexes;
- `CatalogRecommendationPairScorer` как чистая функция сравнивает два профиля и возвращает typed score/breakdown/reasons либо `null`;
- `CatalogRecommendationDiversityReranker` применяет bounded redundancy/concentration penalties после relevance;
- `CatalogRecommendationQualityEvaluator` считает offline-метрики для двух наборов rows без изменения production-таблицы;
- builder загружает данные, вызывает units, сохраняет строки транзакционно и возвращает operational summary.

Существующий `CatalogRecommendationService` остаётся единственной runtime orchestration boundary для title/discovery/home/library/API. Ни controller, ни Blade не получают scoring logic.

## Извлечение признаков

Theme extractor переходит от свободных подстрок к нормализованным Unicode tokens и allowlisted фразам:

- нормализация регистра, `ё→е`, пробелов и пунктуации;
- точные словоформы или stem только внутри границ токена;
- multi-token phrase matcher для фраз вроде «шоу-бизнес» и «научная фантастика»;
- явные negative corpus cases для найденных коллизий;
- стабильные theme codes и русские labels остаются отделены;
- максимум восемь тем сохраняет memory bound.

Версия extractor включается в recommendation algorithm version. Неизвестные keys не попадают в публичные explanations.

Generic taxonomy/rating/year/page-quality signals не дублируются в recommendation-signal storage после перехода. Таблица остаётся для реально внешних нормализованных связей `provider_recommendation`/`related_title` с provenance. Удаление прежних managed generic rows выполняется отдельным обратимым maintenance-шагом только после проверки, что ни один reader их не использует.

## Candidate generation

Pool остаётся bounded и deterministic. Источники кандидатов:

- genres, tags, directors, actors, networks/studios;
- темы и составные ключи `theme+genre`, `theme+country`;
- подтверждённые explicit/provider relations;
- fallback по watchable shared genre только после отсутствия рассчитанных rows.

Broad feature не должен вытеснять редкое точное совпадение. Sampling использует document frequency и stable ID tie-break. Текущий title исключается до scoring. Candidate обязан пройти canonical visibility и иметь опубликованный реально воспроизводимый media.

Provider-only relation получает отдельный candidate path; иначе signal, существующий только в signal table, оставался бы недостижимым для scorer.

## Pair scoring

Scorer сохраняет независимые buckets:

- `metadata_score`: content similarity;
- `source_score`: только общие или направленные verified provider relations;
- `quality_score`: небольшой bonus после relevance gate;
- `total`: сумма после ограниченных penalties.

Для множественных relations используется сочетание bounded IDF и overlap coefficient/Jaccard. Требования:

- один общий режиссёр, актёр или tag не является безусловно strong match;
- две общие роли в больших cast учитывают долю пересечения и частоту каждого актёра;
- high-cardinality cast/crew получает bounded penalty;
- тема считается сильной только после точного token/phrase match;
- year, country, status, translation, age и candidate quality не проводят пару через relevance gate самостоятельно;
- source relation не смешивается с generic page-quality/rating;
- quality никогда не поднимает недоступный или нерелевантный candidate.

Конкретные веса хранятся в versioned config, но quality gate сравнивает итоговые rankings, а не объявляет веса правильными сами по себе.

## Причины рекомендации

Pair scorer возвращает reason codes вместе с вкладом и supporting count/ratio. Persistence хранит только bounded structured breakdown. Canonical service передаёт до четырёх причин без сведения всех stored reasons к одному общему enum.

Публичный вывод использует 2–4 коротких русских labels, например «Романтика», «Общий режиссёр», «Похожие актёры», «По данным источника». В HTML/API отсутствуют raw score, internal IDs, private history и provider payload. Причина обязана соответствовать реально ненулевому вкладу выбранной пары.

## Quality gate и golden set

Создаётся versioned fixture с ручными relevance grades `0`, `1`, `2` для стратифицированного набора:

- популярные сериалы;
- sparse metadata;
- аниме;
- документальные проекты;
- телепередачи;
- длинные cast/crew;
- тайтлы без recommendations;
- все найденные лексические коллизии.

Offline evaluator считает:

- `Precision@12` и `nDCG@12`;
- долю source-title без результатов;
- watchable availability@12, целевое значение 100%;
- catalog/candidate coverage;
- concentration: top candidate frequency и долю кандидатов с ≥100 входящими связями;
- intra-list diversity;
- explanation faithfulness;
- долю явно оценённых строк golden pool (`judgment coverage`), чтобы не считать неразмеченные пары нерелевантными;
- churn относительно active version;
- duration и peak memory rebuild.

`v6` не активируется, если build не содержит ни одной строки либо ухудшает `nDCG@12`, availability или количество пустых выдач сверх явно записанного допуска. Первоначальный допуск: candidate rows > 0, availability не ниже 100%, `nDCG@12` не ниже baseline, empty-source count не растёт, а `judgment coverage` golden pool не ниже 80%. Явный override отсутствующей локальной golden-разметки не отменяет требования непустой, доступной и объяснимой выдачи. Неразмеченные пары исключаются из Precision/nDCG, а не получают вымышленную оценку `0`; остальные метрики публикуются в summary для ручной оценки.

## Shadow build и активация

Новая additive shadow table хранит rows по `algorithm_version`, source и rank; active table/reader не переключается во время расчёта. Поток:

1. построить `v6` shadow rows;
2. вычислить baseline и candidate metrics;
3. остановить активацию при нарушении gate, сохранив действующие rows;
4. при успехе атомарно обозначить active version и инвалидировать только versioned cache namespace;
5. удалить устаревшие shadow runs bounded maintenance-процессом.

Миграция additive и reversible. Сбой одного source-title сохраняет прежнюю активную выдачу и отражается в summary; сбой всего build не оставляет смешанную active version. Missing recommendation schema сохраняет существующий genre fallback.

Публичная команда остаётся единственной: `php artisan seasonvar:import`. Отдельная production import-команда не создаётся; evaluator может быть internal service/test helper или опцией существующего maintenance path, не новым публичным импортом.

## Инкрементальное обновление

После устойчивого full `v6` targeted import помечает изменённый title и titles из затронутых inverted-feature buckets. Scoped rebuild обновляет source-title и bounded neighbours. Если dirty set превышает настраиваемый предел или меняется algorithm/feature version, выполняется полный build.

Persistence сравнивает hash/rank payload и не переписывает неизменённые rows. Это уменьшает churn и invalidation, но full rebuild остаётся безопасным fallback.

## Тестирование и rollout

TDD покрывает pure units отдельно, затем integration boundary:

1. token/phrase extractor и corpus false positives;
2. overlap/IDF/high-cardinality score cases;
3. provider-only candidate path;
4. visibility и playable-media gate;
5. exact structured reasons и public labels;
6. deterministic candidate/rank tie-break;
7. golden metrics;
8. shadow failure без изменения active rows;
9. atomic activation и cache namespace;
10. scoped rebuild и full fallback;
11. importer summary и единственную публичную команду;
12. title/discovery/API reason rendering и privacy.

Проверки: focused PHPUnit, Pint, полный `php artisan test`, `npm run build`, read-only SQL metrics и Playwright для title с precomputed rows и с fallback. Production-sized rebuild запускается только когда активный текущий импорт завершён; существующий импортный процесс не прерывается.

## Вне области этого проекта

- Collaborative filtering.
- User embeddings и vector database.
- Внешний inference API.
- Сбор impression/click/play analytics.
- Изменение публичных recommendation routes или типов discovery.
