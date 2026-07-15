<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Services\Tags\TagResolver;
use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Symfony\Component\HttpFoundation\Response;

final readonly class ResolveCanonicalTagRoute
{
    public function __construct(private TagResolver $tags) {}

    public function handle(Request $request, Closure $next): Response
    {
        if ($request->route('type') !== 'tag') {
            return $next($request);
        }

        $value = $request->route('taxonomy');
        abort_unless(is_string($value), 404);

        $resolved = $this->tags->resolvePublic($value);
        abort_if($resolved === null, 404);

        if (! $resolved->isCanonical() || ! $request->routeIs('titles.taxonomy')) {
            return $this->canonicalRedirect($request, (string) $resolved->tag->slug);
        }

        $request->attributes->set('resolved_public_tag', $resolved->tag);

        return $next($request);
    }

    private function canonicalRedirect(Request $request, string $slug): RedirectResponse
    {
        $query = Arr::except($request->query(), ['_method', '_token']);
        $url = route('titles.taxonomy', ['type' => 'tag', 'taxonomy' => $slug]);

        if ($query !== []) {
            $url .= '?'.http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        }

        return redirect()->to($url, 301);
    }
}
