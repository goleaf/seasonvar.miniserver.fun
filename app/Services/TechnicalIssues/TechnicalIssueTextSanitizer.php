<?php

declare(strict_types=1);

namespace App\Services\TechnicalIssues;

use App\DTOs\TechnicalIssues\SanitizedIssueText;
use App\Support\UserPlainText;
use Illuminate\Support\Str;

final class TechnicalIssueTextSanitizer
{
    private const string REDACTION_MARKER = '[[technical-issue-redacted]]';

    public function summary(mixed $value): SanitizedIssueText
    {
        return $this->sanitize(UserPlainText::name($value), 240);
    }

    public function body(mixed $value, int $limit = 6000): SanitizedIssueText
    {
        return $this->sanitize(UserPlainText::description($value), $limit);
    }

    public function redactAll(mixed $value): SanitizedIssueText
    {
        $before = UserPlainText::description($value) ?? '';
        $replacement = self::REDACTION_MARKER;

        return new SanitizedIssueText(
            value: $replacement,
            beforeHash: $this->hash($before),
            afterHash: $this->hash($replacement),
            redactionReasons: ['manual'],
        );
    }

    public function display(mixed $value): ?string
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        return str_replace(self::REDACTION_MARKER, __('issues.redacted'), $value);
    }

    public function containsStoredMarker(mixed ...$values): bool
    {
        foreach ($values as $value) {
            if (is_string($value) && str_contains($value, self::REDACTION_MARKER)) {
                return true;
            }
        }

        return false;
    }

    private function sanitize(?string $value, int $limit): SanitizedIssueText
    {
        $value = $value === null || $value === '' ? null : Str::limit($value, $limit, '');
        $value = $this->normalizeDisplayedMarker($value);
        $before = $value ?? '';
        $reasons = [];

        if ($value !== null) {
            $patterns = [
                'authorization' => '/\b(?:Bearer|Basic)\s+[A-Za-z0-9._~+\/-]{12,}={0,2}\b/iu',
                'jwt' => '/\beyJ[A-Za-z0-9_-]{8,}\.[A-Za-z0-9_-]{8,}\.[A-Za-z0-9_-]{8,}\b/u',
                'secret' => '/\b(?:password|passwd|token|secret|session|cookie|api[_-]?key|authorization)\s*[:=]\s*[^\s,;]{4,}/iu',
                'email' => '/\b[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}\b/iu',
                'phone' => '/(?<!\d)(?:\+?\d[\s().-]*){9,15}(?!\d)/u',
                'private_url' => '/https?:\/\/[^\s]+[?&](?:token|key|secret|signature|code|session|auth)=[^\s&#]+/iu',
                'credential_url' => '~https?://[^/\s:@]+:[^@\s/]+@[^\s]+~iu',
                'private_network_url' => '~https?://(?:localhost|127(?:\.\d{1,3}){3}|10(?:\.\d{1,3}){3}|192\.168(?:\.\d{1,3}){2}|172\.(?:1[6-9]|2\d|3[01])(?:\.\d{1,3}){2}|\[?::1\]?)(?::\d+)?[^\s]*~iu',
                'protected_media_url' => '~https?://[^\s<>"\']+(?:\.m3u8|\.mpd|\.mp4|/manifest(?:/|\?|$)|/playlist(?:/|\?|$))[^\s<>"\']*~iu',
            ];

            foreach ($patterns as $reason => $pattern) {
                $replaced = preg_replace($pattern, self::REDACTION_MARKER, $value, -1, $count);

                if (is_string($replaced) && $count > 0) {
                    $value = $replaced;
                    $reasons[] = $reason;
                }
            }

            $value = trim($value);
            $value = $value === '' ? null : $value;
        }

        return new SanitizedIssueText(
            value: $value,
            beforeHash: $this->hash($before),
            afterHash: $this->hash($value ?? ''),
            redactionReasons: array_values(array_unique($reasons)),
        );
    }

    private function normalizeDisplayedMarker(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $localizedMarker = __('issues.redacted');

        return is_string($localizedMarker) && $localizedMarker !== ''
            ? str_replace($localizedMarker, self::REDACTION_MARKER, $value)
            : $value;
    }

    private function hash(string $value): string
    {
        return hash_hmac('sha256', $value, (string) config('app.key', 'seasonvar-technical-issue-redaction'));
    }
}
