<?php

declare(strict_types=1);

namespace App\View\ViewData;

use App\DTOs\CatalogDirectoryDefinition;
use App\Models\CatalogTitle;
use App\Services\Auth\AuthenticationRedirectService;
use App\Services\Catalog\CatalogDirectoryRegistry;
use App\Services\Localization\LocalizedRouteResolver;
use App\Services\TechnicalIssues\TechnicalIssueContext;
use App\Support\PlainText;
use App\View\ViewModels\LayoutNavigationItem;
use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Contracts\Translation\Translator;
use Illuminate\Http\Request;
use Illuminate\Routing\Router;
use Illuminate\Routing\UrlGenerator;
use Illuminate\Support\Js;
use Illuminate\Support\Str;

final class AppLayoutData
{
    private const HEADER_LINK_CLASS = 'inline-flex min-h-11 min-w-11 items-center justify-center gap-2 rounded-control px-3 py-2';

    private const FOOTER_LINK_CLASS = '-mx-3 flex min-h-11 items-center gap-3 rounded-control px-3 py-2 text-sm font-semibold transition';

    public function __construct(
        private readonly CatalogDirectoryRegistry $directories,
        private readonly AuthenticationRedirectService $authenticationRoutes,
        private readonly Request $request,
        private readonly Gate $gate,
        private readonly Router $router,
        private readonly UrlGenerator $urls,
        private readonly Translator $translator,
        private readonly TechnicalIssueContext $technicalIssues,
        private readonly LocalizedRouteResolver $localizedRoutes,
    ) {}

