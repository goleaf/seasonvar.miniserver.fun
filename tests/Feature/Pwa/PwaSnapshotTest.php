<?php

declare(strict_types=1);

namespace Tests\Feature\Pwa;

use App\DTOs\VerifiedExternalUrlData;
use App\Enums\CatalogWatchStatus;
use App\Enums\HelpArticleType;
use App\Enums\HelpAudience;
use App\Enums\HelpEscalationType;
use App\Enums\HelpFeature;
use App\Enums\HelpOwnerTeam;
use App\Enums\HelpPublicationStatus;
use App\Models\CatalogTitle;
use App\Models\CatalogTitleUserState;
use App\Models\HelpArticle;
use App\Models\HelpArticleTranslation;
use App\Models\HelpCategory;
use App\Models\HelpCategoryTranslation;
use App\Models\User;
use App\Services\Catalog\CatalogStatsPosterUrlGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

final class PwaSnapshotTest extends TestCase
{
    use RefreshDatabase;

    public function test_library_snapshot_is_owner_only_minimal_bounded_and_private(): void
    {
        $user = User::factory()->create();
        $foreignUser = User::factory()->create();
        $visible = CatalogTitle::factory()->create([
            'slug' => 'offline-visible',
            'title' => 'Видимый сериал',
            'poster_url' => 'https://media.example.com/poster.jpg',
        ]);
        $hidden = CatalogTitle::factory()->create([
            'slug' => 'offline-hidden',
            'title' => 'Скрытый сериал',
            'is_published' => false,
        ]);
        $foreign = CatalogTitle::factory()->create([
            'slug' => 'offline-foreign',
            'title' => 'Чужой сериал',
        ]);

        CatalogTitleUserState::query()->create([
            'user_id' => $user->id,
            'catalog_title_id' => $visible->id,
            'in_watchlist' => true,
            'rating' => 9,
            'watchlist_version' => 3,
            'rating_version' => 2,
            'watch_status' => CatalogWatchStatus::Watching,
            'watch_status_version' => 1,
            'watchlist_updated_at' => now(),
            'rating_updated_at' => now(),
            'watch_status_updated_at' => now(),
        ]);
        CatalogTitleUserState::query()->create([
            'user_id' => $user->id,
            'catalog_title_id' => $hidden->id,
            'in_watchlist' => true,
        ]);
        CatalogTitleUserState::query()->create([
            'user_id' => $foreignUser->id,
            'catalog_title_id' => $foreign->id,
            'in_watchlist' => true,
        ]);

        $this->get('/pwa/library-snapshot')
            ->assertRedirect(route('login'));

        $response = $this->actingAs($user)
            ->getJson('/pwa/library-snapshot')
            ->assertOk()
            ->assertJsonPath('data.items.0.slug', 'offline-visible')
            ->assertJsonPath('data.items.0.title', 'Видимый сериал')
            ->assertJsonPath('data.items.0.in_watchlist', true)
            ->assertJsonPath('data.items.0.rating', 9)
            ->assertJsonPath('data.items.0.watch_status', CatalogWatchStatus::Watching->value)
            ->assertJsonPath('data.items.0.versions.watchlist', 3)
            ->assertJsonPath('data.items.0.versions.rating', 2)
            ->assertJsonCount(1, 'data.items');

        $this->assertStringContainsString('private', (string) $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));

        $payload = (string) $response->getContent();

