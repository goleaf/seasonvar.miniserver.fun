<?php

namespace App\Services\Seasonvar;

class SeasonvarImportGroupKey
{
    public function forUrl(string $url, string $urlHash): string
    {
        $path = (string) parse_url($url, PHP_URL_PATH);

        if (preg_match('~^/serial-(\d+)-~u', $path, $matches) === 1) {
            return 'seasonvar-title:'.$matches[1];
        }

        return 'seasonvar-page:'.$urlHash;
    }
}
