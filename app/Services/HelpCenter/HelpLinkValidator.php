<?php

declare(strict_types=1);

namespace App\Services\HelpCenter;

use App\Models\HelpArticle;
use Illuminate\Support\Facades\Route;

final class HelpLinkValidator
{
    /** @return list<string> */
    public function validate(string $markdown): array
    {
        preg_match_all('/\[[^\]]*\]\(([^\s)]+)(?:\s+"[^"]*")?\)/u', $markdown, $matches);
        $errors = [];

        foreach (array_unique($matches[1] ?? []) as $target) {
            $error = $this->errorFor((string) $target);

            if ($error !== null) {
                $errors[] = $error;
            }
        }

        return array_values(array_unique($errors));
    }

    private function errorFor(string $target): ?string
    {
        if (str_starts_with($target, '#')) {
            return null;
        }

        if (str_starts_with($target, 'route:')) {
            $route = substr($target, 6);

            return in_array($route, (array) config('help-center.allowed_route_names', []), true) && Route::has($route)
                ? null
                : __('help.admin.links.unknown_route', ['target' => $target]);
        }

        if (str_starts_with($target, 'help:')) {
            $code = substr($target, 5);

            return preg_match('/^[a-z0-9][a-z0-9-]{1,95}$/D', $code) === 1
                && HelpArticle::query()->where('code', $code)->exists()
                    ? null
                    : __('help.admin.links.unknown_article', ['target' => $target]);
        }

        if (preg_match('#^https?://#i', $target) === 1) {
            $parts = parse_url($target);

            return is_array($parts)
                && isset($parts['host'])
                && ! isset($parts['user'], $parts['pass'])
                && mb_strlen($target) <= 1_000
                && ! str_contains($target, '\\')
                && preg_match('/[\x00-\x1F\x7F]/', $target) !== 1
                    ? null
                    : __('help.admin.links.unsafe_external', ['target' => $target]);
        }

        return __('help.admin.links.use_route_token', ['target' => $target]);
    }
}
