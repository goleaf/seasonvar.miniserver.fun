<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Str;

final readonly class CatalogTitleDisplayName
{
    public function __construct(
        public string $primary,
        public ?string $original,
    ) {}

    public static function from(mixed $title, mixed $originalTitle): self
    {
        $primary = PlainText::clean($title);
        $original = PlainText::clean($originalTitle);

        if ($original === '') {
            return new self($primary, null);
        }

        if (self::equivalent($primary, $original)) {
            return new self($primary, null);
        }

        preg_match_all('/\//u', $primary, $separators, PREG_OFFSET_CAPTURE);

        foreach ($separators[0] as $separator) {
            $offset = $separator[1];

            if ($offset < 0) {
                continue;
            }

            $suffix = PlainText::clean(substr($primary, $offset + 1));

            if (! self::equivalent($suffix, $original)) {
                continue;
            }

            $russianTitle = PlainText::clean(substr($primary, 0, $offset));

            if ($russianTitle !== '') {
                return new self($russianTitle, $original);
            }
        }

        return new self($primary, $original);
    }

    public function contains(mixed $candidate): bool
    {
        $candidateKey = self::comparisonKey($candidate);

        return $candidateKey !== '' && in_array($candidateKey, array_filter([
            self::comparisonKey($this->primary),
            self::comparisonKey($this->original),
        ]), true);
    }

    public static function nameHash(mixed $value): string
    {
        return hash('sha256', self::comparisonKey($value));
    }

    private static function equivalent(string $left, string $right): bool
    {
        return self::comparisonKey($left) === self::comparisonKey($right);
    }

    public static function comparisonKey(mixed $value): string
    {
        return Str::lower(str_replace(['’', '‘', '`', '´'], "'", PlainText::clean($value)));
    }
}
