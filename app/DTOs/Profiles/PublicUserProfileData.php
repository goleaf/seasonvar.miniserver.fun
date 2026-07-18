<?php

declare(strict_types=1);

namespace App\DTOs\Profiles;

final readonly class PublicUserProfileData
{
    /**
     * @param  array<string, int>  $counts
     * @param  array<string, bool>  $sections
     */
    public function __construct(
        public string $displayName,
        public string $username,
        public string $initial,
        public ?string $biography,
        public ?string $biographyPreview,
        public bool $biographyIsLong,
        public ?string $memberSince,
        public ?string $avatarUrl,
        public ?string $coverUrl,
        public array $counts,
        public array $sections,
        public bool $isOwner,
        public bool $canBlock,
        public bool $isBlocked,
        public bool $canMute,
        public bool $isMuted,
        public bool $canReport,
        public bool $canModerate,
        public string $canonicalUrl,
        public int $contentVersion,
    ) {}
}
