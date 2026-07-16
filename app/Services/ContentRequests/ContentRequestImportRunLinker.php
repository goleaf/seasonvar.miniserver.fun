<?php

declare(strict_types=1);

namespace App\Services\ContentRequests;

use App\Models\ContentRequest;
use App\Models\SeasonvarImportRun;
use App\Models\SourcePage;

final readonly class ContentRequestImportRunLinker
{
    public function __construct(private ContentRequestSchema $schema) {}

    public function link(SeasonvarImportRun $run): int
    {
        if (! $this->schema->ready()) {
            return 0;
        }

        $sourcePages = SourcePage::query()
            ->where('last_import_run_id', $run->id)
            ->select('id');

        return ContentRequest::query()
            ->whereIn('source_page_id', $sourcePages)
            ->whereNull('completed_at')
            ->where(function ($query) use ($run): void {
                $query->whereNull('import_run_id')
                    ->orWhere('import_run_id', '!=', $run->id);
            })
            ->update(['import_run_id' => $run->id]);
    }
}
