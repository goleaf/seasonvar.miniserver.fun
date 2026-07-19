<?php

declare(strict_types=1);

namespace App\DTOs\Administration;

final readonly class AdminUserData
{
    /**
     * @param  list<string>  $roleLabels
     * @param  list<string>  $restrictionLabels
     * @param  list<string>  $restrictionPublicIds
     */
    public function __construct(
        public string $publicId,
        public string $name,
        public string $maskedEmail,
        public string $verificationLabel,
        public array $roleLabels,
        public array $restrictionLabels,
        public array $restrictionPublicIds,
        public int $commentsCount,
        public int $reviewsCount,
        public int $requestsCount,
        public string $registeredAtLabel,
    ) {}
}
