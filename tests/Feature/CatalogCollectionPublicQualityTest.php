<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\DTOs\CatalogCollectionData;
use App\Enums\CatalogCollectionModerationStatus;
use App\Enums\CatalogCollectionSort;
use App\Enums\CatalogCollectionType;
use App\Enums\CatalogCollectionVisibility;
use App\Models\CatalogCollection;
use App\Models\CatalogCollectionCategory;
use App\Models\CatalogCollectionItem;
use App\Models\CatalogTitle;
use App\Models\LicensedMedia;
use App\Models\User;
use App\Services\Collections\CatalogCollectionItemService;
use App\Services\Collections\CatalogCollectionModerationService;
use App\Services\Collections\CatalogCollectionQuery;
use App\Services\Collections\CatalogCollectionSeoPresenter;
use App\Services\Collections\CatalogCollectionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class CatalogCollectionPublicQualityTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function public_scope_keeps_only_published_categorized_bounded_non_empty_collections(): void
    {
        config(['catalog-collections.maximum_public_items_per_collection' => 2]);
        $activeCategory = $this->category('quality-active');
        $inactiveCategory = $this->category('quality-inactive', active: false);
        $valid = $this->collection('Качественная подборка', $activeCategory);
        $uncategorized = $this->collection('Без категории');
        $empty = $this->collection('Пустая подборка', $activeCategory);
        $inactive = $this->collection('Архивная категория', $inactiveCategory);
        $oversized = $this->collection('Слишком большая подборка', $activeCategory);
        $this->attachTitles($valid, 1);
        $this->attachTitles($uncategorized, 1);
        $this->attachTitles($inactive, 1);
        $this->attachTitles($oversized, 3);

        $listedIds = CatalogCollection::query()
            ->publiclyListed()
            ->orderBy('id')
            ->pluck('id')
            ->all();
        $directory = app(CatalogCollectionQuery::class)->publicDirectory(
            search: 'Качественная',
            sort: 'title',
            category: $activeCategory->slug,
        );

        self::assertSame([$valid->id], $listedIds);
        self::assertSame(
            [$valid->id],
            array_map(
                static fn (CatalogCollection $collection): int => (int) $collection->id,
                $directory->items(),
            ),
        );
        self::assertSame(1, $directory->total());
        self::assertNotContains($uncategorized->id, $listedIds);
        self::assertNotContains($empty->id, $listedIds);
        self::assertNotContains($inactive->id, $listedIds);
        self::assertNotContains($oversized->id, $listedIds);
    }

    #[Test]
    public function api_and_seo_do_not_publish_an_oversized_collection(): void
    {
        config(['catalog-collections.maximum_public_items_per_collection' => 2]);
        $category = $this->category('quality-api');
        $valid = $this->collection('API quality valid', $category);
        $oversized = $this->collection('API quality oversized', $category);
        $this->attachTitles($valid, 1);
        $this->attachTitles($oversized, 3);

        $this->getJson(route('api.v1.collections.show', [
            'collectionSlug' => $valid->slug,
        ]))->assertOk();
        $this->getJson(route('api.v1.collections.show', [
            'collectionSlug' => $oversized->slug,
        ]))->assertNotFound();
        $this->get(route('collections.show', [
            'collectionSlug' => $valid->slug,
        ]))->assertOk();
        $this->get(route('collections.show', [
            'collectionSlug' => $oversized->slug,
        ]))->assertNotFound();

        $summary = app(CatalogCollectionQuery::class)->summary($oversized);
        $seo = app(CatalogCollectionSeoPresenter::class)->collection($summary, null);

        self::assertSame('noindex,nofollow', $seo['robots']);
        self::assertSame([], $seo['jsonLd']);
    }

    #[Test]
    public function moderation_refuses_public_approval_until_quality_requirements_are_met(): void
    {
        config([
            'catalog-collections.maximum_public_items_per_collection' => 2,
            'seasonvar.admin_emails' => ['admin@example.com'],
        ]);
        $admin = User::factory()->create(['email' => 'admin@example.com']);
        $collection = $this->collection(
            'Pending quality',
            $this->category('quality-moderation'),
            moderation: CatalogCollectionModerationStatus::Pending,
            published: false,
        );
        $service = app(CatalogCollectionModerationService::class);

        try {
            $service->moderate($admin, $collection, CatalogCollectionModerationStatus::Approved);
            self::fail('Пустая публичная подборка не должна получать approved state.');
        } catch (ValidationException $exception) {
            self::assertSame(
                [__('collections.errors.public_quality_not_ready', ['count' => 2])],
                $exception->errors()['moderation'],
            );
        }

        self::assertSame(
            CatalogCollectionModerationStatus::Pending,
            $collection->fresh()->moderation_status,
        );
        self::assertNull($collection->fresh()->published_at);

        $collection->forceFill([
            'description' => 'Содержательное описание тематической подборки перед публикацией.',
        ])->save();
        $this->attachTitles($collection, 1);
        $collection->forceFill([
            'content_version' => $collection->content_version + 1,
        ])->save();
        $approved = $service->moderate(
            $admin,
            $collection->fresh(),
            CatalogCollectionModerationStatus::Approved,
        );

        self::assertSame(CatalogCollectionModerationStatus::Approved, $approved->moderation_status);
        self::assertNotNull($approved->published_at);
        self::assertTrue(
            CatalogCollection::query()->publiclyListed()->whereKey($approved->id)->exists(),
        );
    }

    #[Test]
    public function public_membership_cap_does_not_reduce_private_storage_limit(): void
    {
        config([
            'catalog-collections.maximum_items_per_collection' => 3,
            'catalog-collections.maximum_public_items_per_collection' => 2,
        ]);
        $owner = User::factory()->create(['email_verified_at' => now()]);
        $category = $this->category('quality-membership');
        $public = $this->collection('Public membership cap', $category);
        $public->forceFill(['owner_id' => $owner->id])->save();
        $private = $this->collection('Private storage cap', $category);
        $private->forceFill([
            'owner_id' => $owner->id,
            'visibility' => CatalogCollectionVisibility::Private,
            'published_at' => null,
        ])->save();
        $titles = CatalogTitle::factory()->count(3)->create()->values();
        $service = app(CatalogCollectionItemService::class);

        self::assertTrue($service->add($owner, $public, $titles[0]));
        self::assertTrue($service->add($owner, $public, $titles[1]));

        try {
            $service->add($owner, $public, $titles[2]);
            self::fail('Публичная подборка не должна превышать quality cap.');
        } catch (ValidationException $exception) {
            self::assertSame(
                [__('collections.errors.item_limit')],
                $exception->errors()['collection'],
            );
        }

        foreach ($titles as $title) {
            self::assertTrue($service->add($owner, $private, $title));
        }

        self::assertSame(2, $public->items()->count());
        self::assertSame(3, $private->items()->count());
    }

    #[Test]
    public function new_public_editorial_collection_waits_for_explicit_moderation(): void
    {
        config(['seasonvar.admin_emails' => ['admin@example.com']]);
        $editor = User::factory()->create([
            'email' => 'admin@example.com',
            'email_verified_at' => now(),
        ]);
        $category = $this->category('quality-editorial-create');

        $collection = app(CatalogCollectionService::class)->create(
            $editor,
            new CatalogCollectionData(
                name: 'Редакционная подборка на проверке',
                description: 'Проверяется перед публичным показом.',
                visibility: CatalogCollectionVisibility::Public,
                type: CatalogCollectionType::Editorial,
                categoryPublicId: $category->public_id,
            ),
        );

        self::assertSame(CatalogCollectionModerationStatus::Pending, $collection->moderation_status);
        self::assertNull($collection->published_at);
        self::assertFalse(CatalogCollection::query()->publiclyListed()->whereKey($collection->id)->exists());
    }

    private function category(string $slug, bool $active = true): CatalogCollectionCategory
    {
        return CatalogCollectionCategory::query()->create([
            'public_id' => (string) Str::uuid(),
            'slug' => $slug,
            'position' => 100,
            'is_active' => $active,
        ]);
    }

    private function collection(
        string $name,
        ?CatalogCollectionCategory $category = null,
        CatalogCollectionModerationStatus $moderation = CatalogCollectionModerationStatus::Approved,
        bool $published = true,
    ): CatalogCollection {
        return CatalogCollection::query()->create([
            'public_id' => (string) Str::uuid(),
            'catalog_collection_category_id' => $category?->id,
            'name' => $name,
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(8)),
            'type' => CatalogCollectionType::Editorial,
            'visibility' => CatalogCollectionVisibility::Public,
            'moderation_status' => $moderation,
            'sort_mode' => CatalogCollectionSort::Manual,
            'content_locale' => 'ru',
            'is_featured' => false,
            'content_version' => 1,
            'published_at' => $published ? now() : null,
        ]);
    }

    private function attachTitles(CatalogCollection $collection, int $count): void
    {
        CatalogTitle::factory()->count($count)->create()->each(
            function (CatalogTitle $title, int $index) use ($collection): void {
                LicensedMedia::factory()->for($title)->create([
                    'status' => 'published',
                    'published_at' => now(),
                ]);
                CatalogCollectionItem::query()->create([
                    'catalog_collection_id' => $collection->id,
                    'catalog_title_id' => $title->id,
                    'position' => $index + 1,
                ]);
            },
        );
    }
}
