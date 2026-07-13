<?php

declare(strict_types=1);

namespace App\DTOs\Seasonvar;

final readonly class SeasonvarTitleManifest
{
    /**
     * @param  array<string, true>  $seasonKeys
     * @param  array<string, true>  $episodeKeys
     * @param  array<string, true>  $mediaKeys
     */
    public function __construct(
        public array $seasonKeys,
        public array $episodeKeys,
        public array $mediaKeys,
    ) {}

    /** @return array<string, int> */
    public function comparison(self $local): array
    {
        return [
            'source_seasons' => count($this->seasonKeys),
            'local_seasons' => count($local->seasonKeys),
            'source_episodes' => count($this->episodeKeys),
            'local_episodes' => count($local->episodeKeys),
            'source_media' => count($this->mediaKeys),
            'local_media' => count($local->mediaKeys),
            'missing_local' => count(array_diff_key($this->seasonKeys, $local->seasonKeys))
                + count(array_diff_key($this->episodeKeys, $local->episodeKeys))
                + count(array_diff_key($this->mediaKeys, $local->mediaKeys)),
            'local_only' => count(array_diff_key($local->seasonKeys, $this->seasonKeys))
                + count(array_diff_key($local->episodeKeys, $this->episodeKeys))
                + count(array_diff_key($local->mediaKeys, $this->mediaKeys)),
        ];
    }
}
