<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\DTOs\PlayerEpisodePageData;
use PHPUnit\Framework\TestCase;

final class PlayerEpisodePageDataTest extends TestCase
{
    public function test_it_serializes_only_the_bounded_allowlisted_page_shape(): void
    {
        $data = new PlayerEpisodePageData(
            seasonId: 11,
            seasonLabel: '2 сезон',
            episodes: [[
                'id' => 101,
                'label' => '3 серия',
                'title' => 'Возвращение',
                'mediaCount' => 2,
                'current' => true,
            ]],
            page: 2,
            lastPage: 4,
        );

        self::assertSame([
            'status' => 'ready',
            'season' => ['id' => 11, 'label' => '2 сезон'],
            'episodes' => [[
                'id' => 101,
                'label' => '3 серия',
                'title' => 'Возвращение',
                'mediaCount' => 2,
                'current' => true,
            ]],
            'pagination' => [
                'page' => 2,
                'lastPage' => 4,
                'previousPage' => 1,
                'nextPage' => 3,
            ],
        ], $data->toArray());
    }
}