    /**
     * @param  array<string, mixed>  $viewData
     * @return array<string, mixed>
     */
    public function from(array $viewData): array
    {
        $siteName = config('app.name', __('catalog.layout.site_name'));
        $interfaceLocale = $this->translator->getLocale();
        $defaultLocale = (string) config('catalog-collections.default_locale', 'ru');
        $layoutHomeUrl = $this->request->routeIs('localized.*') || $interfaceLocale !== $defaultLocale
            ? $this->localizedRoutes->homeFor($interfaceLocale)
            : $this->route('home');
        $authenticatedUser = $this->request->user();
        $isAuthenticated = $authenticatedUser !== null;
        $canManageImports = $authenticatedUser !== null
            && $this->gate->forUser($authenticatedUser)->allows('manage-seasonvar-imports');
        $canManageCatalog = $authenticatedUser !== null
            && $this->gate->forUser($authenticatedUser)->allows('manage-catalog');
        $canManageComments = $authenticatedUser !== null
            && $this->gate->forUser($authenticatedUser)->allows('manage-comments');
        $canManageReviews = $authenticatedUser !== null
            && $this->gate->forUser($authenticatedUser)->allows('manage-reviews');
        $canManageContentRequests = $authenticatedUser !== null
            && $this->gate->forUser($authenticatedUser)->allows('manage-content-requests');
        $canManageTechnicalIssues = $authenticatedUser !== null
            && $this->gate->forUser($authenticatedUser)->allows('manage-technical-issues');
        $canManageReleaseCalendar = $authenticatedUser !== null
            && $this->gate->forUser($authenticatedUser)->allows('manage-release-calendar');
        $canCreateTechnicalIssue = (bool) config('technical-issues.enabled', true)
            && $authenticatedUser !== null;
        $layoutHeaderNavigation = [
            $this->headerLinkUrl($layoutHomeUrl, 'fa-solid fa-house', __('catalog.navigation.home'), $this->request->routeIs('home', 'localized.home')),
            $this->headerLink('titles.index', 'fa-solid fa-list-ul', __('catalog.navigation.all_titles'), $this->request->routeIs('titles.*')),
        ];
        $layoutHeaderActions = [];

        if ($this->router->has('discover.index')) {
            $layoutHeaderNavigation[] = $this->headerLink(
                'discover.index',
                'fa-solid fa-compass',
                __('recommendations.navigation.discover'),
                $this->request->routeIs('discover.*', 'localized.discover.*'),
                ['type' => 'popular'],
            );
        }

        if ($this->router->has('calendar.upcoming')) {
            $layoutHeaderNavigation[] = $this->headerLink(
                'calendar.upcoming',
                'fa-regular fa-calendar-days',
                __('calendar.title'),
                $this->request->routeIs('calendar.*', 'localized.calendar.*'),
            );
        }

        if ($this->router->has('top.show')) {
            $layoutHeaderNavigation[] = $this->headerLink(
                'top.show',
                'fa-solid fa-trophy',
                __('top_lists.navigation'),
                $this->request->routeIs('top.*', 'localized.top.*'),
                ['category' => 'movies'],
            );
        }

        if ($this->router->has('collections.index')) {
            $layoutHeaderNavigation[] = $this->headerLink(
                'collections.index',
                'fa-solid fa-layer-group',
                __('collections.navigation.collections'),
                $this->request->routeIs('collections.index', 'collections.show', 'localized.collections.*', 'profiles.collections'),
            );
        }

        if ($this->router->has('requests.index')) {
            $layoutHeaderNavigation[] = $this->headerLink(
                'requests.index',
                'fa-solid fa-list-check',
                __('requests.directory.title'),
                $this->request->routeIs('requests.*', 'localized.requests.*'),
            );
        }

        if ($isAuthenticated) {
            $layoutHeaderNavigation[] = $this->headerLink(
                'library.index',
                'fa-solid fa-bookmark',
                __('catalog.layout.my_library'),
                $this->request->routeIs('library.*', 'viewing-activity'),
            );
            if ($this->router->has('personal-tags.index')) {
                $layoutHeaderNavigation[] = $this->headerLink(
                    'personal-tags.index',
                    'fa-solid fa-tags',
                    __('tags.personal_page.title'),
                    $this->request->routeIs('personal-tags.*'),
                );
            }
            if ($this->router->has('collections.mine')) {
                $layoutHeaderNavigation[] = $this->headerLink(
                    'collections.mine',
                    'fa-solid fa-folder-open',
                    __('collections.navigation.my_collections'),
                    $this->request->routeIs('collections.mine', 'collections.edit'),
                );
            }
            if ($this->router->has('profile.show')) {
                $layoutHeaderNavigation[] = $this->headerLink(
                    'profile.show',
                    'fa-solid fa-user',
                    __('catalog.navigation.profile'),
                    $this->request->routeIs('profile.show'),
                );
            }
            if ($this->router->has('settings.index')) {
                $layoutHeaderNavigation[] = $this->headerLink(
                    'settings.index',
                    'fa-solid fa-gear',
                    __('settings.navigation.settings'),
                    $this->request->routeIs('settings.*', 'localized.settings.*', 'profile.show', 'profile.security'),
                );
            }
            if ($this->router->has('profile.discussions')) {
                $layoutHeaderNavigation[] = $this->headerLink(
                    'profile.discussions',
                    'fa-solid fa-comments',
                    __('comments.navigation.discussions'),
                    $this->request->routeIs('profile.discussions', 'notifications.index'),
                );
            }
            if ($this->router->has('profile.reviews')) {
                $layoutHeaderNavigation[] = $this->headerLink(
                    'profile.reviews',
                    'fa-solid fa-star-half-stroke',
                    __('reviews.navigation.my_reviews'),
                    $this->request->routeIs('profile.reviews'),
                );
            }
            if ($this->router->has('issues.mine')) {
                $layoutHeaderNavigation[] = $this->headerLink(
                    'issues.mine',
                    'fa-solid fa-screwdriver-wrench',
                    __('issues.my_tickets'),
                    $this->request->routeIs('issues.*', 'localized.issues.*'),
                );
            }
            if ($canManageImports) {
                $layoutHeaderNavigation[] = $this->headerLink(
                    'admin.imports',
                    'fa-solid fa-cloud-arrow-down',
                    __('catalog.layout.import'),
                    $this->request->routeIs('admin.imports'),
                );
            }

            if ($canManageCatalog && $this->router->has('admin.collections')) {
                $layoutHeaderNavigation[] = $this->headerLink(
                    'admin.collections',
                    'fa-solid fa-list-check',
                    __('collections.navigation.admin'),
                    $this->request->routeIs('admin.collections'),
                );
            }
            if ($canManageComments && $this->router->has('admin.comments')) {
                $layoutHeaderNavigation[] = $this->headerLink(
                    'admin.comments',
                    'fa-solid fa-shield-halved',
                    __('comments.navigation.administration'),
                    $this->request->routeIs('admin.comments'),
                );
            }
            if ($canManageReviews && $this->router->has('admin.reviews')) {
                $layoutHeaderNavigation[] = $this->headerLink(
                    'admin.reviews',
                    'fa-solid fa-star-half-stroke',
                    __('reviews.navigation.administration'),
                    $this->request->routeIs('admin.reviews'),
                );
            }
            if ($canManageCatalog && $this->router->has('admin.tags')) {
                $layoutHeaderNavigation[] = $this->headerLink(
                    'admin.tags',
                    'fa-solid fa-tags',
                    __('tags.admin.title'),
                    $this->request->routeIs('admin.tags'),
                );
            }
            if ($canManageContentRequests && $this->router->has('admin.requests')) {
                $layoutHeaderNavigation[] = $this->headerLink(
                    'admin.requests',
                    'fa-solid fa-inbox',
                    __('requests.admin.title'),
                    $this->request->routeIs('admin.requests'),
                );
            }
            if ($canManageTechnicalIssues && $this->router->has('admin.issues')) {
                $layoutHeaderNavigation[] = $this->headerLink(
                    'admin.issues',
                    'fa-solid fa-headset',
                    __('issues.support_queue'),
                    $this->request->routeIs('admin.issues'),
                );
            }
            if ($canManageReleaseCalendar && $this->router->has('admin.calendar')) {
                $layoutHeaderNavigation[] = $this->headerLink(
                    'admin.calendar',
                    'fa-regular fa-calendar-check',
                    __('calendar.admin.title'),
                    $this->request->routeIs('admin.calendar'),
                );
            }
        } else {
            $layoutHeaderActions[] = $this->headerLinkUrl(
                $this->authenticationRoutes->guestUrl('login'),
                'fa-solid fa-right-to-bracket',
                __('auth.actions.login'),
                $this->request->routeIs('login', 'localized.login'),
            );

            if (config('authentication.registration.enabled', true) && $this->router->has('register')) {
                $layoutHeaderActions[] = $this->headerLinkUrl(
                    $this->authenticationRoutes->guestUrl('register'),
                    'fa-solid fa-user-plus',
                    __('auth.pages.register.title'),
                    $this->request->routeIs('register', 'localized.register'),
                );
            }
        }

        $layoutFooterNavigation = [
            $this->footerLinkUrl($layoutHomeUrl, 'fa-solid fa-house text-slate-400', __('catalog.navigation.home'), $this->request->routeIs('home', 'localized.home')),
            $this->footerLink('titles.index', 'fa-solid fa-list-ul text-slate-400', __('catalog.navigation.all_titles'), $this->request->routeIs('titles.*')),
        ];

        if ($this->router->has('discover.index')) {
            $layoutFooterNavigation[] = $this->footerLink(
                'discover.index',
                'fa-solid fa-compass text-slate-400',
                __('recommendations.navigation.discover'),
                $this->request->routeIs('discover.*', 'localized.discover.*'),
                ['type' => 'popular'],
            );
        }

        if ($this->router->has('calendar.upcoming')) {
            $layoutFooterNavigation[] = $this->footerLink(
                'calendar.upcoming',
                'fa-regular fa-calendar-days text-slate-400',
                __('calendar.title'),
                $this->request->routeIs('calendar.*', 'localized.calendar.*'),
            );
        }

        if ($this->router->has('top.show')) {
            $layoutFooterNavigation[] = $this->footerLink(
                'top.show',
                'fa-solid fa-trophy text-slate-400',
                __('top_lists.navigation'),
                $this->request->routeIs('top.*', 'localized.top.*'),
                ['category' => 'movies'],
            );
        }

        if ($this->router->has('collections.index')) {
            $layoutFooterNavigation[] = $this->footerLink(
                'collections.index',
                'fa-solid fa-layer-group text-slate-400',
                __('collections.navigation.collections'),
                $this->request->routeIs('collections.*', 'localized.collections.*'),
            );
        }

        if ($this->router->has('requests.index')) {
            $layoutFooterNavigation[] = $this->footerLink(
                'requests.index',
                'fa-solid fa-list-check text-slate-400',
                __('requests.directory.title'),
                $this->request->routeIs('requests.*', 'localized.requests.*'),
            );
        }

        if ($isAuthenticated) {
            $layoutFooterNavigation[] = $this->footerLink(
                'library.section',
                'fa-solid fa-clock-rotate-left text-slate-400',
                __('catalog.layout.my_library'),
                $this->request->routeIs('library.*'),
                ['section' => 'continue-watching'],
            );
            if ($this->router->has('issues.mine')) {
                $layoutFooterNavigation[] = $this->footerLink(
                    'issues.mine',
                    'fa-solid fa-screwdriver-wrench text-slate-400',
                    __('issues.my_tickets'),
                    $this->request->routeIs('issues.*', 'localized.issues.*'),
                );
            }
        }

        $catalogDirectoryLinks = $this->directories->all()
            ->map(fn (CatalogDirectoryDefinition $directory): LayoutNavigationItem => $this->directoryLink($directory))
            ->values();
        $layoutFooterServiceLinks = [
            $this->footerLink('stats', 'fa-solid fa-chart-simple text-slate-400', __('catalog.layout.catalog_statistics'), $this->request->routeIs('stats')),
            $this->footerLink('sitemap', 'fa-solid fa-sitemap text-slate-400', __('catalog.layout.sitemap'), false),
            $this->footerLink('feed', 'fa-solid fa-rss text-slate-400', __('catalog.layout.rss_feed'), false),
        ];

        if ($canCreateTechnicalIssue && $this->router->has('issues.create')) {
            $layoutFooterServiceLinks[] = $this->footerLinkUrl(
                $this->technicalIssueUrl(),
                'fa-solid fa-triangle-exclamation text-slate-400',
                __('issues.report_problem'),
                $this->request->routeIs('issues.create', 'localized.issues.create'),
            );
        }
        $layoutHeader = [
            'home_url' => $layoutHomeUrl,
            'search_url' => $this->navigationRoute('search.index'),
            'navigation' => $layoutHeaderNavigation,
            'actions' => $layoutHeaderActions,
            'show_logout' => $isAuthenticated,
        ];
        $layoutFooter = [
            'home_url' => $layoutHomeUrl,
            'catalog_url' => $this->route('titles.index'),
            'navigation' => $layoutFooterNavigation,
            'directories' => $catalogDirectoryLinks,
            'directory_label' => __('catalog.directories.label'),
            'service_links' => $layoutFooterServiceLinks,
            'current_year' => now()->year,
        ];
        $layoutHeadUrls = [
            'sitemap' => $this->route('sitemap.index'),
            'landing_sitemap' => $this->route('sitemap.landings'),
            'feed' => $this->route('feed'),
            'opensearch' => $this->route('opensearch'),
            'llms' => $this->route('llms'),
        ];
        $seo = is_array($viewData['seo'] ?? null)
            ? $this->normalizeSeoPayload($viewData['seo'])
            : [];
        $pageTitle = $this->nullableString($seo['title'] ?? $viewData['title'] ?? null) ?? $siteName;
        $pageTitle = $pageTitle !== '' ? $pageTitle : $siteName;
        $fullTitle = Str::contains(Str::lower($pageTitle), Str::lower($siteName))
            ? $pageTitle
            : $pageTitle.' - '.$siteName;
        $seoDescription = PlainText::clean($seo['description'] ?? __('catalog.seo.default_description'), 190);

        if ($seoDescription === '') {
            $seoDescription = PlainText::clean(__('catalog.seo.default_description'), 190);
        }

        $canonicalUrl = $this->nullableString($seo['canonical'] ?? null) ?? $this->urls->current();
        $layoutSearchValue = $this->request->old('q', $this->request->query('q', ''));
        $layoutSearchQuery = is_scalar($layoutSearchValue)
            ? mb_substr(Str::squish((string) $layoutSearchValue), 0, 80)
            : '';
        $robots = $this->nullableString($seo['robots'] ?? null)
            ?? 'index,follow,max-image-preview:large,max-snippet:-1,max-video-preview:-1';
        $seoType = $this->nullableString($seo['type'] ?? null) ?? 'website';
        $showSocialMetadata = ($seo['social'] ?? true) !== false;
        $seoImage = $this->nullableString($seo['image'] ?? null);
        $seoVideo = $this->nullableString($seo['video'] ?? null);
        $interfaceLocale = str_replace('_', '-', $interfaceLocale);
        $htmlLang = $this->nullableString($seo['htmlLang'] ?? null) ?? $interfaceLocale;
        $seoLocale = $this->nullableString($seo['locale'] ?? null) ?? __('catalog.locale.open_graph');
        $seoSection = $this->nullableString($seo['section'] ?? null);
        $previousPageUrl = $this->nullableString($seo['prev'] ?? null);
        $nextPageUrl = $this->nullableString($seo['next'] ?? null);
        $publishedTime = $this->nullableString($seo['published_time'] ?? null);
        $updatedTime = $this->nullableString($seo['updated_time'] ?? null);
        $breadcrumbs = $this->breadcrumbs($seo['breadcrumbs'] ?? []);
        $showBreadcrumbs = count($breadcrumbs) > 1;

        if ($seoImage && ! Str::startsWith($seoImage, ['http://', 'https://'])) {
            $seoImage = $this->urls->to($seoImage);
        }

        $seoImageAlt = $this->nullableString($seo['image_alt'] ?? null) ?? $fullTitle;
        $jsonLdScripts = $this->encodeJsonLdScripts($seo['jsonLd'] ?? []);
        $alternateUrls = $this->alternateUrls($seo, $htmlLang, $canonicalUrl);

        return compact(
            'siteName',
            'layoutHeader',
            'layoutFooter',
            'layoutHeadUrls',
            'layoutSearchQuery',
            'htmlLang',
            'robots',
            'seoDescription',
            'canonicalUrl',
            'seoSection',
            'previousPageUrl',
            'nextPageUrl',
            'seoLocale',
            'seoType',
            'showSocialMetadata',
            'fullTitle',
            'seoImage',
            'seoImageAlt',
            'seoVideo',
            'publishedTime',
            'updatedTime',
            'jsonLdScripts',
            'alternateUrls',
            'breadcrumbs',
            'showBreadcrumbs',
        );
    }

