<?php

declare(strict_types=1);

namespace App\Actions\ContentRequests;

use App\Enums\ContentRequestStatus;
use App\Exceptions\ContentRequests\ContentRequestActionException;
use App\Models\CatalogTitle;
use App\Models\ContentRequest;
use App\Models\ContentRequestStatusHistory;
use App\Models\SourcePage;
use App\Models\User;
use App\Services\Seasonvar\CatalogTitleRefreshCoordinator;
use App\Services\Seasonvar\SeasonvarDiscoveredPageStore;
use App\Services\Seasonvar\SeasonvarUrl;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;

final readonly class HandoffContentRequestToImporter
{
    public function __construct(
        private CatalogTitleRefreshCoordinator $refresh,
        private SeasonvarDiscoveredPageStore $pages,
        private SeasonvarUrl $urls,
        private ChangeContentRequestStatus $statuses,
    ) {}

    public function handle(User $moderator, int $requestId): ContentRequest
    {
        $request = ContentRequest::query()->with('sourceLinks')->findOrFail($requestId);
        Gate::forUser($moderator)->authorize('moderate', $request);

        if (! in_array($request->status, [ContentRequestStatus::Approved, ContentRequestStatus::Planned, ContentRequestStatus::InProgress], true)) {
            throw new ContentRequestActionException('requests.errors.import_not_eligible');
        }

        if ($request->catalog_title_id !== null) {
            $title = CatalogTitle::query()->findOrFail($request->catalog_title_id);
            $state = $this->refresh->request($title);

            if ($state->status?->value === 'failed') {
                throw new ContentRequestActionException('requests.errors.import_handoff_failed');
            }

            if ($request->status !== ContentRequestStatus::InProgress) {
                return $this->statuses->handle(
                    $moderator,
                    $request->id,
                    ContentRequestStatus::InProgress,
                    $request->version,
                    null,
                );
            }

            return $request;
        }

        $link = $request->sourceLinks->first(fn ($source): bool => $source->provider?->value === 'seasonvar');

        if ($link === null) {
            throw new ContentRequestActionException('requests.errors.seasonvar_source_required');
        }

        try {
            $url = $this->urls->normalize($link->url);
        } catch (InvalidArgumentException) {
            throw new ContentRequestActionException('requests.errors.invalid_source_url');
        }

        if (! $this->urls->isAllowed($url)) {
            throw new ContentRequestActionException('requests.errors.invalid_source_url');
        }

        $this->pages->store([$url], $this->urls->baseUrl());
        $sourcePage = SourcePage::query()->where('url_hash', $this->urls->hash($url))->firstOrFail();
        $request->source_page_id = $sourcePage->id;
        $request->save();
        ContentRequestStatusHistory::query()->firstOrCreate(
            ['idempotency_key' => hash('sha256', 'import-handoff:'.$request->id.':'.$sourcePage->id)],
            [
                'content_request_id' => $request->id,
                'actor_id' => $moderator->id,
                'from_status' => $request->status,
                'to_status' => $request->status,
                'public_reason' => null,
            ],
        );

        return $request->status === ContentRequestStatus::Approved
            ? $this->statuses->handle(
                $moderator,
                $request->id,
                ContentRequestStatus::Planned,
                $request->version,
                null,
            )
            : $request;
    }
}
