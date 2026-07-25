<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\ReleaseCalendarFeedScope;
use App\Livewire\ReleaseCalendar\ReleaseCalendarFeedManager;
use App\Models\CatalogCollection;
use App\Models\CatalogTitle;
use App\Models\ReleaseCalendarFeed;
use App\Models\User;
use App\Services\ReleaseCalendar\ReleaseCalendarFeedService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

final class ReleaseCalendarFeedManagerTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_cannot_mount_the_private_feed_manager(): void
    {
        Livewire::test(ReleaseCalendarFeedManager::class, ['locale' => 'ru'])
            ->assertForbidden();
    }

    public function test_owner_can_create_copy_and_open_calendar_subscription_links(): void
    {
        $user = User::factory()->create();

        $component = Livewire::actingAs($user)
            ->test(ReleaseCalendarFeedManager::class, ['locale' => 'ru'])
            ->set('scope', ReleaseCalendarFeedScope::All->value)
            ->call('createFeed')
            ->assertHasNoErrors()
            ->assertSee(__('calendar.feeds.created'))
            ->assertSeeHtml('data-calendar-copy')
            ->assertSeeHtml('data-calendar-google')
            ->assertSee('Добавить в Apple Calendar');

        $feed = ReleaseCalendarFeed::query()->sole();
        $component->assertSee(route('calendar.feed', ['privateToken' => $feed->token_secret]));
    }

    public function test_scope_specific_fields_are_validated_and_resolved_server_side(): void
    {
        $user = User::factory()->create();
        $title = CatalogTitle::factory()->create(['title' => 'Сериал для календаря']);
        $collection = CatalogCollection::query()->create([
            'public_id' => (string) Str::uuid(),
            'owner_id' => $user->id,
            'name' => 'Моя подборка',
            'slug' => 'moya-podborka-kalendaria',
        ]);

        Livewire::actingAs($user)
            ->test(ReleaseCalendarFeedManager::class, ['locale' => 'ru'])
            ->set('scope', ReleaseCalendarFeedScope::Translation->value)
            ->call('selectTitle', $title->id)
            ->set('translationName', ' AniDub ')
            ->set('languageCode', ' RU ')
            ->call('createFeed')
            ->assertHasNoErrors();

        $feed = ReleaseCalendarFeed::query()->sole();
        $this->assertSame($title->id, $feed->catalog_title_id);
        $this->assertSame('AniDub', $feed->translation_name);
        $this->assertSame('ru', $feed->language_code);

        Livewire::actingAs($user)
            ->test(ReleaseCalendarFeedManager::class, ['locale' => 'ru'])
            ->set('scope', ReleaseCalendarFeedScope::Collection->value)
            ->set('collectionPublicId', $collection->public_id)
            ->call('createFeed')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('release_calendar_feeds', [
            'user_id' => $user->id,
            'catalog_collection_id' => $collection->id,
            'scope' => ReleaseCalendarFeedScope::Collection->value,
        ]);
    }

    public function test_owner_can_regenerate_and_delete_a_feed_but_not_manage_another_users_feed(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $service = app(ReleaseCalendarFeedService::class);
        $owned = $service->create($owner, ReleaseCalendarFeedScope::All, 'ru');
        $foreign = $service->create($other, ReleaseCalendarFeedScope::All, 'ru');

        Livewire::actingAs($owner)
            ->test(ReleaseCalendarFeedManager::class, ['locale' => 'ru'])
            ->call('regenerateFeed', $owned->public_id)
            ->assertHasNoErrors()
            ->assertSee(__('calendar.feeds.regenerated'));
        $this->assertSame(2, $owned->refresh()->version);

        Livewire::actingAs($owner)
            ->test(ReleaseCalendarFeedManager::class, ['locale' => 'ru'])
            ->call('deleteFeed', $foreign->public_id)
            ->assertNotFound();
        $this->assertDatabaseHas('release_calendar_feeds', ['id' => $foreign->id]);

        Livewire::actingAs($owner)
            ->test(ReleaseCalendarFeedManager::class, ['locale' => 'ru'])
            ->call('deleteFeed', $owned->public_id)
            ->assertHasNoErrors()
            ->assertSee(__('calendar.feeds.deleted'));
        $this->assertDatabaseMissing('release_calendar_feeds', ['id' => $owned->id]);
    }

    public function test_manager_is_only_rendered_on_the_authenticated_personal_calendar(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('calendar.mine'))
            ->assertOk()
            ->assertSeeHtml('data-calendar-feed-manager');
        $this->get(route('calendar.index'))
            ->assertOk()
            ->assertDontSee('data-calendar-feed-manager', false);
    }
}
