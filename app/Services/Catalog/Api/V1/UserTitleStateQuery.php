<?php

declare(strict_types=1);

namespace App\Services\Catalog\Api\V1;

use App\Models\CatalogTitle;
use App\Models\User;
use App\Services\Catalog\CatalogPrimaryActionResolver;
use App\Services\Catalog\CatalogUserStateService;

final readonly class UserTitleStateQuery
{
    public function __construct(
        private CatalogUserStateService $states,
        private CatalogPrimaryActionResolver $actions,
    ) {}

    /** @return array<string, mixed> */
    public function get(User $user, CatalogTitle $title): array
    {
        return [
            'state' => $this->states->state($user, $title),
            'summary' => $this->states->summary($title),
            'rating_range' => $this->states->ratingRange(),
            'primary_action' => $this->actions->resolve($title, $user),
        ];
    }
}
