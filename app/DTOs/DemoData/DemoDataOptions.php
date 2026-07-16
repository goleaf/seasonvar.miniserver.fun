<?php

declare(strict_types=1);

namespace App\DTOs\DemoData;

use InvalidArgumentException;
use LogicException;

final readonly class DemoDataOptions
{
    public function __construct(
        public string $version,
        public bool $enabled,
        public int $userCount,
        public int $coverageNumerator,
        public int $coverageDenominator,
        public int $chunkSize,
        public int $minimumFreeBytes,
        public int $personalTagMinimum,
        public int $personalTagMaximum,
        public int $personalTagsPerTitleMinimum,
        public int $personalTagsPerTitleMaximum,
        public int $collectionMinimum,
        public int $collectionMaximum,
        public int $collectionsPerTitleMinimum,
        public int $collectionsPerTitleMaximum,
        public int $requestMinimum,
        public int $requestMaximum,
        public int $issueMinimum,
        public int $issueMaximum,
        public int $notificationMinimum,
        public int $notificationMaximum,
        public int $publicTagTarget,
        public string $assetDisk,
        public string $assetPrefix,
    ) {
        if ($this->version === '' || $this->assetDisk === '' || $this->assetPrefix === '') {
            throw new InvalidArgumentException('Demo data string options must not be empty.');
        }

        if ($this->userCount < 1 || $this->coverageNumerator < 1
            || $this->coverageDenominator <= $this->coverageNumerator) {
            throw new InvalidArgumentException('Demo data coverage options are invalid.');
        }

        if ($this->chunkSize < 100 || $this->chunkSize > 5_000 || $this->minimumFreeBytes < 0) {
            throw new InvalidArgumentException('Demo data resource limits are invalid.');
        }

        foreach ([
            [$this->personalTagMinimum, $this->personalTagMaximum],
            [$this->personalTagsPerTitleMinimum, $this->personalTagsPerTitleMaximum],
            [$this->collectionMinimum, $this->collectionMaximum],
            [$this->collectionsPerTitleMinimum, $this->collectionsPerTitleMaximum],
            [$this->requestMinimum, $this->requestMaximum],
            [$this->issueMinimum, $this->issueMaximum],
            [$this->notificationMinimum, $this->notificationMaximum],
        ] as [$minimum, $maximum]) {
            if ($minimum < 1 || $maximum < $minimum) {
                throw new InvalidArgumentException('Demo data count range is invalid.');
            }
        }

        if ($this->publicTagTarget < 1) {
            throw new InvalidArgumentException('Demo public tag target must be positive.');
        }
    }

    public static function fromConfig(): self
    {
        return new self(
            version: (string) config('demo-data.version'),
            enabled: (bool) config('demo-data.enabled'),
            userCount: (int) config('demo-data.user_count'),
            coverageNumerator: (int) config('demo-data.coverage_numerator'),
            coverageDenominator: (int) config('demo-data.coverage_denominator'),
            chunkSize: (int) config('demo-data.chunk_size'),
            minimumFreeBytes: (int) config('demo-data.minimum_free_bytes'),
            personalTagMinimum: (int) config('demo-data.personal_tags.minimum'),
            personalTagMaximum: (int) config('demo-data.personal_tags.maximum'),
            personalTagsPerTitleMinimum: (int) config('demo-data.personal_tags.per_title_minimum'),
            personalTagsPerTitleMaximum: (int) config('demo-data.personal_tags.per_title_maximum'),
            collectionMinimum: (int) config('demo-data.collections.minimum'),
            collectionMaximum: (int) config('demo-data.collections.maximum'),
            collectionsPerTitleMinimum: (int) config('demo-data.collections.per_title_minimum'),
            collectionsPerTitleMaximum: (int) config('demo-data.collections.per_title_maximum'),
            requestMinimum: (int) config('demo-data.requests.minimum'),
            requestMaximum: (int) config('demo-data.requests.maximum'),
            issueMinimum: (int) config('demo-data.issues.minimum'),
            issueMaximum: (int) config('demo-data.issues.maximum'),
            notificationMinimum: (int) config('demo-data.notifications.minimum'),
            notificationMaximum: (int) config('demo-data.notifications.maximum'),
            publicTagTarget: (int) config('demo-data.public_tag_target'),
            assetDisk: (string) config('demo-data.asset_disk'),
            assetPrefix: (string) config('demo-data.asset_prefix'),
        );
    }

    public function assertEnvironment(string $environment): void
    {
        if (! in_array($environment, ['dev', 'testing'], true)) {
            throw new LogicException('Полное демонстрационное наполнение разрешено только в средах dev и testing.');
        }
    }

    public function selectedTitleCount(int $publishedTitleCount): int
    {
        if ($publishedTitleCount < 0) {
            throw new InvalidArgumentException('Published title count must not be negative.');
        }

        return intdiv($publishedTitleCount * $this->coverageNumerator, $this->coverageDenominator);
    }
}
