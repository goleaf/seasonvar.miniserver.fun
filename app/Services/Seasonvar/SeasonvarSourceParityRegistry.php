<?php

declare(strict_types=1);

namespace App\Services\Seasonvar;

use App\Enums\SeasonvarPageType;

final class SeasonvarSourceParityRegistry
{
    public function __construct(private readonly SeasonvarPageHandlerRegistry $handlers) {}

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
        $capabilities = [];

        foreach (SeasonvarPageType::cases() as $type) {
            $definition = $this->handlers->definition($type);
            $isTaxonomy = in_array($type, [
                SeasonvarPageType::Actor,
                SeasonvarPageType::Genre,
                SeasonvarPageType::Country,
                SeasonvarPageType::Tag,
            ], true);
            $capabilities[$type->value] = [
                'can_discover' => $definition->persistOnDiscovery,
                'can_store_source_page' => $definition->persistOnDiscovery,
                'can_parse' => $definition->parserClass !== null && $definition->importerClass !== null,
                'can_publish_local_page' => $definition->canGenerateLocalPublicPage && $definition->publicationAuthorized,
                'can_add_to_sitemap' => $definition->canGenerateLocalPublicPage && $definition->publicationAuthorized,
                'parser_class' => $definition->parserClass,
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
