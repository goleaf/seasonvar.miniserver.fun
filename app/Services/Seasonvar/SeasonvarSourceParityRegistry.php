<?php

declare(strict_types=1);

namespace App\Services\Seasonvar;

use App\Enums\CatalogFilterType;
use App\Enums\SeasonvarPageType;

final class SeasonvarSourceParityRegistry
{
    /**
     * @return array<string, array{
     *     can_discover: bool,
     *     can_store_source_page: bool,
     *     can_parse: bool,
     *     can_publish_local_page: bool,
     *     can_add_to_sitemap: bool,
     *     parser_class: class-string|null,
     *     local_route_name: string|null
     * }>
     */
    public function capabilities(): array
    {
        $taxonomyTypes = CatalogFilterType::values();
        $capabilities = [];

        foreach (SeasonvarPageType::cases() as $type) {
            $isTaxonomy = in_array($type->value, $taxonomyTypes, true);
            $capabilities[$type->value] = [
                'can_discover' => true,
                'can_store_source_page' => true,
                'can_parse' => $type === SeasonvarPageType::Serial,
                'can_publish_local_page' => $type === SeasonvarPageType::Serial
                    || $isTaxonomy
                    || in_array($type, [
                        SeasonvarPageType::StaticPage,
                        SeasonvarPageType::Rss,
                        SeasonvarPageType::Search,
                        SeasonvarPageType::Sitemap,
                    ], true),
                'can_add_to_sitemap' => $type === SeasonvarPageType::Serial
                    || $isTaxonomy
                    || in_array($type, [SeasonvarPageType::StaticPage, SeasonvarPageType::Search], true),
                'parser_class' => $type === SeasonvarPageType::Serial
                    ? SeasonvarCatalogParser::class
                    : null,
                'local_route_name' => $this->routeName($type, $isTaxonomy),
            ];
        }

        return $capabilities;
    }

    /** @return list<string> */
    public function supportedImportTypes(): array
    {
        return collect($this->capabilities())
            ->filter(fn (array $capability): bool => $capability['can_parse'])
            ->keys()
            ->values()
            ->all();
    }

    /** @return list<string> */
    public function supportedPublicPageTypes(): array
    {
        return collect($this->capabilities())
            ->filter(fn (array $capability): bool => $capability['can_publish_local_page'])
            ->keys()
            ->values()
            ->all();
    }

    /**
     * @param  array<string, int>  $countsByPageType
     * @return list<string>
     */
    public function unsupportedDiscoveredTypes(array $countsByPageType): array
    {
        $capabilities = $this->capabilities();

        return collect($countsByPageType)
            ->filter(fn (int $count): bool => $count > 0)
            ->keys()
            ->filter(function (string $type) use ($capabilities): bool {
                $capability = $capabilities[$type] ?? null;

                return $capability === null
                    || ! $capability['can_parse']
                    || ! $capability['can_publish_local_page'];
            })
            ->values()
            ->all();
    }

    private function routeName(SeasonvarPageType $type, bool $isTaxonomy): ?string
    {
        if ($isTaxonomy) {
            return 'titles.taxonomy';
        }

        return match ($type) {
            SeasonvarPageType::Serial => 'titles.show',
            SeasonvarPageType::StaticPage => 'home',
            SeasonvarPageType::Rss => 'feed',
            SeasonvarPageType::Search => 'titles.index',
            SeasonvarPageType::Sitemap => 'sitemap.index',
            default => null,
        };
    }
}
