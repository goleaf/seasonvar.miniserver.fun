<?php

namespace App\Services\ProjectDocumentation;

class ProjectDocumentationRefreshResult
{
    /**
     * @param  list<string>  $changedFiles
     * @param  list<string>  $missingFiles
     * @param  list<string>  $brokenLinks
     */
    public function __construct(
        public readonly array $changedFiles,
        public readonly array $missingFiles,
        public readonly array $brokenLinks = [],
    ) {}

    public function hasChanges(): bool
    {
        return $this->changedFiles !== [];
    }

    public function hasBrokenLinks(): bool
    {
        return $this->brokenLinks !== [];
    }
}
