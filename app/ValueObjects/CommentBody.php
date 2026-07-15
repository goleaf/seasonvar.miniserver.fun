<?php

declare(strict_types=1);

namespace App\ValueObjects;

use App\Exceptions\Comments\CommentActionException;
use App\Support\UserPlainText;
use Illuminate\Support\Str;

final readonly class CommentBody
{
    private function __construct(
        public string $value,
        public string $hash,
        public int $linkCount,
        public int $mentionCount,
    ) {}

    public static function from(mixed $input): self
    {
        $value = UserPlainText::description($input) ?? '';
        $maximumLength = max(1, (int) config('comments.body.maximum_length', 5_000));

        if ($value === '') {
            throw new CommentActionException('comments.errors.body_required');
        }

        if (Str::length($value) > $maximumLength) {
            throw new CommentActionException('comments.errors.body_too_long', [
                'maximum' => $maximumLength,
            ]);
        }

        $lineCount = substr_count($value, "\n") + 1;
        $maximumLines = max(1, (int) config('comments.body.maximum_lines', 40));

        if ($lineCount > $maximumLines) {
            throw new CommentActionException('comments.errors.too_many_lines', [
                'maximum' => $maximumLines,
            ]);
        }

        if (preg_match(
            '/(?:javascript|vbscript)\s*:|data\s*:\s*(?:text\/html|text\/javascript|image\/svg\+xml|application\/(?:javascript|xhtml\+xml))/iu',
            $value,
        ) === 1) {
            throw new CommentActionException('comments.errors.dangerous_link');
        }

        $linkCount = preg_match_all('/\b(?:https?:\/\/|www\.)[^\s<>{}\[\]]+/iu', $value) ?: 0;
        $maximumLinks = max(0, (int) config('comments.body.maximum_links', 2));

        if ($linkCount > $maximumLinks) {
            throw new CommentActionException('comments.errors.too_many_links', [
                'maximum' => $maximumLinks,
            ]);
        }

        $mentionCount = preg_match_all('/(?<![\p{L}\p{N}_])@[\p{L}\p{N}_][\p{L}\p{N}_.-]{0,63}/u', $value) ?: 0;
        $maximumMentions = max(0, (int) config('comments.body.maximum_mentions', 5));

        if ($mentionCount > $maximumMentions) {
            throw new CommentActionException('comments.errors.too_many_mentions', [
                'maximum' => $maximumMentions,
            ]);
        }

        $maximumRepeated = max(3, (int) config('comments.body.maximum_repeated_characters', 30));

        if (preg_match('/(.)\1{'.($maximumRepeated - 1).',}/us', $value) === 1) {
            throw new CommentActionException('comments.errors.excessive_repetition', [
                'maximum' => $maximumRepeated,
            ]);
        }

        return new self(
            value: $value,
            hash: hash('sha256', Str::lower($value)),
            linkCount: $linkCount,
            mentionCount: $mentionCount,
        );
    }
}
