<?php

declare(strict_types=1);

namespace App\DTOs\Seasonvar;

final readonly class SeasonvarRobotsRules
{
    /**
     * @param  list<array{pattern: string, allow: bool}>  $rules
     */
    public function __construct(
        public array $rules,
        public int $crawlDelaySeconds,
    ) {}

    public function allows(string $url): bool
    {
        $path = (string) (parse_url($url, PHP_URL_PATH) ?: '/');
        $query = parse_url($url, PHP_URL_QUERY);
        $target = $path.($query === null ? '' : '?'.$query);
        $matchedLength = -1;
        $allowed = true;

        foreach ($this->rules as $rule) {
            if (! $this->matches($target, $rule['pattern'])) {
                continue;
            }

            $length = mb_strlen(str_replace(['*', '$'], '', $rule['pattern']));

            if ($length > $matchedLength || ($length === $matchedLength && $rule['allow'])) {
                $matchedLength = $length;
                $allowed = $rule['allow'];
            }
        }

        return $allowed;
    }

    private function matches(string $target, string $pattern): bool
    {
        $endsAtBoundary = str_ends_with($pattern, '$');
        $pattern = $endsAtBoundary ? substr($pattern, 0, -1) : $pattern;
        $expression = str_replace('\\*', '.*', preg_quote($pattern, '~'));

        return preg_match('~^'.$expression.($endsAtBoundary ? '$' : '').'~u', $target) === 1;
    }
}
