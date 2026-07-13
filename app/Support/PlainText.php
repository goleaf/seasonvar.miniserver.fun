<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Str;

final class PlainText
{
    public static function clean(mixed $value, ?int $limit = null): string
    {
        $text = preg_replace('/<(script|style)\b[^>]*>.*?<\/\1>/isu', ' ', (string) $value) ?? '';
        $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/[\p{C}\s]+/u', ' ', $text) ?? '';
        $text = trim($text);

        if ($limit !== null && $limit > 0) {
            $text = Str::limit($text, $limit, '');
        }

        return $text;
    }
}
