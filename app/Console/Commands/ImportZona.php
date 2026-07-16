<?php

declare(strict_types=1);

namespace App\Console\Commands;

use DOMDocument;
use DOMNode;
use DOMXPath;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

#[Signature('zona:import {--sitemap=https://w127.zona.plus/sitemaps/sitemap-2.xml : HTTPS sitemap Zona} {--url=* : Прямая ссылка на страницу фильма; опцию можно повторять} {--limit=30 : Количество последних страниц, от 1 до 100}')]
#[Description('Собирает метаданные и актуальные MP4 последних страниц Zona в терминальную таблицу')]
class ImportZona extends Command
{
    private const MAX_REDIRECTS = 3;

    private const CLIENT_TIME_SALT = 'YxY4EQ';

    private const USER_AGENT = 'Mozilla/5.0 (compatible; SeasonvarZonaImporter/1.0)';

    public function handle(): int
    {
        try {
            $limit = $this->limit();
            $sourceUrls = $this->sourceUrls($limit);
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info(sprintf('Собираю данные: %d страниц.', count($sourceUrls)));

        $items = [];

        foreach ($sourceUrls as $index => $sourceUrl) {
            try {
                $items[] = $this->collectPage($sourceUrl, $index + 1);
            } catch (Throwable $exception) {
                $items[] = $this->failedItem($sourceUrl, $index + 1, $exception);
            }

            if ($index < count($sourceUrls) - 1) {
                usleep(100_000);
            }
        }

        $this->table(
            [
                '№',
                'Название',
                'Оригинал',
                'Год',
                'Страна',
                'Жанры',
                'Режиссёр',
                'Сценарий',
                'Актёры',
                'Время',
                'Рейтинг',
                'Видео',
                'HEAD',
                'Размер',
                'MP4',
            ],
            array_map($this->tableRow(...), $items),
        );

        $failures = count(array_filter($items, fn (array $item): bool => $item['error'] !== null));
        $movies = count(array_filter($items, fn (array $item): bool => $item['media_kind'] === 'movie'));
        $trailers = count(array_filter($items, fn (array $item): bool => $item['media_kind'] === 'trailer'));
        $available = count(array_filter(
            $items,
            fn (array $item): bool => $item['media_status'] === 200 && $item['content_type'] === 'video/mp4',
        ));

        $this->line(sprintf(
            'Итого: %d; фильмы: %d; трейлеры: %d; MP4 доступны: %d; ошибки: %d.',
            count($items),
            $movies,
            $trailers,
            $available,
            $failures,
        ));

        return $failures === count($items) ? self::FAILURE : self::SUCCESS;
    }

    private function limit(): int
    {
        $rawLimit = $this->option('limit');

        if (! is_scalar($rawLimit) || ! preg_match('/^\d+$/', (string) $rawLimit)) {
            throw new RuntimeException('Опция --limit должна быть целым числом от 1 до 100.');
        }

        $limit = (int) $rawLimit;

        if ($limit < 1 || $limit > 100) {
            throw new RuntimeException('Опция --limit должна быть целым числом от 1 до 100.');
        }

        return $limit;
    }

    /**
     * @return list<string>
     */
    private function sourceUrls(int $limit): array
    {
        $directUrls = array_values(array_filter(
            array_map(
                static fn (mixed $url): string => is_string($url) ? trim($url) : '',
                (array) $this->option('url'),
            ),
        ));

        $sourceUrls = $directUrls !== []
            ? $directUrls
            : $this->sitemapUrls($this->sitemapUrl());

        $sourceUrls = array_values(array_unique($sourceUrls));

        foreach ($sourceUrls as $sourceUrl) {
            $this->assertZonaUrl($sourceUrl, moviePage: true);
        }

        return array_slice($sourceUrls, -$limit);
    }

    private function sitemapUrl(): string
    {
        $sitemapUrl = $this->option('sitemap');

        if (! is_string($sitemapUrl) || trim($sitemapUrl) === '') {
            throw new RuntimeException('Опция --sitemap должна содержать HTTPS URL Zona.');
        }

        $sitemapUrl = trim($sitemapUrl);
        $this->assertZonaUrl($sitemapUrl);

        return $sitemapUrl;
    }

    /**
     * @return list<string>
     */
    private function sitemapUrls(string $sitemapUrl): array
    {
        [$response] = $this->getZona($sitemapUrl, [
            'Accept' => 'application/xml,text/xml;q=0.9,*/*;q=0.8',
        ]);

        if (! $response->successful()) {
            throw new RuntimeException('Zona sitemap вернул HTTP '.$response->status().'.');
        }

        $document = new DOMDocument;
        $previousErrors = libxml_use_internal_errors(true);

        try {
            $loaded = $document->loadXML($response->body(), LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previousErrors);
        }

        if (! $loaded) {
            throw new RuntimeException('Zona sitemap содержит некорректный XML.');
        }

        $xpath = new DOMXPath($document);
        $nodes = $xpath->query('//*[local-name()="loc"]');
        $urls = [];

        if ($nodes !== false) {
            foreach ($nodes as $node) {
                $url = trim(html_entity_decode($node->textContent, ENT_QUOTES | ENT_HTML5, 'UTF-8'));

                if ($url !== '' && $this->isMoviePageUrl($url)) {
                    $urls[] = $url;
                }
            }
        }

        if ($urls === []) {
            throw new RuntimeException('Zona sitemap не содержит ссылок /movies/.');
        }

        return $urls;
    }

    /**
     * @return array<string, mixed>
     */
    private function collectPage(string $sourceUrl, int $position): array
    {
        [$response, $effectiveUrl] = $this->getZona($sourceUrl, [
            'Accept' => 'text/html,application/xhtml+xml;q=0.9,*/*;q=0.8',
        ]);

        if (! $response->successful()) {
            throw new RuntimeException('Страница вернула HTTP '.$response->status().'.');
        }

        $xpath = new DOMXPath($this->htmlDocument($response->body()));
        $displayFields = $this->displayFields($xpath);
        $titleRaw = $this->firstText(
            $xpath,
            '//*[contains(concat(" ", normalize-space(@class), " "), " js-title ") and @itemprop="name"] | //h1',
        );
        $title = $titleRaw !== null
            ? Str::squish((string) preg_replace('/\s+\d+\+\s*$/u', '', $titleRaw))
            : null;
        $mainVideoId = $this->firstText(
            $xpath,
            '//*[@data-id and contains(concat(" ", normalize-space(@class), " "), " entity-play-btn ")]/@data-id | //*[@data-id and contains(concat(" ", normalize-space(@class), " "), " entity-player ")]/@data-id',
        );
        $trailerId = $this->firstText(
            $xpath,
            '//*[@data-id and contains(concat(" ", normalize-space(@class), " "), " js-trailer ")]/@data-id',
        );
        $mediaId = $mainVideoId ?? $trailerId;
        $mediaKind = $mainVideoId !== null ? 'movie' : ($trailerId !== null ? 'trailer' : null);
        $media = $mediaId !== null ? $this->media($effectiveUrl, $mediaId) : [];
        $mp4Url = is_string($media['url'] ?? null) ? $media['url'] : null;
        $probe = $mp4Url !== null ? $this->probeMedia($mp4Url, $effectiveUrl) : [];
        $yearText = $this->firstText($xpath, '//*[@itemprop="copyrightYear"]') ?? ($displayFields['Год'] ?? null);

        return [
            'position' => $position,
            'source_url' => $sourceUrl,
            'canonical_url' => $this->firstText($xpath, '//link[@rel="canonical"]/@href') ?? $effectiveUrl,
            'title' => $title ?? $displayFields['Название'] ?? $this->slugTitle($sourceUrl),
            'original_title' => $this->firstText($xpath, '//meta[@itemprop="alternativeHeadline"]/@content | //*[@itemprop="alternateName"]')
                ?? $displayFields['Название']
                ?? null,
            'year' => is_string($yearText) && preg_match('/\b(\d{4})\b/', $yearText, $yearMatch)
                ? (int) $yearMatch[1]
                : null,
            'countries' => $this->valuesOrDisplayField(
                $this->allTexts($xpath, '//*[contains(concat(" ", normalize-space(@class), " "), " js-countries ")]//*[contains(concat(" ", normalize-space(@class), " "), " entity-desc-link-u ")]'),
                $displayFields['Страна'] ?? null,
            ),
            'genres' => $this->valuesOrDisplayField(
                $this->allTexts($xpath, '//*[@itemprop="genre"]'),
                $displayFields['Жанры'] ?? null,
            ),
            'directors' => $this->valuesOrDisplayField(
                $this->allTexts($xpath, '//*[@itemprop="director"]//*[@itemprop="name"]'),
                $displayFields['Режиссёр'] ?? null,
            ),
            'writers' => $this->valuesOrDisplayField(
                $this->allTexts($xpath, '//*[@itemprop="author"]//*[@itemprop="name"]'),
                $displayFields['Сценарий'] ?? null,
            ),
            'actors' => $this->valuesOrDisplayField(
                $this->allTexts($xpath, '//*[@itemprop="actor"]//*[@itemprop="name"]'),
                $displayFields['Актёры'] ?? null,
            ),
            'duration' => $this->firstText($xpath, '//time[@datetime]') ?? ($displayFields['Время'] ?? null),
            'ratings' => array_filter([
                'Zona' => $this->firstNumber($xpath, '//*[contains(concat(" ", normalize-space(@class), " "), " entity-rating-mobi ")]'),
                'КП' => $this->firstNumber($xpath, '//*[contains(concat(" ", normalize-space(@class), " "), " entity-rating-kp ")]'),
                'IMDb' => $this->firstNumber($xpath, '//*[contains(concat(" ", normalize-space(@class), " "), " entity-rating-imdb ")]'),
            ], static fn (?float $rating): bool => $rating !== null),
            'media_kind' => $mediaKind,
            'media_id' => $mediaId,
            'mp4_url' => $mp4Url,
            'hls_url' => is_string($media['lqHlsUrl'] ?? null) ? $media['lqHlsUrl'] : null,
            'media_status' => $probe['status'] ?? null,
            'content_type' => $probe['content_type'] ?? null,
            'content_length' => $probe['content_length'] ?? null,
            'error' => null,
        ];
    }

    private function htmlDocument(string $html): DOMDocument
    {
        $document = new DOMDocument;
        $previousErrors = libxml_use_internal_errors(true);

        try {
            $loaded = $document->loadHTML(
                '<?xml encoding="UTF-8">'.$html,
                LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING,
            );
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previousErrors);
        }

        if (! $loaded) {
            throw new RuntimeException('Не удалось разобрать HTML страницы.');
        }

        return $document;
    }

