<?php

declare(strict_types=1);

namespace App\Support\Cache;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;

final class PublicPageHtmlTransformer
{
    private const CSRF_MARKER = '__SEASONVAR_PUBLIC_PAGE_CSRF__';

    private const PLAYBACK_MARKER_PATTERN = '/__SEASONVAR_PUBLIC_PAGE_PLAYBACK_(\d+)__/';

    public function sanitize(string $html): ?string
    {
        if (str_contains($html, self::CSRF_MARKER)
            || preg_match(self::PLAYBACK_MARKER_PATTERN, $html) === 1) {
            return null;
        }

        $csrf = csrf_token();

        if (str_contains($html, 'data-csrf=') && ($csrf === '' || ! str_contains($html, e($csrf)))) {
            return null;
        }

        if ($csrf !== '') {
            $html = str_replace([$csrf, e($csrf)], self::CSRF_MARKER, $html);
        }

        $playbackPrefix = preg_quote(rtrim(url('/playback'), '/').'/', '/');

        return preg_replace_callback(
            '/'.$playbackPrefix.'(?<media>\d+)\?(?<query>[^"\'\s<>]+)/',
            function (array $match): string {
                $candidate = html_entity_decode($match[0], ENT_QUOTES | ENT_HTML5, 'UTF-8');
                $request = Request::create($candidate);

                if (! $request->hasValidSignature()
                    || (int) $request->query('viewer', -1) !== 0
                    || ! ctype_digit((string) ($match['media'] ?? ''))) {
                    return $match[0];
                }

                return '__SEASONVAR_PUBLIC_PAGE_PLAYBACK_'.(int) $match['media'].'__';
            },
            $html,
        );
    }

    public function restore(string $html): string
    {
        $html = str_replace(self::CSRF_MARKER, e(csrf_token()), $html);
        $ttl = max(30, min(600, (int) config('playback.signed_url_ttl_seconds', 300)));
        $urls = [];

        return (string) preg_replace_callback(
            self::PLAYBACK_MARKER_PATTERN,
            function (array $match) use (&$urls, $ttl): string {
                $mediaId = (int) $match[1];
                $urls[$mediaId] ??= URL::temporarySignedRoute(
                    'playback.source',
                    now()->addSeconds($ttl),
                    ['licensedMedia' => $mediaId, 'viewer' => 0],
                );

                return e($urls[$mediaId]);
            },
            $html,
        );
    }
}
