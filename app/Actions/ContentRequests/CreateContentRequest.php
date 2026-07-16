<?php

declare(strict_types=1);

namespace App\Actions\ContentRequests;

use App\DTOs\ContentRequests\ContentRequestInput;
use App\Enums\ContentRequestDuplicateConfidence;
use App\Enums\ContentRequestPriority;
use App\Enums\ContentRequestStatus;
use App\Exceptions\ContentRequests\ContentRequestActionException;
use App\Models\ContentRequest;
use App\Models\ContentRequestFollower;
use App\Models\ContentRequestStatusHistory;
use App\Models\ContentRequestVote;
use App\Models\User;
use App\Services\ContentRequests\ContentExistenceService;
use App\Services\ContentRequests\ContentRequestCacheInvalidator;
use App\Services\ContentRequests\ContentRequestExternalIdentifierService;
use App\Services\ContentRequests\ContentRequestIdentity;
use App\Services\ContentRequests\ContentRequestNotificationService;
use App\Services\ContentRequests\ContentRequestRateLimiter;
use App\Services\ContentRequests\ContentRequestSourceLinkService;
use App\Services\ContentRequests\ContentRequestTypeRules;
use App\Services\ContentRequests\DuplicateContentRequestService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

final readonly class CreateContentRequest
{
    public function __construct(
        private ContentRequestTypeRules $rules,
        private ContentRequestExternalIdentifierService $externalIds,
        private ContentRequestSourceLinkService $links,
        private ContentExistenceService $existence,
        private DuplicateContentRequestService $duplicates,
        private ContentRequestIdentity $identity,
        private ContentRequestRateLimiter $rateLimiter,
        private ContentRequestCacheInvalidator $cache,
        private ContentRequestNotificationService $notifications,
    ) {}

    public function handle(User $user, ContentRequestInput $input): ContentRequest
    {
        Gate::forUser($user)->authorize('create', ContentRequest::class);

        if (! Str::isUuid($input->submissionToken)) {
            throw new ContentRequestActionException('requests.errors.invalid_submission');
        }

        $this->rules->assert($input);
        $externalIds = $this->externalIds->normalize($input->externalIdentifiers);
        $links = $this->links->normalize($input->sourceLinks);
        $submissionKey = hash('sha256', $user->id.':'.Str::lower($input->submissionToken));
        $existingSubmission = ContentRequest::query()->where('submission_key', $submissionKey)->first();

        if ($existingSubmission !== null) {
            return $existingSubmission;
        }

        $existence = $this->existence->check($input);

        if ($existence->exact) {
            throw new ContentRequestActionException(
                'requests.errors.content_exists',
                canonicalUrl: $existence->matches[0]['url'] ?? route('titles.index'),
            );
        }

        $duplicate = $this->duplicates->check($input, $externalIds);

        if ($duplicate->confidence === ContentRequestDuplicateConfidence::Exact) {
            $canonical = $duplicate->candidates[0] ?? null;
            throw new ContentRequestActionException(
                'requests.errors.exact_duplicate',
                canonicalPublicId: $canonical['public_id'] ?? null,
                canonicalUrl: $canonical['url'] ?? null,
            );
        }

        if ($duplicate->confidence === ContentRequestDuplicateConfidence::Probable
            && mb_strlen((string) $input->differentExplanation) < 20) {
            throw new ContentRequestActionException(
                'requests.errors.probable_duplicate',
                canonicalPublicId: $duplicate->candidates[0]['public_id'] ?? null,
                canonicalUrl: $duplicate->candidates[0]['url'] ?? null,
            );
        }

        $exactHash = $duplicate->exactIdentityHash ?? $this->identity->exactHash($input, $externalIds);
        $this->rateLimiter->hit('create', $user, $exactHash);
        $isReviewOnly = $links !== [] && $user->created_at?->isAfter(now()->subMinutes(30));

        $created = false;
        $request = DB::transaction(function () use (
            $user,
            $input,
            $externalIds,
            $links,
            $submissionKey,
            $exactHash,
            $duplicate,
            $isReviewOnly,
            &$created,
        ): ContentRequest {
            User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
            $existing = ContentRequest::query()
                ->where('submission_key', $submissionKey)
                ->orWhere('active_identity_key', $exactHash)
                ->first();

            if ($existing !== null) {
                return $existing;
            }

            $normalizedTitle = $this->identity->normalizedTitle($input);
            $request = ContentRequest::query()->create([
                'public_id' => (string) Str::uuid(),
                'requester_id' => $user->id,
                'type' => $input->type,
                'status' => $isReviewOnly ? ContentRequestStatus::PendingReview : ContentRequestStatus::Submitted,
                'priority' => ContentRequestPriority::Normal,
                'title' => $input->title,
                'normalized_title' => $normalizedTitle,
                'normalized_title_hash' => hash('sha256', $normalizedTitle),
                'original_title' => $input->originalTitle,
                'alternative_title' => $input->alternativeTitle,
                'release_year' => $input->releaseYear,
                'country' => $input->country,
                'content_locale' => $input->contentLocale,
                'original_language' => $input->originalLanguage,
                'audio_language' => $input->audioLanguage,
                'subtitle_language' => $input->subtitleLanguage,
                'translation_type' => $input->translationType,
                'translation_studio' => $input->translationStudio,
                'catalog_title_id' => $input->catalogTitleId,
                'season_id' => $input->seasonId,
                'episode_id' => $input->episodeId,
                'season_number' => $input->seasonNumber,
                'season_kind' => $input->seasonKind,
                'episode_number' => $input->episodeNumber,
                'episode_release_date' => $input->episodeReleaseDate,
                'current_quality' => $input->currentQuality,
                'requested_quality' => $input->requestedQuality,
                'correction_field' => $input->correctionField,
                'current_value' => $input->currentValue,
                'proposed_value' => $input->proposedValue,
                'explanation' => $input->explanation,
                'different_explanation' => $input->differentExplanation,
                'exact_identity_hash' => $exactHash,
                'active_identity_key' => $exactHash,
                'submission_key' => $submissionKey,
                'probable_duplicate' => $duplicate->confidence === ContentRequestDuplicateConfidence::Probable,
                'is_public' => ! $isReviewOnly,
            ]);
            $created = true;

            ContentRequestStatusHistory::query()->create([
                'content_request_id' => $request->id,
                'actor_id' => $user->id,
                'to_status' => $request->status,
                'public_reason' => null,
                'idempotency_key' => hash('sha256', 'create:'.$request->id),
            ]);
            ContentRequestVote::query()->firstOrCreate(['content_request_id' => $request->id, 'user_id' => $user->id]);
            ContentRequestFollower::query()->firstOrCreate(['content_request_id' => $request->id, 'user_id' => $user->id]);

            foreach ($links as $link) {
                $request->sourceLinks()->create([...$link, 'added_by_id' => $user->id, 'is_public' => false]);
            }

            foreach ($externalIds as $externalId) {
                $request->externalIdentifiers()->create($externalId);
            }

            return $request;
        }, attempts: 3);

        if (! $created && ! $request->is_public && $request->requester_id !== $user->id) {
            throw new ContentRequestActionException('requests.errors.exact_duplicate');
        }

        if (! $created) {
            ContentRequestVote::query()->firstOrCreate(['content_request_id' => $request->id, 'user_id' => $user->id]);
            ContentRequestFollower::query()->firstOrCreate(['content_request_id' => $request->id, 'user_id' => $user->id]);
        }

        $this->cache->changed($request->public_id, sitemap: true);

        if ($created) {
            $this->notifications->submitted($request);
        }

        return $request;
    }
}
