<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\ContentRequest;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

final class ContentRequestPolicy
{
    public function view(?User $user, ContentRequest $request): bool
    {
        return $request->is_public
            || ($user !== null && ($request->requester_id === $user->id || Gate::forUser($user)->allows('manage-content-requests')));
    }

    public function create(User $user): bool
    {
        return (bool) config('content-requests.enabled', true) && $user->hasVerifiedEmail();
    }

    public function update(User $user, ContentRequest $request): bool
    {
        return $request->requester_id === $user->id && $request->status->canRequesterEdit();
    }

    public function withdraw(User $user, ContentRequest $request): bool
    {
        return $request->requester_id === $user->id && $request->status->isOpen();
    }

    public function vote(User $user, ContentRequest $request): bool
    {
        return $user->hasVerifiedEmail() && $request->is_public && $request->status->canEngage();
    }

    public function follow(User $user, ContentRequest $request): bool
    {
        return $user->hasVerifiedEmail() && $request->is_public && $request->status->canEngage();
    }

    public function clarify(User $user, ContentRequest $request): bool
    {
        return $request->requester_id === $user->id && $request->status->value === 'clarification_needed';
    }

    public function moderate(User $user, ContentRequest $request): bool
    {
        return Gate::forUser($user)->allows('manage-content-requests');
    }
}
