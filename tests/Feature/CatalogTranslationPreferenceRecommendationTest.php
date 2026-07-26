<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\DTOs\CatalogRecommendationContext;
use App\Enums\CatalogRecommendationType;
use App\Models\CatalogTitle;
use App\Models\LicensedMedia;
use App\Models\User;
use App\Models\UserAccountSetting;
use App\Models\UserHiddenPlaybackVariant;
use App\Services\Catalog\CatalogRecommendationAvailabilityReranker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class CatalogTranslationPreferenceRecommendationTest extends TestCase
{
    use RefreshDatabase;

    public function test_personalized_availability_uses_all_translation_preferences_in_one_media_query(): void
    {
        $user = User::factory()->create();
        UserAccountSetting::query()->create([
            'user_id' => $user->id,
            'preferred_variant' => 'voiceover-studio-a',
            'fallback_variant' => 'voiceover-studio-b',
            'preferred_playback_mode' => 'original_subtitles',
            'preferred_subtitle_language' => 'en',
        ]);
        UserHiddenPlaybackVariant::query()->create([
            'user_id' => $user->id,
            'variant_key' => 'voiceover-studio-c',
        ]);
        $favorite = CatalogTitle::factory()->create();
        $fallback = CatalogTitle::factory()->create();
        $subtitles = CatalogTitle::factory()->create();
        $hidden = CatalogTitle::factory()->create();
        $this->media($favorite, 'voiceover-studio-a');
        $this->media($fallback, 'voiceover-studio-b');
        $this->media($subtitles, 'subtitles-en', 'subtitles', true, 'en');
        $this->media($hidden, 'voiceover-studio-c');
        $queries = [];
        DB::listen(static function ($query) use (&$queries): void {
            if (str_contains($query->sql, 'from "licensed_media"')
                && str_contains($query->sql, 'select "catalog_title_id"')) {
                $queries[] = $query->sql;
            }
        });

        $reranked = app(CatalogRecommendationAvailabilityReranker::class)->rerank(
            new CatalogRecommendationContext(CatalogRecommendationType::Personalized, $user, 'ru'),
            collect([$favorite, $fallback, $subtitles, $hidden])
                ->map(fn (CatalogTitle $title): array => [
                    'id' => $title->id,
                    'score' => 10,
                    'source' => 'test',
                    'reason' => 'test',
                ])
                ->all(),
        );
        $scores = collect($reranked)->pluck('score', 'id');

        $this->assertSame(22, $scores[$favorite->id]);
        $this->assertSame(18, $scores[$fallback->id]);
        $this->assertSame(20, $scores[$subtitles->id]);
        $this->assertSame(10, $scores[$hidden->id]);
        $this->assertSame([$favorite->id, $subtitles->id, $fallback->id, $hidden->id], array_column($reranked, 'id'));
        $this->assertCount(1, $queries);
    }

    private function media(
        CatalogTitle $title,
        string $variantKey,
        string $variantType = 'voiceover',
        bool $hasSubtitles = false,
        ?string $subtitleLanguage = null,
    ): LicensedMedia {
        return LicensedMedia::factory()->create([
            'catalog_title_id' => $title->id,
            'status' => 'published',
            'published_at' => now(),
            'playback_url' => 'https://data00-cdn.11cdn.org/'.$variantKey.'.mp4',
            'path' => 'https://data00-cdn.11cdn.org/'.$variantKey.'.mp4',
            'health_status' => 'active',
            'variant_type' => $variantType,
            'variant_name' => $variantKey,
            'variant_key' => $variantKey,
            'has_subtitles' => $hasSubtitles,
            'subtitle_language' => $subtitleLanguage,
            'format' => 'mp4',
        ]);
    }
}
