# Персонализация и безопасное исследование рекомендаций

## Статус реализации на 16 июля 2026 года

Контракт реализован за выключенным rollout-флагом. Профиль имеет уровни `cold/low/medium/high`, объединяет bounded evidence с давностью и глубиной, использует только активную совместимую шкалу similarity `v6`, осторожно ослабляет признаки после минимум трёх независимых отрицательных тайтлов и резервирует не более 15% слотов для детерминированного релевантного исследования. Старый ranking остаётся rollback-путём при `0%`.

По умолчанию `RECOMMENDATIONS_PERSONALIZED_V2_ENABLED=false` и `RECOMMENDATIONS_PERSONALIZED_V2_PERCENT=0`. Частная выдача не использует shared cache; regression-тест проверяет отсутствие user/source IDs, точного прогресса, названий личных коллекций/тегов и negative feature keys в URL, HTML, Livewire snapshot и публичном API. Включение разрешено только после активной `v6`-сборки с `score_min/score_median/score_p95` и проходит этапы `0 → internal fixture → 10 → 50 → 100` с немедленным возвратом к `0` при нарушении privacy, exact exclusions, watchability или relevance floor.

## Цель

Поверх прошедшего quality gate content similarity `v6` построить честную персональную выдачу, которая учитывает силу и свежесть нескольких пользовательских сигналов, не переобучается на одном просмотре, уважает отрицательную обратную связь и оставляет небольшое место для релевантного нового контента.

Этот проект начинается только после активации стабильного `v6`. На текущей локальной базе почти нет пользовательской истории, поэтому collaborative filtering и статистическое обучение между пользователями не применяются.

## Проверенный baseline

Текущий `CatalogPersonalizedRecommendationQuery` использует progress, watchlist, watch status, rating, collections и personal tags, но:

- для одного source-title сохраняется только максимальный вес, а несколько подтверждений не усиливают уверенность;
- score не затухает по давности;
- одна завершённая серия может представить весь тайтл как completed signal;
- оценки 7–10 дают одинаковую силу;
- глубина просмотра не учитывается;
- один слабый source-title уже позволяет назвать выдачу персональной;
- отрицательное действие исключает конкретный тайтл, но не даёт осторожного feature-level demotion;
- explanation показывает только один общий источник, а не причину конкретного candidate;
- exploration quota отсутствует.

Существующие сильные границы сохраняются: canonical visibility, playable-first availability, exact feedback exclusions, private cache bypass и bounded repeat suppression.

## Архитектурные компоненты

- `CatalogPersonalPreferenceProfileBuilder` агрегирует private events в bounded profile без сериализации в URL/HTML/shared cache.
- `CatalogPersonalSourceSignal` представляет один source-title: confidence, recency factor, evidence codes и effective weight.
- `CatalogPersonalizedCandidateScorer` объединяет content similarity `v6`, preference confidence, availability и bounded novelty.
- `CatalogRecommendationExplorationMixer` детерминированно выбирает exploit/explore slots после relevance floor.
- `CatalogPersonalizedRecommendationQuery` остаётся query boundary и orchestrates эти pure services.
- `CatalogRecommendationService` сохраняет текущий public interface и fallback semantics.

Новые DTO используют enum codes и scalar values. Они не содержат названия личных коллекций, personal tags, точный episode/timecode или raw comment/review text.

## Агрегация положительных сигналов

Для каждого source-title evidence складывается с diminishing returns и общим cap:

- meaningful progress учитывает просмотренную долю и число реально начатых/завершённых серий;
- completed-title требует достаточной глубины по доступным сериям, а не одной completed episode;
- rating масштабируется внутри 7–10;
- watchlist/planned — слабый intent;
- watching — средний intent;
- completed и высокая rating — сильные evidence;
- собственная collection/tag assignment используется только как bounded подтверждение, без раскрытия label.

Каждый evidence получает recency decay по semantic timestamp. Если старый row не имеет надёжного semantic timestamp, используется консервативный нейтральный factor, а не выдуманная дата.

Несколько evidence одного source-title усиливают confidence до cap. Несколько независимых source-title, поддерживающих одного candidate, суммируются с diminishing returns и сильнее одного случайного сигнала.

## Уровни уверенности и fallback

Profile получает уровень:

- `cold`: нет meaningful evidence — честный `editorial → trending → popular` fallback;
- `low`: один слабый source — публичная основа с небольшим personal boost, display type не обещает глубокую персонализацию;
- `medium`: несколько evidence или один сильный source — смешанная персональная выдача;
- `high`: несколько независимых сильных source-title — основной personalized ranking.

Порог зависит от effective confidence, а не просто количества database rows. Если после exclusions/watchability недостаточно candidates, сервис дозаполняет публичным fallback без дубликатов и с корректными reason codes.

