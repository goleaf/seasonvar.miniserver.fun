<?php

declare(strict_types=1);

namespace App\Services\Seasonvar;

use App\DTOs\Seasonvar\SeasonvarPageHandlerDefinition;
use App\Enums\SeasonvarPageType;
use App\Services\Seasonvar\PageHandlers\SeasonvarPageHandler;
use App\Services\Seasonvar\PageHandlers\SeasonvarPassivePageHandler;
use App\Services\Seasonvar\PageHandlers\SeasonvarRssPageHandler;
use App\Services\Seasonvar\PageHandlers\SeasonvarSerialPageHandler;
use App\Services\Seasonvar\PageHandlers\SeasonvarTaxonomyPageHandler;
use InvalidArgumentException;

final class SeasonvarPageHandlerRegistry
{
    /** @var array<string, SeasonvarPageHandler>|null */
    private ?array $handlers = null;

    public function __construct(
        private readonly SeasonvarTaxonomyPageParser $taxonomyParser,
        private readonly SeasonvarTaxonomyPageImporter $taxonomyImporter,
        private readonly SeasonvarRssFreshnessImporter $rssImporter,
    ) {}

    public function handler(SeasonvarPageType|string $type): SeasonvarPageHandler
    {
        $type = is_string($type) ? SeasonvarPageType::tryFrom($type) : $type;

        if ($type === null || ! isset($this->all()[$type->value])) {
            throw new InvalidArgumentException('Для указанного типа страницы Seasonvar нет обработчика.');
        }

        return $this->all()[$type->value];
    }

    /** @return array<string, SeasonvarPageHandler> */
    public function all(): array
    {
        if ($this->handlers !== null) {
            return $this->handlers;
        }

        $handlers = [
            new SeasonvarSerialPageHandler,
            new SeasonvarTaxonomyPageHandler(SeasonvarPageType::Actor, $this->taxonomyParser, $this->taxonomyImporter),
            new SeasonvarTaxonomyPageHandler(SeasonvarPageType::Genre, $this->taxonomyParser, $this->taxonomyImporter),
            new SeasonvarTaxonomyPageHandler(SeasonvarPageType::Country, $this->taxonomyParser, $this->taxonomyImporter),
            new SeasonvarTaxonomyPageHandler(SeasonvarPageType::Tag, $this->taxonomyParser, $this->taxonomyImporter),
            new SeasonvarRssPageHandler($this->rssImporter),
        ];
        $implemented = collect($handlers)
            ->map(fn (SeasonvarPageHandler $handler): string => $handler->definition()->pageType->value)
            ->all();

        foreach (SeasonvarPageType::cases() as $type) {
            if (! in_array($type->value, $implemented, true)) {
                $handlers[] = new SeasonvarPassivePageHandler($type);
            }
        }

        return $this->handlers = collect($handlers)
            ->mapWithKeys(fn (SeasonvarPageHandler $handler): array => [
                $handler->definition()->pageType->value => $handler,
            ])
            ->all();
    }

    /** @return array<string, SeasonvarPageHandlerDefinition> */
    public function definitions(): array
    {
        return collect($this->all())
            ->map(fn (SeasonvarPageHandler $handler): SeasonvarPageHandlerDefinition => $this->configuredDefinition($handler->definition()))
            ->all();
    }

    public function definition(SeasonvarPageType|string $type): SeasonvarPageHandlerDefinition
    {
        return $this->configuredDefinition($this->handler($type)->definition());
    }

    /**
     * @param  list<string>|null  $requestedTypes
     * @return list<string>
     */
    public function processingTypes(?array $requestedTypes = null): array
    {
        $requested = $requestedTypes === null
            ? null
            : collect($requestedTypes)->map(fn (string $type): string => trim($type))->filter()->unique()->values();

        return collect($this->definitions())
            ->filter(function (SeasonvarPageHandlerDefinition $definition) use ($requested): bool {
                if (! $this->isEnabled($definition->pageType)) {
                    return false;
                }

                if ($requested !== null) {
                    return $requested->contains($definition->pageType->value)
                        && $definition->parserClass !== null
                        && $definition->importerClass !== null;
                }

                return $definition->automaticParsing
                    && $definition->parserClass !== null
                    && $definition->importerClass !== null;
            })
            ->keys()
            ->values()
            ->all();
    }

    public function isEnabled(SeasonvarPageType $type): bool
    {
        return (bool) config("seasonvar.page_types.{$type->value}.enabled", false);
    }

    public function chunkSize(SeasonvarPageType $type): int
    {
        return max(1, (int) config("seasonvar.page_types.{$type->value}.chunk_size", config('seasonvar.import.chunk_size', 100)));
    }

    public function refreshHours(SeasonvarPageType $type): int
    {
        return max(1, (int) config("seasonvar.page_types.{$type->value}.refresh_after_hours", config('seasonvar.import.refresh_after_hours', 24)));
    }

    private function configuredDefinition(SeasonvarPageHandlerDefinition $definition): SeasonvarPageHandlerDefinition
    {
        return new SeasonvarPageHandlerDefinition(
            pageType: $definition->pageType,
            persistOnDiscovery: $definition->persistOnDiscovery,
            automaticParsing: (bool) config(
                "seasonvar.page_types.{$definition->pageType->value}.automatic",
                $definition->automaticParsing,
            ),
            metadataOnly: $definition->metadataOnly,
            parserClass: $definition->parserClass,
            importerClass: $definition->importerClass,
            retryBehavior: $definition->retryBehavior,
            expectedResultType: $definition->expectedResultType,
            canGenerateLocalPublicPage: $definition->canGenerateLocalPublicPage,
            sourceAccess: $definition->sourceAccess,
            publicationAuthorized: (bool) config(
                "seasonvar.page_types.{$definition->pageType->value}.publication_authorized",
                $definition->publicationAuthorized,
            ),
        );
    }
}
