<?php

declare(strict_types=1);

namespace App\Services\Media;

use App\Models\CatalogTitle;
use App\Models\LicensedMedia;
use Illuminate\Support\Str;

final class LicensedMediaDownloadFilename
{
    private const MAX_LENGTH = 180;

    /** @var list<string> */
    private const RESERVED_NAMES = [
        'con', 'prn', 'aux', 'nul',
        'com1', 'com2', 'com3', 'com4', 'com5', 'com6', 'com7', 'com8', 'com9',
        'lpt1', 'lpt2', 'lpt3', 'lpt4', 'lpt5', 'lpt6', 'lpt7', 'lpt8', 'lpt9',
    ];

    public function generate(CatalogTitle $title, LicensedMedia $media, string $extension): string
    {
        $extension = Str::lower(preg_replace('/[^a-z0-9]/i', '', $extension) ?? '');
        $extension = $extension !== '' ? $extension : 'bin';
        $slug = Str::slug($title->display_title ?: $title->title);
        $slug = $this->safeBase($slug);
        $season = $media->season?->number;
        $episode = $media->episode?->number;

        $base = match (true) {
            $slug !== '' && $season !== null && $episode !== null => $slug.'-sezon-'.$this->number($season).'-serija-'.$this->number($episode),
            $slug !== '' && $season !== null => $slug.'-sezon-'.$this->number($season),
            $slug !== '' && $episode !== null => $slug.'-serija-'.$this->number($episode),
            $slug !== '' => $slug.'-video',
            default => 'video-'.max(1, (int) $media->id),
        };
        $base = $this->safeBase($base);
        $maximumBaseLength = max(1, self::MAX_LENGTH - strlen($extension) - 1);
        $base = rtrim(mb_strcut($base, 0, $maximumBaseLength, 'UTF-8'), '.- ');

        if ($base === '' || in_array(Str::lower($base), self::RESERVED_NAMES, true)) {
            $base = 'video-'.max(1, (int) $media->id);
        }

        return $base.'.'.$extension;
    }

    private function safeBase(string $value): string
    {
        $value = preg_replace('/[\x00-\x1F\x7F\\\/]+/u', '-', $value) ?? '';
        $value = preg_replace('/[^a-z0-9.-]+/i', '-', $value) ?? '';
        $value = preg_replace('/[-.]{2,}/', '-', $value) ?? '';

        return trim($value, '.- ');
    }

    private function number(int $number): string
    {
        return str_pad((string) max(0, $number), 2, '0', STR_PAD_LEFT);
    }
}
