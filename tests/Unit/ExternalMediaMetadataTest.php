<?php

namespace Tests\Unit;

use App\Services\Media\ExternalMediaMetadata;
use Tests\TestCase;

class ExternalMediaMetadataTest extends TestCase
{
    public function test_it_detects_subtitle_playback_variant_from_seasonvar_title_and_source_url(): void
    {
        $metadata = new ExternalMediaMetadata;
        $sourceUrl = 'https://seasonvar.ru/playls2/hash/trans%D0%A1%D1%83%D0%B1%D1%82%D0%B8%D1%82%D1%80%D1%8B/415/plist.txt';
        $playbackUrl = 'https://media.example.com/big-bang-s01e01-sd.mp4';

        $variant = $metadata->playbackVariant('1 серия SDСубтитры', $sourceUrl, $playbackUrl);

        $this->assertSame('480p', $metadata->quality('1 серия SDСубтитры', $playbackUrl));
        $this->assertNull($metadata->translationName('1 серия SDСубтитры', $sourceUrl));
        $this->assertTrue($variant['has_subtitles']);
        $this->assertSame('subtitles', $variant['variant_type']);
        $this->assertSame('Субтитры', $variant['variant_name']);
        $this->assertSame('subtitles-subtitry', $variant['variant_key']);
    }

    public function test_it_infers_voiceover_name_from_compact_seasonvar_media_title(): void
    {
        $metadata = new ExternalMediaMetadata;
        $playbackUrl = 'https://media.example.com/big-bang-s01e01-fullhd.mp4';

        $variant = $metadata->playbackVariant('1 серия SD/FullHDКураж-Бамбей', null, $playbackUrl);

        $this->assertSame('1080p', $metadata->quality('1 серия SD/FullHDКураж-Бамбей', $playbackUrl));
        $this->assertSame('Кураж-Бамбей', $metadata->translationName('1 серия SD/FullHDКураж-Бамбей'));
        $this->assertFalse($variant['has_subtitles']);
        $this->assertSame('voiceover', $variant['variant_type']);
        $this->assertSame('Кураж-Бамбей', $variant['variant_name']);
        $this->assertSame('voiceover-kuraz-bambei', $variant['variant_key']);
    }

    public function test_it_normalizes_seasonvar_quality_prefixes_and_rejects_non_translation_variants(): void
    {
        $metadata = app(ExternalMediaMetadata::class);

        foreach ([
            'HDRuDub' => 'RuDub',
            'SDRuDub' => 'RuDub',
            'FullHDRuDub' => 'RuDub',
            'HDLostFilm' => 'LostFilm',
            'HDHDRezka' => 'HDRezka',
            'HDOriginal' => 'Оригинал',
            'SDБез перевода' => 'Оригинал',
        ] as $rawName => $expectedName) {
            $this->assertSame($expectedName, $metadata->translationName($rawName), $rawName);
        }

        foreach (['Трейлеры', 'HDТрейлер', 'SDСубтитры', 'FullHD subtitles'] as $rawName) {
            $this->assertNull($metadata->translationName($rawName), $rawName);
        }

        $original = $metadata->playbackVariant('HDOriginal', null, 'https://media.example.com/show/s01e01.mp4');

        $this->assertSame('original', $original['variant_type']);
        $this->assertSame('Оригинал', $original['variant_name']);
    }
}
