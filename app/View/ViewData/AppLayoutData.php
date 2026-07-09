<?php

namespace App\View\ViewData;

use Illuminate\Support\Str;

class AppLayoutData
{
    /**
     * @param  array<string, mixed>  $viewData
     * @return array<string, mixed>
     */
    public function from(array $viewData): array
    {
        extract($viewData, EXTR_SKIP);
        $siteName = config('app.name', 'Каталог сериалов');
        $seo = $seo ?? [];
        $pageTitle = trim((string) ($seo['title'] ?? $title ?? $siteName));
        $pageTitle = $pageTitle !== '' ? $pageTitle : $siteName;
        $fullTitle = Str::contains(Str::lower($pageTitle), Str::lower($siteName))
            ? $pageTitle
            : $pageTitle.' - '.$siteName;
        $seoDescription = trim((string) ($seo['description'] ?? 'Каталог сериалов онлайн с фильтрами по жанрам, странам, актерам, годам, сезонам и сериям.'));
        $seoDescription = Str::limit(preg_replace('/\s+/u', ' ', strip_tags($seoDescription)) ?: $seoDescription, 180, '...');
        $canonicalUrl = $seo['canonical'] ?? url()->current();
        $seoSearchContext = collect($seo['search_context'] ?? []);
        $seoSearchContextTitle = trim((string) $seoSearchContext->get('title', ''));
        $seoSearchContextSlug = trim((string) $seoSearchContext->get('slug', ''));
        $seoSearchUrl = function ($query) use ($seoSearchContextTitle, $seoSearchContextSlug) {
            $query = trim(preg_replace('/\s+/u', ' ', strip_tags((string) $query)) ?: '');

            if ($query === '') {
                return route('titles.index');
            }

            $params = ['q' => $query];

            if ($query !== '{search_term_string}' && $seoSearchContextTitle !== '' && $seoSearchContextSlug !== '') {
                if (! str_contains(mb_strtolower($query), mb_strtolower($seoSearchContextTitle))) {
                    $params['q'] = trim($seoSearchContextTitle.' '.$query);
                }

                $params['title'] = $seoSearchContextSlug;
            }

            return route('titles.index', $params);
        };
        $robots = $seo['robots'] ?? 'index,follow,max-image-preview:large,max-snippet:-1,max-video-preview:-1';
        $seoType = $seo['type'] ?? 'website';
        $seoImage = $seo['image'] ?? null;
        $seoVideo = $seo['video'] ?? null;
        $seoLocale = $seo['locale'] ?? 'ru_RU';
        $htmlLang = $seo['htmlLang'] ?? 'ru';
        $seoTags = collect($seo['tags'] ?? [])->filter()->unique()->take(25)->values();
        $seoClusterTerms = collect($seo['keyword_clusters'] ?? [])
            ->flatMap(fn ($cluster) => collect($cluster['items'] ?? []));
        $topicTerms = collect($seo['topic_terms'] ?? [])
            ->merge($seoTags)
            ->merge($seo['search_phrases'] ?? [])
            ->merge($seoClusterTerms)
            ->filter()
            ->map(fn ($term) => trim((string) $term))
            ->filter()
            ->unique()
            ->take(40)
            ->values();
        $seoIntents = collect([
            'смотреть онлайн',
            'сериал онлайн',
            'все серии',
            'сезоны и серии',
            'описание сериала',
            'актеры и роли',
            'жанры и страны',
        ])->merge($topicTerms->take(12))->unique()->values();
        $semanticEntities = $topicTerms->take(24)->map(fn ($term) => [
            '@type' => 'Thing',
            'name' => $term,
            'url' => $seoSearchUrl($term),
        ])->values();
        $longTailQueries = $topicTerms->take(10)
            ->flatMap(fn ($term) => collect([
                $term.' смотреть онлайн',
                $term.' сериал онлайн',
                $term.' все серии',
                $term.' сезоны и серии',
                $term.' описание и актеры',
            ]))
            ->merge($seoIntents->take(8)->map(fn ($intent) => trim($pageTitle.' '.$intent)))
            ->map(fn ($query) => trim(preg_replace('/\s+/u', ' ', (string) $query)))
            ->filter(fn ($query) => $query !== '' && mb_strlen($query) <= 100)
            ->unique()
            ->take(40)
            ->values();
        $relatedCollections = $topicTerms->take(8)
            ->flatMap(fn ($term) => collect([
                [
                    'name' => 'Сериалы по теме: '.$term,
                    'query' => $term.' сериалы',
                    'description' => 'Связанная подборка сериалов и страниц каталога по теме «'.$term.'».',
                ],
                [
                    'name' => $term.' смотреть онлайн',
                    'query' => $term.' смотреть онлайн',
                    'description' => 'Поиск страниц, сезонов, серий и описаний по запросу «'.$term.' смотреть онлайн».',
                ],
                [
                    'name' => $term.' актеры и описание',
                    'query' => $term.' актеры описание',
                    'description' => 'Подборка материалов с описанием, актерами, ролями и связанными сериалами по теме «'.$term.'».',
                ],
            ]))
            ->merge($seoIntents->take(6)->map(fn ($intent) => [
                'name' => $pageTitle.' - '.$intent,
                'query' => $pageTitle.' '.$intent,
                'description' => 'Связанная поисковая подборка по запросу «'.$pageTitle.' '.$intent.'».',
            ]))
            ->filter(fn ($item) => ! empty($item['name']) && ! empty($item['query']))
            ->unique('name')
            ->take(30)
            ->values();
        $semanticHubs = collect([
            [
                'title' => 'Смотреть онлайн',
                'description' => 'Быстрые SEO-ссылки на страницы просмотра, сезонов и серий по темам этой страницы.',
                'items' => $topicTerms->take(8)->map(fn ($term) => [
                    'name' => $term.' смотреть онлайн',
                    'query' => $term.' смотреть онлайн',
                    'description' => 'Поиск страниц и серий по запросу «'.$term.' смотреть онлайн».',
                ])->values(),
            ],
            [
                'title' => 'Сезоны и серии',
                'description' => 'Запросы для поиска сезонов, серий, выпусков, переводов и доступных видео.',
                'items' => $topicTerms->take(8)->map(fn ($term) => [
                    'name' => $term.' сезоны и серии',
                    'query' => $term.' сезоны серии',
                    'description' => 'Поиск сезонов, серий и связанных страниц по теме «'.$term.'».',
                ])->values(),
            ],
            [
                'title' => 'Описание, актеры, жанры',
                'description' => 'Информационные запросы для пользователей, которые ищут описание, актеров, жанры и похожие сериалы.',
                'items' => $topicTerms->take(8)->map(fn ($term) => [
                    'name' => $term.' описание актеры жанры',
                    'query' => $term.' описание актеры жанры',
                    'description' => 'Поиск описаний, актеров, жанров и связанных подборок по теме «'.$term.'».',
                ])->values(),
            ],
            [
                'title' => 'Связанные подборки',
                'description' => 'Автоматические тематические подборки, связанные с текущей страницей.',
                'items' => $relatedCollections->take(8)->map(fn ($collection) => [
                    'name' => $collection['name'],
                    'query' => $collection['query'],
                    'description' => $collection['description'] ?? $collection['name'],
                ])->values(),
            ],
        ])->map(fn ($hub) => [
            'title' => $hub['title'],
            'description' => $hub['description'],
            'items' => collect($hub['items'] ?? [])
                ->filter(fn ($item) => ! empty($item['name']) && ! empty($item['query']))
                ->unique('name')
                ->take(10)
                ->values(),
        ])->filter(fn ($hub) => $hub['items']->isNotEmpty())->values();
        $snippetBlocks = collect([
            [
                'title' => 'Кратко о странице',
                'text' => $seoDescription,
                'query' => $pageTitle,
            ],
            [
                'title' => 'Что можно найти',
                'text' => $topicTerms->isNotEmpty()
                    ? 'На странице связаны темы: '.$topicTerms->take(10)->implode(', ').'.'
                    : 'На странице доступны поиск, описание, категории, подборки и связанные материалы каталога.',
                'query' => $topicTerms->first() ?: $pageTitle,
            ],
            [
                'title' => 'По каким запросам искать',
                'text' => $seoIntents->isNotEmpty()
                    ? 'Подходящие запросы: '.$seoIntents->take(10)->implode(', ').'.'
                    : 'Подходящие запросы: смотреть онлайн, все серии, сезоны, актеры, жанры, описание.',
                'query' => $seoIntents->first() ?: $pageTitle,
            ],
            [
                'title' => 'Связанные подборки',
                'text' => $relatedCollections->isNotEmpty()
                    ? 'Есть связанные подборки: '.$relatedCollections->pluck('name')->take(5)->implode(', ').'.'
                    : 'Связанные подборки строятся автоматически по темам и поисковым формулировкам страницы.',
                'query' => $relatedCollections->first()['query'] ?? $pageTitle,
            ],
        ])->merge($topicTerms->take(8)->map(fn ($term) => [
            'title' => 'Тема: '.$term,
            'text' => 'Тема «'.$term.'» помогает найти связанные сериалы, сезоны, серии, описания, актеров, жанры и похожие подборки.',
            'query' => $term,
        ]))->filter(fn ($block) => ! empty($block['title']) && ! empty($block['text']) && ! empty($block['query']))
            ->unique('title')
            ->take(16)
            ->values();
        $seoActions = collect([
            [
                'type' => 'SearchAction',
                'name' => 'Найти сериалы по странице «'.$pageTitle.'»',
                'label' => 'Найти по теме страницы',
                'url' => $seoSearchUrl($pageTitle),
                'description' => 'Поиск сериалов, сезонов, серий и связанных материалов по теме этой страницы.',
            ],
            [
                'type' => 'ReadAction',
                'name' => 'Читать информацию: '.$pageTitle,
                'label' => 'Читать информацию',
                'url' => $canonicalUrl,
                'description' => 'Открыть описание, связанные темы, подборки и быстрые ответы на этой странице.',
            ],
            [
                'type' => 'ViewAction',
                'name' => 'Открыть полный каталог сериалов',
                'label' => 'Открыть каталог',
                'url' => route('titles.index'),
                'description' => 'Перейти в общий каталог сериалов с поиском и фильтрами.',
            ],
            [
                'type' => 'WatchAction',
                'name' => 'Смотреть доступные серии: '.$pageTitle,
                'label' => 'Смотреть серии',
                'url' => $canonicalUrl,
                'description' => 'Открыть страницу с доступными сезонами, сериями, описанием и связанными материалами.',
            ],
        ])->merge($topicTerms->take(8)->map(fn ($term) => [
            'type' => 'SearchAction',
            'name' => 'Найти сериалы: '.$term,
            'label' => $term,
            'url' => $seoSearchUrl($term),
            'description' => 'Поиск сериалов и страниц каталога по теме «'.$term.'».',
        ]))->filter(fn ($action) => ! empty($action['name']) && ! empty($action['url']))->unique('name')->take(24)->values();
        $semanticGlossary = $topicTerms->take(18)->map(fn ($term) => [
            'term' => $term,
            'url' => $seoSearchUrl($term),
            'description' => 'Тема «'.$term.'» связана со страницей «'.$pageTitle.'» и помогает найти сериалы, сезоны, серии, описания, актеров и похожие подборки.',
        ])->values();
        $quickAnswers = collect([
            [
                'question' => 'Что есть на странице «'.$pageTitle.'»?',
                'answer' => $seoDescription,
            ],
            [
                'question' => 'Как найти похожие сериалы и подборки?',
                'answer' => $topicTerms->isNotEmpty()
                    ? 'Используйте связанные темы: '.$topicTerms->take(8)->implode(', ').'.'
                    : 'Используйте поиск, жанры, страны, годы выпуска, актеров и режиссеров в каталоге.',
            ],
            [
                'question' => 'Какие запросы связаны с этой страницей?',
                'answer' => $seoIntents->isNotEmpty()
                    ? $seoIntents->take(10)->implode(', ').'.'
                    : 'Сериалы онлайн, все серии, сезоны, описание, актеры и жанры.',
            ],
        ])->filter(fn ($item) => ! empty($item['question']) && ! empty($item['answer']))->values();
        $contentSignals = collect([
            [
                'name' => 'Темы страницы',
                'value' => $topicTerms->count(),
                'description' => 'Количество уникальных тем, тегов и смысловых связей, найденных для этой страницы.',
                'query' => $topicTerms->first() ?: $pageTitle,
            ],
            [
                'name' => 'Поисковые формулировки',
                'value' => $longTailQueries->count(),
                'description' => 'Количество long-tail запросов, автоматически созданных для внутренней навигации и SEO.',
                'query' => $longTailQueries->first() ?: $pageTitle,
            ],
            [
                'name' => 'Связанные подборки',
                'value' => $relatedCollections->count(),
                'description' => 'Количество тематических подборок, построенных из контекста текущей страницы.',
                'query' => $relatedCollections->first()['query'] ?? $pageTitle,
            ],
            [
                'name' => 'Тематические хабы',
                'value' => $semanticHubs->count(),
                'description' => 'Количество группированных SEO-хабов по просмотру, сезонам, описанию, актерам и жанрам.',
                'query' => $semanticHubs->first()['title'] ?? $pageTitle,
            ],
            [
                'name' => 'Быстрые действия',
                'value' => $seoActions->count(),
                'description' => 'Количество прямых действий для поиска, чтения, просмотра каталога и открытия страницы.',
                'query' => $pageTitle,
            ],
            [
                'name' => 'Короткие ответы',
                'value' => $quickAnswers->count(),
                'description' => 'Количество коротких ответов, сгенерированных по описанию, темам и запросам страницы.',
                'query' => $pageTitle,
            ],
        ])->filter(fn ($signal) => ! empty($signal['name']) && ($signal['value'] ?? 0) > 0)->values();
        $audiencePaths = collect([
            [
                'name' => 'Для просмотра онлайн',
                'description' => 'Путь для пользователей, которые хотят быстро найти серии, сезоны и доступное видео.',
                'query' => $pageTitle.' смотреть онлайн',
                'items' => $topicTerms->take(6)->map(fn ($term) => $term.' смотреть онлайн')->values(),
            ],
            [
                'name' => 'Для выбора сериала',
                'description' => 'Путь для пользователей, которые сравнивают жанры, страны, описание, год выпуска и похожие подборки.',
                'query' => $pageTitle.' описание жанры',
                'items' => $topicTerms->take(6)->map(fn ($term) => $term.' описание жанры')->values(),
            ],
            [
                'name' => 'Для поиска актеров и ролей',
                'description' => 'Путь для поиска актеров, режиссеров, ролей и связанных страниц каталога.',
                'query' => $pageTitle.' актеры роли',
                'items' => $topicTerms->take(6)->map(fn ($term) => $term.' актеры роли')->values(),
            ],
            [
                'name' => 'Для похожих подборок',
                'description' => 'Путь для перехода к похожим темам, long-tail запросам и связанным коллекциям.',
                'query' => $pageTitle.' похожие сериалы',
                'items' => $relatedCollections->pluck('name')->take(6)->values(),
            ],
        ])->map(fn ($path) => [
            'name' => $path['name'],
            'description' => $path['description'],
            'query' => $path['query'],
            'items' => collect($path['items'] ?? [])->filter()->unique()->take(8)->values(),
        ])->filter(fn ($path) => $path['items']->isNotEmpty())->values();
        $alsoSearches = $topicTerms->take(10)
            ->flatMap(fn ($term) => collect([
                'сериалы похожие на '.$term,
                $term.' новые серии',
                $term.' дата выхода серий',
                $term.' перевод и озвучка',
                $term.' актеры и роли',
                $term.' описание серий',
                $term.' все сезоны',
            ]))
            ->merge($audiencePaths->flatMap(fn ($path) => $path['items']))
            ->merge($longTailQueries->take(16))
            ->map(fn ($query) => trim(preg_replace('/\s+/u', ' ', strip_tags((string) $query))))
            ->filter(fn ($query) => $query !== '' && mb_strlen($query) <= 120)
            ->unique()
            ->take(60)
            ->values();
        $discoverySignals = collect([
            [
                'name' => 'Каноническая страница',
                'value' => $canonicalUrl,
                'url' => $canonicalUrl,
                'description' => 'Основной индексируемый адрес этой страницы для поисковых систем.',
            ],
            [
                'name' => 'Карта сайта',
                'value' => 'sitemap-index.xml',
                'url' => route('sitemap.index'),
                'description' => 'Главная XML-карта сайта с разделами, страницами, видео и SEO-посадочными страницами.',
            ],
            [
                'name' => 'RSS обновления',
                'value' => 'feed.xml',
                'url' => route('feed'),
                'description' => 'Лента последних обновлений каталога для поисковых систем и подписчиков.',
            ],
            [
                'name' => 'Поиск браузера',
                'value' => 'opensearch.xml',
                'url' => route('opensearch'),
                'description' => 'OpenSearch-описание для быстрого поиска по каталогу из браузера.',
            ],
            [
                'name' => 'LLMs discovery',
                'value' => 'llms.txt',
                'url' => route('llms'),
                'description' => 'Текстовый discovery-файл для систем, которые анализируют структуру портала.',
            ],
        ])->when(! empty($seo['updated_time']), fn ($signals) => $signals->prepend([
            'name' => 'Дата обновления',
            'value' => $seo['updated_time'],
            'url' => $canonicalUrl,
            'description' => 'Дата последнего обновления индексируемой информации этой страницы.',
        ]))->filter(fn ($signal) => ! empty($signal['name']) && ! empty($signal['url']))->values();
        $queryMatrix = collect([
            [
                'name' => 'Смотреть онлайн',
                'description' => 'Запросы для пользователей, которые ищут просмотр, доступные серии и видео.',
                'suffixes' => ['смотреть онлайн', 'все серии онлайн', 'сезоны онлайн'],
            ],
            [
                'name' => 'Серии и сезоны',
                'description' => 'Запросы по сериям, сезонам, датам выхода, переводам и озвучке.',
                'suffixes' => ['новые серии', 'дата выхода серий', 'перевод озвучка'],
            ],
            [
                'name' => 'Описание и актеры',
                'description' => 'Запросы по описанию, актерам, ролям, режиссерам, жанрам и странам.',
                'suffixes' => ['описание актеры', 'актеры и роли', 'жанр страна'],
            ],
            [
                'name' => 'Похожие подборки',
                'description' => 'Запросы для перехода к похожим сериалам, темам и внутренним подборкам.',
                'suffixes' => ['похожие сериалы', 'что посмотреть', 'подборка сериалов'],
            ],
        ])->map(fn ($group) => [
            'name' => $group['name'],
            'description' => $group['description'],
            'items' => $topicTerms->take(7)
                ->flatMap(fn ($term) => collect($group['suffixes'])->map(fn ($suffix) => trim($term.' '.$suffix)))
                ->prepend(trim($pageTitle.' '.$group['name']))
                ->map(fn ($query) => trim(preg_replace('/\s+/u', ' ', strip_tags((string) $query))))
                ->filter(fn ($query) => $query !== '' && mb_strlen($query) <= 120)
                ->unique()
                ->take(12)
                ->values(),
        ])->filter(fn ($group) => $group['items']->isNotEmpty())->values();
        $normalizedSeoImage = $seoImage
            ? (Str::startsWith($seoImage, ['http://', 'https://']) ? $seoImage : url($seoImage))
            : null;
        $normalizedSeoVideo = $seoVideo
            ? (Str::startsWith($seoVideo, ['http://', 'https://']) ? $seoVideo : url($seoVideo))
            : null;
        $mediaSignals = collect([
            $normalizedSeoImage ? [
                'type' => 'image',
                'schema' => 'ImageObject',
                'name' => $seo['image_alt'] ?? 'Постер и изображение страницы «'.$pageTitle.'»',
                'url' => $normalizedSeoImage,
                'description' => 'Основное изображение, постер или превью для страницы «'.$pageTitle.'».',
            ] : null,
            $normalizedSeoVideo ? [
                'type' => 'video',
                'schema' => 'VideoObject',
                'name' => 'Видео страницы «'.$pageTitle.'»',
                'url' => $normalizedSeoVideo,
                'thumbnail' => $normalizedSeoImage,
                'description' => 'Доступное видео или удаленный медиапоток, связанный со страницей «'.$pageTitle.'».',
            ] : null,
        ])->filter()->values();
        $publisherSignals = collect([
            [
                'name' => 'Издатель портала',
                'value' => $siteName,
                'url' => route('home'),
                'description' => 'Единый каталог сериалов с автоматической индексацией страниц, фильтров, подборок и медиа.',
            ],
            [
                'name' => 'Раздел каталога',
                'value' => 'Сериалы онлайн',
                'url' => route('titles.index'),
                'description' => 'Основной раздел портала для поиска сериалов, сезонов, серий, жанров, стран, актеров и режиссеров.',
            ],
            [
                'name' => 'Правила индексации',
                'value' => $robots,
                'url' => $canonicalUrl,
                'description' => 'Страница открыта для индексации с расширенным просмотром изображений, сниппетов и видео.',
            ],
            [
                'name' => 'Поисковая карта',
                'value' => 'XML sitemap',
                'url' => route('sitemap.index'),
                'description' => 'XML-карта помогает поисковым системам находить все важные страницы портала.',
            ],
            [
                'name' => 'Лента обновлений',
                'value' => 'RSS feed',
                'url' => route('feed'),
                'description' => 'RSS-лента помогает отслеживать новые и обновленные страницы каталога.',
            ],
            [
                'name' => 'Встроенный поиск',
                'value' => 'OpenSearch',
                'url' => route('opensearch'),
                'description' => 'OpenSearch-описание позволяет быстро искать по сериалам и страницам каталога.',
            ],
        ])->filter(fn ($signal) => ! empty($signal['name']) && ! empty($signal['url']))->values();
        $currentSeoYear = (int) now()->year;
        $freshnessQueries = collect([
            [
                'name' => 'Новинки сериалов '.$currentSeoYear,
                'query' => 'сериалы '.$currentSeoYear.' смотреть онлайн',
                'url' => route('titles.year', ['year' => $currentSeoYear]),
                'description' => 'Актуальная посадочная страница для сериалов, выпусков и обновлений '.$currentSeoYear.' года.',
            ],
            [
                'name' => 'Новые серии онлайн',
                'query' => 'новые серии смотреть онлайн',
                'url' => $seoSearchUrl('новые серии смотреть онлайн'),
                'description' => 'Поиск свежих серий, последних выпусков, переводов и обновленных страниц каталога.',
            ],
            [
                'name' => 'Дата выхода серий',
                'query' => 'дата выхода серий',
                'url' => $seoSearchUrl('дата выхода серий'),
                'description' => 'Запросы для поиска дат выхода, новых эпизодов, сезонов и информации об обновлениях.',
            ],
            [
                'name' => 'Обновления каталога',
                'query' => 'обновления сериалов онлайн',
                'url' => route('feed'),
                'description' => 'Лента и страницы каталога помогают находить недавно обновленную информацию.',
            ],
        ])->merge($topicTerms->take(8)->flatMap(fn ($term) => collect([
            [
                'name' => $term.' '.$currentSeoYear,
                'query' => $term.' '.$currentSeoYear.' смотреть онлайн',
                'url' => $seoSearchUrl($term.' '.$currentSeoYear.' смотреть онлайн'),
                'description' => 'Актуальный поиск по теме «'.$term.'» для '.$currentSeoYear.' года.',
            ],
            [
                'name' => $term.' новые серии',
                'query' => $term.' новые серии',
                'url' => $seoSearchUrl($term.' новые серии'),
                'description' => 'Поиск новых серий, переводов и обновлений по теме «'.$term.'».',
            ],
        ])))->filter(fn ($item) => ! empty($item['name']) && ! empty($item['query']) && ! empty($item['url']))
            ->unique('query')
            ->take(24)
            ->values();
        $russianQueryVariants = collect([
            'на русском',
            'с русской озвучкой',
            'с субтитрами',
            'в хорошем качестве',
            'все серии подряд',
            'без регистрации',
            'полное описание',
            'актеры и роли',
        ])->flatMap(fn ($suffix) => collect([
            trim($pageTitle.' '.$suffix),
        ])->merge($topicTerms->take(8)->map(fn ($term) => trim($term.' '.$suffix))))
            ->merge($topicTerms->take(8)->flatMap(fn ($term) => collect([
                'сериал '.$term.' смотреть онлайн',
                $term.' сериал на русском',
                $term.' сериал все серии',
            ])))
            ->map(fn ($query) => trim(preg_replace('/\s+/u', ' ', strip_tags((string) $query))))
            ->filter(fn ($query) => $query !== '' && mb_strlen($query) <= 120)
            ->unique()
            ->take(60)
            ->values();
        $catalogDirections = collect([
            [
                'name' => 'Сериалы по жанрам',
                'query' => $pageTitle.' жанры сериалов',
                'url' => $seoSearchUrl($pageTitle.' жанры сериалов'),
                'description' => 'Переход к жанрам, тематическим страницам и похожим сериалам каталога.',
            ],
            [
                'name' => 'Сериалы по странам',
                'query' => $pageTitle.' страны сериалы',
                'url' => $seoSearchUrl($pageTitle.' страны сериалы'),
                'description' => 'Поиск сериалов по странам, регионам, языкам, производству и связанным темам.',
            ],
            [
                'name' => 'Сериалы '.$currentSeoYear.' года',
                'query' => 'сериалы '.$currentSeoYear.' года',
                'url' => route('titles.year', ['year' => $currentSeoYear]),
                'description' => 'Чистая годовая посадочная страница для новых и актуальных сериалов.',
            ],
            [
                'name' => 'Актеры и роли',
                'query' => $pageTitle.' актеры роли',
                'url' => $seoSearchUrl($pageTitle.' актеры роли'),
                'description' => 'Поиск страниц по актерам, ролям, персонажам и связанным сериалам.',
            ],
            [
                'name' => 'Режиссеры и студии',
                'query' => $pageTitle.' режиссер студия',
                'url' => $seoSearchUrl($pageTitle.' режиссер студия'),
                'description' => 'Поиск информации по режиссерам, студиям, каналам и производству.',
            ],
            [
                'name' => 'Переводы и озвучка',
                'query' => $pageTitle.' перевод озвучка',
                'url' => $seoSearchUrl($pageTitle.' перевод озвучка'),
                'description' => 'Переход к страницам по переводам, озвучке, субтитрам и версиям просмотра.',
            ],
            [
                'name' => 'Возрастные ограничения',
                'query' => $pageTitle.' возрастное ограничение',
                'url' => $seoSearchUrl($pageTitle.' возрастное ограничение'),
                'description' => 'Поиск страниц с возрастными отметками, рейтингами и описанием ограничений.',
            ],
        ])->merge($topicTerms->take(8)->map(fn ($term) => [
            'name' => 'Каталог по теме: '.$term,
            'query' => $term.' каталог сериалов',
            'url' => $seoSearchUrl($term.' каталог сериалов'),
            'description' => 'Внутреннее направление каталога по теме «'.$term.'» с сериалами, описаниями и подборками.',
        ]))->filter(fn ($item) => ! empty($item['name']) && ! empty($item['query']) && ! empty($item['url']))
            ->unique('query')
            ->take(24)
            ->values();
        $comparisonQueries = collect([
            [
                'name' => 'Похожие на '.$pageTitle,
                'query' => 'похожие на '.$pageTitle,
                'description' => 'Поиск похожих сериалов, тематических подборок и связанных страниц каталога.',
            ],
            [
                'name' => 'Что посмотреть после '.$pageTitle,
                'query' => 'что посмотреть после '.$pageTitle,
                'description' => 'Запрос для пользователей, которые ищут похожие сериалы после этой страницы.',
            ],
            [
                'name' => 'Лучшие сериалы по теме '.$pageTitle,
                'query' => 'лучшие сериалы '.$pageTitle,
                'description' => 'Поиск лучших и похожих сериалов по темам, жанрам, странам и описанию страницы.',
            ],
            [
                'name' => 'Альтернативы '.$pageTitle,
                'query' => 'альтернативы '.$pageTitle,
                'description' => 'Поиск альтернативных сериалов, похожих подборок и связанных страниц.',
            ],
        ])->merge($topicTerms->take(8)->flatMap(fn ($term) => collect([
            [
                'name' => 'Похожие сериалы: '.$term,
                'query' => 'похожие сериалы '.$term,
                'description' => 'Сравнительный поиск похожих сериалов и подборок по теме «'.$term.'».',
            ],
            [
                'name' => 'Что посмотреть про '.$term,
                'query' => 'что посмотреть '.$term,
                'description' => 'Поиск сериалов, страниц и подборок для пользователей, которым интересна тема «'.$term.'».',
            ],
        ])))->map(fn ($item) => [
            'name' => trim(preg_replace('/\s+/u', ' ', strip_tags((string) $item['name']))),
            'query' => trim(preg_replace('/\s+/u', ' ', strip_tags((string) $item['query']))),
            'description' => trim(preg_replace('/\s+/u', ' ', strip_tags((string) $item['description']))),
        ])->filter(fn ($item) => $item['name'] !== '' && $item['query'] !== '')
            ->unique('query')
            ->take(24)
            ->values();
        $episodeIntentQueries = collect([
            [
                'name' => 'Все серии '.$pageTitle,
                'query' => $pageTitle.' все серии',
                'description' => 'Поиск всех серий, сезонов, выпусков и страниц просмотра по текущей теме.',
            ],
            [
                'name' => 'Последняя серия '.$pageTitle,
                'query' => $pageTitle.' последняя серия',
                'description' => 'Поиск последних серий, свежих выпусков, переводов и обновлений.',
            ],
            [
                'name' => 'Сезоны '.$pageTitle,
                'query' => $pageTitle.' сезоны',
                'description' => 'Поиск сезонов, эпизодов, описаний сезонов и связанных страниц каталога.',
            ],
            [
                'name' => 'Расписание серий '.$pageTitle,
                'query' => $pageTitle.' расписание серий',
                'description' => 'Поиск расписания, дат выхода, обновлений и информации о новых сериях.',
            ],
        ])->merge($topicTerms->take(8)->flatMap(fn ($term) => collect([
            [
                'name' => $term.' все серии',
                'query' => $term.' все серии смотреть онлайн',
                'description' => 'Поиск всех серий и сезонов по теме «'.$term.'».',
            ],
            [
                'name' => $term.' последняя серия',
                'query' => $term.' последняя серия',
                'description' => 'Поиск последних выпусков и обновлений по теме «'.$term.'».',
            ],
            [
                'name' => $term.' 1 сезон 1 серия',
                'query' => $term.' 1 сезон 1 серия',
                'description' => 'Поиск стартовых сезонов, первых серий и связанных страниц по теме «'.$term.'».',
            ],
        ])))->map(fn ($item) => [
            'name' => trim(preg_replace('/\s+/u', ' ', strip_tags((string) $item['name']))),
            'query' => trim(preg_replace('/\s+/u', ' ', strip_tags((string) $item['query']))),
            'description' => trim(preg_replace('/\s+/u', ' ', strip_tags((string) $item['description']))),
        ])->filter(fn ($item) => $item['name'] !== '' && $item['query'] !== '')
            ->unique('query')
            ->take(28)
            ->values();
        $watchModeQueries = collect([
            [
                'name' => 'Смотреть через веб-плеер',
                'query' => $pageTitle.' веб плеер смотреть',
                'description' => 'Поиск страниц, где просмотр связан с веб-плеером, удаленными видео и доступными сериями.',
            ],
            [
                'name' => 'Смотреть на телефоне',
                'query' => $pageTitle.' смотреть на телефоне',
                'description' => 'Поиск страниц и серий, удобных для мобильного просмотра и адаптивного интерфейса.',
            ],
            [
                'name' => 'Смотреть в хорошем качестве',
                'query' => $pageTitle.' смотреть в хорошем качестве',
                'description' => 'Поиск страниц с видео, качеством, плейлистами и доступными вариантами просмотра.',
            ],
            [
                'name' => 'Удаленное видео',
                'query' => $pageTitle.' удаленное видео',
                'description' => 'Поиск страниц, где медиафайлы лежат удаленно и открываются через собственный веб-плеер.',
            ],
        ])->merge($topicTerms->take(8)->flatMap(fn ($term) => collect([
            [
                'name' => $term.' веб-плеер',
                'query' => $term.' веб плеер смотреть',
                'description' => 'Поиск просмотра через веб-плеер по теме «'.$term.'».',
            ],
            [
                'name' => $term.' хорошее качество',
                'query' => $term.' смотреть в хорошем качестве',
                'description' => 'Поиск качественных вариантов просмотра и связанных страниц по теме «'.$term.'».',
            ],
            [
                'name' => $term.' мобильный просмотр',
                'query' => $term.' смотреть на телефоне',
                'description' => 'Поиск мобильных страниц просмотра, серий и сезонов по теме «'.$term.'».',
            ],
        ])))->map(fn ($item) => [
            'name' => trim(preg_replace('/\s+/u', ' ', strip_tags((string) $item['name']))),
            'query' => trim(preg_replace('/\s+/u', ' ', strip_tags((string) $item['query']))),
            'description' => trim(preg_replace('/\s+/u', ' ', strip_tags((string) $item['description']))),
        ])->filter(fn ($item) => $item['name'] !== '' && $item['query'] !== '')
            ->unique('query')
            ->take(28)
            ->values();
        $translationQueries = collect([
            [
                'name' => 'Русская озвучка '.$pageTitle,
                'query' => $pageTitle.' русская озвучка',
                'description' => 'Поиск страниц, переводов, озвучек и версий просмотра на русском языке.',
            ],
            [
                'name' => 'Субтитры '.$pageTitle,
                'query' => $pageTitle.' субтитры',
                'description' => 'Поиск страниц с субтитрами, переводами, версиями просмотра и описанием серий.',
            ],
            [
                'name' => 'Дубляж '.$pageTitle,
                'query' => $pageTitle.' дубляж',
                'description' => 'Поиск дубляжа, многоголосой озвучки, любительских и профессиональных переводов.',
            ],
            [
                'name' => 'Перевод серий '.$pageTitle,
                'query' => $pageTitle.' перевод серий',
                'description' => 'Поиск переводов серий, сезонов, новых выпусков и доступных вариантов озвучки.',
            ],
        ])->merge($topicTerms->take(8)->flatMap(fn ($term) => collect([
            [
                'name' => $term.' русская озвучка',
                'query' => $term.' русская озвучка смотреть',
                'description' => 'Поиск русской озвучки и переводов по теме «'.$term.'».',
            ],
            [
                'name' => $term.' субтитры',
                'query' => $term.' субтитры смотреть',
                'description' => 'Поиск субтитров, переводов и версий просмотра по теме «'.$term.'».',
            ],
            [
                'name' => $term.' перевод серий',
                'query' => $term.' перевод серий',
                'description' => 'Поиск переводов серий, сезонов и обновлений по теме «'.$term.'».',
            ],
        ])))->map(fn ($item) => [
            'name' => trim(preg_replace('/\s+/u', ' ', strip_tags((string) $item['name']))),
            'query' => trim(preg_replace('/\s+/u', ' ', strip_tags((string) $item['query']))),
            'description' => trim(preg_replace('/\s+/u', ' ', strip_tags((string) $item['description']))),
        ])->filter(fn ($item) => $item['name'] !== '' && $item['query'] !== '')
            ->unique('query')
            ->take(28)
            ->values();
        $voiceSearchQueries = collect([
            [
                'name' => 'Где смотреть '.$pageTitle.'?',
                'query' => 'где смотреть '.$pageTitle,
                'description' => 'Разговорный запрос для поиска страницы просмотра, сезонов, серий и доступного видео.',
            ],
            [
                'name' => 'Когда выйдет новая серия '.$pageTitle.'?',
                'query' => 'когда выйдет новая серия '.$pageTitle,
                'description' => 'Разговорный запрос по новым сериям, датам выхода, расписанию и обновлениям.',
            ],
            [
                'name' => 'Сколько серий в '.$pageTitle.'?',
                'query' => 'сколько серий в '.$pageTitle,
                'description' => 'Разговорный запрос по количеству серий, сезонов, выпусков и описанию страницы.',
            ],
            [
                'name' => 'Какая озвучка у '.$pageTitle.'?',
                'query' => 'какая озвучка у '.$pageTitle,
                'description' => 'Разговорный запрос по переводам, озвучкам, субтитрам и версиям просмотра.',
            ],
        ])->merge($topicTerms->take(8)->flatMap(fn ($term) => collect([
            [
                'name' => 'Где смотреть '.$term.'?',
                'query' => 'где смотреть '.$term,
                'description' => 'Голосовой запрос для поиска просмотра и связанных страниц по теме «'.$term.'».',
            ],
            [
                'name' => 'Что посмотреть про '.$term.'?',
                'query' => 'что посмотреть про '.$term,
                'description' => 'Голосовой запрос для поиска похожих сериалов и подборок по теме «'.$term.'».',
            ],
            [
                'name' => 'Какие сериалы похожи на '.$term.'?',
                'query' => 'какие сериалы похожи на '.$term,
                'description' => 'Голосовой запрос для поиска похожих сериалов и тематических подборок.',
            ],
        ])))->map(fn ($item) => [
            'name' => trim(preg_replace('/\s+/u', ' ', strip_tags((string) $item['name']))),
            'query' => trim(preg_replace('/\s+/u', ' ', strip_tags((string) $item['query']))),
            'description' => trim(preg_replace('/\s+/u', ' ', strip_tags((string) $item['description']))),
        ])->filter(fn ($item) => $item['name'] !== '' && $item['query'] !== '')
            ->unique('query')
            ->take(28)
            ->values();
        $topicAuthoritySignals = collect([
            [
                'name' => 'Описание и факты',
                'query' => $pageTitle.' описание факты',
                'description' => 'Сводный поисковый переход к описанию, фактам, жанрам, странам и основным сведениям страницы.',
            ],
            [
                'name' => 'Навигация по каталогу',
                'query' => $pageTitle.' каталог сериалов',
                'description' => 'Переход к связанным страницам каталога, подборкам, фильтрам и внутренним направлениям.',
            ],
            [
                'name' => 'Обновления и серии',
                'query' => $pageTitle.' обновления серии '.$currentSeoYear,
                'description' => 'Поиск новых серий, свежих обновлений, дат выхода и сезонной информации.',
            ],
            [
                'name' => 'Похожие темы',
                'query' => $pageTitle.' похожие темы',
                'description' => 'Поиск похожих сериалов, альтернатив, связанных тем и рекомендационных переходов.',
            ],
        ])->merge($topicTerms->take(8)->map(fn ($term) => [
            'name' => 'Тематический авторитет: '.$term,
            'query' => $term.' описание факты сериалы',
            'description' => 'Семантический переход по теме «'.$term.'» для описаний, фактов, похожих сериалов и связанных страниц.',
        ]))->map(fn ($item) => [
            'name' => trim(preg_replace('/\s+/u', ' ', strip_tags((string) $item['name']))),
            'query' => trim(preg_replace('/\s+/u', ' ', strip_tags((string) $item['query']))),
            'description' => trim(preg_replace('/\s+/u', ' ', strip_tags((string) $item['description']))),
        ])->filter(fn ($item) => $item['name'] !== '' && $item['query'] !== '')
            ->unique('query')
            ->take(24)
            ->values();
        $releaseCalendarQueries = collect([
            [
                'name' => 'Новые серии сегодня',
                'query' => $pageTitle.' новые серии сегодня',
                'description' => 'Поиск свежих серий, обновлений и выпусков, которые пользователи ищут сегодня.',
            ],
            [
                'name' => 'Новые серии завтра',
                'query' => $pageTitle.' новые серии завтра',
                'description' => 'Поиск ожидаемых серий, расписания и ближайших обновлений каталога.',
            ],
            [
                'name' => 'Релизы на этой неделе',
                'query' => $pageTitle.' серии на этой неделе',
                'description' => 'Поиск серий, сезонов и обновлений, актуальных в течение недели.',
            ],
            [
                'name' => 'Календарь выхода серий',
                'query' => $pageTitle.' календарь выхода серий',
                'description' => 'Поиск дат выхода, расписания серий, новых сезонов и обновлений страницы.',
            ],
        ])->merge($topicTerms->take(8)->flatMap(fn ($term) => collect([
            [
                'name' => $term.' дата выхода',
                'query' => $term.' дата выхода серии',
                'description' => 'Поиск дат выхода и календаря серий по теме «'.$term.'».',
            ],
            [
                'name' => $term.' новые серии сегодня',
                'query' => $term.' новые серии сегодня',
                'description' => 'Поиск сегодняшних обновлений, новых серий и выпусков по теме «'.$term.'».',
            ],
            [
                'name' => $term.' расписание серий '.$currentSeoYear,
                'query' => $term.' расписание серий '.$currentSeoYear,
                'description' => 'Поиск расписания, релизов и сезонных обновлений '.$currentSeoYear.' года.',
            ],
        ])))->map(fn ($item) => [
            'name' => trim(preg_replace('/\s+/u', ' ', strip_tags((string) $item['name']))),
            'query' => trim(preg_replace('/\s+/u', ' ', strip_tags((string) $item['query']))),
            'description' => trim(preg_replace('/\s+/u', ' ', strip_tags((string) $item['description']))),
        ])->filter(fn ($item) => $item['name'] !== '' && $item['query'] !== '')
            ->unique('query')
            ->take(28)
            ->values();
        $expandedKeywords = collect(explode(',', (string) ($seo['keywords'] ?? '')))
            ->merge($topicTerms)
            ->merge($seoIntents)
            ->merge($longTailQueries)
            ->merge($relatedCollections->pluck('name'))
            ->merge($semanticHubs->flatMap(fn ($hub) => $hub['items']->pluck('name')))
            ->merge($snippetBlocks->pluck('title'))
            ->merge($contentSignals->pluck('name'))
            ->merge($audiencePaths->pluck('name'))
            ->merge($audiencePaths->flatMap(fn ($path) => $path['items']))
            ->merge($alsoSearches)
            ->merge($discoverySignals->pluck('name'))
            ->merge($queryMatrix->pluck('name'))
            ->merge($queryMatrix->flatMap(fn ($group) => $group['items']))
            ->merge($mediaSignals->pluck('name'))
            ->merge($publisherSignals->pluck('name'))
            ->merge($freshnessQueries->pluck('name'))
            ->merge($freshnessQueries->pluck('query'))
            ->merge($russianQueryVariants)
            ->merge($catalogDirections->pluck('name'))
            ->merge($catalogDirections->pluck('query'))
            ->merge($comparisonQueries->pluck('name'))
            ->merge($comparisonQueries->pluck('query'))
            ->merge($episodeIntentQueries->pluck('name'))
            ->merge($episodeIntentQueries->pluck('query'))
            ->merge($watchModeQueries->pluck('name'))
            ->merge($watchModeQueries->pluck('query'))
            ->merge($translationQueries->pluck('name'))
            ->merge($translationQueries->pluck('query'))
            ->merge($voiceSearchQueries->pluck('name'))
            ->merge($voiceSearchQueries->pluck('query'))
            ->merge($topicAuthoritySignals->pluck('name'))
            ->merge($topicAuthoritySignals->pluck('query'))
            ->merge($releaseCalendarQueries->pluck('name'))
            ->merge($releaseCalendarQueries->pluck('query'))
            ->map(fn ($keyword) => trim(preg_replace('/\s+/u', ' ', strip_tags((string) $keyword))))
            ->filter(fn ($keyword) => $keyword !== '' && mb_strlen($keyword) <= 120)
            ->unique()
            ->take(100)
            ->values();
        $newsKeywords = collect(explode(',', (string) ($seo['news_keywords'] ?? '')))
            ->merge($expandedKeywords)
            ->map(fn ($keyword) => trim(preg_replace('/\s+/u', ' ', strip_tags((string) $keyword))))
            ->filter()
            ->unique()
            ->take(70)
            ->values();
        $keywordAliases = $topicTerms->take(12)
            ->flatMap(fn ($term) => collect([
                $term.' онлайн',
                $term.' смотреть',
                $term.' все серии онлайн',
                $term.' сериал',
            ]))
            ->merge($longTailQueries)
            ->merge($audiencePaths->flatMap(fn ($path) => $path['items']))
            ->merge($alsoSearches)
            ->merge($queryMatrix->flatMap(fn ($group) => $group['items']))
            ->merge($freshnessQueries->pluck('query'))
            ->merge($russianQueryVariants)
            ->merge($catalogDirections->pluck('query'))
            ->merge($comparisonQueries->pluck('query'))
            ->merge($episodeIntentQueries->pluck('query'))
            ->merge($watchModeQueries->pluck('query'))
            ->merge($translationQueries->pluck('query'))
            ->merge($voiceSearchQueries->pluck('query'))
            ->merge($topicAuthoritySignals->pluck('query'))
            ->merge($releaseCalendarQueries->pluck('query'))
            ->map(fn ($keyword) => trim(preg_replace('/\s+/u', ' ', strip_tags((string) $keyword))))
            ->filter(fn ($keyword) => $keyword !== '' && mb_strlen($keyword) <= 120)
            ->unique()
            ->take(70)
            ->values();
        $seoSections = collect([
            ['id' => 'seo-summary', 'name' => 'Описание страницы', 'enabled' => ! empty($seo['seo_text']) || ! empty($seo['related_links'])],
            ['id' => 'key-topics', 'name' => 'Ключевые темы', 'enabled' => $topicTerms->isNotEmpty()],
            ['id' => 'semantic-glossary', 'name' => 'Глоссарий страницы', 'enabled' => $semanticGlossary->isNotEmpty()],
            ['id' => 'query-navigation', 'name' => 'Навигация по запросам', 'enabled' => $seoIntents->isNotEmpty()],
            ['id' => 'long-tail-queries', 'name' => 'Поисковые формулировки', 'enabled' => $longTailQueries->isNotEmpty()],
            ['id' => 'related-collections', 'name' => 'Связанные подборки', 'enabled' => $relatedCollections->isNotEmpty()],
            ['id' => 'semantic-hubs', 'name' => 'Тематические хабы', 'enabled' => $semanticHubs->isNotEmpty()],
            ['id' => 'page-actions', 'name' => 'Действия на странице', 'enabled' => $seoActions->isNotEmpty()],
            ['id' => 'snippet-blocks', 'name' => 'Короткие тезисы', 'enabled' => $snippetBlocks->isNotEmpty()],
            ['id' => 'content-signals', 'name' => 'Сигналы страницы', 'enabled' => $contentSignals->isNotEmpty()],
            ['id' => 'audience-paths', 'name' => 'Пути поиска', 'enabled' => $audiencePaths->isNotEmpty()],
            ['id' => 'also-searches', 'name' => 'Также ищут', 'enabled' => $alsoSearches->isNotEmpty()],
            ['id' => 'discovery-signals', 'name' => 'Индексация и обновления', 'enabled' => $discoverySignals->isNotEmpty()],
            ['id' => 'query-matrix', 'name' => 'Матрица запросов', 'enabled' => $queryMatrix->isNotEmpty()],
            ['id' => 'media-signals', 'name' => 'Медиа и превью', 'enabled' => $mediaSignals->isNotEmpty()],
            ['id' => 'publisher-trust', 'name' => 'Доверие и индексация', 'enabled' => $publisherSignals->isNotEmpty()],
            ['id' => 'freshness-seo', 'name' => 'Актуальные запросы', 'enabled' => $freshnessQueries->isNotEmpty()],
            ['id' => 'russian-query-variants', 'name' => 'Русские варианты поиска', 'enabled' => $russianQueryVariants->isNotEmpty()],
            ['id' => 'catalog-directions', 'name' => 'Направления каталога', 'enabled' => $catalogDirections->isNotEmpty()],
            ['id' => 'comparison-seo', 'name' => 'Похожие и сравнения', 'enabled' => $comparisonQueries->isNotEmpty()],
            ['id' => 'episode-intents', 'name' => 'Серии и сезоны', 'enabled' => $episodeIntentQueries->isNotEmpty()],
            ['id' => 'watch-mode-seo', 'name' => 'Способы просмотра', 'enabled' => $watchModeQueries->isNotEmpty()],
            ['id' => 'translation-seo', 'name' => 'Переводы и озвучки', 'enabled' => $translationQueries->isNotEmpty()],
            ['id' => 'voice-search-seo', 'name' => 'Голосовые запросы', 'enabled' => $voiceSearchQueries->isNotEmpty()],
            ['id' => 'topic-authority-seo', 'name' => 'Тематический авторитет', 'enabled' => $topicAuthoritySignals->isNotEmpty()],
            ['id' => 'release-calendar-seo', 'name' => 'Календарь релизов', 'enabled' => $releaseCalendarQueries->isNotEmpty()],
            ['id' => 'quick-answers', 'name' => 'Быстрые ответы', 'enabled' => $quickAnswers->isNotEmpty()],
            ['id' => 'semantic-clusters', 'name' => 'Семантические подборки', 'enabled' => ! empty($seo['keyword_clusters'])],
            ['id' => 'popular-searches', 'name' => 'Популярные запросы', 'enabled' => ! empty($seo['search_phrases'])],
        ])->filter(fn ($section) => $section['enabled'])->values();
        $breadcrumbs = collect($seo['breadcrumbs'] ?? [])->filter(fn ($item) => is_array($item) && ! empty($item['name']) && ! empty($item['url']))->values();

        if ($seoImage && ! Str::startsWith($seoImage, ['http://', 'https://'])) {
            $seoImage = url($seoImage);
        }

        $jsonLdItems = $seo['jsonLd'] ?? [];

        if ($jsonLdItems !== [] && ! array_is_list($jsonLdItems)) {
            $jsonLdItems = [$jsonLdItems];
        }

        if ($topicTerms->isNotEmpty()) {
            $jsonLdItems[] = [
                '@context' => 'https://schema.org',
                '@type' => 'DefinedTermSet',
                'name' => $fullTitle.' - ключевые темы',
                'hasDefinedTerm' => $topicTerms->take(35)->map(fn ($term) => [
                    '@type' => 'DefinedTerm',
                    'name' => $term,
                    'url' => $seoSearchUrl($term),
                ])->values()->all(),
            ];
            $jsonLdItems[] = [
                '@context' => 'https://schema.org',
                '@type' => 'ItemList',
                'name' => $fullTitle.' - тематическая навигация',
                'itemListElement' => $topicTerms->take(30)->values()->map(fn ($term, $index) => [
                    '@type' => 'ListItem',
                    'position' => $index + 1,
                    'name' => $term,
                    'url' => $seoSearchUrl($term),
                ])->values()->all(),
            ];
            $jsonLdItems[] = [
                '@context' => 'https://schema.org',
                '@type' => 'WebPage',
                '@id' => $canonicalUrl.'#semantic-page',
                'url' => $canonicalUrl,
                'name' => $fullTitle,
                'description' => $seoDescription,
                'inLanguage' => 'ru',
                'keywords' => $expandedKeywords->take(60)->implode(', '),
                'about' => $semanticEntities->take(8)->values()->all(),
                'mentions' => $semanticEntities->slice(8)->values()->all(),
                'isPartOf' => [
                    '@type' => 'WebSite',
                    'name' => $siteName,
                    'url' => route('home'),
                ],
            ];
        }

        if ($quickAnswers->isNotEmpty()) {
            $jsonLdItems[] = [
                '@context' => 'https://schema.org',
                '@type' => 'FAQPage',
                '@id' => $canonicalUrl.'#quick-answers',
                'mainEntity' => $quickAnswers->map(fn ($item) => [
                    '@type' => 'Question',
                    'name' => $item['question'],
                    'acceptedAnswer' => [
                        '@type' => 'Answer',
                        'text' => $item['answer'],
                    ],
                ])->values()->all(),
            ];
        }

        if ($longTailQueries->isNotEmpty()) {
            $jsonLdItems[] = [
                '@context' => 'https://schema.org',
                '@type' => 'ItemList',
                '@id' => $canonicalUrl.'#long-tail-queries-list',
                'name' => $fullTitle.' - поисковые формулировки',
                'itemListElement' => $longTailQueries->take(35)->values()->map(fn ($query, $index) => [
                    '@type' => 'ListItem',
                    'position' => $index + 1,
                    'name' => $query,
                    'url' => $seoSearchUrl($query),
                ])->values()->all(),
            ];
        }

        if ($relatedCollections->isNotEmpty()) {
            $jsonLdItems[] = [
                '@context' => 'https://schema.org',
                '@type' => 'CollectionPage',
                '@id' => $canonicalUrl.'#related-collections-schema',
                'name' => $fullTitle.' - связанные подборки',
                'url' => $canonicalUrl.'#related-collections',
                'description' => 'Автоматические тематические подборки и внутренние ссылки по странице «'.$pageTitle.'».',
                'hasPart' => $relatedCollections->take(24)->map(fn ($collection) => [
                    '@type' => 'CollectionPage',
                    'name' => $collection['name'],
                    'description' => $collection['description'] ?? $collection['name'],
                    'url' => $seoSearchUrl($collection['query']),
                ])->values()->all(),
            ];
        }

        if ($seoActions->isNotEmpty()) {
            $jsonLdItems[] = [
                '@context' => 'https://schema.org',
                '@type' => 'WebPage',
                '@id' => $canonicalUrl.'#actions-schema',
                'name' => $fullTitle.' - доступные действия',
                'url' => $canonicalUrl,
                'potentialAction' => $seoActions->take(20)->map(function ($action) {
                    $item = [
                        '@type' => $action['type'],
                        'name' => $action['name'],
                        'target' => $action['url'],
                        'description' => $action['description'] ?? $action['name'],
                    ];

                    if ($action['type'] === 'SearchAction') {
                        $item['query-input'] = 'required name=q';
                    }

                    return $item;
                })->values()->all(),
            ];
            $jsonLdItems[] = [
                '@context' => 'https://schema.org',
                '@type' => 'ItemList',
                '@id' => $canonicalUrl.'#actions-list',
                'name' => $fullTitle.' - список действий',
                'itemListElement' => $seoActions->take(20)->values()->map(fn ($action, $index) => [
                    '@type' => 'ListItem',
                    'position' => $index + 1,
                    'name' => $action['name'],
                    'url' => $action['url'],
                ])->values()->all(),
            ];
        }

        if ($semanticGlossary->isNotEmpty()) {
            $jsonLdItems[] = [
                '@context' => 'https://schema.org',
                '@type' => 'DefinedTermSet',
                '@id' => $canonicalUrl.'#semantic-glossary-schema',
                'name' => $fullTitle.' - глоссарий страницы',
                'url' => $canonicalUrl.'#semantic-glossary',
                'hasDefinedTerm' => $semanticGlossary->map(fn ($item) => [
                    '@type' => 'DefinedTerm',
                    'name' => $item['term'],
                    'description' => $item['description'],
                    'url' => $item['url'],
                ])->values()->all(),
            ];
        }

        if ($semanticHubs->isNotEmpty()) {
            $jsonLdItems[] = [
                '@context' => 'https://schema.org',
                '@type' => 'ItemList',
                '@id' => $canonicalUrl.'#semantic-hubs-schema',
                'name' => $fullTitle.' - тематические хабы',
                'itemListElement' => $semanticHubs->flatMap(fn ($hub) => $hub['items']->map(fn ($item) => [
                    'hub' => $hub['title'],
                    'item' => $item,
                ]))->take(30)->values()->map(fn ($entry, $index) => [
                    '@type' => 'ListItem',
                    'position' => $index + 1,
                    'name' => $entry['hub'].': '.$entry['item']['name'],
                    'description' => $entry['item']['description'] ?? $entry['item']['name'],
                    'url' => $seoSearchUrl($entry['item']['query']),
                ])->values()->all(),
            ];
        }

        if ($snippetBlocks->isNotEmpty()) {
            $jsonLdItems[] = [
                '@context' => 'https://schema.org',
                '@type' => 'ItemList',
                '@id' => $canonicalUrl.'#snippet-blocks-schema',
                'name' => $fullTitle.' - короткие тезисы',
                'itemListElement' => $snippetBlocks->map(fn ($block, $index) => [
                    '@type' => 'ListItem',
                    'position' => $index + 1,
                    'item' => [
                        '@type' => 'WebPageElement',
                        'name' => $block['title'],
                        'description' => $block['text'],
                        'url' => $canonicalUrl.'#snippet-blocks',
                        'about' => [
                            '@type' => 'Thing',
                            'name' => $block['query'],
                            'url' => $seoSearchUrl($block['query']),
                        ],
                    ],
                ])->values()->all(),
            ];
        }

        if ($contentSignals->isNotEmpty()) {
            $jsonLdItems[] = [
                '@context' => 'https://schema.org',
                '@type' => 'ItemList',
                '@id' => $canonicalUrl.'#content-signals-schema',
                'name' => $fullTitle.' - сигналы качества страницы',
                'itemListElement' => $contentSignals->map(fn ($signal, $index) => [
                    '@type' => 'ListItem',
                    'position' => $index + 1,
                    'item' => [
                        '@type' => 'PropertyValue',
                        'name' => $signal['name'],
                        'value' => $signal['value'],
                        'description' => $signal['description'],
                        'url' => $seoSearchUrl($signal['query']),
                    ],
                ])->values()->all(),
            ];
        }

        if ($audiencePaths->isNotEmpty()) {
            $jsonLdItems[] = [
                '@context' => 'https://schema.org',
                '@type' => 'ItemList',
                '@id' => $canonicalUrl.'#audience-paths-schema',
                'name' => $fullTitle.' - пути поиска',
                'itemListElement' => $audiencePaths->flatMap(fn ($path) => $path['items']->map(fn ($item) => [
                    'path' => $path,
                    'item' => $item,
                ]))->take(32)->values()->map(fn ($entry, $index) => [
                    '@type' => 'ListItem',
                    'position' => $index + 1,
                    'name' => $entry['path']['name'].': '.$entry['item'],
                    'description' => $entry['path']['description'],
                    'url' => $seoSearchUrl($entry['item']),
                ])->values()->all(),
            ];
        }

        if ($alsoSearches->isNotEmpty()) {
            $jsonLdItems[] = [
                '@context' => 'https://schema.org',
                '@type' => 'ItemList',
                '@id' => $canonicalUrl.'#also-searches-schema',
                'name' => $fullTitle.' - также ищут',
                'itemListElement' => $alsoSearches->take(40)->values()->map(fn ($query, $index) => [
                    '@type' => 'ListItem',
                    'position' => $index + 1,
                    'name' => $query,
                    'url' => $seoSearchUrl($query),
                ])->values()->all(),
            ];
        }

        if ($discoverySignals->isNotEmpty()) {
            $jsonLdItems[] = [
                '@context' => 'https://schema.org',
                '@type' => 'DataCatalog',
                '@id' => $canonicalUrl.'#discovery-catalog',
                'name' => $fullTitle.' - индексация и обновления',
                'url' => $canonicalUrl.'#discovery-signals',
                'description' => 'Автоматические сигналы индексации, карты сайта, поиска и обновлений для страницы «'.$pageTitle.'».',
                'dataset' => $discoverySignals->map(fn ($signal) => [
                    '@type' => 'Dataset',
                    'name' => $signal['name'],
                    'description' => $signal['description'],
                    'url' => $signal['url'],
                    'identifier' => $signal['value'],
                ])->values()->all(),
            ];
        }

        if ($queryMatrix->isNotEmpty()) {
            $jsonLdItems[] = [
                '@context' => 'https://schema.org',
                '@type' => 'ItemList',
                '@id' => $canonicalUrl.'#query-matrix-schema',
                'name' => $fullTitle.' - матрица поисковых запросов',
                'itemListElement' => $queryMatrix->flatMap(fn ($group) => $group['items']->map(fn ($query) => [
                    'group' => $group,
                    'query' => $query,
                ]))->take(40)->values()->map(fn ($entry, $index) => [
                    '@type' => 'ListItem',
                    'position' => $index + 1,
                    'name' => $entry['group']['name'].': '.$entry['query'],
                    'description' => $entry['group']['description'],
                    'url' => $seoSearchUrl($entry['query']),
                ])->values()->all(),
            ];
        }

        if ($mediaSignals->isNotEmpty()) {
            $jsonLdItems[] = [
                '@context' => 'https://schema.org',
                '@type' => 'ItemList',
                '@id' => $canonicalUrl.'#media-signals-schema',
                'name' => $fullTitle.' - медиа и превью',
                'itemListElement' => $mediaSignals->map(function ($signal, $index) use ($seo) {
                    return [
                        '@type' => 'ListItem',
                        'position' => $index + 1,
                        'item' => array_filter([
                            '@type' => $signal['schema'],
                            'name' => $signal['name'],
                            'description' => $signal['description'],
                            'url' => $signal['url'],
                            'contentUrl' => $signal['url'],
                            'thumbnailUrl' => $signal['thumbnail'] ?? null,
                            'dateModified' => $seo['updated_time'] ?? null,
                            'inLanguage' => 'ru',
                        ]),
                    ];
                })->values()->all(),
            ];
        }

        if ($publisherSignals->isNotEmpty()) {
            $jsonLdItems[] = [
                '@context' => 'https://schema.org',
                '@type' => 'CreativeWork',
                '@id' => $canonicalUrl.'#publisher-trust-schema',
                'name' => $fullTitle.' - доверие и индексация',
                'url' => $canonicalUrl.'#publisher-trust',
                'description' => 'Сигналы издателя, индексации, поиска, карты сайта и обновлений для страницы «'.$pageTitle.'».',
                'publisher' => [
                    '@type' => 'Organization',
                    'name' => $siteName,
                    'url' => route('home'),
                ],
                'isPartOf' => [
                    '@type' => 'WebSite',
                    'name' => $siteName,
                    'url' => route('home'),
                    'potentialAction' => [
                        '@type' => 'SearchAction',
                        'target' => route('titles.index', ['q' => '{search_term_string}']),
                        'query-input' => 'required name=search_term_string',
                    ],
                ],
                'about' => $publisherSignals->map(fn ($signal) => [
                    '@type' => 'Thing',
                    'name' => $signal['name'],
                    'description' => $signal['description'],
                    'url' => $signal['url'],
                ])->values()->all(),
            ];
        }

        if ($freshnessQueries->isNotEmpty()) {
            $jsonLdItems[] = [
                '@context' => 'https://schema.org',
                '@type' => 'ItemList',
                '@id' => $canonicalUrl.'#freshness-seo-schema',
                'name' => $fullTitle.' - актуальные запросы',
                'itemListElement' => $freshnessQueries->map(fn ($item, $index) => [
                    '@type' => 'ListItem',
                    'position' => $index + 1,
                    'name' => $item['name'],
                    'description' => $item['description'],
                    'url' => $item['url'],
                ])->values()->all(),
            ];
        }

        if ($russianQueryVariants->isNotEmpty()) {
            $jsonLdItems[] = [
                '@context' => 'https://schema.org',
                '@type' => 'ItemList',
                '@id' => $canonicalUrl.'#russian-query-variants-schema',
                'name' => $fullTitle.' - русские варианты поиска',
                'itemListElement' => $russianQueryVariants->take(40)->values()->map(fn ($query, $index) => [
                    '@type' => 'ListItem',
                    'position' => $index + 1,
                    'name' => $query,
                    'url' => $seoSearchUrl($query),
                ])->values()->all(),
            ];
        }

        if ($catalogDirections->isNotEmpty()) {
            $jsonLdItems[] = [
                '@context' => 'https://schema.org',
                '@type' => 'ItemList',
                '@id' => $canonicalUrl.'#catalog-directions-schema',
                'name' => $fullTitle.' - направления каталога',
                'itemListElement' => $catalogDirections->map(fn ($item, $index) => [
                    '@type' => 'ListItem',
                    'position' => $index + 1,
                    'name' => $item['name'],
                    'description' => $item['description'],
                    'url' => $item['url'],
                ])->values()->all(),
            ];
        }

        if ($comparisonQueries->isNotEmpty()) {
            $jsonLdItems[] = [
                '@context' => 'https://schema.org',
                '@type' => 'ItemList',
                '@id' => $canonicalUrl.'#comparison-seo-schema',
                'name' => $fullTitle.' - похожие и сравнения',
                'itemListElement' => $comparisonQueries->map(fn ($item, $index) => [
                    '@type' => 'ListItem',
                    'position' => $index + 1,
                    'name' => $item['name'],
                    'description' => $item['description'],
                    'url' => $seoSearchUrl($item['query']),
                ])->values()->all(),
            ];
        }

        if ($episodeIntentQueries->isNotEmpty()) {
            $jsonLdItems[] = [
                '@context' => 'https://schema.org',
                '@type' => 'ItemList',
                '@id' => $canonicalUrl.'#episode-intents-schema',
                'name' => $fullTitle.' - серии и сезоны',
                'itemListElement' => $episodeIntentQueries->map(fn ($item, $index) => [
                    '@type' => 'ListItem',
                    'position' => $index + 1,
                    'name' => $item['name'],
                    'description' => $item['description'],
                    'url' => $seoSearchUrl($item['query']),
                ])->values()->all(),
            ];
        }

        if ($watchModeQueries->isNotEmpty()) {
            $jsonLdItems[] = [
                '@context' => 'https://schema.org',
                '@type' => 'ItemList',
                '@id' => $canonicalUrl.'#watch-mode-seo-schema',
                'name' => $fullTitle.' - способы просмотра',
                'itemListElement' => $watchModeQueries->map(fn ($item, $index) => [
                    '@type' => 'ListItem',
                    'position' => $index + 1,
                    'name' => $item['name'],
                    'description' => $item['description'],
                    'url' => $seoSearchUrl($item['query']),
                ])->values()->all(),
            ];
        }

        if ($translationQueries->isNotEmpty()) {
            $jsonLdItems[] = [
                '@context' => 'https://schema.org',
                '@type' => 'ItemList',
                '@id' => $canonicalUrl.'#translation-seo-schema',
                'name' => $fullTitle.' - переводы и озвучки',
                'itemListElement' => $translationQueries->map(fn ($item, $index) => [
                    '@type' => 'ListItem',
                    'position' => $index + 1,
                    'name' => $item['name'],
                    'description' => $item['description'],
                    'url' => $seoSearchUrl($item['query']),
                ])->values()->all(),
            ];
        }

        if ($voiceSearchQueries->isNotEmpty()) {
            $jsonLdItems[] = [
                '@context' => 'https://schema.org',
                '@type' => 'ItemList',
                '@id' => $canonicalUrl.'#voice-search-seo-schema',
                'name' => $fullTitle.' - голосовые запросы',
                'itemListElement' => $voiceSearchQueries->map(fn ($item, $index) => [
                    '@type' => 'ListItem',
                    'position' => $index + 1,
                    'name' => $item['name'],
                    'description' => $item['description'],
                    'url' => $seoSearchUrl($item['query']),
                ])->values()->all(),
            ];
        }

        if ($topicAuthoritySignals->isNotEmpty()) {
            $jsonLdItems[] = [
                '@context' => 'https://schema.org',
                '@type' => 'ItemList',
                '@id' => $canonicalUrl.'#topic-authority-seo-schema',
                'name' => $fullTitle.' - тематический авторитет',
                'itemListElement' => $topicAuthoritySignals->map(fn ($item, $index) => [
                    '@type' => 'ListItem',
                    'position' => $index + 1,
                    'name' => $item['name'],
                    'description' => $item['description'],
                    'url' => $seoSearchUrl($item['query']),
                ])->values()->all(),
            ];
        }

        if ($releaseCalendarQueries->isNotEmpty()) {
            $jsonLdItems[] = [
                '@context' => 'https://schema.org',
                '@type' => 'ItemList',
                '@id' => $canonicalUrl.'#release-calendar-seo-schema',
                'name' => $fullTitle.' - календарь релизов',
                'itemListElement' => $releaseCalendarQueries->map(fn ($item, $index) => [
                    '@type' => 'ListItem',
                    'position' => $index + 1,
                    'name' => $item['name'],
                    'description' => $item['description'],
                    'url' => $seoSearchUrl($item['query']),
                ])->values()->all(),
            ];
        }

        if ($seoSections->isNotEmpty()) {
            $jsonLdItems[] = [
                '@context' => 'https://schema.org',
                '@type' => 'ItemList',
                '@id' => $canonicalUrl.'#table-of-contents',
                'name' => $fullTitle.' - содержание страницы',
                'itemListElement' => $seoSections->map(fn ($section, $index) => [
                    '@type' => 'ListItem',
                    'position' => $index + 1,
                    'name' => $section['name'],
                    'url' => $canonicalUrl.'#'.$section['id'],
                ])->values()->all(),
            ];
            $jsonLdItems[] = [
                '@context' => 'https://schema.org',
                '@type' => 'WebPage',
                '@id' => $canonicalUrl.'#page-sections',
                'name' => $fullTitle.' - структура страницы',
                'hasPart' => $seoSections->map(fn ($section) => [
                    '@type' => 'WebPageElement',
                    '@id' => $canonicalUrl.'#'.$section['id'],
                    'name' => $section['name'],
                    'url' => $canonicalUrl.'#'.$section['id'],
                ])->values()->all(),
            ];
        }

        $data = get_defined_vars();
        unset($data['viewData'], $data['data']);

        return $data;
    }
}
