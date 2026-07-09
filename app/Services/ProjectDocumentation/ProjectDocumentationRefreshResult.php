<?php

namespace App\Services\ProjectDocumentation;

class ProjectDocumentationRefreshResult
{
    /**
     * @param  list<string>  $changedFiles
     * @param  list<string>  $missingFiles
     */
    public function __construct(
        public readonly array $changedFiles,
        public readonly array $missingFiles,
    ) {}

    public function hasChanges(): bool
    {
        return $this->changedFiles !== [];
    }
}
