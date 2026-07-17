<?php

declare(strict_types=1);

namespace App\Services\Collections\Import;

use App\DTOs\HdRezkaCollectionDefinition;
use App\DTOs\HdRezkaCollectionItemData;
use App\Services\Catalog\Search\CatalogSearchNormalizer;
use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;
use Illuminate\Support\Str;
use InvalidArgumentException;
use JsonException;
use UnexpectedValueException;

final class HdRezkaCollectionParser
{
    public function __construct(
        private readonly HdRezkaCollectionUrlGuard $urlGuard,
        private readonly CatalogSearchNormalizer $normalizer,
    ) {}

    /** @return list<HdRezkaCollectionDefinition> */
    public function collections(string $html): array
    {
        $xpath = $this->xpath($html);
        $nodes = $xpath->query(
            '//*[contains(concat(" ", normalize-space(@class), " "), " collections-grid ")]//a[@href]',
        );
        $collections = [];
        $seen = [];

        foreach ($nodes ?: [] as $node) {
            if (! $node instanceof DOMElement) {
                continue;
            }

            $name = $this->nodeText($xpath, './/*[contains(concat(" ", normalize-space(@class), " "), " name ")]', $node);

            if ($name === null) {
                throw new UnexpectedValueException('У коллекции отсутствует название.');
            }

            $path = $this->relativePath($this->urlGuard->absolute(
                $node->getAttribute('href'),
                HdRezkaCollectionUrlGuard::PURPOSE_COLLECTION,
            ));
            $sourceKey = hash('sha256', $this->collectionIdentity($path));

            if (isset($seen[$sourceKey])) {
                continue;
            }

            $coverPath = null;
            $image = $xpath->query('.//img[1]', $node)?->item(0);

            if ($image instanceof DOMElement) {
                $coverUrl = trim($image->getAttribute('data-src')) ?: trim($image->getAttribute('src'));

                if ($coverUrl !== '') {
                    $coverPath = $this->relativePath($this->urlGuard->absolute(
                        $coverUrl,
                        HdRezkaCollectionUrlGuard::PURPOSE_COVER,
                    ));
                }
            }

            $seen[$sourceKey] = true;
            $collections[] = new HdRezkaCollectionDefinition(
                sourceKey: $sourceKey,
                name: $name,
                path: $path,
                coverPath: $coverPath,
                position: count($collections) + 1,
            );
        }

        if ($collections === []) {
            throw new UnexpectedValueException('На странице не найдены коллекции.');
        }

        return $collections;
    }

    /**
     * @return array{items: list<HdRezkaCollectionItemData>, next_path: ?string}
     */
    public function page(string $html, string $collectionPath, int $page): array
    {
        if ($page < 1) {
            throw new UnexpectedValueException('Номер страницы коллекции должен быть положительным.');
        }

        $collectionPath = $this->relativePath($this->urlGuard->absolute(
            $collectionPath,
            HdRezkaCollectionUrlGuard::PURPOSE_COLLECTION,
        ));
        $xpath = $this->xpath($html);
        $nodes = $xpath->query(
            '//*[@id="dle-content"]//*[contains(concat(" ", normalize-space(@class), " "), " card_item ")]',
        );
        $items = [];

        foreach ($nodes ?: [] as $node) {
            if (! $node instanceof DOMElement) {
                continue;
            }

            $link = $xpath->query(
                './/a[contains(concat(" ", normalize-space(@class), " "), " card_item__title ")][1]',
                $node,
            )?->item(0);

            if (! $link instanceof DOMElement) {
                throw new UnexpectedValueException('У карточки коллекции отсутствует ссылка на тайтл.');
            }

            $title = $this->normalizer->display($link->textContent);
            $normalizedTitleKey = $this->normalizer->key($title);

            if ($title === '' || $normalizedTitleKey === '') {
                throw new UnexpectedValueException('У карточки коллекции отсутствует нормализуемое название.');
            }

            try {
                $detailPath = $this->relativePath($this->urlGuard->absolute(
                    $link->getAttribute('href'),
                    HdRezkaCollectionUrlGuard::PURPOSE_DETAIL,
                ));
            } catch (InvalidArgumentException $exception) {
                throw new UnexpectedValueException(
                    'У карточки коллекции отсутствует разрешённый detail URL со стабильным числовым ID.',
                    0,
                    $exception,
                );
            }

            if (preg_match('~^/([1-9][0-9]*)-[^/]*\.html$~i', rawurldecode($detailPath), $matches) !== 1) {
                throw new UnexpectedValueException('У карточки коллекции отсутствует стабильный числовой ID.');
            }

            $misc = $this->nodeText(
                $xpath,
                './/*[contains(concat(" ", normalize-space(@class), " "), " card_item__misc ")][1]',
                $node,
            ) ?? '';
            [$year, $countries] = $this->yearAndCountries($misc);

            $items[] = new HdRezkaCollectionItemData(
                sourceItemKey: $matches[1],
                title: $title,
                normalizedTitleKey: $normalizedTitleKey,
                year: $year,
                type: $this->cardType($xpath, $node),
                countries: $countries,
                detailPath: $detailPath,
                page: $page,
                position: count($items) + 1,
            );
        }

        if ($items === []) {
            throw new UnexpectedValueException('На странице коллекции не найдены карточки тайтлов.');
        }

        return [
            'items' => $items,
            'next_path' => $this->nextPath($xpath, $collectionPath, $page),
        ];
    }

