<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Enums\CatalogFilterType;
use App\Enums\CatalogPublicationType;
use App\Enums\CatalogSort;
use App\Http\Controllers\Controller;
use App\Support\CatalogAlphabet;
use Illuminate\Http\JsonResponse;

final class CatalogFilterSchemaController extends Controller
{
    /** @var list<string> */
    private const CATALOG_QUALITIES = ['2160p', '1440p', '1080p', '720p', '480p', '360p', '240p'];

    public function __invoke(): JsonResponse
    {
        $alphabet = CatalogAlphabet::titleGroups();

        return response()->json(['data' => [
            'controls' => [
                ['key' => 'q', 'type' => 'string', 'minimum_length' => 2, 'maximum_length' => 80],
                ['key' => 'year', 'type' => 'integer_array', 'maximum_items' => 20],
                ...array_map(
                    static fn (CatalogFilterType $filter): array => [
                        'key' => $filter->value,
                        'type' => 'slug_array',
                        'label' => $filter->label(),
                        'maximum_items' => 20,
                    ],
                    CatalogFilterType::cases(),
                ),
            ],
            'sorts' => array_map(
                static fn (CatalogSort $sort): array => ['value' => $sort->value, 'label' => $sort->label()],
                CatalogSort::cases(),
            ),
            'publication_types' => array_map(
                static fn (CatalogPublicationType $type): array => ['value' => $type->value, 'label' => $type->label()],
                CatalogPublicationType::cases(),
            ),
            'rating_sources' => [
                ['value' => 'imdb', 'label' => 'IMDb'],
                ['value' => 'kinopoisk', 'label' => 'КиноПоиск'],
            ],
            'video' => [
                ['value' => 'available', 'label' => 'Есть видео'],
                ['value' => 'missing', 'label' => 'Без видео'],
            ],
            'subtitles' => [
                ['value' => 'available', 'label' => 'Есть субтитры'],
                ['value' => 'missing', 'label' => 'Без субтитров'],
            ],
            'updated' => [
                ['value' => 'day', 'label' => 'За день'],
                ['value' => 'week', 'label' => 'За неделю'],
                ['value' => 'month', 'label' => 'За месяц'],
                ['value' => 'year', 'label' => 'За год'],
            ],
            'qualities' => array_values(array_intersect(
                (array) config('playback.supported_qualities', []),
                self::CATALOG_QUALITIES,
            )),
            'bounds' => [
                'year' => ['minimum' => 1900, 'maximum' => now()->year + 1],
                'seasons' => ['minimum' => 0, 'maximum' => 9999],
                'episodes' => ['minimum' => 0, 'maximum' => 999999],
                'rating' => ['minimum' => 0, 'maximum' => 10],
                'votes' => ['minimum' => 0],
                'per_page' => ['minimum' => 1, 'maximum' => (int) config('mobile-api.maximum_per_page', 50)],
            ],
            'alphabet' => [
                'cyrillic' => $alphabet['cyrillic'],
                'latin' => $alphabet['latin'],
                'other' => $alphabet['symbols'],
            ],
        ]]);
    }
}