    /**
     * @return array<string, string>
     */
    private function displayFields(DOMXPath $xpath): array
    {
        $fields = [];
        $rows = $xpath->query('//*[contains(concat(" ", normalize-space(@class), " "), " entity-desc-item-wrap ")]');

        if ($rows === false) {
            return $fields;
        }

        foreach ($rows as $row) {
            $label = $this->firstText($xpath, './dt', $row);
            $value = $this->firstText($xpath, './dd', $row);

            if ($label !== null && $value !== null) {
                $fields[$label] = $value;
            }
        }

        return $fields;
    }

    /**
     * @return array<string, mixed>
     */
    private function media(string $pageUrl, string $mediaId): array
    {
        $origin = $this->origin($pageUrl);
        $clientTime = $this->zonaClientTime($origin, $pageUrl);
        $endpoint = sprintf(
            '%s/ajax/video/%s?client_time=%s',
            $origin,
            rawurlencode($mediaId),
            $clientTime,
        );
        [$response] = $this->getZona($endpoint, [
            'Accept' => 'application/json, text/javascript, */*; q=0.01',
            'Referer' => $pageUrl,
            'X-Requested-With' => 'XMLHttpRequest',
        ]);

        if (! $response->successful()) {
            throw new RuntimeException('Media endpoint вернул HTTP '.$response->status().'.');
        }

        $media = $response->json();

        if (! is_array($media)) {
            throw new RuntimeException('Media endpoint вернул некорректный JSON.');
        }

        return $media;
    }

