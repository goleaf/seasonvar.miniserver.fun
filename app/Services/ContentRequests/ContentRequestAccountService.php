<?php

declare(strict_types=1);

namespace App\Services\ContentRequests;

use App\Models\ContentRequest;
use App\Models\ContentRequestClarification;
use App\Models\ContentRequestFollower;
use App\Models\ContentRequestNotificationPreference;
use App\Models\ContentRequestStatusHistory;
use App\Models\ContentRequestVote;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final readonly class ContentRequestAccountService
{
    public function __construct(private ContentRequestSchema $schema, private ContentRequestCacheInvalidator $cache) {}

    /** @return array<string, mixed> */
    public function export(User $user): array
    {
        if (! $this->schema->ready()) {
            return [
                'created' => [],
                'voted' => [],
                'followed' => [],
                'clarifications' => [],
                'notification_preferences' => null,
            ];
        }

        return [
            'created' => ContentRequest::query()->where('requester_id', $user->id)->orderBy('created_at')->get()
                ->map(fn (ContentRequest $request): array => [
                    'public_id' => $request->public_id,
                    'type' => $request->type->value,
                    'status' => $request->status->value,
                    'title' => $request->title,
                    'explanation' => $request->explanation,
                    'created_at' => $request->created_at?->toAtomString(),
                ])->all(),
            'voted' => ContentRequestVote::query()->where('user_id', $user->id)->with('contentRequest:id,public_id')->orderBy('created_at')->get()
                ->map(fn (ContentRequestVote $vote): array => ['request_public_id' => $vote->contentRequest?->public_id, 'created_at' => $vote->created_at?->toAtomString()])->all(),
            'followed' => ContentRequestFollower::query()->where('user_id', $user->id)->with('contentRequest:id,public_id')->orderBy('created_at')->get()
                ->map(fn (ContentRequestFollower $follow): array => ['request_public_id' => $follow->contentRequest?->public_id, 'created_at' => $follow->created_at?->toAtomString()])->all(),
            'clarifications' => ContentRequestClarification::query()->where('author_id', $user->id)->with('contentRequest:id,public_id')->orderBy('created_at')->get()
                ->map(fn (ContentRequestClarification $message): array => ['request_public_id' => $message->contentRequest?->public_id, 'role' => $message->author_role, 'body' => $message->body, 'created_at' => $message->created_at?->toAtomString()])->all(),
            'notification_preferences' => ContentRequestNotificationPreference::query()->find($user->id)?->only([
                'requester_updates',
                'voted_updates',
                'followed_updates',
            ]),
        ];
    }

    public function prepareForDeletion(User $user): void
    {
        if (! $this->schema->ready()) {
            return;
        }

        $publicIds = ContentRequest::query()->where('requester_id', $user->id)->pluck('public_id')->all();
        ContentRequest::query()->where('requester_id', $user->id)->update(['requester_id' => null]);
        ContentRequestStatusHistory::query()->where('actor_id', $user->id)->update(['actor_id' => null]);
        ContentRequestClarification::query()->where('author_id', $user->id)->update(['author_id' => null]);
        ContentRequestVote::query()->where('user_id', $user->id)->delete();
        ContentRequestFollower::query()->where('user_id', $user->id)->delete();
        ContentRequestNotificationPreference::query()->whereKey($user->id)->delete();
        $user->notifications()->where('type', 'content-request.activity')->delete();

        foreach ($publicIds as $publicId) {
            $this->cache->changed((string) $publicId);
        }
    }

    public function mergeUsers(User $source, User $canonical): void
    {
        if (! $this->schema->ready() || $source->is($canonical)) {
            return;
        }

        $publicIds = ContentRequest::query()
            ->where('requester_id', $source->id)
            ->orWhereHas('votes', fn ($votes) => $votes->where('user_id', $source->id))
            ->orWhereHas('followers', fn ($followers) => $followers->where('user_id', $source->id))
            ->pluck('public_id')
            ->all();

        DB::transaction(function () use ($source, $canonical): void {
            ContentRequest::query()->where('requester_id', $source->id)->update(['requester_id' => $canonical->id]);

            foreach (ContentRequestVote::query()->where('user_id', $source->id)->get() as $vote) {
                ContentRequestVote::query()->firstOrCreate(['content_request_id' => $vote->content_request_id, 'user_id' => $canonical->id]);
            }

            foreach (ContentRequestFollower::query()->where('user_id', $source->id)->get() as $follow) {
                ContentRequestFollower::query()->firstOrCreate(['content_request_id' => $follow->content_request_id, 'user_id' => $canonical->id]);
            }

            ContentRequestVote::query()->where('user_id', $source->id)->delete();
            ContentRequestFollower::query()->where('user_id', $source->id)->delete();
            ContentRequestStatusHistory::query()->where('actor_id', $source->id)->update(['actor_id' => $canonical->id]);
            ContentRequestClarification::query()->where('author_id', $source->id)->update(['author_id' => $canonical->id]);

            $sourcePreference = ContentRequestNotificationPreference::query()->find($source->id);

            if ($sourcePreference !== null && ContentRequestNotificationPreference::query()->find($canonical->id) === null) {
                ContentRequestNotificationPreference::query()->whereKey($source->id)->update(['user_id' => $canonical->id]);
            } else {
                ContentRequestNotificationPreference::query()->whereKey($source->id)->delete();
            }
        }, attempts: 3);

        foreach ($publicIds as $publicId) {
            $this->cache->changed((string) $publicId);
        }
    }
}
