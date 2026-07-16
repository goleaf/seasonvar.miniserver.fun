<?php

declare(strict_types=1);

namespace App\Services\Seasonvar;

use App\DTOs\Seasonvar\SeasonvarPreparedCatalogPage;
use App\DTOs\Seasonvar\SeasonvarTitleManifest;
use App\Models\CatalogTitle;
use BackedEnum;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

final class SeasonvarTitleManifestBuilder
{
    /** @param Collection<int, SeasonvarPreparedCatalogPage> $pages */
    public function fromPrepared(Collection $pages): SeasonvarTitleManifest
    {
        $seasonKeys = [];
        $episodeKeys = [];
        $mediaKeys = [];

        foreach ($pages as $page) {
            foreach ($page->catalogData->seasons as $season) {
                $number = (int) ($season['number'] ?? 0);

                if ($number > 0) {
                    $seasonKeys[$this->seasonKey(
                        'regular',
                        $number,
                        $this->urlHash($season['source_url'] ?? null),
                    )] = true;
                }
            }

            foreach ($page->catalogData->episodes as $episode) {
                $seasonNumber = (int) ($episode['season_number'] ?? 0);
                $episodeNumber = (int) ($episode['number'] ?? 0);

                if ($seasonNumber > 0 && $episodeNumber > 0) {
                    $episodeKeys[$this->episodeKey('regular', $seasonNumber, 'regular', $episodeNumber)] = true;
                }
            }

            foreach ($page->catalogData->media as $media) {
                if (isset($media['url']) && is_string($media['url'])) {
                    $mediaKeys[$this->mediaKey($media['url'])] = true;
                }
            }
        }

        return new SeasonvarTitleManifest($seasonKeys, $episodeKeys, $mediaKeys);
    }

    public function fromCatalog(CatalogTitle $title): SeasonvarTitleManifest
    {
        $title->loadMissing([
            'seasons:id,catalog_title_id,kind,number,source_url_hash',
            'seasons.episodes:id,season_id,kind,number',
            'licensedMedia:id,catalog_title_id,playback_url',
        ]);
        $seasonKeys = [];
        $episodeKeys = [];
        $mediaKeys = [];

        foreach ($title->seasons as $season) {
            $kind = $this->enumValue($season->kind);
            $seasonKeys[$this->seasonKey($kind, (int) $season->number, $season->source_url_hash)] = true;

            foreach ($season->episodes as $episode) {
                $episodeKeys[$this->episodeKey(
                    $kind,
                    (int) $season->number,
                    $this->enumValue($episode->kind),
                    (int) $episode->number,
                )] = true;
            }
        }

        foreach ($title->licensedMedia as $media) {
            if (is_string($media->playback_url) && $media->playback_url !== '') {
                $mediaKeys[$this->mediaKey($media->playback_url)] = true;
            }
        }

        return new SeasonvarTitleManifest($seasonKeys, $episodeKeys, $mediaKeys);
    }

    private function seasonKey(string $kind, int $number, ?string $sourceUrlHash): string
    {
        return $kind.'|'.$number.'|'.($sourceUrlHash ?? '');
    }

    private function episodeKey(string $seasonKind, int $seasonNumber, string $episodeKind, int $episodeNumber): string
    {
        return $seasonKind.'|'.$seasonNumber.'|'.$episodeKind.'|'.$episodeNumber;
    }

    private function mediaKey(string $url): string
    {
        return hash('sha256', Str::lower($url));
    }

    private function urlHash(mixed $url): ?string
    {
        return is_string($url) && $url !== '' ? hash('sha256', $url) : null;
    }

    private function enumValue(mixed $value): string
    {
        return $value instanceof BackedEnum ? (string) $value->value : (string) $value;
    }
}