    /**
     * @param  array<string, mixed>  $seo
     * @return list<array{hreflang: string, url: string}>
     */
    private function alternateUrls(array $seo, string $htmlLang, string $canonicalUrl): array
    {
        if (! array_key_exists('alternates', $seo)) {
            return [
                ['hreflang' => $htmlLang, 'url' => $canonicalUrl],
                ['hreflang' => 'x-default', 'url' => $canonicalUrl],
            ];
        }

        if (! is_iterable($seo['alternates'])) {
            return [];
        }

        return collect($seo['alternates'])
            ->filter(fn (mixed $url, mixed $locale): bool => is_string($locale) && is_scalar($url))
            ->map(fn (mixed $url, string $locale): array => ['hreflang' => $locale, 'url' => trim((string) $url)])
            ->filter(fn (array $item): bool => $item['url'] !== '')
            ->values()
            ->all();
    }

    /** @param array<string, mixed> $parameters */
    private function headerLink(
        string $routeName,
        string $icon,
        string $label,
        bool $active,
        array $parameters = [],
    ): LayoutNavigationItem {
        return $this->headerLinkUrl($this->navigationRoute($routeName, $parameters), $icon, $label, $active);
    }

    private function headerLinkUrl(
        string $url,
        string $icon,
        string $label,
        bool $active,
    ): LayoutNavigationItem {
        return new LayoutNavigationItem(
            url: $url,
            icon: $icon,
            label: $label,
            className: self::HEADER_LINK_CLASS.' '.($active
                ? 'bg-emerald-50 text-emerald-700'
                : 'text-slate-600 hover:bg-slate-50 hover:text-emerald-700'),
            ariaCurrent: $active ? 'page' : null,
        );
    }