    private function zonaClientTime(string $origin, string $pageUrl): string
    {
        $response = $this->request([
            'Accept' => '*/*; q=0.01',
            'Referer' => $pageUrl,
            'X-Requested-With' => 'XMLHttpRequest',
        ])->withoutRedirecting()->head($origin.'/ajax/video');

        if (! $response->successful()) {
            throw new RuntimeException('Проверка времени Zona вернула HTTP '.$response->status().'.');
        }

        $date = $response->header('Date');
        $timestamp = is_string($date) ? strtotime($date) : false;

        if ($timestamp === false) {
            throw new RuntimeException('Проверка времени Zona не вернула корректный заголовок Date.');
        }

        $milliseconds = abs($this->javascriptHash($timestamp.self::USER_AGENT.self::CLIENT_TIME_SALT)) % 1000;
        $checksum = 3 ^ (999 - $milliseconds);

        return ($timestamp * 1000 + $milliseconds).'.'.str_pad((string) $checksum, 3, '0', STR_PAD_LEFT);
    }

    private function javascriptHash(string $value): int
    {
        $hash = 0;

        foreach (str_split($value) as $character) {
            $hash = (($hash * 31) + ord($character)) & 0xFFFFFFFF;

            if ($hash >= 0x80000000) {
                $hash -= 0x100000000;
            }
        }

        return $hash;
    }

