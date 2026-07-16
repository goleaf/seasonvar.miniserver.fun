<?php

declare(strict_types=1);

namespace App\Actions\ContentRequests;

use App\Exceptions\ContentRequests\ContentRequestActionException;
use App\Models\ContentRequest;
use App\Models\User;
use App\Services\ContentRequests\ContentRequestCacheInvalidator;
use App\Services\ContentRequests\ContentRequestIdentity;
use App\Services\ContentRequests\ContentRequestRateLimiter;
use App\Services\ContentRequests\ContentRequestSourceLinkService;
use App\Support\PlainText;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

final readonly class UpdateContentRequest
{
    public function __construct(
        private ContentRequestSourceLinkService $links,
        private ContentRequestIdentity $identity,
        private ContentRequestRateLimiter $rateLimiter,
        private ContentRequestCacheInvalidator $cache,
    ) {}

    /** @param array{alternative_title?: mixed, explanation?: mixed, audio_language?: mixed, subtitle_language?: mixed, source_links?: list<string>} $data */
    public function handle(User $user, int $requestId, int $expectedVersion, array $data): ContentRequest
    {
        $request = ContentRequest::query()->findOrFail($requestId);
        Gate::forUser($user)->authorize('update', $request);
        $this->rateLimiter->hit('edit', $user, (string) $requestId);
        $sourceLinks = $this->links->normalize($data['source_links'] ?? []);

        $updated = DB::transaction(function () use ($user, $requestId, $expectedVersion, $data, $sourceLinks): ContentRequest {
            $request = ContentRequest::query()->lockForUpdate()->findOrFail($requestId);
            Gate::forUser($user)->authorize('update', $request);

            if ($request->version !== $expectedVersion) {
                throw new ContentRequestActionException('requests.errors.stale_request');
            }

            $request->alternative_title = $this->optional($data['alternative_title'] ?? null, 240);
            $request->explanation = $this->optional($data['explanation'] ?? null, 4_000);
            $request->audio_language = $this->language($data['audio_language'] ?? null);
            $request->subtitle_language = $this->language($data['subtitle_language'] ?? null);
            $request->loadMissing('externalIdentifiers');
            $identity = $this->identity->forRequest($request);
            $duplicate = ContentRequest::query()
                ->where('active_identity_key', $identity)
                ->whereKeyNot($request->id)
                ->first(['id', 'public_id']);

            if ($duplicate !== null) {
                throw new ContentRequestActionException(
                    'requests.errors.exact_duplicate',
                    canonicalPublicId: $duplicate->public_id,
                    canonicalUrl: route('requests.show', $duplicate->public_id),
                );
            }

            $request->exact_identity_hash = $identity;
            $request->active_identity_key = $identity;
            $request->version++;
            $request->save();

            foreach ($sourceLinks as $link) {
                $request->sourceLinks()->firstOrCreate(
                    ['url_hash' => $link['url_hash']],
                    [...$link, 'added_by_id' => $user->id, 'is_public' => false],
                );
            }

            return $request;
        }, attempts: 3);

        $this->cache->changed($updated->public_id);

        return $updated;
    }

    private function optional(mixed $value, int $limit): ?string
    {
        $clean = PlainText::clean($value, $limit);

        return $clean !== '' ? $clean : null;
    }

    private function language(mixed $value): ?string
    {
        $value = strtolower(trim((string) $value));

        if ($value !== '' && ! in_array($value, (array) config('content-requests.language_codes', []), true)) {
            throw new ContentRequestActionException('requests.errors.invalid_language');
        }

        return $value !== '' ? $value : null;
    }
}
