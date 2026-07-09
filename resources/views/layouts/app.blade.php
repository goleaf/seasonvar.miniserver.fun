@php
    $siteName = config('app.name', 'Каталог сериалов');
    $seo = $seo ?? [];
    $pageTitle = trim((string) ($seo['title'] ?? $title ?? $siteName));
    $pageTitle = $pageTitle !== '' ? $pageTitle : $siteName;
    $fullTitle = \Illuminate\Support\Str::contains(\Illuminate\Support\Str::lower($pageTitle), \Illuminate\Support\Str::lower($siteName))
        ? $pageTitle
        : $pageTitle.' - '.$siteName;
    $seoDescription = trim((string) ($seo['description'] ?? 'Каталог сериалов онлайн с фильтрами по жанрам, странам, актерам, годам, сезонам и сериям.'));
    $seoDescription = \Illuminate\Support\Str::limit(preg_replace('/\s+/u', ' ', strip_tags($seoDescription)) ?: $seoDescription, 180, '...');
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
        ? (\Illuminate\Support\Str::startsWith($seoImage, ['http://', 'https://']) ? $seoImage : url($seoImage))
        : null;
    $normalizedSeoVideo = $seoVideo
        ? (\Illuminate\Support\Str::startsWith($seoVideo, ['http://', 'https://']) ? $seoVideo : url($seoVideo))
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

    if ($seoImage && ! \Illuminate\Support\Str::startsWith($seoImage, ['http://', 'https://'])) {
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
@endphp

<!DOCTYPE html>
<html lang="{{ $htmlLang }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="robots" content="{{ $robots }}">
        <meta name="description" content="{{ $seoDescription }}">
        <meta name="author" content="{{ $siteName }}">
        <meta name="application-name" content="{{ $siteName }}">
        <meta name="generator" content="Laravel">
        <meta name="url" content="{{ $canonicalUrl }}">
        <meta name="identifier-URL" content="{{ $canonicalUrl }}">
        <meta name="summary" content="{{ $seoDescription }}">
        <meta name="owner" content="{{ $siteName }}">
        <meta name="answer-count" content="{{ $quickAnswers->count() }}">
        <meta name="toc-count" content="{{ $seoSections->count() }}">
        <meta name="query-count" content="{{ $longTailQueries->count() }}">
        <meta name="related-collection-count" content="{{ $relatedCollections->count() }}">
        <meta name="expanded-keyword-count" content="{{ $expandedKeywords->count() }}">
        <meta name="action-count" content="{{ $seoActions->count() }}">
        <meta name="glossary-count" content="{{ $semanticGlossary->count() }}">
        <meta name="semantic-hub-count" content="{{ $semanticHubs->count() }}">
        <meta name="snippet-count" content="{{ $snippetBlocks->count() }}">
        <meta name="content-signal-count" content="{{ $contentSignals->count() }}">
        <meta name="content-signal-summary" content="{{ $contentSignals->map(fn ($signal) => $signal['name'].': '.$signal['value'])->implode(', ') }}">
        <meta name="audience-path-count" content="{{ $audiencePaths->count() }}">
        <meta name="also-search-count" content="{{ $alsoSearches->count() }}">
        <meta name="discovery-signal-count" content="{{ $discoverySignals->count() }}">
        <meta name="query-matrix-count" content="{{ $queryMatrix->sum(fn ($group) => $group['items']->count()) }}">
        <meta name="media-signal-count" content="{{ $mediaSignals->count() }}">
        <meta name="publisher-signal-count" content="{{ $publisherSignals->count() }}">
        <meta name="freshness-query-count" content="{{ $freshnessQueries->count() }}">
        <meta name="freshness-year" content="{{ $currentSeoYear }}">
        <meta name="russian-query-variant-count" content="{{ $russianQueryVariants->count() }}">
        <meta name="catalog-direction-count" content="{{ $catalogDirections->count() }}">
        <meta name="comparison-query-count" content="{{ $comparisonQueries->count() }}">
        <meta name="episode-intent-count" content="{{ $episodeIntentQueries->count() }}">
        <meta name="watch-mode-count" content="{{ $watchModeQueries->count() }}">
        <meta name="translation-query-count" content="{{ $translationQueries->count() }}">
        <meta name="voice-search-query-count" content="{{ $voiceSearchQueries->count() }}">
        <meta name="topic-authority-count" content="{{ $topicAuthoritySignals->count() }}">
        <meta name="release-calendar-query-count" content="{{ $releaseCalendarQueries->count() }}">
        @if ($expandedKeywords->isNotEmpty())
            <meta name="keywords" content="{{ $expandedKeywords->take(60)->implode(', ') }}">
            <meta name="news_keywords" content="{{ $newsKeywords->implode(', ') }}">
            <meta name="keyphrases" content="{{ $keywordAliases->take(40)->implode(', ') }}">
            <meta name="topic-keywords" content="{{ $topicTerms->take(30)->implode(', ') }}">
            <meta name="content-keywords" content="{{ $expandedKeywords->slice(20)->take(40)->implode(', ') }}">
        @endif
        @if ($topicTerms->isNotEmpty())
            <meta name="subject" content="{{ $topicTerms->take(12)->implode(', ') }}">
            <meta name="classification" content="{{ $topicTerms->take(16)->implode(', ') }}">
            <meta name="category" content="{{ $seo['section'] ?? $topicTerms->first() }}">
            <meta name="page-topic" content="{{ $topicTerms->take(10)->implode(', ') }}">
            <meta name="audience" content="зрители сериалов онлайн">
            <meta name="coverage" content="Worldwide">
            <meta name="distribution" content="Global">
            <meta name="revisit-after" content="1 days">
            <meta name="abstract" content="{{ $seoDescription }}">
            <meta name="topic" content="{{ $topicTerms->take(14)->implode(', ') }}">
            <meta name="target" content="{{ $seoIntents->take(18)->implode(', ') }}">
            <meta name="search-intent" content="{{ $seoIntents->take(18)->implode(', ') }}">
            <meta name="long-tail-keywords" content="{{ $longTailQueries->take(20)->implode(', ') }}">
            <meta name="keyword-aliases" content="{{ $keywordAliases->take(30)->implode(', ') }}">
            <meta name="defined-terms" content="{{ $semanticGlossary->pluck('term')->take(20)->implode(', ') }}">
            <meta name="semantic-hubs" content="{{ $semanticHubs->pluck('title')->implode(', ') }}">
            <meta name="snippet-topics" content="{{ $snippetBlocks->pluck('query')->take(20)->implode(', ') }}">
            <meta name="content-signals" content="{{ $contentSignals->pluck('name')->implode(', ') }}">
            <meta name="audience-paths" content="{{ $audiencePaths->pluck('name')->implode(', ') }}">
            <meta name="also-searches" content="{{ $alsoSearches->take(30)->implode(', ') }}">
            <meta name="discovery-signals" content="{{ $discoverySignals->pluck('name')->implode(', ') }}">
            <meta name="query-matrix" content="{{ $queryMatrix->pluck('name')->implode(', ') }}">
            <meta name="query-matrix-keywords" content="{{ $queryMatrix->flatMap(fn ($group) => $group['items'])->take(30)->implode(', ') }}">
            <meta name="media-assets" content="{{ $mediaSignals->pluck('name')->implode(', ') }}">
            <meta name="publisher-signals" content="{{ $publisherSignals->pluck('name')->implode(', ') }}">
            <meta name="freshness-keywords" content="{{ $freshnessQueries->pluck('query')->take(30)->implode(', ') }}">
            <meta name="russian-query-variants" content="{{ $russianQueryVariants->take(35)->implode(', ') }}">
            <meta name="catalog-directions" content="{{ $catalogDirections->pluck('name')->implode(', ') }}">
            <meta name="catalog-direction-keywords" content="{{ $catalogDirections->pluck('query')->take(30)->implode(', ') }}">
            <meta name="comparison-keywords" content="{{ $comparisonQueries->pluck('query')->take(30)->implode(', ') }}">
            <meta name="episode-keywords" content="{{ $episodeIntentQueries->pluck('query')->take(35)->implode(', ') }}">
            <meta name="watch-mode-keywords" content="{{ $watchModeQueries->pluck('query')->take(35)->implode(', ') }}">
            <meta name="translation-keywords" content="{{ $translationQueries->pluck('query')->take(35)->implode(', ') }}">
            <meta name="voice-search-keywords" content="{{ $voiceSearchQueries->pluck('query')->take(35)->implode(', ') }}">
            <meta name="topic-authority-keywords" content="{{ $topicAuthoritySignals->pluck('query')->take(35)->implode(', ') }}">
            <meta name="release-calendar-keywords" content="{{ $releaseCalendarQueries->pluck('query')->take(35)->implode(', ') }}">
            <meta name="resource-type" content="document">
            <meta name="language" content="Russian">
        @endif
        @foreach ($topicTerms->take(10) as $term)
            <meta name="entity" content="{{ $term }}">
        @endforeach
        @if (! empty($seo['section']))
            <meta property="article:section" content="{{ $seo['section'] }}">
        @endif
        @foreach ($topicTerms->take(12) as $term)
            <meta property="article:tag" content="{{ $term }}">
        @endforeach
        <meta name="theme-color" content="#ecfdf5">
        <link rel="canonical" href="{{ $canonicalUrl }}">
        <link rel="alternate" hreflang="ru" href="{{ $canonicalUrl }}">
        <link rel="alternate" hreflang="x-default" href="{{ $canonicalUrl }}">
        <link rel="sitemap" type="application/xml" href="{{ route('sitemap.index') }}">
        <link rel="sitemap" type="application/xml" href="{{ route('sitemap.landings') }}">
        <link rel="alternate" type="application/rss+xml" title="{{ $siteName }}" href="{{ route('feed') }}">
        <link rel="search" type="application/opensearchdescription+xml" title="{{ $siteName }}" href="{{ route('opensearch') }}">
        <link rel="alternate" type="text/plain" title="LLMs" href="{{ route('llms') }}">
        <meta name="rating" content="general">
        <meta name="referrer" content="strict-origin-when-cross-origin">
        <meta name="DC.title" content="{{ $fullTitle }}">
        <meta name="DC.description" content="{{ $seoDescription }}">
        <meta name="DC.language" content="ru">
        @if (! empty($seo['prev']))
            <link rel="prev" href="{{ $seo['prev'] }}">
        @endif
        @if (! empty($seo['next']))
            <link rel="next" href="{{ $seo['next'] }}">
        @endif
        <meta property="og:locale" content="{{ $seoLocale }}">
        <meta property="og:site_name" content="{{ $siteName }}">
        <meta property="og:type" content="{{ $seoType }}">
        <meta property="og:title" content="{{ $fullTitle }}">
        <meta property="og:description" content="{{ $seoDescription }}">
        <meta property="og:url" content="{{ $canonicalUrl }}">
        @if ($seoImage)
            <link rel="image_src" href="{{ $seoImage }}">
            <meta property="og:image" content="{{ $seoImage }}">
            <meta property="og:image:alt" content="{{ $seo['image_alt'] ?? $fullTitle }}">
            <meta name="twitter:card" content="summary_large_image">
            <meta name="twitter:image" content="{{ $seoImage }}">
        @else
            <meta name="twitter:card" content="summary">
        @endif
        @if ($seoVideo)
            <meta property="og:video" content="{{ $seoVideo }}">
            <meta property="og:video:secure_url" content="{{ $seoVideo }}">
        @endif
        @if (! empty($seo['published_time']))
            <meta property="article:published_time" content="{{ $seo['published_time'] }}">
        @endif
        @if (! empty($seo['updated_time']))
            <meta name="last-modified" content="{{ $seo['updated_time'] }}">
            <meta property="og:updated_time" content="{{ $seo['updated_time'] }}">
            <meta property="article:modified_time" content="{{ $seo['updated_time'] }}">
        @endif
        @foreach ($seoTags as $tag)
            <meta property="article:tag" content="{{ $tag }}">
        @endforeach
        <meta name="twitter:title" content="{{ $fullTitle }}">
        <meta name="twitter:description" content="{{ $seoDescription }}">
        <title>{{ $fullTitle }}</title>
        @foreach ($jsonLdItems as $jsonLd)
            <script type="application/ld+json">{!! json_encode($jsonLd, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) !!}</script>
        @endforeach
        @stack('head')
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="min-h-screen bg-slate-50 text-slate-700 antialiased">
        <header class="border-b border-slate-200 bg-white shadow-sm shadow-slate-200/70">
            <div class="mx-auto flex max-w-[1760px] flex-col gap-3 px-3 py-4 sm:px-6 lg:flex-row lg:items-center lg:px-8">
                <a href="{{ route('home') }}" class="flex min-w-0 items-center gap-3">
                    <span class="grid h-11 w-11 shrink-0 place-items-center rounded-lg bg-emerald-50 text-xl font-black text-emerald-700 ring-1 ring-emerald-100 sm:h-12 sm:w-12">
                        <i class="fa-solid fa-film" aria-hidden="true"></i>
                    </span>
                    <span>
                        <span class="block text-xl font-black tracking-tight text-slate-700 sm:text-2xl">Каталог сериалов</span>
                    </span>
                </a>

                <form action="{{ route('titles.index') }}" method="GET" class="flex min-w-0 w-full flex-1 overflow-hidden rounded-lg border border-slate-200 bg-white lg:mx-6">
                    <span class="flex shrink-0 items-center pl-4 text-slate-400">
                        <i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i>
                    </span>
                    <input type="search" name="q" value="{{ request('q') }}" placeholder="Поиск сериала..." class="min-w-0 flex-1 border-0 px-3 py-3 text-sm text-slate-700 outline-none placeholder:text-slate-400">
                    <button type="submit" class="inline-flex shrink-0 items-center gap-2 bg-emerald-50 px-4 text-sm font-bold text-emerald-700 hover:bg-emerald-100 sm:px-5">
                        <i class="fa-solid fa-arrow-right" aria-hidden="true"></i>
                        <span>Найти</span>
                    </button>
                </form>

                <nav class="flex w-full flex-wrap items-center gap-2 text-sm font-semibold lg:w-auto">
                    <a href="{{ route('home') }}" class="inline-flex items-center gap-2 rounded-lg bg-slate-50 px-3 py-2 text-slate-600 ring-1 ring-slate-200 hover:bg-emerald-50 hover:text-emerald-700">
                        <i class="fa-solid fa-house" aria-hidden="true"></i>
                        <span>Главная</span>
                    </a>
                    <a href="{{ route('titles.index') }}" class="inline-flex items-center gap-2 rounded-lg bg-slate-50 px-3 py-2 text-slate-600 ring-1 ring-slate-200 hover:bg-emerald-50 hover:text-emerald-700">
                        <i class="fa-solid fa-list" aria-hidden="true"></i>
                        <span>Все сериалы</span>
                    </a>
                </nav>
            </div>
        </header>

        <main id="main-content" class="mx-auto max-w-[1760px] px-3 py-4 sm:px-6 sm:py-6 lg:px-8" itemscope itemtype="https://schema.org/WebPageElement" itemid="{{ $canonicalUrl }}#main-content">
            @if ($breadcrumbs->count() > 1)
                <nav aria-label="Хлебные крошки" class="mb-4 overflow-x-auto rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm shadow-sm shadow-slate-200/60">
                    <ol class="flex min-w-max items-center gap-2 text-slate-500">
                        @foreach ($breadcrumbs as $breadcrumb)
                            <li class="inline-flex items-center gap-2">
                                @if (! $loop->first)
                                    <i class="fa-solid fa-chevron-right text-[0.7em] text-slate-300" aria-hidden="true"></i>
                                @endif
                                @if ($loop->last)
                                    <span class="font-semibold text-slate-700" aria-current="page">{{ $breadcrumb['name'] }}</span>
                                @else
                                    <a href="{{ $breadcrumb['url'] }}" class="font-semibold text-emerald-700 hover:text-emerald-600">{{ $breadcrumb['name'] }}</a>
                                @endif
                            </li>
                        @endforeach
                    </ol>
                </nav>
            @endif
            @yield('content')
            @if ($seoSections->isNotEmpty())
                <nav id="table-of-contents" class="mt-5 rounded-lg border border-slate-200 bg-white p-4 shadow-sm shadow-slate-200/60" aria-label="Содержание страницы" itemscope itemtype="https://schema.org/SiteNavigationElement">
                    <div class="flex items-center gap-2 text-sm font-bold text-slate-700">
                        <i class="fa-solid fa-table-list text-emerald-700" aria-hidden="true"></i>
                        <span itemprop="name">Содержание страницы</span>
                    </div>
                    <div class="mt-3 flex flex-wrap gap-2">
                        @foreach ($seoSections as $section)
                            <a href="#{{ $section['id'] }}" itemprop="url" class="inline-flex items-center gap-1 rounded-full bg-slate-50 px-2.5 py-1 text-xs font-semibold text-slate-700 ring-1 ring-slate-200 hover:bg-emerald-50 hover:text-emerald-700">
                                <i class="fa-solid fa-bookmark text-[0.8em] text-slate-400" aria-hidden="true"></i>
                                <span itemprop="name">{{ $section['name'] }}</span>
                            </a>
                        @endforeach
                    </div>
                </nav>
            @endif
            @if (! empty($seo['seo_text']) || ! empty($seo['related_links']))
                <section id="seo-summary" class="mt-5 rounded-lg border border-slate-200 bg-white p-4 shadow-sm shadow-slate-200/60" aria-label="SEO описание страницы" data-seo-summary>
                    <div class="flex items-center gap-2 text-sm font-bold text-slate-700">
                        <i class="fa-solid fa-file-lines text-emerald-700" aria-hidden="true"></i>
                        <span>Описание страницы</span>
                    </div>
                    @if (! empty($seo['seo_text']))
                        <div class="mt-3 space-y-2 text-sm leading-6 text-slate-600">
                            @foreach (collect($seo['seo_text'])->filter()->take(4) as $paragraph)
                                <p>{{ $paragraph }}</p>
                            @endforeach
                        </div>
                    @endif
                    @if (! empty($seo['related_links']))
                        <div class="mt-4 flex flex-wrap gap-2">
                            @foreach (collect($seo['related_links'])->filter()->take(14) as $link)
                                <a href="{{ $link['url'] }}" class="inline-flex items-center gap-1 rounded-full bg-emerald-50 px-2.5 py-1 text-xs font-semibold text-emerald-700 ring-1 ring-emerald-100 hover:bg-emerald-100">
                                    <i class="fa-solid fa-link text-[0.8em]" aria-hidden="true"></i>
                                    <span>{{ $link['name'] }}</span>
                                </a>
                            @endforeach
                        </div>
                    @endif
                </section>
            @endif
            @if ($topicTerms->isNotEmpty())
                <section id="key-topics" class="mt-5 rounded-lg border border-slate-200 bg-white p-4 shadow-sm shadow-slate-200/60" aria-label="Ключевые темы" itemscope itemtype="https://schema.org/SiteNavigationElement">
                    <div class="flex items-center gap-2 text-sm font-bold text-slate-700">
                        <i class="fa-solid fa-tags text-emerald-700" aria-hidden="true"></i>
                        <span itemprop="name">Ключевые темы</span>
                    </div>
                    <div class="mt-3 flex flex-wrap gap-2">
                        @foreach ($topicTerms as $term)
                            <a href="{{ $seoSearchUrl($term) }}" itemprop="url" class="inline-flex items-center gap-1 rounded-full bg-amber-50 px-2.5 py-1 text-xs font-semibold text-amber-800 ring-1 ring-amber-100 hover:bg-emerald-50 hover:text-emerald-700 hover:ring-emerald-100">
                                <i class="fa-solid fa-hashtag text-[0.8em] text-amber-500" aria-hidden="true"></i>
                                <span itemprop="name">{{ $term }}</span>
                            </a>
                        @endforeach
                    </div>
                </section>
            @endif
            @if ($semanticGlossary->isNotEmpty())
                <section id="semantic-glossary" class="mt-5 rounded-lg border border-slate-200 bg-white p-4 shadow-sm shadow-slate-200/60" aria-label="Глоссарий страницы" itemscope itemtype="https://schema.org/DefinedTermSet">
                    <div class="flex items-center gap-2 text-sm font-bold text-slate-700">
                        <i class="fa-solid fa-book-open text-emerald-700" aria-hidden="true"></i>
                        <span itemprop="name">Глоссарий страницы</span>
                    </div>
                    <div class="mt-3 grid gap-3 md:grid-cols-2 xl:grid-cols-3">
                        @foreach ($semanticGlossary as $item)
                            <article class="rounded-lg border border-slate-200 bg-slate-50 p-3" itemprop="hasDefinedTerm" itemscope itemtype="https://schema.org/DefinedTerm">
                                <a href="{{ $item['url'] }}" class="text-sm font-bold text-slate-800 hover:text-emerald-700" itemprop="url">
                                    <span itemprop="name">{{ $item['term'] }}</span>
                                </a>
                                <p class="mt-2 text-xs leading-5 text-slate-600" itemprop="description">{{ $item['description'] }}</p>
                            </article>
                        @endforeach
                    </div>
                </section>
            @endif
            @if ($seoIntents->isNotEmpty())
                <section id="query-navigation" class="mt-5 rounded-lg border border-slate-200 bg-white p-4 shadow-sm shadow-slate-200/60" aria-label="Навигация по запросам" itemscope itemtype="https://schema.org/SiteNavigationElement">
                    <div class="flex items-center gap-2 text-sm font-bold text-slate-700">
                        <i class="fa-solid fa-route text-emerald-700" aria-hidden="true"></i>
                        <span itemprop="name">Навигация по запросам</span>
                    </div>
                    <div class="mt-3 grid gap-2 sm:grid-cols-2 lg:grid-cols-4">
                        @foreach ($seoIntents->take(16) as $intent)
                            <a href="{{ $seoSearchUrl($intent) }}" itemprop="url" class="flex items-center gap-2 rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 text-xs font-semibold text-slate-700 hover:border-emerald-100 hover:bg-emerald-50 hover:text-emerald-700">
                                <i class="fa-solid fa-arrow-up-right-from-square text-[0.8em] text-slate-400" aria-hidden="true"></i>
                                <span itemprop="name">{{ $intent }}</span>
                            </a>
                        @endforeach
                    </div>
                </section>
            @endif
            @if ($longTailQueries->isNotEmpty())
                <section id="long-tail-queries" class="mt-5 rounded-lg border border-slate-200 bg-white p-4 shadow-sm shadow-slate-200/60" aria-label="Поисковые формулировки" itemscope itemtype="https://schema.org/SiteNavigationElement">
                    <div class="flex items-center gap-2 text-sm font-bold text-slate-700">
                        <i class="fa-solid fa-keyboard text-emerald-700" aria-hidden="true"></i>
                        <span itemprop="name">Поисковые формулировки</span>
                    </div>
                    <div class="mt-3 grid gap-2 sm:grid-cols-2 lg:grid-cols-4">
                        @foreach ($longTailQueries->take(24) as $query)
                            <a href="{{ $seoSearchUrl($query) }}" itemprop="url" class="flex items-center gap-2 rounded-lg border border-slate-200 bg-white px-3 py-2 text-xs font-semibold text-slate-700 hover:border-emerald-100 hover:bg-emerald-50 hover:text-emerald-700">
                                <i class="fa-solid fa-magnifying-glass text-[0.8em] text-slate-400" aria-hidden="true"></i>
                                <span itemprop="name">{{ $query }}</span>
                            </a>
                        @endforeach
                    </div>
                </section>
            @endif
            @if ($relatedCollections->isNotEmpty())
                <section id="related-collections" class="mt-5 rounded-lg border border-slate-200 bg-white p-4 shadow-sm shadow-slate-200/60" aria-label="Связанные подборки" itemscope itemtype="https://schema.org/CollectionPage">
                    <div class="flex items-center gap-2 text-sm font-bold text-slate-700">
                        <i class="fa-solid fa-layer-group text-emerald-700" aria-hidden="true"></i>
                        <span itemprop="name">Связанные подборки</span>
                    </div>
                    <div class="mt-3 grid gap-3 md:grid-cols-2 xl:grid-cols-3">
                        @foreach ($relatedCollections->take(18) as $collection)
                            <a href="{{ $seoSearchUrl($collection['query']) }}" class="block rounded-lg border border-slate-200 bg-slate-50 p-3 hover:border-emerald-100 hover:bg-emerald-50" itemprop="hasPart" itemscope itemtype="https://schema.org/CollectionPage">
                                <span class="flex items-center gap-2 text-sm font-bold text-slate-800" itemprop="name">
                                    <i class="fa-solid fa-folder-open text-[0.85em] text-emerald-700" aria-hidden="true"></i>
                                    {{ $collection['name'] }}
                                </span>
                                <span class="mt-2 block text-xs leading-5 text-slate-600" itemprop="description">{{ $collection['description'] }}</span>
                                <meta itemprop="url" content="{{ $seoSearchUrl($collection['query']) }}">
                            </a>
                        @endforeach
                    </div>
                </section>
            @endif
            @if ($semanticHubs->isNotEmpty())
                <section id="semantic-hubs" class="mt-5 rounded-lg border border-slate-200 bg-white p-4 shadow-sm shadow-slate-200/60" aria-label="Тематические хабы">
                    <div class="flex items-center gap-2 text-sm font-bold text-slate-700">
                        <i class="fa-solid fa-sitemap text-emerald-700" aria-hidden="true"></i>
                        <span>Тематические хабы</span>
                    </div>
                    <div class="mt-3 grid gap-3 lg:grid-cols-2">
                        @foreach ($semanticHubs as $hub)
                            <article class="rounded-lg border border-slate-200 bg-slate-50 p-3">
                                <h2 class="text-sm font-bold text-slate-800">{{ $hub['title'] }}</h2>
                                <p class="mt-1 text-xs leading-5 text-slate-600">{{ $hub['description'] }}</p>
                                <div class="mt-3 flex flex-wrap gap-2">
                                    @foreach ($hub['items'] as $item)
                                        <a href="{{ $seoSearchUrl($item['query']) }}" class="inline-flex items-center gap-1 rounded-full bg-white px-2.5 py-1 text-xs font-semibold text-slate-700 ring-1 ring-slate-200 hover:bg-emerald-50 hover:text-emerald-700">
                                            <i class="fa-solid fa-link text-[0.8em] text-slate-400" aria-hidden="true"></i>
                                            <span>{{ $item['name'] }}</span>
                                        </a>
                                    @endforeach
                                </div>
                            </article>
                        @endforeach
                    </div>
                </section>
            @endif
            @if ($seoActions->isNotEmpty())
                <section id="page-actions" class="mt-5 rounded-lg border border-slate-200 bg-white p-4 shadow-sm shadow-slate-200/60" aria-label="Действия на странице" itemscope itemtype="https://schema.org/SiteNavigationElement">
                    <div class="flex items-center gap-2 text-sm font-bold text-slate-700">
                        <i class="fa-solid fa-bolt text-emerald-700" aria-hidden="true"></i>
                        <span itemprop="name">Действия на странице</span>
                    </div>
                    <div class="mt-3 grid gap-2 sm:grid-cols-2 lg:grid-cols-4">
                        @foreach ($seoActions->take(16) as $action)
                            <a href="{{ $action['url'] }}" itemprop="url" class="flex items-center gap-2 rounded-lg border border-slate-200 bg-white px-3 py-2 text-xs font-semibold text-slate-700 hover:border-emerald-100 hover:bg-emerald-50 hover:text-emerald-700">
                                <i class="fa-solid fa-circle-arrow-right text-[0.85em] text-emerald-700" aria-hidden="true"></i>
                                <span itemprop="name">{{ $action['label'] ?? $action['name'] }}</span>
                            </a>
                        @endforeach
                    </div>
                </section>
            @endif
            @if ($snippetBlocks->isNotEmpty())
                <section id="snippet-blocks" class="mt-5 rounded-lg border border-slate-200 bg-white p-4 shadow-sm shadow-slate-200/60" aria-label="Короткие тезисы страницы">
                    <div class="flex items-center gap-2 text-sm font-bold text-slate-700">
                        <i class="fa-solid fa-quote-left text-emerald-700" aria-hidden="true"></i>
                        <span>Короткие тезисы</span>
                    </div>
                    <div class="mt-3 grid gap-3 md:grid-cols-2 xl:grid-cols-4">
                        @foreach ($snippetBlocks as $block)
                            <article class="rounded-lg border border-slate-200 bg-slate-50 p-3">
                                <h2 class="text-sm font-bold text-slate-800">{{ $block['title'] }}</h2>
                                <p class="mt-2 text-xs leading-5 text-slate-600">{{ $block['text'] }}</p>
                                <a href="{{ $seoSearchUrl($block['query']) }}" class="mt-3 inline-flex items-center gap-1 text-xs font-bold text-emerald-700 hover:text-emerald-600">
                                    <i class="fa-solid fa-magnifying-glass text-[0.8em]" aria-hidden="true"></i>
                                    <span>Найти: {{ $block['query'] }}</span>
                                </a>
                            </article>
                        @endforeach
                    </div>
                </section>
            @endif
            @if ($contentSignals->isNotEmpty())
                <section id="content-signals" class="mt-5 rounded-lg border border-slate-200 bg-white p-4 shadow-sm shadow-slate-200/60" aria-label="Сигналы страницы">
                    <div class="flex items-center gap-2 text-sm font-bold text-slate-700">
                        <i class="fa-solid fa-chart-simple text-emerald-700" aria-hidden="true"></i>
                        <span>Сигналы страницы</span>
                    </div>
                    <div class="mt-3 grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
                        @foreach ($contentSignals as $signal)
                            <article class="rounded-lg border border-slate-200 bg-slate-50 p-3">
                                <div class="flex items-start justify-between gap-3">
                                    <h2 class="text-sm font-bold text-slate-800">{{ $signal['name'] }}</h2>
                                    <span class="rounded-full bg-emerald-50 px-2 py-0.5 text-xs font-bold text-emerald-700 ring-1 ring-emerald-100">{{ $signal['value'] }}</span>
                                </div>
                                <p class="mt-2 text-xs leading-5 text-slate-600">{{ $signal['description'] }}</p>
                                <a href="{{ $seoSearchUrl($signal['query']) }}" class="mt-3 inline-flex items-center gap-1 text-xs font-bold text-emerald-700 hover:text-emerald-600">
                                    <i class="fa-solid fa-arrow-up-right-from-square text-[0.8em]" aria-hidden="true"></i>
                                    <span>Открыть связанный поиск</span>
                                </a>
                            </article>
                        @endforeach
                    </div>
                </section>
            @endif
            @if ($audiencePaths->isNotEmpty())
                <section id="audience-paths" class="mt-5 rounded-lg border border-slate-200 bg-white p-4 shadow-sm shadow-slate-200/60" aria-label="Пути поиска">
                    <div class="flex items-center gap-2 text-sm font-bold text-slate-700">
                        <i class="fa-solid fa-signs-post text-emerald-700" aria-hidden="true"></i>
                        <span>Пути поиска</span>
                    </div>
                    <div class="mt-3 grid gap-3 lg:grid-cols-2">
                        @foreach ($audiencePaths as $path)
                            <article class="rounded-lg border border-slate-200 bg-slate-50 p-3">
                                <div class="flex items-start justify-between gap-3">
                                    <div>
                                        <h2 class="text-sm font-bold text-slate-800">{{ $path['name'] }}</h2>
                                        <p class="mt-1 text-xs leading-5 text-slate-600">{{ $path['description'] }}</p>
                                    </div>
                                    <a href="{{ $seoSearchUrl($path['query']) }}" class="shrink-0 rounded-full bg-emerald-50 px-2 py-1 text-xs font-bold text-emerald-700 ring-1 ring-emerald-100 hover:bg-emerald-100">
                                        Открыть
                                    </a>
                                </div>
                                <div class="mt-3 flex flex-wrap gap-2">
                                    @foreach ($path['items'] as $item)
                                        <a href="{{ $seoSearchUrl($item) }}" class="inline-flex items-center gap-1 rounded-full bg-white px-2.5 py-1 text-xs font-semibold text-slate-700 ring-1 ring-slate-200 hover:bg-emerald-50 hover:text-emerald-700">
                                            <i class="fa-solid fa-compass text-[0.8em] text-slate-400" aria-hidden="true"></i>
                                            <span>{{ $item }}</span>
                                        </a>
                                    @endforeach
                                </div>
                            </article>
                        @endforeach
                    </div>
                </section>
            @endif
            @if ($alsoSearches->isNotEmpty())
                <section id="also-searches" class="mt-5 rounded-lg border border-slate-200 bg-white p-4 shadow-sm shadow-slate-200/60" aria-label="Также ищут" itemscope itemtype="https://schema.org/SiteNavigationElement">
                    <div class="flex items-center gap-2 text-sm font-bold text-slate-700">
                        <i class="fa-solid fa-binoculars text-emerald-700" aria-hidden="true"></i>
                        <span itemprop="name">Также ищут</span>
                    </div>
                    <div class="mt-3 flex flex-wrap gap-2">
                        @foreach ($alsoSearches->take(36) as $query)
                            <a href="{{ $seoSearchUrl($query) }}" itemprop="url" class="inline-flex items-center gap-1 rounded-full bg-white px-2.5 py-1 text-xs font-semibold text-slate-700 ring-1 ring-slate-200 hover:bg-emerald-50 hover:text-emerald-700">
                                <i class="fa-solid fa-magnifying-glass-plus text-[0.8em] text-slate-400" aria-hidden="true"></i>
                                <span itemprop="name">{{ $query }}</span>
                            </a>
                        @endforeach
                    </div>
                </section>
            @endif
            @if ($discoverySignals->isNotEmpty())
                <section id="discovery-signals" class="mt-5 rounded-lg border border-slate-200 bg-white p-4 shadow-sm shadow-slate-200/60" aria-label="Индексация и обновления">
                    <div class="flex items-center gap-2 text-sm font-bold text-slate-700">
                        <i class="fa-solid fa-satellite-dish text-emerald-700" aria-hidden="true"></i>
                        <span>Индексация и обновления</span>
                    </div>
                    <div class="mt-3 grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
                        @foreach ($discoverySignals as $signal)
                            <article class="rounded-lg border border-slate-200 bg-slate-50 p-3">
                                <h2 class="text-sm font-bold text-slate-800">{{ $signal['name'] }}</h2>
                                <p class="mt-1 break-words text-xs font-semibold text-emerald-700">{{ $signal['value'] }}</p>
                                <p class="mt-2 text-xs leading-5 text-slate-600">{{ $signal['description'] }}</p>
                                <a href="{{ $signal['url'] }}" class="mt-3 inline-flex items-center gap-1 text-xs font-bold text-emerald-700 hover:text-emerald-600">
                                    <i class="fa-solid fa-arrow-up-right-from-square text-[0.8em]" aria-hidden="true"></i>
                                    <span>Открыть</span>
                                </a>
                            </article>
                        @endforeach
                    </div>
                </section>
            @endif
            @if ($queryMatrix->isNotEmpty())
                <section id="query-matrix" class="mt-5 rounded-lg border border-slate-200 bg-white p-4 shadow-sm shadow-slate-200/60" aria-label="Матрица запросов">
                    <div class="flex items-center gap-2 text-sm font-bold text-slate-700">
                        <i class="fa-solid fa-table-cells-large text-emerald-700" aria-hidden="true"></i>
                        <span>Матрица запросов</span>
                    </div>
                    <div class="mt-3 grid gap-3 lg:grid-cols-2">
                        @foreach ($queryMatrix as $group)
                            <article class="rounded-lg border border-slate-200 bg-slate-50 p-3">
                                <h2 class="text-sm font-bold text-slate-800">{{ $group['name'] }}</h2>
                                <p class="mt-1 text-xs leading-5 text-slate-600">{{ $group['description'] }}</p>
                                <div class="mt-3 flex flex-wrap gap-2">
                                    @foreach ($group['items'] as $query)
                                        <a href="{{ $seoSearchUrl($query) }}" class="inline-flex items-center gap-1 rounded-full bg-white px-2.5 py-1 text-xs font-semibold text-slate-700 ring-1 ring-slate-200 hover:bg-emerald-50 hover:text-emerald-700">
                                            <i class="fa-solid fa-table-cells text-[0.8em] text-slate-400" aria-hidden="true"></i>
                                            <span>{{ $query }}</span>
                                        </a>
                                    @endforeach
                                </div>
                            </article>
                        @endforeach
                    </div>
                </section>
            @endif
            @if ($mediaSignals->isNotEmpty())
                <section id="media-signals" class="mt-5 rounded-lg border border-slate-200 bg-white p-4 shadow-sm shadow-slate-200/60" aria-label="Медиа и превью">
                    <div class="flex items-center gap-2 text-sm font-bold text-slate-700">
                        <i class="fa-solid fa-photo-film text-emerald-700" aria-hidden="true"></i>
                        <span>Медиа и превью</span>
                    </div>
                    <div class="mt-3 grid gap-3 md:grid-cols-2">
                        @foreach ($mediaSignals as $signal)
                            <article class="rounded-lg border border-slate-200 bg-slate-50 p-3">
                                <h2 class="text-sm font-bold text-slate-800">{{ $signal['name'] }}</h2>
                                <p class="mt-2 text-xs leading-5 text-slate-600">{{ $signal['description'] }}</p>
                                <a href="{{ $signal['url'] }}" class="mt-3 inline-flex items-center gap-1 break-all text-xs font-bold text-emerald-700 hover:text-emerald-600">
                                    <i class="fa-solid fa-arrow-up-right-from-square text-[0.8em]" aria-hidden="true"></i>
                                    <span>{{ $signal['type'] === 'video' ? 'Открыть видео' : 'Открыть изображение' }}</span>
                                </a>
                            </article>
                        @endforeach
                    </div>
                </section>
            @endif
            @if ($publisherSignals->isNotEmpty())
                <section id="publisher-trust" class="mt-5 rounded-lg border border-slate-200 bg-white p-4 shadow-sm shadow-slate-200/60" aria-label="Доверие и индексация">
                    <div class="flex items-center gap-2 text-sm font-bold text-slate-700">
                        <i class="fa-solid fa-shield-halved text-emerald-700" aria-hidden="true"></i>
                        <span>Доверие и индексация</span>
                    </div>
                    <div class="mt-3 grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
                        @foreach ($publisherSignals as $signal)
                            <article class="rounded-lg border border-slate-200 bg-slate-50 p-3">
                                <h2 class="text-sm font-bold text-slate-800">{{ $signal['name'] }}</h2>
                                <p class="mt-1 break-words text-xs font-semibold text-emerald-700">{{ $signal['value'] }}</p>
                                <p class="mt-2 text-xs leading-5 text-slate-600">{{ $signal['description'] }}</p>
                                <a href="{{ $signal['url'] }}" class="mt-3 inline-flex items-center gap-1 text-xs font-bold text-emerald-700 hover:text-emerald-600">
                                    <i class="fa-solid fa-arrow-up-right-from-square text-[0.8em]" aria-hidden="true"></i>
                                    <span>Открыть</span>
                                </a>
                            </article>
                        @endforeach
                    </div>
                </section>
            @endif
            @if ($freshnessQueries->isNotEmpty())
                <section id="freshness-seo" class="mt-5 rounded-lg border border-slate-200 bg-white p-4 shadow-sm shadow-slate-200/60" aria-label="Актуальные запросы">
                    <div class="flex items-center gap-2 text-sm font-bold text-slate-700">
                        <i class="fa-solid fa-clock-rotate-left text-emerald-700" aria-hidden="true"></i>
                        <span>Актуальные запросы {{ $currentSeoYear }}</span>
                    </div>
                    <div class="mt-3 grid gap-3 md:grid-cols-2 xl:grid-cols-3">
                        @foreach ($freshnessQueries as $item)
                            <article class="rounded-lg border border-slate-200 bg-slate-50 p-3">
                                <h2 class="text-sm font-bold text-slate-800">{{ $item['name'] }}</h2>
                                <p class="mt-2 text-xs leading-5 text-slate-600">{{ $item['description'] }}</p>
                                <a href="{{ $item['url'] }}" class="mt-3 inline-flex items-center gap-1 text-xs font-bold text-emerald-700 hover:text-emerald-600">
                                    <i class="fa-solid fa-arrow-up-right-from-square text-[0.8em]" aria-hidden="true"></i>
                                    <span>{{ $item['query'] }}</span>
                                </a>
                            </article>
                        @endforeach
                    </div>
                </section>
            @endif
            @if ($russianQueryVariants->isNotEmpty())
                <section id="russian-query-variants" class="mt-5 rounded-lg border border-slate-200 bg-white p-4 shadow-sm shadow-slate-200/60" aria-label="Русские варианты поиска" itemscope itemtype="https://schema.org/SiteNavigationElement">
                    <div class="flex items-center gap-2 text-sm font-bold text-slate-700">
                        <i class="fa-solid fa-language text-emerald-700" aria-hidden="true"></i>
                        <span itemprop="name">Русские варианты поиска</span>
                    </div>
                    <div class="mt-3 flex flex-wrap gap-2">
                        @foreach ($russianQueryVariants->take(42) as $query)
                            <a href="{{ $seoSearchUrl($query) }}" itemprop="url" class="inline-flex items-center gap-1 rounded-full bg-white px-2.5 py-1 text-xs font-semibold text-slate-700 ring-1 ring-slate-200 hover:bg-emerald-50 hover:text-emerald-700">
                                <i class="fa-solid fa-spell-check text-[0.8em] text-slate-400" aria-hidden="true"></i>
                                <span itemprop="name">{{ $query }}</span>
                            </a>
                        @endforeach
                    </div>
                </section>
            @endif
            @if ($catalogDirections->isNotEmpty())
                <section id="catalog-directions" class="mt-5 rounded-lg border border-slate-200 bg-white p-4 shadow-sm shadow-slate-200/60" aria-label="Направления каталога">
                    <div class="flex items-center gap-2 text-sm font-bold text-slate-700">
                        <i class="fa-solid fa-compass-drafting text-emerald-700" aria-hidden="true"></i>
                        <span>Направления каталога</span>
                    </div>
                    <div class="mt-3 grid gap-3 md:grid-cols-2 xl:grid-cols-3">
                        @foreach ($catalogDirections as $item)
                            <article class="rounded-lg border border-slate-200 bg-slate-50 p-3">
                                <h2 class="text-sm font-bold text-slate-800">{{ $item['name'] }}</h2>
                                <p class="mt-2 text-xs leading-5 text-slate-600">{{ $item['description'] }}</p>
                                <a href="{{ $item['url'] }}" class="mt-3 inline-flex items-center gap-1 text-xs font-bold text-emerald-700 hover:text-emerald-600">
                                    <i class="fa-solid fa-arrow-up-right-from-square text-[0.8em]" aria-hidden="true"></i>
                                    <span>{{ $item['query'] }}</span>
                                </a>
                            </article>
                        @endforeach
                    </div>
                </section>
            @endif
            @if ($comparisonQueries->isNotEmpty())
                <section id="comparison-seo" class="mt-5 rounded-lg border border-slate-200 bg-white p-4 shadow-sm shadow-slate-200/60" aria-label="Похожие и сравнения">
                    <div class="flex items-center gap-2 text-sm font-bold text-slate-700">
                        <i class="fa-solid fa-code-compare text-emerald-700" aria-hidden="true"></i>
                        <span>Похожие и сравнения</span>
                    </div>
                    <div class="mt-3 grid gap-3 md:grid-cols-2 xl:grid-cols-3">
                        @foreach ($comparisonQueries as $item)
                            <article class="rounded-lg border border-slate-200 bg-slate-50 p-3">
                                <h2 class="text-sm font-bold text-slate-800">{{ $item['name'] }}</h2>
                                <p class="mt-2 text-xs leading-5 text-slate-600">{{ $item['description'] }}</p>
                                <a href="{{ $seoSearchUrl($item['query']) }}" class="mt-3 inline-flex items-center gap-1 text-xs font-bold text-emerald-700 hover:text-emerald-600">
                                    <i class="fa-solid fa-magnifying-glass text-[0.8em]" aria-hidden="true"></i>
                                    <span>{{ $item['query'] }}</span>
                                </a>
                            </article>
                        @endforeach
                    </div>
                </section>
            @endif
            @if ($episodeIntentQueries->isNotEmpty())
                <section id="episode-intents" class="mt-5 rounded-lg border border-slate-200 bg-white p-4 shadow-sm shadow-slate-200/60" aria-label="Серии и сезоны">
                    <div class="flex items-center gap-2 text-sm font-bold text-slate-700">
                        <i class="fa-solid fa-film text-emerald-700" aria-hidden="true"></i>
                        <span>Серии и сезоны</span>
                    </div>
                    <div class="mt-3 grid gap-3 md:grid-cols-2 xl:grid-cols-4">
                        @foreach ($episodeIntentQueries as $item)
                            <article class="rounded-lg border border-slate-200 bg-slate-50 p-3">
                                <h2 class="text-sm font-bold text-slate-800">{{ $item['name'] }}</h2>
                                <p class="mt-2 text-xs leading-5 text-slate-600">{{ $item['description'] }}</p>
                                <a href="{{ $seoSearchUrl($item['query']) }}" class="mt-3 inline-flex items-center gap-1 text-xs font-bold text-emerald-700 hover:text-emerald-600">
                                    <i class="fa-solid fa-magnifying-glass text-[0.8em]" aria-hidden="true"></i>
                                    <span>{{ $item['query'] }}</span>
                                </a>
                            </article>
                        @endforeach
                    </div>
                </section>
            @endif
            @if ($watchModeQueries->isNotEmpty())
                <section id="watch-mode-seo" class="mt-5 rounded-lg border border-slate-200 bg-white p-4 shadow-sm shadow-slate-200/60" aria-label="Способы просмотра">
                    <div class="flex items-center gap-2 text-sm font-bold text-slate-700">
                        <i class="fa-solid fa-display text-emerald-700" aria-hidden="true"></i>
                        <span>Способы просмотра</span>
                    </div>
                    <div class="mt-3 grid gap-3 md:grid-cols-2 xl:grid-cols-4">
                        @foreach ($watchModeQueries as $item)
                            <article class="rounded-lg border border-slate-200 bg-slate-50 p-3">
                                <h2 class="text-sm font-bold text-slate-800">{{ $item['name'] }}</h2>
                                <p class="mt-2 text-xs leading-5 text-slate-600">{{ $item['description'] }}</p>
                                <a href="{{ $seoSearchUrl($item['query']) }}" class="mt-3 inline-flex items-center gap-1 text-xs font-bold text-emerald-700 hover:text-emerald-600">
                                    <i class="fa-solid fa-play text-[0.8em]" aria-hidden="true"></i>
                                    <span>{{ $item['query'] }}</span>
                                </a>
                            </article>
                        @endforeach
                    </div>
                </section>
            @endif
            @if ($translationQueries->isNotEmpty())
                <section id="translation-seo" class="mt-5 rounded-lg border border-slate-200 bg-white p-4 shadow-sm shadow-slate-200/60" aria-label="Переводы и озвучки">
                    <div class="flex items-center gap-2 text-sm font-bold text-slate-700">
                        <i class="fa-solid fa-microphone-lines text-emerald-700" aria-hidden="true"></i>
                        <span>Переводы и озвучки</span>
                    </div>
                    <div class="mt-3 grid gap-3 md:grid-cols-2 xl:grid-cols-4">
                        @foreach ($translationQueries as $item)
                            <article class="rounded-lg border border-slate-200 bg-slate-50 p-3">
                                <h2 class="text-sm font-bold text-slate-800">{{ $item['name'] }}</h2>
                                <p class="mt-2 text-xs leading-5 text-slate-600">{{ $item['description'] }}</p>
                                <a href="{{ $seoSearchUrl($item['query']) }}" class="mt-3 inline-flex items-center gap-1 text-xs font-bold text-emerald-700 hover:text-emerald-600">
                                    <i class="fa-solid fa-language text-[0.8em]" aria-hidden="true"></i>
                                    <span>{{ $item['query'] }}</span>
                                </a>
                            </article>
                        @endforeach
                    </div>
                </section>
            @endif
            @if ($voiceSearchQueries->isNotEmpty())
                <section id="voice-search-seo" class="mt-5 rounded-lg border border-slate-200 bg-white p-4 shadow-sm shadow-slate-200/60" aria-label="Голосовые запросы">
                    <div class="flex items-center gap-2 text-sm font-bold text-slate-700">
                        <i class="fa-solid fa-comments text-emerald-700" aria-hidden="true"></i>
                        <span>Голосовые запросы</span>
                    </div>
                    <div class="mt-3 grid gap-3 md:grid-cols-2 xl:grid-cols-4">
                        @foreach ($voiceSearchQueries as $item)
                            <article class="rounded-lg border border-slate-200 bg-slate-50 p-3">
                                <h2 class="text-sm font-bold text-slate-800">{{ $item['name'] }}</h2>
                                <p class="mt-2 text-xs leading-5 text-slate-600">{{ $item['description'] }}</p>
                                <a href="{{ $seoSearchUrl($item['query']) }}" class="mt-3 inline-flex items-center gap-1 text-xs font-bold text-emerald-700 hover:text-emerald-600">
                                    <i class="fa-solid fa-magnifying-glass text-[0.8em]" aria-hidden="true"></i>
                                    <span>{{ $item['query'] }}</span>
                                </a>
                            </article>
                        @endforeach
                    </div>
                </section>
            @endif
            @if ($topicAuthoritySignals->isNotEmpty())
                <section id="topic-authority-seo" class="mt-5 rounded-lg border border-slate-200 bg-white p-4 shadow-sm shadow-slate-200/60" aria-label="Тематический авторитет">
                    <div class="flex items-center gap-2 text-sm font-bold text-slate-700">
                        <i class="fa-solid fa-award text-emerald-700" aria-hidden="true"></i>
                        <span>Тематический авторитет</span>
                    </div>
                    <div class="mt-3 grid gap-3 md:grid-cols-2 xl:grid-cols-4">
                        @foreach ($topicAuthoritySignals as $item)
                            <article class="rounded-lg border border-slate-200 bg-slate-50 p-3">
                                <h2 class="text-sm font-bold text-slate-800">{{ $item['name'] }}</h2>
                                <p class="mt-2 text-xs leading-5 text-slate-600">{{ $item['description'] }}</p>
                                <a href="{{ $seoSearchUrl($item['query']) }}" class="mt-3 inline-flex items-center gap-1 text-xs font-bold text-emerald-700 hover:text-emerald-600">
                                    <i class="fa-solid fa-magnifying-glass text-[0.8em]" aria-hidden="true"></i>
                                    <span>{{ $item['query'] }}</span>
                                </a>
                            </article>
                        @endforeach
                    </div>
                </section>
            @endif
            @if ($releaseCalendarQueries->isNotEmpty())
                <section id="release-calendar-seo" class="mt-5 rounded-lg border border-slate-200 bg-white p-4 shadow-sm shadow-slate-200/60" aria-label="Календарь релизов">
                    <div class="flex items-center gap-2 text-sm font-bold text-slate-700">
                        <i class="fa-solid fa-calendar-days text-emerald-700" aria-hidden="true"></i>
                        <span>Календарь релизов</span>
                    </div>
                    <div class="mt-3 grid gap-3 md:grid-cols-2 xl:grid-cols-4">
                        @foreach ($releaseCalendarQueries as $item)
                            <article class="rounded-lg border border-slate-200 bg-slate-50 p-3">
                                <h2 class="text-sm font-bold text-slate-800">{{ $item['name'] }}</h2>
                                <p class="mt-2 text-xs leading-5 text-slate-600">{{ $item['description'] }}</p>
                                <a href="{{ $seoSearchUrl($item['query']) }}" class="mt-3 inline-flex items-center gap-1 text-xs font-bold text-emerald-700 hover:text-emerald-600">
                                    <i class="fa-solid fa-clock text-[0.8em]" aria-hidden="true"></i>
                                    <span>{{ $item['query'] }}</span>
                                </a>
                            </article>
                        @endforeach
                    </div>
                </section>
            @endif
            @if ($quickAnswers->isNotEmpty())
                <section class="mt-5 rounded-lg border border-slate-200 bg-white p-4 shadow-sm shadow-slate-200/60" aria-label="Быстрые ответы" id="quick-answers">
                    <div class="flex items-center gap-2 text-sm font-bold text-slate-700">
                        <i class="fa-solid fa-circle-question text-emerald-700" aria-hidden="true"></i>
                        <span>Быстрые ответы</span>
                    </div>
                    <div class="mt-3 grid gap-3 lg:grid-cols-3">
                        @foreach ($quickAnswers as $item)
                            <article class="rounded-lg border border-slate-200 bg-slate-50 p-3">
                                <h2 class="text-sm font-bold text-slate-800">{{ $item['question'] }}</h2>
                                <p class="mt-2 text-sm leading-6 text-slate-600">{{ $item['answer'] }}</p>
                            </article>
                        @endforeach
                    </div>
                </section>
            @endif
            @if (! empty($seo['keyword_clusters']))
                <section id="semantic-clusters" class="mt-5 rounded-lg border border-slate-200 bg-white p-4 shadow-sm shadow-slate-200/60" aria-label="Семантические кластеры">
                    <div class="flex items-center gap-2 text-sm font-bold text-slate-700">
                        <i class="fa-solid fa-diagram-project text-emerald-700" aria-hidden="true"></i>
                        <span>Семантические подборки</span>
                    </div>
                    <div class="mt-3 grid gap-3 md:grid-cols-3">
                        @foreach (collect($seo['keyword_clusters'])->filter()->take(6) as $cluster)
                            <div class="rounded-lg bg-slate-50 p-3 ring-1 ring-slate-200">
                                <div class="text-xs font-bold uppercase tracking-wide text-slate-500">{{ $cluster['title'] }}</div>
                                <div class="mt-2 flex flex-wrap gap-1.5">
                                    @foreach (collect($cluster['items'] ?? [])->filter()->unique()->take(8) as $item)
                                        <a href="{{ $seoSearchUrl($item) }}" class="rounded-full bg-white px-2 py-1 text-xs font-semibold text-slate-600 ring-1 ring-slate-200 hover:bg-emerald-50 hover:text-emerald-700">{{ $item }}</a>
                                    @endforeach
                                </div>
                            </div>
                        @endforeach
                    </div>
                </section>
            @endif
            @if (! empty($seo['search_phrases']))
                <section id="popular-searches" class="mt-5 rounded-lg border border-slate-200 bg-white p-4 shadow-sm shadow-slate-200/60" aria-label="Популярные поисковые запросы">
                    <div class="flex items-center gap-2 text-sm font-bold text-slate-700">
                        <i class="fa-solid fa-magnifying-glass-chart text-emerald-700" aria-hidden="true"></i>
                        <span>Популярные запросы</span>
                    </div>
                    <div class="mt-3 flex flex-wrap gap-2">
                        @foreach (collect($seo['search_phrases'])->filter()->unique()->take(18) as $phrase)
                            <a href="{{ $seoSearchUrl($phrase) }}" class="inline-flex items-center gap-1 rounded-full bg-slate-50 px-2.5 py-1 text-xs font-semibold text-slate-600 ring-1 ring-slate-200 hover:bg-emerald-50 hover:text-emerald-700">
                                <i class="fa-solid fa-key text-[0.8em] text-slate-400" aria-hidden="true"></i>
                                <span>{{ $phrase }}</span>
                            </a>
                        @endforeach
                    </div>
                </section>
            @endif
        </main>
    </body>
</html>