    /**
     * @return array{status: int, content_type: ?string, content_length: ?int}
     */
    private function probeMedia(string $url, string $referer): array
    {
        $currentUrl = $url;

        for ($redirects = 0; $redirects <= self::MAX_REDIRECTS; $redirects++) {
            $this->assertMediaUrl($currentUrl);
            $response = $this->request([
                'Accept' => 'video/mp4,*/*;q=0.8',
                'Accept-Encoding' => 'identity',
                'Origin' => $this->origin($referer),
                'Referer' => $referer,
            ])->withoutRedirecting()->head($currentUrl);

            if ($this->isRedirect($response)) {
                $location = $response->header('Location');

                if (! is_string($location) || $location === '') {
                    throw new RuntimeException('MP4 redirect не содержит Location.');
                }

                $currentUrl = $this->resolveUrl($currentUrl, $location);

                continue;
            }

            $contentType = $response->header('Content-Type');
            $contentLength = $response->header('Content-Length');

            return [
                'status' => $response->status(),
                'content_type' => is_string($contentType) ? Str::before($contentType, ';') : null,
                'content_length' => is_string($contentLength) && ctype_digit($contentLength)
                    ? (int) $contentLength
                    : null,
            ];
        }

        throw new RuntimeException('Превышен лимит redirect для MP4.');
    }

    /**
     * @param  array<string, string>  $headers
     * @return array{Response, string}
     */
    private function getZona(string $url, array $headers = []): array
    {
        $currentUrl = $url;

        for ($redirects = 0; $redirects <= self::MAX_REDIRECTS; $redirects++) {
            $this->assertZonaUrl($currentUrl);
            $response = $this->request($headers)->withoutRedirecting()->get($currentUrl);

            if ($this->isRedirect($response)) {
                $location = $response->header('Location');

                if (! is_string($location) || $location === '') {
                    throw new RuntimeException('Zona redirect не содержит Location.');
                }

                $currentUrl = $this->resolveUrl($currentUrl, $location);

                continue;
            }

            return [$response, $currentUrl];
        }

        throw new RuntimeException('Превышен лимит redirect для Zona.');
    }

