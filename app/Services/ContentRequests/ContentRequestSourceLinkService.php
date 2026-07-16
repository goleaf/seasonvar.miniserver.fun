<?php

declare(strict_types=1);

namespace App\Services\ContentRequests;

use App\Enums\ContentRequestExternalProvider;
use App\Exceptions\ContentRequests\ContentRequestActionException;
use App\Services\Seasonvar\SeasonvarUrl;
use Illuminate\Support\Str;
use InvalidArgumentException;

final readonly class ContentRequestSourceLinkService
{
    public function __construct(private SeasonvarUrl $seasonvarUrl) {}

    /** @param list<string> $links
     * @return list<array{url: string, url_hash: string, provider: string|null}>
     */
    public function normalize(array $links): array
    {
        $maximum = max(1, (int) config('content-requests.max_source_links', 3));

        if (count($links) > $maximum) {
            throw new ContentRequestActionException('requests.errors.too_many_links');
        }

        return collect($links)
            ->map(fn (string $url): array => $this->one($url))
            ->unique('url_hash')
            ->values()
            ->all();
    }

    /** @return array{url: string, url_hash: string, provider: string|null} */
    private function one(string $url): array
    {
        $url = trim($url);

        if (mb_strlen($url) > 2048 || filter_var($url, FILTER_VALIDATE_URL) === false) {
            throw new ContentRequestActionException('requests.errors.invalid_source_url');
        }

        $parts = parse_url($url);
        $scheme = Str::lower((string) ($parts['scheme'] ?? ''));
        $host = Str::lower(rtrim((string) ($parts['host'] ?? ''), '.'));

        if (! in_array($scheme, ['http', 'https'], true)
            || $host === ''
            || isset($parts['user'])
            || isset($parts['pass'])
            || $this->dangerousPath((string) ($parts['path'] ?? ''))
            || $this->privateHost($host)) {
            throw new ContentRequestActionException('requests.errors.invalid_source_url');
        }

        $provider = null;

        if ($host === 'seasonvar.ru' || str_ends_with($host, '.seasonvar.ru')) {
            try {
                $url = $this->seasonvarUrl->normalize($url);
            } catch (InvalidArgumentException) {
                throw new ContentRequestActionException('requests.errors.invalid_source_url');
            }

            if (! $this->seasonvarUrl->isAllowed($url)) {
                throw new ContentRequestActionException('requests.errors.invalid_source_url');
            }
            $provider = ContentRequestExternalProvider::Seasonvar->value;
        }

        return ['url' => $url, 'url_hash' => hash('sha256', $url), 'provider' => $provider];
    }

    private function privateHost(string $host): bool
    {
        if ($host === 'localhost' || str_ends_with($host, '.local') || str_ends_with($host, '.internal')) {
            return true;
        }

        if (filter_var($host, FILTER_VALIDATE_IP) === false) {
            return false;
        }

        return filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false;
    }

    private function dangerousPath(string $path): bool
    {
        return preg_match('/\.(?:avi|bat|cmd|com|exe|js|m3u8|mkv|mov|mp4|msi|scr|torrent|webm|zip)$/i', $path) === 1;
    }
}
