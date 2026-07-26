<?php

declare(strict_types=1);

namespace App\Services\Pwa;

use App\Models\User;
use App\Services\Catalog\CatalogStatsPosterResponder;
use App\Services\Catalog\CatalogTitleQuery;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class PwaPosterResponder
{
    public function __construct(
        private readonly CatalogTitleQuery $titles,
        private readonly CatalogStatsPosterResponder $posters,
    ) {}

    public function response(Request $request, string $titleSlug): Response
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        $title = $this->titles
            ->visibleTo($user)
            ->where('catalog_titles.slug', $titleSlug)
            ->firstOrFail();

        return $this->posters->response($title);
    }
}
