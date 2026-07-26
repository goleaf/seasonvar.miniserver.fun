<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Livewire\CatalogTitlePlayer;
use App\Models\CatalogTitle;
use App\Models\Episode;
use App\Models\LicensedMedia;
use App\Models\Season;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

final class CatalogPlayerWorkspaceTest extends TestCase
{
    use RefreshDatabase;

    public function test_player_renders_a_compact_truthful_workspace_with_real_fallback_links(): void
    {
        app()->setLocale('ru');
        $title = CatalogTitle::factory()->create([
            'title' => 'Цветок зла',
            'slug' => 'cvetok-zla',
        ]);
        $season = Season::factory()->create([
            'catalog_title_id' => $title->id,
            'number' => 1,
        ]);
        $episodes = collect([6 => 'Прошлое', 7 => 'Настоящее', 8 => 'Раскрытие'])
            ->map(function (string $episodeTitle, int $number) use ($title, $season): Episode {
                $episode = Episode::factory()->create([
                    'season_id' => $season->id,
                    'number' => $number,
                    'sort_order' => $number,
                    'title' => $episodeTitle,
                ]);
                LicensedMedia::factory()->create([
                    'catalog_title_id' => $title->id,
                    'season_id' => $season->id,
                    'episode_id' => $episode->id,
                    'status' => 'published',
                    'published_at' => now(),
                    'variant_type' => 'voiceover',
                    'variant_name' => 'Мобильное ТВ',
                    'variant_key' => 'voiceover-mobile-tv',
                    'translation_name' => 'Мобильное ТВ',
                    'quality' => '480p',
                    'format' => 'mp4',
                    'has_subtitles' => true,
                    'subtitle_language' => 'ru',
                ]);

                return $episode;
            });
        $selectedEpisode = $episodes->get(7);
        self::assertInstanceOf(Episode::class, $selectedEpisode);
        $russianMedia = $selectedEpisode->licensedMedia()->firstOrFail();
        LicensedMedia::factory()->create([
            'catalog_title_id' => $title->id,
            'season_id' => $season->id,
            'episode_id' => $selectedEpisode->id,
            'status' => 'published',
            'published_at' => now(),
            'variant_type' => 'subtitles',
            'variant_name' => 'Оригинал',
            'variant_key' => 'subtitles-en',
            'quality' => '480p',
            'format' => 'mp4',
            'has_subtitles' => true,
            'subtitle_language' => 'en',
        ]);

        $component = Livewire::withQueryParams([
            'season' => $season->id,
            'episode' => $selectedEpisode->id,
            'media' => $russianMedia->id,
        ])->test(CatalogTitlePlayer::class, ['catalogTitleId' => $title->id]);

        $component
            ->assertSeeHtml('data-player-workspace')
            ->assertSeeHtml('data-player-context-bar')
            ->assertSeeHtml('data-player-theatre-toggle')
            ->assertSeeHtml('aria-pressed="false"')
            ->assertSeeHtml('data-player-recovery')
            ->assertSeeHtml('data-player-choose-source')
            ->assertSeeHtml('data-player-context-translation')
            ->assertSeeHtml('data-player-context-quality')
            ->assertSeeHtml('data-player-context-subtitles')
            ->assertSeeText('Мобильное ТВ')
            ->assertSeeText('480P')
            ->assertSeeText('Субтитры: Русский')
            ->assertSeeText('Субтитры: Английский')
            ->assertSeeText('← 6 серия: Прошлое')
            ->assertSeeText('8 серия: Раскрытие →')
            ->assertSeeText('? Горячие клавиши')
            ->assertDontSee('11cdn.org', escape: false);
    }

    public function test_title_page_exposes_scoped_theatre_layout_markers(): void
    {
        $detail = file_get_contents(resource_path('views/livewire/catalog-title-detail.blade.php'));

        self::assertIsString($detail);
        self::assertStringContainsString('data-title-detail-workspace', $detail);
        self::assertStringContainsString('data-title-detail-layout', $detail);
        self::assertStringContainsString('data-title-detail-sidebar', $detail);
        self::assertStringContainsString('data-title-detail-primary', $detail);
        self::assertStringContainsString('data-player-workspace-region', $detail);
    }
}
