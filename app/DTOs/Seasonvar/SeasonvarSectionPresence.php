<?php

declare(strict_types=1);

namespace App\DTOs\Seasonvar;

use InvalidArgumentException;

final readonly class SeasonvarSectionPresence
{
    public const COMPLETE = 'complete';

    public const PARTIAL = 'partial';

    public const ABSENT = 'absent';

    public const UNKNOWN = 'unknown';

    public const INVALID = 'invalid';

    /** @var list<string> */
    private const SECTIONS = [
        'metadata',
        'taxonomies',
        'seasons',
        'episodes',
        'media',
        'aliases',
        'ratings',
        'recommendations',
        'reviews',
    ];

    /** @var list<string> */
    private const STATES = [
        self::COMPLETE,
        self::PARTIAL,
        self::ABSENT,
        self::UNKNOWN,
        self::INVALID,
    ];

    /** @param array<string, string> $states */
    public function __construct(
        public array $states,
    ) {
        foreach (self::SECTIONS as $section) {
            $state = $states[$section] ?? null;

            if (! is_string($state) || ! in_array($state, self::STATES, true)) {
                throw new InvalidArgumentException("Invalid Seasonvar presence state for section [{$section}].");
            }
        }
    }

    /** @param array<string, mixed> $parseMeta */
    public static function fromParseMeta(array $parseMeta): self
    {
        $stored = $parseMeta['section_presence'] ?? null;

        if (is_array($stored)) {
            return new self(collect(self::SECTIONS)
                ->mapWithKeys(static fn (string $section): array => [
                    $section => is_string($stored[$section] ?? null)
                        ? $stored[$section]
                        : self::UNKNOWN,
                ])
                ->all());
        }

        $metadata = ($parseMeta['has_info_list'] ?? false) === true
            ? self::COMPLETE
            : self::UNKNOWN;
        $seasons = ($parseMeta['has_season_list'] ?? false) === true
            ? self::COMPLETE
            : self::UNKNOWN;
        $episodes = ($parseMeta['has_episode_script'] ?? false) === true
            ? self::COMPLETE
            : self::UNKNOWN;

        return new self([
            'metadata' => $metadata,
            'taxonomies' => $metadata,
            'seasons' => $seasons,
            'episodes' => $episodes,
            'media' => self::UNKNOWN,
            'aliases' => $metadata,
            'ratings' => $metadata,
            'recommendations' => self::UNKNOWN,
            'reviews' => self::UNKNOWN,
        ]);
    }

    public function state(string $section): string
    {
        if (! in_array($section, self::SECTIONS, true)) {
            throw new InvalidArgumentException("Unknown Seasonvar section [{$section}].");
        }

        return $this->states[$section];
    }

    public function isAuthoritative(string $section): bool
    {
        return in_array($this->state($section), [self::COMPLETE, self::ABSENT], true);
    }

    /** @return array<string, string> */
    public function toArray(): array
    {
        return $this->states;
    }
}