    /**
     * @param  array<string, string>  $headers
     */
    private function request(array $headers): PendingRequest
    {
        return Http::withHeaders(array_merge([
            'User-Agent' => self::USER_AGENT,
        ], $headers))
            ->connectTimeout(5)
            ->timeout(20)
            ->retry(
                [200, 500],
                static fn (Throwable $exception): bool => $exception instanceof ConnectionException
                    || ($exception instanceof RequestException && $exception->response->serverError()),
                throw: false,
            );
    }

    private function isRedirect(Response $response): bool
    {
        return in_array($response->status(), [301, 302, 303, 307, 308], true);
    }

    private function resolveUrl(string $baseUrl, string $location): string
    {
        $location = trim($location);

        if (filter_var($location, FILTER_VALIDATE_URL) !== false) {
            return $location;
        }

        $base = parse_url($baseUrl);

        if (! is_array($base) || ! isset($base['scheme'], $base['host'])) {
            throw new RuntimeException('Не удалось разрешить относительный redirect URL.');
        }

        if (str_starts_with($location, '//')) {
            return $base['scheme'].':'.$location;
        }

        $origin = $base['scheme'].'://'.$base['host'];

        if (str_starts_with($location, '/')) {
            return $origin.$location;
        }

        $basePath = isset($base['path']) ? $base['path'] : '/';
        $directory = rtrim(str_replace('\\', '/', dirname($basePath)), '/');

        return $origin.($directory === '' ? '' : $directory).'/'.$location;
    }

    private function assertZonaUrl(string $url, bool $moviePage = false): void
    {
        $parts = parse_url($url);
        $host = is_array($parts) && isset($parts['host']) ? Str::lower($parts['host']) : null;
        $path = is_array($parts) && isset($parts['path']) ? $parts['path'] : '/';

        if (! is_array($parts)
            || ($parts['scheme'] ?? null) !== 'https'
            || ! is_string($host)
            || preg_match('/^(?:w\d+\.)?zona\.plus$/', $host) !== 1
            || isset($parts['user'])
            || isset($parts['pass'])
        ) {
            throw new RuntimeException('Разрешены только HTTPS URL доменов w*.zona.plus.');
        }

        if ($moviePage && ! str_starts_with($path, '/movies/')) {
            throw new RuntimeException('Ссылка фильма должна находиться внутри /movies/.');
        }
    }

    private function assertMediaUrl(string $url): void
    {
        $parts = parse_url($url);
        $host = is_array($parts) && isset($parts['host']) ? Str::lower($parts['host']) : null;

        if (! is_array($parts)
            || ($parts['scheme'] ?? null) !== 'https'
            || ! is_string($host)
            || preg_match('/^(?:[a-z0-9-]+\.)*vibio\.tv$/', $host) !== 1
            || isset($parts['user'])
            || isset($parts['pass'])
        ) {
            throw new RuntimeException('MP4 URL находится вне разрешённых HTTPS CDN *.vibio.tv.');
        }
    }

