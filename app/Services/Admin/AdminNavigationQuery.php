<?php

declare(strict_types=1);

namespace App\Services\Admin;

use App\DTOs\Administration\AdminNavigationItemData;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Routing\Router;
use Illuminate\Routing\UrlGenerator;

final readonly class AdminNavigationQuery
{
    public function __construct(
        private AdminNavigationRegistry $registry,
        private AdminAccessResolver $access,
        private Request $request,
        private Router $router,
        private UrlGenerator $urls,
    ) {}

    /** @return array<string, list<AdminNavigationItemData>> */
    public function for(User $user): array
    {
        $permissions = $this->access->permissionsFor($user);
        $groups = [];

        foreach ($this->registry->definitions() as $definition) {
            if (! $this->router->has($definition['route'])
                || ! isset($permissions[$definition['permission']->value])) {
                continue;
            }

            $groups[$definition['group']][] = new AdminNavigationItemData(
                code: $definition['code'],
                group: $definition['group'],
                routeName: $definition['route'],
                url: $this->urls->route($definition['route']),
                label: __("administration.navigation.{$definition['label']}"),
                icon: $definition['icon'],
                active: $this->request->routeIs($definition['route'], $definition['route'].'.*'),
            );
        }

        return $groups;
    }
}
