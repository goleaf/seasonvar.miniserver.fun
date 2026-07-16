<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\CatalogCollectionModerationStatus;
use App\Enums\CatalogCollectionType;
use App\Enums\CatalogCollectionVisibility;
use App\Livewire\Collections\CatalogCollectionDashboard;
use App\Livewire\Collections\CatalogCollectionEditor;
use App\Livewire\Tags\PersonalTagManager;
use App\Livewire\Tags\TagAdministrationManager;
use App\Models\CatalogCollection;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

final class RussianOnlyAuthoringTest extends TestCase
{
    use RefreshDatabase;

    public function test_personal_tag_editor_has_no_language_control_and_saves_russian_content(): void
    {
        $user = User::factory()->create();

        Livewire::actingAs($user)
            ->test(PersonalTagManager::class)
            ->assertDontSeeHtml('id="personal-tag-language"')
            ->set('name', 'Любимые детективы')
            ->set('description', 'Смотреть вечером')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('user_tags', [
            'user_id' => $user->id,
            'name' => 'Любимые детективы',
            'content_locale' => 'ru',
        ]);
    }

    public function test_editorial_collection_edits_only_russian_without_a_language_switch(): void
    {
        $admin = $this->admin();
        $collection = CatalogCollection::query()->create([
            'public_id' => (string) Str::uuid(),
            'owner_id' => $admin->id,
            'name' => 'Русская подборка',
            'slug' => 'russkaia-podborka',
            'type' => CatalogCollectionType::Editorial,
            'visibility' => CatalogCollectionVisibility::Private,
            'moderation_status' => CatalogCollectionModerationStatus::Approved,
            'content_locale' => 'en',
        ]);
        $collection->translations()->createMany([
            ['locale' => 'ru', 'name' => 'Русская подборка'],
            ['locale' => 'en', 'name' => 'English collection'],
        ]);

        Livewire::actingAs($admin)
            ->test(CatalogCollectionEditor::class, ['collectionPublicId' => $collection->public_id])
            ->assertSet('contentLocale', 'ru')
            ->assertDontSeeHtml('wire:click="selectEditorialLocale')
            ->assertDontSeeText('Язык перевода')
            ->set('name', 'Обновлённая подборка')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('catalog_collection_translations', [
            'catalog_collection_id' => $collection->id,
            'locale' => 'ru',
            'name' => 'Обновлённая подборка',
        ]);
        $this->assertDatabaseHas('catalog_collection_translations', [
            'catalog_collection_id' => $collection->id,
            'locale' => 'en',
            'name' => 'English collection',
        ]);
    }

    public function test_new_editorial_collection_is_created_in_russian_regardless_of_interface_locale(): void
    {
        $admin = $this->admin();
        app()->setLocale('en');

        Livewire::actingAs($admin)
            ->test(CatalogCollectionDashboard::class)
            ->set('name', 'Русская редакционная подборка')
            ->set('type', CatalogCollectionType::Editorial->value)
            ->set('visibility', CatalogCollectionVisibility::Private->value)
            ->call('create')
            ->assertHasNoErrors();

        $collection = CatalogCollection::query()->where('name', 'Русская редакционная подборка')->sole();

        $this->assertSame('ru', $collection->content_locale);
        $this->assertDatabaseHas('catalog_collection_translations', [
            'catalog_collection_id' => $collection->id,
            'locale' => 'ru',
            'name' => 'Русская редакционная подборка',
        ]);
    }

    public function test_tag_administration_edits_russian_translation_and_aliases_without_language_controls(): void
    {
        $admin = $this->admin();
        $tag = Tag::query()->create([
            'name' => 'Детектив',
            'slug' => 'detektiv',
        ]);
        $tag->translations()->createMany([
            ['locale' => 'ru', 'label' => 'Детектив'],
            ['locale' => 'en', 'label' => 'Detective'],
        ]);

        $component = Livewire::actingAs($admin)
            ->test(TagAdministrationManager::class)
            ->call('selectTag', $tag->public_id)
            ->assertDontSeeHtml('admin-tag-translation-en')
            ->assertDontSeeHtml('id="admin-tag-alias-locale"')
            ->assertDontSeeText('Русский')
            ->set('translationForms.ru.label', 'Русский детектив')
            ->call('saveTranslation')
            ->assertHasNoErrors()
            ->set('aliasName', 'Сыщик')
            ->call('addAlias')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('tag_translations', [
            'tag_id' => $tag->id,
            'locale' => 'ru',
            'label' => 'Русский детектив',
        ]);
        $this->assertDatabaseHas('tag_translations', [
            'tag_id' => $tag->id,
            'locale' => 'en',
            'label' => 'Detective',
        ]);
        $this->assertDatabaseHas('tag_aliases', [
            'tag_id' => $tag->id,
            'locale' => 'ru',
            'name' => 'Сыщик',
        ]);
    }

    private function admin(): User
    {
        config(['seasonvar.admin_emails' => ['admin@example.com']]);

        return User::factory()->create(['email' => 'admin@example.com']);
    }
}