    private function isMoviePageUrl(string $url): bool
    {
        try {
            $this->assertZonaUrl($url, moviePage: true);

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    private function origin(string $url): string
    {
        $parts = parse_url($url);

        if (! is_array($parts) || ! isset($parts['scheme'], $parts['host'])) {
            throw new RuntimeException('Некорректный абсолютный URL.');
        }

        return $parts['scheme'].'://'.$parts['host'];
    }

    private function firstText(DOMXPath $xpath, string $expression, ?DOMNode $context = null): ?string
    {
        $nodes = $xpath->query($expression, $context);

        if ($nodes === false || $nodes->length === 0) {
            return null;
        }

        $text = Str::squish(html_entity_decode($nodes->item(0)?->textContent ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8'));

        return $text !== '' ? $text : null;
    }

    /**
     * @return list<string>
     */
    private function allTexts(DOMXPath $xpath, string $expression): array
    {
        $nodes = $xpath->query($expression);
        $values = [];

        if ($nodes === false) {
            return $values;
        }

        foreach ($nodes as $node) {
            $value = Str::squish(html_entity_decode($node->textContent, ENT_QUOTES | ENT_HTML5, 'UTF-8'));

            if ($value !== '') {
                $values[] = $value;
            }
        }

        return array_values(array_unique($values));
    }

    private function firstNumber(DOMXPath $xpath, string $expression): ?float
    {
        $value = $this->firstText($xpath, $expression);

        if ($value === null || ! preg_match('/\d+(?:[.,]\d+)?/', $value, $match)) {
            return null;
        }

        return (float) str_replace(',', '.', $match[0]);
    }

    /**
     * @param  list<string>  $values
     * @return list<string>
     */
    private function valuesOrDisplayField(array $values, ?string $displayField): array
    {
        if ($values !== []) {
            return $values;
        }

        if ($displayField === null || $displayField === '') {
            return [];
        }

        return array_values(array_filter(array_map(
            static fn (string $value): string => Str::squish($value),
            preg_split('/\s*,\s*/u', $displayField) ?: [],
        )));
    }

    private function slugTitle(string $url): string
    {
        $path = (string) parse_url($url, PHP_URL_PATH);
        $slug = basename($path);

        return Str::headline(str_replace('-', ' ', $slug));
    }

    /**
     * @return array<string, mixed>
     */
    private function failedItem(string $sourceUrl, int $position, Throwable $exception): array
    {
        return [
            'position' => $position,
            'source_url' => $sourceUrl,
            'canonical_url' => null,
            'title' => $this->slugTitle($sourceUrl),
            'original_title' => null,
            'year' => null,
            'countries' => [],
            'genres' => [],
            'directors' => [],
            'writers' => [],
            'actors' => [],
            'duration' => null,
            'ratings' => [],
            'media_kind' => null,
            'media_id' => null,
            'mp4_url' => null,
            'hls_url' => null,
            'media_status' => null,
            'content_type' => null,
            'content_length' => null,
            'error' => Str::limit(Str::squish($exception->getMessage()), 160),
        ];
    }

    /**
     * @param  array<string, mixed>  $item
     * @return list<int|string>
     */
    private function tableRow(array $item): array
    {
        if ($item['error'] !== null) {
            return [
                $item['position'],
                $item['title'],
                '—',
                '—',
                '—',
                '—',
                '—',
                '—',
                '—',
                '—',
                '—',
                'ошибка',
                $item['error'],
                '—',
                '—',
            ];
        }

        $rating = collect($item['ratings'])
            ->map(fn (float $value, string $provider): string => $provider.' '.rtrim(rtrim(number_format($value, 1, '.', ''), '0'), '.'))
            ->implode('; ');
        $head = $item['media_status'] !== null
            ? trim($item['media_status'].' '.($item['content_type'] ?? ''))
            : '—';

        return [
            $item['position'],
            $item['title'] ?: '—',
            $item['original_title'] ?: '—',
            $item['year'] ?: '—',
            $this->join($item['countries']),
            $this->join($item['genres']),
            $this->join($item['directors']),
            $this->join($item['writers']),
            $this->join($item['actors']),
            $item['duration'] ?: '—',
            $rating !== '' ? $rating : '—',
            match ($item['media_kind']) {
                'movie' => 'фильм',
                'trailer' => 'трейлер',
                default => '—',
            },
            $head,
            $this->fileSize($item['content_length']),
            $item['mp4_url'] ?: '—',
        ];
    }

    /**
     * @param  list<string>  $values
     */
    private function join(array $values): string
    {
        return $values !== [] ? implode(', ', $values) : '—';
    }

    private function fileSize(?int $bytes): string
    {
        return $bytes !== null
            ? number_format($bytes / 1_048_576, 1, '.', ' ').' MiB'
            : '—';
    }
}
