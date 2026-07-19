<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Enums\CatalogCollectionModerationStatus;
use App\Enums\CatalogCollectionType;
use App\Enums\CatalogCollectionVisibility;
use App\Enums\PublicationStatus;
use App\Enums\TagModerationStatus;
use App\Enums\TagType;
use App\Enums\TagVisibility;
use App\Enums\UserProfileVisibility;
use App\Models\CatalogCollection;
use App\Models\CatalogTitle;
use App\Models\ContentRequest;
use App\Models\Genre;
use App\Models\Tag;
use App\Models\User;
use App\Models\UserProfile;
use App\Services\Catalog\Search\PortalSearchSuggestionQuery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class PortalSearchSuggestionQueryTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_searches_public_portal_entities_without_leaking_private_or_hidden_records(): void
    {
        $publicTitle = CatalogTitle::factory()->create();
        $hiddenTitle = CatalogTitle::factory()->create([
            'publication_status' => PublicationStatus::Hidden,
        ]);
        $publicGenre = Genre::query()->create([
            'name' => 'Детектив',
            'slug' => 'detektiv',
        ]);
        $hiddenGenre = Genre::query()->create([
            'name' => 'Детектив закрытый',
            'slug' => 'detektiv-zakrytyi',
        ]);
        $publicTitle->genres()->attach($publicGenre);
        $hiddenTitle->genres()->attach($hiddenGenre);

        $publicTag = Tag::query()->create([
            'name' => 'Детективный сюжет',
            'slug' => 'detektivnyi-siuzhet',
        ]);
        $internalTag = Tag::query()->create([
            'name' => 'Детектив внутренний',
            'slug' => 'detektiv-vnutrennii',
            'type' => TagType::HiddenInternal,
            'visibility' => TagVisibility::Internal,
            'moderation_status' => TagModerationStatus::Hidden,
        ]);
        $publicTitle->tags()->attach([$publicTag->id, $internalTag->id]);

        $publicCollection = $this->collection('Детективные вечера', 'detektivnye-vechera', true);
        $privateCollection = $this->collection('Детектив личный', 'detektiv-lichnyi', false);
        $publicRequest = $this->contentRequest('Детектив без перевода', true);
        $privateRequest = $this->contentRequest('Детектив секретный', false);
        $publicProfile = $this->profile('detective_public', 'Детектив Публичный', true);
        $privateProfile = $this->profile('detective_private', 'Детектив Приватный', false);

        DB::enableQueryLog();

        $results = app(PortalSearchSuggestionQuery::class)->search('детектив', 30);

        $this->assertContains('genre', $results->pluck('type')->all());
        $this->assertContains('tag', $results->pluck('type')->all());
        $this->assertContains('collection', $results->pluck('type')->all());
        $this->assertContains('content_request', $results->pluck('type')->all());
        $this->assertContains('profile', $results->pluck('type')->all());
        $this->assertContains(route('titles.taxonomy', ['type' => 'genre', 'taxonomy' => $publicGenre->slug]), $results->pluck('url')->all());
        $this->assertContains(route('titles.taxonomy', ['type' => 'tag', 'taxonomy' => $publicTag->slug]), $results->pluck('url')->all());
        $this->assertContains(route('collections.show', ['collectionSlug' => $publicCollection->slug]), $results->pluck('url')->all());
        $this->assertContains(route('requests.show', $publicRequest), $results->pluck('url')->all());
        $this->assertContains(route('users.show', ['username' => $publicProfile->username]), $results->pluck('url')->all());
        $this->assertNotContains(route('titles.taxonomy', ['type' => 'genre', 'taxonomy' => $hiddenGenre->slug]), $results->pluck('url')->all());
        $this->assertNotContains(route('titles.taxonomy', ['type' => 'tag', 'taxonomy' => $internalTag->slug]), $results->pluck('url')->all());
        $this->assertNotContains(route('collections.show', ['collectionSlug' => $privateCollection->slug]), $results->pluck('url')->all());
        $this->assertNotContains(route('requests.show', $privateRequest), $results->pluck('url')->all());
        $this->assertNotContains(route('users.show', ['username' => $privateProfile->username]), $results->pluck('url')->all());
        $this->assertLessThanOrEqual(
            24,
            count(DB::getQueryLog()),
            'Поиск по всем публичным типам портала должен оставаться ограниченным фиксированным числом запросов.',
        );
    }

    public function test_it_suggests_registered_sections_and_ignores_short_queries(): void
    {
        $this->assertTrue(app(PortalSearchSuggestionQuery::class)->search('к')->isEmpty());

        $results = app(PortalSearchSuggestionQuery::class)->search('каталог', 10);

        $this->assertContains('section', $results->pluck('type')->all());
        $this->assertContains(route('titles.index'), $results->pluck('url')->all());
    }

    private function collection(string $name, string $slug, bool $public): CatalogCollection
    {
        return CatalogCollection::query()->create([
            'public_id' => (string) Str::uuid(),
            'name' => $name,
            'slug' => $slug,
            'type' => CatalogCollectionType::User,
            'visibility' => $public ? CatalogCollectionVisibility::Public : CatalogCollectionVisibility::Private,
            'moderation_status' => CatalogCollectionModerationStatus::Approved,
        ]);
    }

    private function contentRequest(string $title, bool $public): ContentRequest
    {
        $publicId = (string) Str::uuid();

        return ContentRequest::query()->create([
            'public_id' => $publicId,
            'type' => 'serial',
            'title' => $title,
            'normalized_title' => mb_strtolower($title),
            'normalized_title_hash' => hash('sha256', $publicId.'-normalized'),
            'exact_identity_hash' => hash('sha256', $publicId.'-identity'),
            'submission_key' => hash('sha256', $publicId.'-submission'),
            'is_public' => $public,
        ]);
    }

    private function profile(string $username, string $name, bool $public): UserProfile
    {
        $user = User::factory()->create(['name' => $name]);

        return UserProfile::query()->create([
            'user_id' => $user->id,
            'username' => $username,
            'normalized_username' => mb_strtolower($username),
            'profile_visibility' => $public ? UserProfileVisibility::Public : UserProfileVisibility::Private,
        ]);
    }
}