    /** @return array{original_title: ?string, year: ?int, type: ?string, genres: list<string>} */
    public function detail(string $html): array
    {
        $xpath = $this->xpath($html);

        foreach ($xpath->query('//script[translate(@type, "ABCDEFGHIJKLMNOPQRSTUVWXYZ", "abcdefghijklmnopqrstuvwxyz")="application/ld+json"]') ?: [] as $node) {
            try {
                $decoded = json_decode($node->textContent, true, 32, JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                continue;
            }

            foreach ($this->structuredObjects($decoded) as $object) {
                $type = $this->structuredType($object['@type'] ?? null);

                if ($type === null) {
                    continue;
                }

                $originalTitle = isset($object['alternateName']) && is_string($object['alternateName'])
                    ? $this->normalizer->display($object['alternateName'])
                    : '';
                $genres = collect(is_array($object['genre'] ?? null) ? $object['genre'] : [$object['genre'] ?? null])
                    ->filter(fn (mixed $genre): bool => is_string($genre))
                    ->map(fn (string $genre): string => $this->normalizer->key($genre))
                    ->filter()
                    ->unique()
                    ->values()
                    ->all();

                return [
                    'original_title' => $originalTitle !== '' ? $originalTitle : null,
                    'year' => $this->year($object['dateCreated'] ?? null),
                    'type' => $type,
                    'genres' => $genres,
                ];
            }
        }

        return [
            'original_title' => null,
            'year' => null,
            'type' => null,
            'genres' => [],
        ];
    }

    private function xpath(string $html): DOMXPath
    {
        if ($html === '' || ! mb_check_encoding($html, 'UTF-8')) {
            throw new UnexpectedValueException('Получен пустой HTML или HTML в некорректной кодировке.');
        }

        $document = new DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        $loaded = $document->loadHTML(
            '<?xml encoding="UTF-8">'.$html,
            LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_COMPACT,
        );
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (! $loaded) {
            throw new UnexpectedValueException('HTML коллекций не удалось разобрать.');
        }

        return new DOMXPath($document);
    }

    private function nodeText(DOMXPath $xpath, string $query, ?DOMNode $context = null): ?string
    {
        $value = $xpath->query($query, $context)?->item(0)?->textContent;
        $value = is_string($value) ? $this->normalizer->display($value) : '';

        return $value !== '' ? $value : null;
    }

    private function relativePath(string $absoluteUrl): string
    {
        $path = parse_url($absoluteUrl, PHP_URL_PATH);

        if (! is_string($path) || $path === '') {
            throw new UnexpectedValueException('В URL источника отсутствует путь.');
        }

        return $path;
    }

    private function collectionIdentity(string $path): string
    {
        $decoded = rawurldecode($path);
        $decoded = preg_replace('~/page/[1-9][0-9]*/?$~u', '/', $decoded) ?? $decoded;

        return Str::lower($this->normalizer->display(rtrim($decoded, '/')));
    }

    /** @return array{?int, list<string>} */
    private function yearAndCountries(string $misc): array
    {
        $year = null;

        if (preg_match('/\b((?:18|19|20|21)[0-9]{2})\b/u', $misc, $matches) === 1) {
            $year = (int) $matches[1];
        }

        $countries = collect(preg_split('/\s*,\s*/u', $misc) ?: [])
            ->reject(fn (string $part): bool => preg_match('/^\s*(?:18|19|20|21)[0-9]{2}\s*$/u', $part) === 1)
            ->map(fn (string $country): string => $this->normalizer->key($country))
            ->filter()
            ->unique()
            ->values()
            ->all();

        return [$year, $countries];
    }

    private function cardType(DOMXPath $xpath, DOMElement $card): ?string
    {
        $icon = $xpath->query(
            './/*[contains(@class, "card_item__category_icon--")][1]',
            $card,
        )?->item(0);
        $classes = $icon instanceof DOMElement ? Str::lower($icon->getAttribute('class')) : '';

        return match (true) {
            str_contains($classes, '--anime') => 'anime',
            str_contains($classes, '--cartoon') => 'cartoon',
            str_contains($classes, '--series') => 'series',
            str_contains($classes, '--film') => 'film',
            default => null,
        };
    }

    private function nextPath(DOMXPath $xpath, string $collectionPath, int $currentPage): ?string
    {
        $nextPaths = [];
        $hasLaterPage = false;

        foreach ($xpath->query(
            '//*[@id="dle-content"]//*[contains(concat(" ", normalize-space(@class), " "), " pagination ")]//a[@href]',
        ) ?: [] as $link) {
            if (! $link instanceof DOMElement) {
                continue;
            }

            $path = $this->relativePath($this->urlGuard->absolute(
                $link->getAttribute('href'),
                HdRezkaCollectionUrlGuard::PURPOSE_COLLECTION,
            ));

            if ($this->collectionIdentity($path) !== $this->collectionIdentity($collectionPath)) {
                throw new UnexpectedValueException('Pagination вышла за пределы текущей коллекции.');
            }

            $pageNumber = $this->pageNumber($path);

            if ($pageNumber === $currentPage + 1) {
                $nextPaths[$path] = true;
            }

            if ($pageNumber > $currentPage + 1) {
                $hasLaterPage = true;
            }
        }

        if (count($nextPaths) > 1) {
            throw new UnexpectedValueException('Pagination содержит несколько разных следующих страниц.');
        }

        if ($nextPaths === [] && $hasLaterPage) {
            throw new UnexpectedValueException('Pagination содержит разрыв перед следующей страницей.');
        }

        return array_key_first($nextPaths);
    }

    private function pageNumber(string $path): int
    {
        return preg_match('~/page/([1-9][0-9]*)/?$~u', rawurldecode($path), $matches) === 1
            ? (int) $matches[1]
            : 1;
    }

    /** @return list<array<string, mixed>> */
    private function structuredObjects(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $objects = array_is_list($value) ? $value : [$value];
        $result = [];

        foreach ($objects as $object) {
            if (! is_array($object)) {
                continue;
            }

            $result[] = $object;

            if (isset($object['@graph'])) {
                $result = [...$result, ...$this->structuredObjects($object['@graph'])];
            }
        }

        return $result;
    }

    private function structuredType(mixed $value): ?string
    {
        $types = is_array($value) ? $value : [$value];

        foreach ($types as $type) {
            if (! is_string($type)) {
                continue;
            }

            $normalized = Str::lower($type);

            if (in_array($normalized, ['tvseries', 'tvshow', 'creativeworkseries'], true)) {
                return 'series';
            }

            if (in_array($normalized, ['animation', 'animatedmovie'], true)) {
                return 'cartoon';
            }

            if ($normalized === 'anime') {
                return 'anime';
            }

            if ($normalized === 'movie') {
                return 'film';
            }
        }

        return null;
    }

    private function year(mixed $value): ?int
    {
        if (! is_int($value) && ! is_string($value)) {
            return null;
        }

        return preg_match('/\b((?:18|19|20|21)[0-9]{2})\b/', (string) $value, $matches) === 1
            ? (int) $matches[1]
            : null;
    }
}
