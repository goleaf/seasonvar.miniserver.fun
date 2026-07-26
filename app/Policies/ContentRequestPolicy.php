<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\ContentRequestType;
use App\Models\ContentRequest;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

final class ContentRequestPolicy
{
    public function view(?User $user, ContentRequest $request): bool
    {
        if ($request->type->isAdministrativeOnly()) {
            return $user !== null && Gate::forUser($user)->allows('manage-content-requests');
        }

        return $request->is_public
            || ($user !== null && ($request->requester_id === $user->id || Gate::forUser($user)->allows('manage-content-requests')));
    }

    public function create(User $user, ?ContentRequestType $type = null): bool
    {
        if (! (bool) config('content-requests.enabled', true) || ! $user->hasVerifiedEmail()) {
            return false;
        }

        return $type?->isAdministrativeOnly() !== true
            || Gate::forUser($user)->allows('manage-content-requests');
    }

    public function update(User $user, ContentRequest $request): bool
    {
        return ! $request->type->isAdministrativeOnly()
            && $request->requester_id === $user->id
            && $request->status->canRequesterEdit();
    }

    public function withdraw(User $user, ContentRequest $request): bool
    {
        return ! $request->type->isAdministrativeOnly()
            && $request->requester_id === $user->id
            && $request->status->isOpen();
    }

    public function vote(User $user, ContentRequest $request): bool
    {
        return ! $request->type->isAdministrativeOnly()
            && $user->hasVerifiedEmail()
            && $request->is_public
            && $request->status->canEngage();
    }

    public function follow(User $user, ContentRequest $request): bool
    {
        return ! $request->type->isAdministrativeOnly()
            && $user->hasVerifiedEmail()
            && $request->is_public
            && $request->status->canEngage();
    }

    public function clarify(User $user, ContentRequest $request): bool
    {
        return ! $request->type->isAdministrativeOnly()
            && $request->requester_id === $user->id
            && $request->status->value === 'clarification_needed';
    }

    public function moderate(User $user, ContentRequest $request): bool
    {
        return Gate::forUser($user)->allows('manage-content-requests');
    }
}
