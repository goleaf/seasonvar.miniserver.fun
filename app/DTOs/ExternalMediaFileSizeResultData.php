<?php

declare(strict_types=1);

namespace App\DTOs;

use App\Enums\MediaFileSizeCheckStatus;
use Illuminate\Support\Carbon;
use InvalidArgumentException;

final readonly class ExternalMediaFileSizeResultData
{
    public function __construct(
        public MediaFileSizeCheckStatus $status,
        public ?int $bytes,
        public string $source,
        public ?int $httpStatus,
        public Carbon $checkedAt,
        public ?string $errorCategory = null,
        public ?string $safeErrorMessage = null,
        public ?string $contentType = null,
        public ?string $acceptRanges = null,
        public ?string $resolvedUrl = null,
    ) {
        if ($bytes !== null && $bytes < 0) {
            throw new InvalidArgumentException('File size bytes cannot be negative.');
        }

        if ($status === MediaFileSizeCheckStatus::Known && $bytes === null) {
            throw new InvalidArgumentException('A known file size requires an exact byte count.');
        }

        if ($status !== MediaFileSizeCheckStatus::Known && $bytes !== null) {
            throw new InvalidArgumentException('Only a known file size may contain an exact byte count.');
        }
    }

    public static function known(
        int $bytes,
        string $source,
        ?int $httpStatus,
        Carbon $checkedAt,
        ?string $contentType = null,
        ?string $acceptRanges = null,
        ?string $resolvedUrl = null,
    ): self {
        return new self(
            MediaFileSizeCheckStatus::Known,
            $bytes,
            $source,
            $httpStatus,
            $checkedAt,
            contentType: $contentType,
            acceptRanges: $acceptRanges,
            resolvedUrl: $resolvedUrl,
        );
    }

    public static function unknown(
        string $source,
        ?int $httpStatus,
        Carbon $checkedAt,
        string $errorCategory,
        string $safeErrorMessage,
        ?string $contentType = null,
        ?string $acceptRanges = null,
        ?string $resolvedUrl = null,
    ): self {
        return new self(
            MediaFileSizeCheckStatus::Unknown,
            null,
            $source,
            $httpStatus,
            $checkedAt,
            $errorCategory,
            $safeErrorMessage,
            $contentType,
            $acceptRanges,
            $resolvedUrl,
        );
    }

    public static function unsupported(
        string $source,
        Carbon $checkedAt,
        string $errorCategory,
        string $safeErrorMessage,
        ?int $httpStatus = null,
        ?string $contentType = null,
        ?string $resolvedUrl = null,
    ): self {
        return new self(
            MediaFileSizeCheckStatus::Unsupported,
            null,
            $source,
            $httpStatus,
            $checkedAt,
            $errorCategory,
            $safeErrorMessage,
            $contentType,
            resolvedUrl: $resolvedUrl,
        );
    }

    public static function failed(
        string $source,
        ?int $httpStatus,
        Carbon $checkedAt,
        string $errorCategory,
        string $safeErrorMessage,
        ?string $contentType = null,
        ?string $acceptRanges = null,
        ?string $resolvedUrl = null,
    ): self {
        return new self(
            MediaFileSizeCheckStatus::Failed,
            null,
            $source,
            $httpStatus,
            $checkedAt,
            $errorCategory,
            $safeErrorMessage,
            $contentType,
            $acceptRanges,
            $resolvedUrl,
        );
    }
}
