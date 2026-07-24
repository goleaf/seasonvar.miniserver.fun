<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\DTOs\PlaybackTransitionData;
use PHPUnit\Framework\TestCase;

final class PlaybackTransitionDataTest extends TestCase
{
    public function test_it_serializes_only_the_ready_transition_shape(): void
    {
        $data = PlaybackTransitionData::ready(
            message: 'Источник готов',
            contextKey: 'episode:101:media:501',
            source: [
                'url' => '/playback/source-token',
                'mimeType' => 'video/mp4',
                'format' => 'mp4',
                'expiresAt' => '2026-07-24T13:00:00+00:00',
            ],
            selection: [
                'seasonId' => 11,
                'episodeId' => 101,
                'mediaId' => 501,
                'variant' => 'dubbed',
                'quality' => '1080p',
                'format' => 'mp4',
                'query' => [
                    'season' => '11',
                    'episode' => '101',
                    'media' => '501',
                ],
            ],
            labels: [
                'title' => 'Тестовый сериал',
                'season' => '2 сезон',
                'episode' => '3 серия',
                'media' => 'Дублированный · 1080p',
            ],
            translations: [[
                'mediaId' => 501,
                'label' => 'Дублированный',
                'detail' => '1080p · MP4',
                'active' => true,
            ]],
            navigation: [
                'previous' => ['id' => 100, 'label' => '2 серия'],
                'next' => ['id' => 102, 'label' => '4 серия'],
            ],
            mediaSession: [
                'title' => '3 серия',
                'artist' => 'Тестовый сериал',
                'album' => '2 сезон',
                'artwork' => '/storage/posters/title.webp',
            ],
            progress: [
                'enabled' => true,
                'token' => 'progress-token',
                'sequence' => 0,
            ],
            noticeCode: null,
        );

        self::assertTrue($data->isReady());
        self::assertSame([
            'status' => 'ready',
            'message' => 'Источник готов',
            'contextKey' => 'episode:101:media:501',
            'source' => [
                'url' => '/playback/source-token',
                'mimeType' => 'video/mp4',
                'format' => 'mp4',
                'expiresAt' => '2026-07-24T13:00:00+00:00',
            ],
            'selection' => [
                'seasonId' => 11,
                'episodeId' => 101,
                'mediaId' => 501,
                'variant' => 'dubbed',
                'quality' => '1080p',
                'format' => 'mp4',
                'query' => [
                    'season' => '11',
                    'episode' => '101',
                    'media' => '501',
                ],
            ],
            'labels' => [
                'title' => 'Тестовый сериал',
                'season' => '2 сезон',
                'episode' => '3 серия',
                'media' => 'Дублированный · 1080p',
            ],
            'translations' => [[
                'mediaId' => 501,
                'label' => 'Дублированный',
                'detail' => '1080p · MP4',
                'active' => true,
            ]],
            'navigation' => [
                'previous' => ['id' => 100, 'label' => '2 серия'],
                'next' => ['id' => 102, 'label' => '4 серия'],
            ],
            'mediaSession' => [
                'title' => '3 серия',
                'artist' => 'Тестовый сериал',
                'album' => '2 сезон',
                'artwork' => '/storage/posters/title.webp',
            ],
            'progress' => [
                'enabled' => true,
                'token' => 'progress-token',
                'sequence' => 0,
            ],
            'noticeCode' => null,
        ], $data->toArray());
        self::assertStringStartsWith('/playback/', $data->toArray()['source']['url']);
        self::assertArrayNotHasKey('providerUrl', $data->toArray()['source']);
        self::assertArrayNotHasKey('userId', $data->toArray()['progress']);
    }

    public function test_it_serializes_an_unavailable_transition_without_ready_fields(): void
    {
        $data = PlaybackTransitionData::unavailable('Серия недоступна');

        self::assertFalse($data->isReady());
        self::assertSame([
            'status' => 'unavailable',
            'message' => 'Серия недоступна',
        ], $data->toArray());
    }
}