    /** @param array<string, mixed> $parameters */
    private function footerLink(
        string $routeName,
        string $icon,
        string $label,
        bool $active,
        array $parameters = [],
    ): LayoutNavigationItem {
        return $this->footerLinkUrl($this->navigationRoute($routeName, $parameters), $icon, $label, $active);
    }

    private function footerLinkUrl(string $url, string $icon, string $label, bool $active): LayoutNavigationItem
    {
        return new LayoutNavigationItem(
            url: $url,
            icon: $icon,
            label: $label,
            className: self::FOOTER_LINK_CLASS.' '.($active
                ? 'bg-emerald-50 text-emerald-700'
                : 'text-slate-600 hover:bg-slate-50 hover:text-emerald-700'),
            ariaCurrent: $active ? 'page' : null,
        );
    }

    private function technicalIssueFeature(): string
    {
        if ($this->request->routeIs('settings.*', 'localized.settings.*', 'profile.show', 'profile.security')) {
            return 'account';
        }

        if ($this->request->routeIs('notifications.index', 'profile.discussions')) {
            return 'notifications';
        }

        if ($this->request->routeIs('library.*', 'viewing-activity')) {
            return 'library';
        }

        if ($this->request->routeIs('titles.index', 'titles.year', 'titles.taxonomy', '*.index')) {
            return $this->request->filled('q') ? 'search' : ($this->request->query() !== [] ? 'filters' : 'catalog');
        }

        return 'general';
    }

