<?php

declare(strict_types=1);

namespace App\Services\Profiles;

use App\DTOs\Profiles\ResolvedUserProfile;
use App\Models\UserProfile;
use App\Models\UserProfileUsernameHistory;
use App\ValueObjects\ProfileUsername;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;

final class UserProfileResolver
{
    public function __construct(private readonly UserProfileSchema $schema) {}

    public function byUsername(string $username): ResolvedUserProfile
    {
        abort_unless($this->schema->available(), 404);
        $normalized = ProfileUsername::normalize($username);
        abort_unless(ProfileUsername::isValid($normalized), 404);

        $profile = $this->query()->where('normalized_username', $normalized)->first();

        if ($profile instanceof UserProfile) {
            return new ResolvedUserProfile($profile, false);
        }

        $history = UserProfileUsernameHistory::query()
            ->where('normalized_username', $normalized)
            ->first(['user_id']);

        if (! $history instanceof UserProfileUsernameHistory) {
            throw (new ModelNotFoundException)->setModel(UserProfile::class);
        }

        $profile = $this->query()->where('user_id', $history->user_id)->firstOrFail();

        return new ResolvedUserProfile($profile, true);
    }

    public function byUserPublicId(string $publicId): UserProfile
    {
        abort_unless($this->schema->available(), 404);

        return $this->query()
            ->whereHas('user', fn ($query) => $query->where('public_id', $publicId))
            ->firstOrFail();
    }

    /** @return Builder<UserProfile> */
    private function query(): Builder
    {
        return UserProfile::query()->with([
            'user' => fn ($query) => $query->select(['id', 'public_id', 'name', 'created_at']),
        ]);
    }
}
