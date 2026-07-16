<?php

declare(strict_types=1);

namespace App\Services\Profiles;

use App\DTOs\Profiles\PublicUserProfileData;
use App\Enums\CatalogWatchStatus;
use App\Enums\CommentStatus;
use App\Models\CatalogCollection;
use App\Models\CatalogTitleUserState;
use App\Models\Comment;
use App\Models\User;
use App\Models\UserBlock;
use App\Models\UserMute;
use App\Models\UserProfile;
use App\Services\Catalog\CatalogTitleQuery;
use App\Services\Reviews\CatalogTitleReviewQuery;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

final class PublicUserProfilePresenter
{
    public function __construct(
        private readonly UserProfileMediaService $media,
        private readonly CatalogTitleQuery $titles,
        private readonly CatalogTitleReviewQuery $reviews,
    ) {}

    public function present(UserProfile $profile, ?User $viewer): PublicUserProfileData
    {
        $profile->loadMissing('user:id,public_id,name,created_at');
        $owner = $profile->user;
        $isOwner = $viewer !== null && (int) $viewer->id === (int) $owner->id;
        $sections = collect([
            'biography', 'member_since', 'collections', 'reviews', 'comments',
            'watching', 'completed', 'activity',
        ])->mapWithKeys(fn (string $section): array => [$section => $profile->sectionIsPublic($section)])->all();
        $blocked = $viewer !== null && ! $isOwner && UserBlock::query()
            ->where('blocker_id', $viewer->id)
            ->where('blocked_id', $owner->id)
            ->exists();
        $muted = $viewer !== null && ! $isOwner && UserMute::query()
            ->where('muter_id', $viewer->id)
            ->where('muted_id', $owner->id)
            ->exists();

        return new PublicUserProfileData(
            displayName: (string) $owner->name,
            username: (string) $profile->username,
            initial: Str::upper(Str::substr((string) $owner->name, 0, 1)),
            biography: $sections['biography'] ? $profile->biography : null,
            memberSince: $sections['member_since'] && $owner->created_at !== null
                ? $owner->created_at->translatedFormat('F Y')
                : null,
            avatarUrl: $this->media->url($profile, 'avatar'),
            coverUrl: $this->media->url($profile, 'cover'),
            counts: $this->counts($profile, $viewer, $sections),
            sections: $sections,
            isOwner: $isOwner,
            canBlock: $viewer !== null && ! $isOwner,
            isBlocked: $blocked,
            canMute: $viewer !== null && ! $isOwner && ! $blocked,
            isMuted: $muted,
            canReport: $viewer !== null && Gate::forUser($viewer)->allows('report', $profile),
            canModerate: $viewer !== null && Gate::forUser($viewer)->allows('moderate', $profile),
            canonicalUrl: route('users.show', ['username' => $profile->username]),
            contentVersion: (int) $profile->content_version,
        );
    }

    /**
     * @param  array<string, bool>  $sections
     * @return array<string, int>
     */
    private function counts(UserProfile $profile, ?User $viewer, array $sections): array
    {
        $visibleTitleIds = $this->titles->visibleTo($viewer)->select('id');

        return [
            'reviews' => $sections['reviews']
                ? $this->reviews->publicCountForAuthor((int) $profile->user_id, $viewer)
                : 0,
            'comments' => $sections['comments']
                ? Comment::query()
                    ->where('user_id', $profile->user_id)
                    ->where('status', CommentStatus::Published->value)
                    ->whereNotNull('catalog_title_id')
                    ->whereIn('catalog_title_id', clone $visibleTitleIds)
                    ->count()
                : 0,
            'collections' => $sections['collections']
                ? CatalogCollection::query()->where('owner_id', $profile->user_id)->publiclyListed()->count()
                : 0,
            'watching' => $sections['watching']
                ? CatalogTitleUserState::query()
                    ->where('user_id', $profile->user_id)
                    ->where('watch_status', CatalogWatchStatus::Watching->value)
                    ->whereIn('catalog_title_id', clone $visibleTitleIds)
                    ->count()
                : 0,
            'completed' => $sections['completed']
                ? CatalogTitleUserState::query()
                    ->where('user_id', $profile->user_id)
                    ->where('watch_status', CatalogWatchStatus::Completed->value)
                    ->whereIn('catalog_title_id', clone $visibleTitleIds)
                    ->count()
                : 0,
        ];
    }
}