    private function technicalIssueUrl(): string
    {
        $title = $this->request->route('catalogTitle');

        return $title instanceof CatalogTitle
            ? $this->technicalIssues->titleUrl($title)
            : $this->technicalIssues->featureUrl($this->technicalIssueFeature());
    }

    private function directoryLink(CatalogDirectoryDefinition $directory): LayoutNavigationItem
    {
        $active = $this->request->routeIs($directory->key.'.*');

        return new LayoutNavigationItem(
            url: $this->route($directory->indexRouteName),
            icon: $directory->icon.' shrink-0 text-slate-400',
            label: $directory->title,
            className: 'flex min-h-11 min-w-0 items-center gap-2 py-2 text-sm font-semibold transition hover:text-emerald-700 hover:underline focus-visible:outline-none focus-visible:ring-4 focus-visible:ring-emerald-200 '.($active
                ? 'text-emerald-700'
                : 'text-slate-600'),
            ariaCurrent: $active ? 'page' : null,
        );
    }

    /** @param array<string, mixed> $parameters */
    private function route(string $name, array $parameters = []): string
    {
        return $this->urls->route($name, $parameters);
    }

    /** @param array<string, mixed> $parameters */
    private function navigationRoute(string $name, array $parameters = []): string
    {
        $locale = $this->translator->getLocale();
        $localizedName = 'localized.'.$name;
        $shouldLocalize = $this->request->routeIs('localized.*')
            || $locale !== (string) config('catalog-collections.default_locale', 'ru');

        if ($shouldLocalize
            && in_array($locale, (array) config('catalog-collections.supported_locales', []), true)
            && $this->router->has($localizedName)) {
            return $this->urls->route($localizedName, ['locale' => $locale, ...$parameters]);
        }

        return $this->route($name, $parameters);
    }

