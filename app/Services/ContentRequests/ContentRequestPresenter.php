<?php

declare(strict_types=1);

namespace App\Services\ContentRequests;

use App\DTOs\ContentRequests\ContentRequestCardData;
use App\DTOs\ContentRequests\ContentRequestDetailData;
use App\Models\CatalogTitle;
use App\Models\ContentRequest;
use App\Models\Episode;
use App\Models\Season;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

final class ContentRequestPresenter
{
    public function card(ContentRequest $request, ?User $viewer): ContentRequestCardData
    {
        $target = $request->catalogTitle;

        return new ContentRequestCardData(
            id: $request->id,
            publicId: $request->public_id,
            title: $request->title,
            originalTitle: $request->original_title,
            year: $request->release_year,
            type: $request->type->value,
            typeLabel: $request->type->label(),
            status: $request->status->value,
            statusLabel: $request->status->label(),
            statusDescription: $request->status->description(),
            votes: (int) ($request->votes_count ?? 0),
            followers: (int) ($request->followers_count ?? 0),
            hasVoted: (bool) ($request->viewer_has_voted ?? false),
            isFollowing: (bool) ($request->viewer_is_following ?? false),
            isRequester: $viewer !== null && $request->requester_id === $viewer->id,
            canEngage: $request->status->canEngage(),
            targetLabel: $target?->display_title,
            targetUrl: $target instanceof CatalogTitle ? route('titles.show', $target) : null,
            url: route('requests.show', $request),
            createdLabel: $request->created_at?->translatedFormat('j F Y') ?? '',
            updatedLabel: $request->updated_at?->diffForHumans() ?? '',
        );
    }

    public function detail(ContentRequest $request, ?User $viewer, bool $includeClarifications): ContentRequestDetailData
    {
        $card = $this->card($request, $viewer);
        [$completionUrl, $completionLabel] = $this->completion($request);

        return new ContentRequestDetailData(
            card: $card,
            version: $request->version,
            alternativeTitle: $request->alternative_title,
            country: $request->country,
            originalLanguage: $request->original_language,
            audioLanguage: $request->audio_language,
            subtitleLanguage: $request->subtitle_language,
            translationType: $request->translation_type,
            translationStudio: $request->translation_studio,
            seasonNumber: $request->season_number,
            episodeNumber: $request->episode_number,
            currentQuality: $request->current_quality,
            requestedQuality: $request->requested_quality,
            correctionField: $request->correction_field,
            currentValue: $request->current_value,
            proposedValue: $request->proposed_value,
            explanation: $request->explanation,
            publicNote: $request->public_note,
            rejectionReason: $request->rejection_reason?->label(),
            history: $request->statusHistory->map(fn ($history): array => [
                'status' => $history->to_status->value,
                'label' => $history->to_status->label(),
                'reason' => $history->public_reason,
                'date' => $history->created_at?->translatedFormat('j F Y, H:i') ?? '',
            ])->all(),
            sourceLinks: $request->sourceLinks->map(fn ($link): array => [
                'url' => $link->url,
                'label' => $link->provider?->label() ?? parse_url($link->url, PHP_URL_HOST) ?? __('requests.fields.source_link'),
            ])->all(),
            externalIdentifiers: $request->externalIdentifiers->map(fn ($identifier): array => [
                'provider' => $identifier->provider->value,
                'label' => $identifier->provider->label(),
                'identifier' => $identifier->identifier,
            ])->all(),
            clarifications: $includeClarifications ? $request->clarifications->map(fn ($clarification): array => [
                'role' => $clarification->author_role,
                'role_label' => __('requests.clarifications.roles.'.$clarification->author_role),
                'body' => $clarification->body,
                'date' => $clarification->created_at?->translatedFormat('j F Y, H:i') ?? '',
            ])->all() : [],
            canEdit: $viewer !== null && Gate::forUser($viewer)->allows('update', $request),
            canWithdraw: $viewer !== null && Gate::forUser($viewer)->allows('withdraw', $request),
            canClarify: $viewer !== null && Gate::forUser($viewer)->allows('clarify', $request),
            canModerate: $viewer !== null && Gate::forUser($viewer)->allows('moderate', $request),
            completionUrl: $completionUrl,
            completionLabel: $completionLabel,
        );
    }

    /** @return array{string|null, string|null} */
    private function completion(ContentRequest $request): array
    {
        if ($request->completedEpisode instanceof Episode) {
            $season = $request->completedEpisode->season;
            $title = $season?->catalogTitle;

            if ($season instanceof Season && $title instanceof CatalogTitle) {
                return [
                    route('titles.show', [$title, 'season' => $season->number, 'episode' => $request->completedEpisode->number]),
                    $title->display_title.' · '.__('requests.fields.episode_number_value', ['number' => $request->completedEpisode->number]),
                ];
            }
        }

        if ($request->completedSeason instanceof Season && $request->completedSeason->catalogTitle instanceof CatalogTitle) {
            return [
                route('titles.show', [$request->completedSeason->catalogTitle, 'season' => $request->completedSeason->number]),
                $request->completedSeason->catalogTitle->display_title.' · '.__('requests.fields.season_number_value', ['number' => $request->completedSeason->number]),
            ];
        }

        $title = $request->completedCatalogTitle ?? $request->catalogTitle;

        return $title instanceof CatalogTitle
            ? [route('titles.show', $title), $title->display_title]
            : [null, null];
    }
}
