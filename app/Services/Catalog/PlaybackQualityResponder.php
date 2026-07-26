<?php

declare(strict_types=1);

namespace App\Services\Catalog;

use App\Http\Requests\StorePlaybackQualitySampleRequest;
use App\Models\LicensedMedia;
use App\Models\User;
use App\Services\TechnicalIssues\TechnicalIssueContext;
use Illuminate\Http\JsonResponse;

final class PlaybackQualityResponder
{
    public function __construct(
        private readonly PlaybackQualityRecorder $recorder,
        private readonly PlaybackQualityContext $context,
        private readonly TechnicalIssueContext $technicalIssues,
    ) {}

    public function response(StorePlaybackQualitySampleRequest $request): JsonResponse
    {
        $user = $request->user();
        $session = $this->recorder->record(
            $request->validated(),
            $user instanceof User ? $user : null,
        );
        $report = $request->validated('event') === 'report' && $user instanceof User;

        $reportToken = $report ? $this->context->reportToken($session) : null;

        return response()->json(array_filter([
            'request_id' => $session->request_id,
            'issue_url' => $reportToken !== null ? $this->issueUrl($session->current_media_id, $reportToken) : null,
        ]), 202, [
            'Cache-Control' => 'private, no-store, max-age=0',
            'Pragma' => 'no-cache',
            'Referrer-Policy' => 'no-referrer',
            'X-Content-Type-Options' => 'nosniff',
            'X-Robots-Tag' => 'noindex, nofollow, noarchive',
        ]);
    }

    private function issueUrl(?int $mediaId, string $reportToken): ?string
    {
        $media = $mediaId !== null
            ? LicensedMedia::query()
                ->with([
                    'catalogTitle:id,slug,title',
                    'season:id,catalog_title_id,number,kind',
                    'episode:id,season_id,number,kind,title',
                ])
                ->find($mediaId)
            : null;

        if (! $media instanceof LicensedMedia || $media->catalogTitle === null) {
            return null;
        }

        $url = $this->technicalIssues->playerUrl(
            $media->catalogTitle,
            $media->season,
            $media->episode,
            $media,
        );
        $separator = str_contains($url, '?') ? '&' : '?';

        return $url.$separator.http_build_query([
            'diagnostics' => $reportToken,
            'type' => 'video_unavailable',
        ], encoding_type: PHP_QUERY_RFC3986);
    }
}