    private function nullableString(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value !== '' ? $value : null;
    }

    /** @return list<array{name: string, url: string}> */
    private function breadcrumbs(mixed $items): array
    {
        if (! is_iterable($items)) {
            return [];
        }

        $breadcrumbs = [];

        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }

            $name = $this->nullableString($item['name'] ?? null);
            $url = $this->nullableString($item['url'] ?? null);

            if ($name !== null && $url !== null) {
                $breadcrumbs[] = ['name' => $name, 'url' => $url];
            }
        }

        return $breadcrumbs;
    }

    /**
     * @param  array<string, mixed>  $seo
     * @return array<string, mixed>
     */
    private function normalizeSeoPayload(array $seo): array
    {
        foreach (['title', 'image_alt', 'section'] as $key) {
            if (array_key_exists($key, $seo)) {
                $seo[$key] = $this->normalizePhrase($seo[$key]);
            }
        }

        if (array_key_exists('jsonLd', $seo)) {
            $seo['jsonLd'] = $this->normalizeJsonLd($seo['jsonLd']);
        }

        return $seo;
    }

    /** @return list<string> */
    private function encodeJsonLdScripts(mixed $items): array
    {
        if (! is_array($items) || $items === []) {
            return [];
        }

        $items = array_is_list($items) ? $items : [$items];
        $scripts = [];

        foreach ($items as $item) {
            if (! is_array($item) || $item === []) {
                continue;
            }

            $scripts[] = Js::encode($item, JSON_UNESCAPED_SLASHES);
        }

        return $scripts;
    }

    /**
     * @return list<string>
     */
    private function normalizeStringList(mixed $items): array
    {
        if (is_string($items)) {
            $items = explode(',', $items);
        }

        return collect(is_iterable($items) ? $items : [$items])
            ->filter(fn ($item) => is_scalar($item))
            ->map(fn ($item) => $this->normalizePhrase($item))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    private function normalizeJsonLd(mixed $value, ?string $key = null): mixed
    {
        if (is_array($value)) {
            foreach ($value as $itemKey => $itemValue) {
                $value[$itemKey] = $this->normalizeJsonLd(
                    $itemValue,
                    is_string($itemKey) ? $itemKey : $key,
                );
            }

            return $value;
        }

        if (! is_string($value)) {
            return $value;
        }

        if ($key === 'keywords') {
            return implode(', ', $this->normalizeStringList($value));
        }

        if (in_array($key, ['name', 'alternateName', 'headline', 'description', 'text', 'articleSection', 'genre', 'caption', 'label', 'title'], true)) {
            return $this->normalizePhrase($value);
        }

        return $value;
    }

    private function normalizePhrase(mixed $phrase): string
    {
        $phrase = PlainText::clean($phrase);
        $phrase = trim($phrase, " \t\n\r\0\x0B.,;:|-");

        if ($phrase === '') {
            return '';
        }

        for ($i = 0; $i < 4; $i++) {
            $previous = $phrase;
            $phrase = preg_replace('/\b(смотреть онлайн)(?:\s+\1)+\b/iu', '$1', $phrase) ?: $phrase;
            $phrase = preg_replace('/\bонлайн\s+онлайн\b/iu', 'онлайн', $phrase) ?: $phrase;
            $phrase = preg_replace('/\bонлайн\s+смотреть онлайн\b/iu', 'смотреть онлайн', $phrase) ?: $phrase;
            $phrase = preg_replace('/\bонлайн\s+сериал онлайн\b/iu', 'сериал онлайн', $phrase) ?: $phrase;
            $phrase = preg_replace('/\bсмотреть онлайн\s+сериал онлайн\b/iu', 'смотреть онлайн', $phrase) ?: $phrase;
            $phrase = preg_replace('/\b(смотреть в хорошем качестве)(?:\s+\1)+\b/iu', '$1', $phrase) ?: $phrase;
            $phrase = preg_replace('/\b(в хорошем качестве)\s+хорошее качество\b/iu', '$1', $phrase) ?: $phrase;
            $phrase = preg_replace('/\b(все сезоны|все серии)\s+сезоны и серии\b/iu', '$1', $phrase) ?: $phrase;
            $phrase = preg_replace('/\bвсе сезоны\s+все серии\b/iu', 'все сезоны и серии', $phrase) ?: $phrase;
            $phrase = preg_replace('/\b(сезоны и серии)(?:\s+\1)+\b/iu', '$1', $phrase) ?: $phrase;
            $phrase = preg_replace('/\b(веб[- ]плеер)\s+веб[- ]плеер\b/iu', '$1', $phrase) ?: $phrase;
            $phrase = preg_replace('/\b(мобильный просмотр)\s+мобильный просмотр\b/iu', '$1', $phrase) ?: $phrase;

            if ($phrase === $previous) {
                break;
            }
        }

        return trim($phrase, " \t\n\r\0\x0B.,;:|-");
    }
}
