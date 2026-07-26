<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\CatalogTitle;
use App\Models\LicensedMedia;
use App\Models\User;
use App\Models\UserAccountSetting;
use App\Models\UserHiddenPlaybackVariant;
use App\Services\Catalog\CatalogUserCardStateLoader;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class CatalogTranslationPreferenceCardTest extends TestCase
{
    use RefreshDatabase;

    public function test_card_loader_exposes_preferred_and_alternative_translation_state_in_one_media_query(): void
    {
        app()->setLocale('ru');
        $user = User::factory()->create();
        UserAccountSetting::query()->create([
            'user_id' => $user->id,
            'preferred_variant' => 'voiceover-studio-a',
        ]);
        $preferredTitle = CatalogTitle::factory()->create();
        $alternativeTitle = CatalogTitle::factory()->create();
        $this->media($preferredTitle, 'voiceover-studio-a', 'Студия А');
        $this->media($alternativeTitle, 'voiceover-studio-b', 'Студия Б');
        $titles = collect([$preferredTitle, $alternativeTitle]);
        $queries = [];
        DB::listen(static function ($query) use (&$queries): void {
            if (str_contains($query->sql, 'select "catalog_title_id"')
                && str_contains($query->sql, 'from "licensed_media"')) {
                $queries[] = $query->sql;
            }
        });

        app(CatalogUserCardStateLoader::class)->load($titles, $user);

        $this->assertSame('preferred', $preferredTitle->getAttribute('user_translation_preference_state'));
        $this->assertSame('alternative', $alternativeTitle->getAttribute('user_translation_preference_state'));
        $this->assertCount(1, $queries, json_encode($queries, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        $this->assertStringContainsString(
            'Есть ваш предпочитаемый перевод',
            Blade::render('<x-catalog.title-card :title="$title" />', ['title' => $preferredTitle]),
        );
        $this->assertStringContainsString(
            'Пока доступна только другая озвучка',
            Blade::render('<x-catalog.title-card :title="$title" />', ['title' => $alternativeTitle]),
        );
    }

    public function test_hidden_voiceovers_do_not_create_an_alternative_card_signal(): void
    {
        $user = User::factory()->create();
        UserAccountSetting::query()->create([
            'user_id' => $user->id,
            'preferred_variant' => 'voiceover-studio-a',
        ]);
        UserHiddenPlaybackVariant::query()->create([
            'user_id' => $user->id,
            'variant_key' => 'voiceover-studio-b',
        ]);
        $title = CatalogTitle::factory()->create();
        $this->media($title, 'voiceover-studio-b', 'Студия Б');

        app(CatalogUserCardStateLoader::class)->load(collect([$title]), $user);

        $this->assertNull($title->getAttribute('user_translation_preference_state'));
    }

    private function media(CatalogTitle $title, string $variantKey, string $variantName): LicensedMedia
    {
        return LicensedMedia::factory()->create([
            'catalog_title_id' => $title->id,
            'status' => 'published',
            'published_at' => now(),
            'playback_url' => 'https://data00-cdn.11cdn.org/'.$variantKey.'.mp4',
            'path' => 'https://data00-cdn.11cdn.org/'.$variantKey.'.mp4',
            'health_status' => 'active',
            'variant_type' => 'voiceover',
            'variant_name' => $variantName,
            'variant_key' => $variantKey,
            'format' => 'mp4',
        ]);
    }
}
