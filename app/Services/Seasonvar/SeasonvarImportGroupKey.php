<?php

namespace App\Services\Seasonvar;

class SeasonvarImportGroupKey
{
    public function forUrl(string $url, string $urlHash): string
    {
        $path = (string) parse_url($url, PHP_URL_PATH);

        foreach ([
            '~^/serial-\d+-(.+?)_ps[a-z0-9]+(?:-0*\d{1,4}-+(?:season|sezon))?\.html$~iu',
            '~^/serial-\d+-(.+?)[-_]0*\d{1,4}-+(?:season|sezon)\.html$~iu',
        ] as $pattern) {
            if (preg_match($pattern, $path, $matches) !== 1) {
                continue;
            }

            $slug = mb_strtolower(trim($matches[1], '-_'));

            if ($slug !== '') {
                return 'seasonvar-title:'.hash('sha256', $slug);
            }
        }

        return 'seasonvar-page:'.$urlHash;
    }
}