## Отрицательные сигналы

`blacklisted`, `not_interested`, `dropped` и explicit recommendation feedback продолжают жёстко исключать сам тайтл.

Feature-level demotion разрешён только когда есть минимум три независимых отрицательных source-title с общим конкретным feature. Demotion:

- bounded и затухает по времени;
- не создаёт постоянный genre blacklist;
- не может скрыть explicit/editorial relation без отдельного exact exclusion;
- не применяется к broad feature на основании одного события;
- хранится/вычисляется приватно и не раскрывается как публичная причина.

Положительная новая активность может постепенно компенсировать старый demotion.

## Candidate scoring

Candidate обязан пройти visibility, exact exclusions и playable gate. Итоговый порядок формируется из:

- content relevance `v6`;
- суммы capped contributions от независимых source signals;
- небольшого availability/convenience bonus;
- bounded novelty bonus только после relevance floor;
- repeat suppression и diversity penalties.

Similarity score не делится на магическое фиксированное число без versioned normalization. Для active algorithm сохраняются наблюдаемые min/median/p95 или deterministic configured range, после чего similarity переводится в bounded `0..1` component. Это предотвращает смену смысла personal weights при новом content algorithm.

## Объяснения и приватность

Карточка показывает до трёх честных broad reasons:

- «По вашим высоким оценкам»;
- «На основе просмотренного»;
- «Похожие жанры и темы»;
- конкретную content-reason из `v6`, если она действительно поддерживает пару;
- «Новое для вас» только для exploration slot.

Не показываются название source-title как утверждение о поведении пользователя, конкретная серия, прогресс, время, personal tag/collection, negative profile или внутренний score. Shared cache запрещён для personalized results; cache key не содержит raw user history.

## Exploitation и exploration

По умолчанию до 85% слотов занимает exploit ranking. До 15% — explore candidates, но только если они:

- проходят тот же visibility/playable boundary;
- имеют content relevance не ниже отдельного floor;
- не находятся в exact exclusions или recent repeat set;
- не являются near-duplicate/franchise overflow;
- детерминированы для одного bounded session seed.

Если подходящих explore candidates нет, слот возвращается exploit pool. Исследование не является случайным заполнением нерелевантным контентом.

## Ошибки и fallback

- Ошибка одного private signal reader удаляет только этот evidence source и фиксируется без PII; публичный fallback остаётся доступным.
- Недоступный content candidate отбрасывается до personal score.
- Отсутствующий `v6` similarity возвращает canonical public fallback, а не старую приватную эвристику.
- Несовместимая score-normalization version запрещает personal activation до пересчёта.
- Feedback write использует существующие authorization/validation boundaries и после commit инвалидирует только scoped owner cache.

## Измерение качества

До накопления аудитории применяются deterministic scenario fixtures:

- cold, low, medium и high profiles;
- conflicting positive/negative evidence;
- stale versus recent evidence;
- long-running series с одной завершённой серией;
- multiple-source support;
- exact exclusions;
- exhausted explore pool;
- privacy serialization checks.

Offline метрики: source diversity, personalized coverage, exact-exclusion violations (целевое значение 0), watchable availability (100%), repeat rate, explore relevance floor, fallback share и reason faithfulness.

События `shown`, `opened`, `play_started` не добавляются в первый выпуск. Их можно спроектировать отдельно после появления политики согласия, retention, abuse filtering и минимального размера аудитории. Только после этого возможны online CTR/play-start и A/B comparisons.

## Rollout

1. Добавить pure profile/scorer tests без включения нового ranking.
2. Сравнить старый и новый personal result на deterministic fixtures.
3. Включить для internal/test users через server-side config flag.
4. Проверить privacy, cache isolation, exclusions и fallback.
5. Расширять процент активации только при стабильных operational metrics.
6. После полного перехода удалить старую max-only weighting branch отдельным изменением.

Версия personal ranking и public content algorithm версионируются раздельно. Откат personalization не откатывает `v6` content similarity.

## Проверка

TDD покрывает profile aggregation, recency, rating/progress depth, confidence levels, normalization, exact exclusions, bounded negative demotion, mixer ratio/floor, deterministic seed, reason privacy, cache isolation и public fallback. Затем выполняются focused PHPUnit tests, полный `php artisan test`, Pint, `npm run build` при изменении Blade и Playwright для guest/cold/active/feedback flows.

## Вне области проекта

- Collaborative filtering и cross-user similarity.
- Embeddings/vector database.
- Скрытая behavioral analytics без отдельной политики.
- Публичное раскрытие точной истории пользователя.
- Изменение существующих recommendation route codes.
