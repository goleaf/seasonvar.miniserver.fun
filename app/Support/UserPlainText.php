<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Str;
use Normalizer;
use Stringable;

final class UserPlainText
{
    public static function name(mixed $value): string
    {
        return Str::squish(self::clean($value, preserveParagraphs: false));
    }

    public static function description(mixed $value): ?string
    {
        $value = self::clean($value, preserveParagraphs: true);

        return $value === '' ? null : $value;
    }

    private static function clean(mixed $value, bool $preserveParagraphs): string
    {
        if (! is_string($value)
            && ! is_int($value)
            && ! is_float($value)
            && ! $value instanceof Stringable) {
            return '';
        }

        $value = (string) $value;
        $normalized = class_exists(Normalizer::class)
            ? Normalizer::normalize($value, Normalizer::FORM_KC)
            : $value;
        $text = $normalized === false ? '' : str_replace(["\r\n", "\r"], "\n", $normalized);
        $text = preg_replace('/<(script|style)\b[^>]*>.*?<\/\1>/isu', ' ', $text) ?? '';
        $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/[\x{202A}-\x{202E}\x{2066}-\x{2069}]/u', '', $text) ?? $text;

        if (! $preserveParagraphs) {
            return trim(preg_replace('/[\p{Cc}\p{Cs}\s]+/u', ' ', $text) ?? '');
        }

        $text = preg_replace('/[\x{0000}-\x{0008}\x{000B}\x{000C}\x{000E}-\x{001F}\x{007F}-\x{009F}]/u', '', $text) ?? $text;
        $lines = collect(explode("\n", $text))
            ->map(fn (string $line): string => trim(preg_replace('/[\t ]+/u', ' ', $line) ?? ''))
            ->all();
        $text = trim(implode("\n", $lines));

        return preg_replace('/\n{3,}/u', "\n\n", $text) ?? '';
    }
}
