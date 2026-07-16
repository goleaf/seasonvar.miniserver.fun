<?php

declare(strict_types=1);

namespace App\Services\ContentRequests;

use App\DTOs\ContentRequests\ContentRequestInput;
use App\Models\ContentRequest;
use App\Services\Catalog\Search\CatalogSearchNormalizer;

final readonly class ContentRequestIdentity
{
    public function __construct(private CatalogSearchNormalizer $normalizer) {}

    public function normalizedTitle(ContentRequestInput $input): string
    {
        return $this->normalizer->key($input->originalTitle ?: $input->title);
    }

    public function exactHash(ContentRequestInput $input, array $externalIdentifiers): string
    {
        $external = collect($externalIdentifiers)
            ->map(fn (array $item): string => $item['provider'].':'.$item['normalized_identifier'])
            ->sort()
            ->implode('|');
        $usesStableTarget = $input->catalogTitleId !== null || $external !== '';
        $externalIdentity = $input->catalogTitleId === null ? $external : '';

        return hash('sha256', implode('|', [
            $input->type->value,
            $input->catalogTitleId ?? '',
            $input->seasonId ?? '',
            $input->episodeId ?? '',
            $input->seasonKind ?? '',
            $input->seasonNumber ?? '',
            $input->episodeNumber ?? '',
            $usesStableTarget ? '' : $this->normalizedTitle($input),
            $usesStableTarget ? '' : ($input->releaseYear ?? ''),
            $input->audioLanguage ?? '',
            $input->subtitleLanguage ?? '',
            $this->normalizer->key($input->translationStudio ?? ''),
            $input->translationType ?? '',
            $input->requestedQuality ?? '',
            $input->correctionField ?? '',
            $externalIdentity,
        ]));
    }

    public function forRequest(ContentRequest $request): string
    {
        $external = $request->externalIdentifiers
            ->map(fn ($item): string => $item->provider->value.':'.$item->normalized_identifier)
            ->sort()->implode('|');
        $usesStableTarget = $request->catalog_title_id !== null || $external !== '';
        $externalIdentity = $request->catalog_title_id === null ? $external : '';

        return hash('sha256', implode('|', [
            $request->type->value, $request->catalog_title_id ?? '', $request->season_id ?? '',
            $request->episode_id ?? '', $request->season_kind ?? '', $request->season_number ?? '',
            $request->episode_number ?? '', $usesStableTarget ? '' : $request->normalized_title,
            $usesStableTarget ? '' : ($request->release_year ?? ''),
            $request->audio_language ?? '', $request->subtitle_language ?? '',
            $this->normalizer->key((string) $request->translation_studio), $request->translation_type ?? '',
            $request->requested_quality ?? '', $request->correction_field ?? '', $externalIdentity,
        ]));
    }
}
