<?php

declare(strict_types=1);

namespace App\Services\ContentRequests;

use App\Enums\ContentRequestExternalProvider;
use App\Exceptions\ContentRequests\ContentRequestActionException;
use Illuminate\Support\Str;

final class ContentRequestExternalIdentifierService
{
    /**
     * @param list<array{provider: string, identifier: string}> $identifiers
     * @return list<array{provider: string, identifier: string, normalized_identifier: string}>
     */
    public function normalize(array $identifiers): array
    {
        $maximum = max(1, (int) config('content-requests.max_external_ids', 5));

        if (count($identifiers) > $maximum) {
            throw new ContentRequestActionException('requests.errors.too_many_external_ids');
        }

        return collect($identifiers)
            ->map(function (array $item): array {
                $provider = ContentRequestExternalProvider::tryFrom(Str::lower(trim($item['provider'] ?? '')));
                $identifier = trim($item['identifier'] ?? '');

                if ($provider === null || ! $this->valid($provider, $identifier)) {
                    throw new ContentRequestActionException('requests.errors.invalid_external_id');
                }

                $normalized = $provider === ContentRequestExternalProvider::Imdb
                    ? Str::lower($identifier)
                    : (ltrim($identifier, '0') ?: '0');

                return ['provider' => $provider->value, 'identifier' => $identifier, 'normalized_identifier' => $normalized];
            })
            ->unique(fn (array $item): string => $item['provider'].':'.$item['normalized_identifier'])
            ->values()
            ->all();
    }

    private function valid(ContentRequestExternalProvider $provider, string $identifier): bool
    {
        return match ($provider) {
            ContentRequestExternalProvider::Imdb => preg_match('/^tt\d{7,10}$/i', $identifier) === 1,
            ContentRequestExternalProvider::Tmdb,
            ContentRequestExternalProvider::Tvdb,
            ContentRequestExternalProvider::Kinopoisk,
            ContentRequestExternalProvider::Seasonvar => preg_match('/^[1-9]\d{0,11}$/', $identifier) === 1,
        };
    }
}
