<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\AdminPermission;
use App\Enums\CatalogCollectionType;
use App\Enums\CatalogCollectionVisibility;
use App\Models\CatalogCollection;
use App\Models\User;
use App\Services\Collections\CatalogCollectionQuery;
use Illuminate\Auth\Access\Response;
use Illuminate\Support\Facades\Gate;

final class CatalogCollectionPolicy
{
    public function __construct(private readonly CatalogCollectionQuery $collections) {}

    public function view(?User $user, CatalogCollection $collection): Response
    {
        if ($this->isPubliclyViewable($collection)) {
            return Response::allow();
        }

        return $this->canManage($user, $collection)
            ? Response::allow()
            : Response::denyAsNotFound();
    }

    public function create(User $user): bool
    {
        return $user->hasVerifiedEmail();
    }

    public function createEditorial(User $user): bool
    {
        return Gate::forUser($user)->allows('manage-catalog');
    }

    public function update(User $user, CatalogCollection $collection): Response
    {
        if ($collection->type === CatalogCollectionType::System) {
            return Gate::forUser($user)->allows('manage-catalog')
                ? Response::allow()
                : Response::denyAsNotFound();
        }

        return $this->canManage($user, $collection)
            ? Response::allow()
            : Response::denyAsNotFound();
    }

    public function delete(User $user, CatalogCollection $collection): Response
    {
        return $this->update($user, $collection);
    }

    public function restore(User $user, CatalogCollection $collection): Response
    {
        return $collection->trashed()
            ? $this->update($user, $collection)
            : Response::denyAsNotFound();
    }

    public function forceDelete(User $user, CatalogCollection $collection): Response
    {
        return $collection->trashed()
            ? $this->update($user, $collection)
            : Response::denyAsNotFound();
    }

    public function manageItems(User $user, CatalogCollection $collection): Response
    {
        return $this->update($user, $collection);
    }

    public function moderate(User $user): bool
    {
        return Gate::forUser($user)->allows(AdminPermission::CollectionsModerate->value);
    }

    public function feature(User $user, CatalogCollection $collection): bool
    {
        return $this->moderate($user)
            && $collection->type === CatalogCollectionType::Editorial
            && $collection->visibility === CatalogCollectionVisibility::Public;
    }

    public function report(User $user, CatalogCollection $collection): bool
    {
        return $user->hasVerifiedEmail()
            && ! $collection->isOwnedBy($user)
            && $this->isPubliclyViewable($collection);
    }

    private function canManage(?User $user, CatalogCollection $collection): bool
    {
        return $user !== null && (
            $collection->isOwnedBy($user)
            || Gate::forUser($user)->allows('manage-catalog')
        );
    }

    private function isPubliclyViewable(CatalogCollection $collection): bool
    {
        if (! $collection->isPubliclyViewable()) {
            return false;
        }

        return $collection->visibility !== CatalogCollectionVisibility::Public
            || $this->collections->isPubliclyListed($collection);
    }
}