        foreach ([
            'offline-hidden',
            'offline-foreign',
            'media.example.com',
            'source_url',
            'playback',
            'email',
            'user_id',
            'catalog_title_id',
        ] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $payload);
        }

        $this->assertSame(
            '/pwa/posters/offline-visible',
            $response->json('data.items.0.poster_url'),
        );
    }

    public function test_public_help_snapshot_excludes_non_public_audiences_and_sanitizes_markdown(): void
    {
        $category = HelpCategory::query()->create([
            'public_id' => (string) Str::uuid(),
            'code' => 'pwa_offline_help',
            'position' => 999,
            'is_visible' => true,
            'content_version' => 1,
        ]);
        HelpCategoryTranslation::query()->create([
            'help_category_id' => $category->id,
            'locale' => 'ru',
            'slug' => 'pwa-offline-help',
            'title' => 'Помощь без сети',
            'description' => 'Публичная помощь.',
        ]);

        $public = $this->helpArticle($category, HelpAudience::Everyone, 'offline-public', true);
        $this->helpArticle($category, HelpAudience::Authenticated, 'offline-auth', true);
        $this->helpArticle($category, HelpAudience::Premium, 'offline-premium', true);
        $this->helpArticle($category, HelpAudience::Staff, 'offline-staff', true);
        $this->helpArticle($category, HelpAudience::Everyone, 'offline-draft', false);

        $response = $this->getJson('/pwa/help-snapshot?locale=ru')
            ->assertOk()
            ->assertJsonFragment([
                'slug' => 'offline-public',
                'title' => 'Статья offline-public',
            ]);

        $content = (string) $response->getContent();

        foreach (['offline-auth', 'offline-premium', 'offline-staff', 'offline-draft', '<script', '<strong'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $content);
        }

        $this->assertStringContainsString('Безопасный текст', $content);
        $this->assertSame($public->translations()->firstOrFail()->updated_at?->toJSON(), $response->json('data.items.0.updated_at'));
        $this->assertLessThanOrEqual(60, count((array) $response->json('data.items')));
    }

    public function test_help_snapshot_normalizes_locale_and_rejects_unknown_query_parameters_as_json(): void
    {
        $this->getJson('/pwa/help-snapshot?locale=%20RU%20')
            ->assertOk()
            ->assertJsonStructure(['data' => ['items']]);

        $this->getJson('/pwa/help-snapshot?locale=de')
            ->assertUnprocessable()
            ->assertHeader('Content-Type', 'application/json')
            ->assertJsonValidationErrors('locale');

        $this->getJson('/pwa/help-snapshot?locale=ru&user_id=1')
            ->assertUnprocessable()
            ->assertHeader('Content-Type', 'application/json')
            ->assertJsonValidationErrors('query');
    }

    public function test_library_snapshot_uses_a_bounded_query_without_n_plus_one(): void
    {
        $user = User::factory()->create();

        foreach (range(1, 305) as $index) {
            $title = CatalogTitle::factory()->create([
                'slug' => "offline-bound-{$index}",
                'title' => "Offline {$index}",
            ]);
            CatalogTitleUserState::query()->create([
                'user_id' => $user->id,
                'catalog_title_id' => $title->id,
                'in_watchlist' => true,
                'watchlist_updated_at' => now()->subSeconds($index),
            ]);
        }

        DB::enableQueryLog();
        $response = $this->actingAs($user)->getJson('/pwa/library-snapshot')->assertOk();
        $queries = DB::getQueryLog();

        $response->assertJsonCount(300, 'data.items');
        $this->assertLessThanOrEqual(
            5,
            count($queries),
            collect($queries)->pluck('query')->implode("\n"),
        );
    }

    public function test_online_session_bootstrap_is_owner_scoped_private_and_contains_no_identity(): void
    {
        $user = User::factory()->create();

        $this->getJson('/pwa/session')->assertUnauthorized();

        $response = $this->actingAs($user)
            ->getJson('/pwa/session')
            ->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'account_scope',
                    'csrf_token',
                    'library_snapshot_url',
                    'action_url',
                    'push_subscription_url',
                    'locale',
                ],
            ]);

        $this->assertSame(64, strlen((string) $response->json('data.account_scope')));
        $this->assertStringContainsString('private', (string) $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $this->assertStringNotContainsString($user->email, (string) $response->getContent());
        $this->assertStringNotContainsString('"user_id"', (string) $response->getContent());
    }

    public function test_poster_proxy_requires_owner_visibility_and_reuses_the_hardened_image_boundary(): void
    {
        Http::preventStrayRequests();

        $user = User::factory()->create();
        $visibleUrl = 'https://media.example.com/pwa-visible.jpg';
        $visible = CatalogTitle::factory()->create([
            'slug' => 'pwa-visible-poster',
            'poster_url' => $visibleUrl,
        ]);
        $hidden = CatalogTitle::factory()->create([
            'slug' => 'pwa-hidden-poster',
            'poster_url' => 'https://media.example.com/pwa-hidden.jpg',
            'is_published' => false,
        ]);

        Http::fake([
            $visibleUrl => Http::response('safe-poster', 200, ['Content-Type' => 'image/jpeg']),
        ]);
        $this->mock(CatalogStatsPosterUrlGuard::class)
            ->shouldReceive('verifiedUrl')
            ->once()
            ->with($visibleUrl)
            ->andReturn(new VerifiedExternalUrlData($visibleUrl, 'media.example.com', '93.184.216.34'));

        $this->get("/pwa/posters/{$visible->slug}")->assertRedirect(route('login'));
        $this->actingAs($user)
            ->get("/pwa/posters/{$hidden->slug}")
            ->assertNotFound();
        $response = $this->actingAs($user)
            ->get("/pwa/posters/{$visible->slug}")
            ->assertOk()
            ->assertHeader('Content-Type', 'image/jpeg')
            ->assertSee('safe-poster', false);

        $this->assertStringContainsString(
            'private',
            (string) $response->headers->get('Cache-Control'),
        );
    }

    private function helpArticle(
        HelpCategory $category,
        HelpAudience $audience,
        string $slug,
        bool $published,
    ): HelpArticle {
        $article = HelpArticle::query()->create([
            'public_id' => (string) Str::uuid(),
            'code' => 'pwa-'.$slug,
            'help_category_id' => $category->id,
            'type' => HelpArticleType::HowTo,
            'audience' => $audience,
            'status' => $published ? HelpPublicationStatus::Published : HelpPublicationStatus::Draft,
            'owner_team' => HelpOwnerTeam::Support,
            'feature_code' => HelpFeature::General,
            'primary_escalation' => HelpEscalationType::None,
            'secondary_escalation' => HelpEscalationType::None,
            'position' => 1,
            'editorial_priority' => 1,
            'is_featured' => false,
            'is_indexable' => true,
            'content_version' => 1,
            'published_at' => $published ? now() : null,
        ]);
        HelpArticleTranslation::query()->create([
            'help_article_id' => $article->id,
            'locale' => 'ru',
            'slug' => $slug,
            'title' => "Статья {$slug}",
            'summary' => 'Проверка offline help.',
            'body_markdown' => "## Безопасный текст\n\n<strong>Markdown</strong><script>alert(1)</script>",
            'search_text' => "Статья {$slug}",
            'is_published' => $published,
            'published_at' => $published ? now() : null,
        ]);

        return $article;
    }
}
