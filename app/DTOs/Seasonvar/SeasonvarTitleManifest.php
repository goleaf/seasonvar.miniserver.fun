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
    public function comparison(self $local, ?self $localBefore = null): array
    {
        $localBefore ??= $local;
        $missingLocal = $this->differenceCount($this, $local);
        $localOnly = $this->differenceCount($local, $this);

        return [
            'source_seasons' => count($this->seasonKeys),
            'local_seasons' => count($local->seasonKeys),
            'source_episodes' => count($this->episodeKeys),
            'local_episodes' => count($local->episodeKeys),
            'source_media' => count($this->mediaKeys),
            'local_media' => count($local->mediaKeys),
            'local_seasons_before' => count($localBefore->seasonKeys),
            'local_episodes_before' => count($localBefore->episodeKeys),
            'local_media_before' => count($localBefore->mediaKeys),
            'local_seasons_after' => count($local->seasonKeys),
            'local_episodes_after' => count($local->episodeKeys),
            'local_media_after' => count($local->mediaKeys),
            'added' => $this->differenceCount($this, $localBefore),
            'unchanged' => $this->intersectionCount($this, $localBefore),
            'failed' => $missingLocal,
            'missing_local' => $missingLocal,
            'local_only_before' => $this->differenceCount($localBefore, $this),
            'local_only_after' => $localOnly,
            'local_only' => $localOnly,
        ];
    }

    private function differenceCount(self $left, self $right): int
    {
        return count(array_diff_key($left->seasonKeys, $right->seasonKeys))
            + count(array_diff_key($left->episodeKeys, $right->episodeKeys))
            + count(array_diff_key($left->mediaKeys, $right->mediaKeys));
    }

    private function intersectionCount(self $left, self $right): int
    {
        return count(array_intersect_key($left->seasonKeys, $right->seasonKeys))
            + count(array_intersect_key($left->episodeKeys, $right->episodeKeys))
            + count(array_intersect_key($left->mediaKeys, $right->mediaKeys));
    }
}
