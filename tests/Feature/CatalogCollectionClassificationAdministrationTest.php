<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\AdminAuditAction;
use App\Enums\AdminMembershipStatus;
use App\Enums\AdminRoleCode;
use App\Enums\CatalogCollectionModerationStatus;
use App\Enums\CatalogCollectionVisibility;
use App\Livewire\Collections\CatalogCollectionCategoryManager;
use App\Models\AdminAuditEvent;
use App\Models\AdminRole;
use App\Models\AdminUserRole;
use App\Models\CatalogCollection;
use App\Models\CatalogCollectionCategory;
use App\Models\CatalogCollectionItem;
use App\Models\CatalogTitle;
use App\Models\User;
use App\Services\Collections\CatalogCollectionCategoryQuery;
use App\Services\Collections\CatalogCollectionCategoryService;
use App\Services\Collections\CatalogCollectionClassificationQuery;
use App\Services\Collections\CatalogCollectionQuery;
use App\Support\Cache\CacheDomain;
use App\Support\Cache\CacheVersionRegistry;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

final class CatalogCollectionClassificationAdministrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_confirmation_requires_content_manage_permission(): void
    {
        $this->expectException(AuthorizationException::class);

        app(CatalogCollectionCategoryService::class)->confirmAssignments(
            User::factory()->create(),
            [[
                'collectionPublicId' => (string) Str::uuid(),
                'expectedContentVersion' => 1,
                'categoryPublicId' => (string) Str::uuid(),
            ]],
        );
    }

    public function test_confirmation_rejects_malformed_unknown_and_duplicate_assignments_without_writes(): void
    {
        $admin = $this->administrator();
        $collection = $this->collection('Неизменная');
        $category = CatalogCollectionCategory::query()->where('slug', 'comedy')->firstOrFail();
        $service = app(CatalogCollectionCategoryService::class);
        $invalidBatches = [
            [],
            array_fill(0, 101, [
                'collectionPublicId' => (string) Str::uuid(),
                'expectedContentVersion' => 1,
                'categoryPublicId' => $category->public_id,
            ]),
            [[
                'collectionPublicId' => 'not-a-uuid',
                'expectedContentVersion' => 1,
                'categoryPublicId' => $category->public_id,
            ]],
            [
                [
                    'collectionPublicId' => $collection->public_id,
                    'expectedContentVersion' => 1,
                    'categoryPublicId' => $category->public_id,
                ],
                [
                    'collectionPublicId' => $collection->public_id,
                    'expectedContentVersion' => 1,
                    'categoryPublicId' => $category->public_id,
                ],
            ],
            [[
                'collectionPublicId' => (string) Str::uuid(),
                'expectedContentVersion' => 1,
                'categoryPublicId' => $category->public_id,
            ]],
            [[
                'collectionPublicId' => $collection->public_id,
                'expectedContentVersion' => 1,
                'categoryPublicId' => (string) Str::uuid(),
            ]],
        ];

        foreach ($invalidBatches as $assignments) {
            try {
                $service->confirmAssignments($admin, $assignments);
                $this->fail('Ожидалась ошибка пакета классификации.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('assignments', $exception->errors());
            }
        }

        $this->assertNull($collection->refresh()->catalog_collection_category_id);
        $this->assertSame(1, $collection->content_version);
        $this->assertSame(0, AdminAuditEvent::query()->count());
    }

    public function test_confirmation_accepts_the_exact_assignment_shape_independent_of_key_order(): void
    {
        $admin = $this->administrator();
        $collection = $this->collection('Порядок полей');
        $category = CatalogCollectionCategory::query()->where('slug', 'comedy')->firstOrFail();

        $result = app(CatalogCollectionCategoryService::class)->confirmAssignments(
            $admin,
            [[
                'categoryPublicId' => $category->public_id,
                'collectionPublicId' => $collection->public_id,
                'expectedContentVersion' => 1,
            ]],
        );

        $this->assertSame(1, $result->changed);
        $this->assertSame($category->id, $collection->refresh()->catalog_collection_category_id);
    }

    public function test_confirmation_changes_only_current_uncategorized_rows_and_audits_each_category(): void
    {
        $admin = $this->administrator();
        $owner = User::factory()->create();
        $target = CatalogCollectionCategory::query()->where('slug', 'mini-series')->firstOrFail();
        $other = CatalogCollectionCategory::query()->where('slug', 'comedy')->firstOrFail();
        $current = $this->collection('Актуальная', [
            'owner_id' => $owner->id,
            'visibility' => CatalogCollectionVisibility::Unlisted,
            'content_version' => 7,
        ]);
        $stale = $this->collection('Устаревшая', ['content_version' => 4]);
        $assigned = $this->collection('Уже назначенная', [
            'catalog_collection_category_id' => $other->id,
            'content_version' => 2,
        ]);

        $result = app(CatalogCollectionCategoryService::class)->confirmAssignments(
            $admin,
            [
                [
                    'collectionPublicId' => $current->public_id,
                    'expectedContentVersion' => 7,
                    'categoryPublicId' => $target->public_id,
                ],
                [
                    'collectionPublicId' => $stale->public_id,
                    'expectedContentVersion' => 3,
                    'categoryPublicId' => $target->public_id,
                ],
                [
                    'collectionPublicId' => $assigned->public_id,
                    'expectedContentVersion' => 2,
                    'categoryPublicId' => $target->public_id,
                ],
            ],
        );

        $current->refresh();
        $stale->refresh();
        $assigned->refresh();

        $this->assertSame(1, $result->changed);
        $this->assertSame(2, $result->skipped);
        $this->assertSame([$current->id], $result->changedCollectionIds);
        $this->assertSame($target->id, $current->catalog_collection_category_id);
        $this->assertSame(8, $current->content_version);
        $this->assertSame($owner->id, $current->owner_id);
        $this->assertSame(CatalogCollectionVisibility::Unlisted, $current->visibility);
        $this->assertSame(CatalogCollectionModerationStatus::Approved, $current->moderation_status);
        $this->assertNull($stale->catalog_collection_category_id);
        $this->assertSame($other->id, $assigned->catalog_collection_category_id);
        $this->assertDatabaseHas('admin_audit_events', [
            'action' => AdminAuditAction::CollectionCategoryAssignmentsConfirmed->value,
            'resource_type' => 'catalog_collection_category',
            'resource_id' => $target->id,
        ]);
    }

    public function test_inactive_category_rejects_the_entire_confirmation_batch(): void
    {
        config(['cache-architecture.stores.versions' => 'array']);
        $admin = $this->administrator();
        $active = CatalogCollectionCategory::query()->where('slug', 'comedy')->firstOrFail();
        $inactive = CatalogCollectionCategory::query()->where('slug', 'history')->firstOrFail();
        $inactive->forceFill(['is_active' => false])->save();
        $first = $this->collection('Первая атомарная');
        $second = $this->collection('Вторая атомарная');
        $versions = app(CacheVersionRegistry::class);
        $collectionsVersion = $versions->version(CacheDomain::Collections);

        try {
            app(CatalogCollectionCategoryService::class)->confirmAssignments(
                $admin,
                [
                    [
                        'collectionPublicId' => $first->public_id,
                        'expectedContentVersion' => 1,
                        'categoryPublicId' => $active->public_id,
                    ],
                    [
                        'collectionPublicId' => $second->public_id,
                        'expectedContentVersion' => 1,
                        'categoryPublicId' => $inactive->public_id,
                    ],
                ],
            );
            $this->fail('Ожидалась ошибка архивной категории.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('assignments', $exception->errors());
        }

        $this->assertSame(
            0,
            CatalogCollection::query()
                ->whereKey([$first->id, $second->id])
                ->whereNotNull('catalog_collection_category_id')
                ->count(),
        );
        $this->assertSame(0, AdminAuditEvent::query()->count());
        $this->assertSame(
            $collectionsVersion,
            $versions->version(CacheDomain::Collections),
        );
    }

    public function test_confirmed_assignment_is_immediately_reflected_in_public_directory_counts_and_cache_version(): void
    {
        config(['cache-architecture.stores.versions' => 'array']);
        $admin = $this->administrator();
        $category = CatalogCollectionCategory::query()->where('slug', 'netflix')->firstOrFail();
        $root = CatalogCollectionCategory::query()->findOrFail($category->parent_id);
        $collection = $this->collection('Публичная Netflix');
        CatalogCollectionItem::query()->create([
            'catalog_collection_id' => $collection->id,
            'catalog_title_id' => CatalogTitle::factory()->create()->id,
            'position' => 1,
        ]);
        $categories = app(CatalogCollectionCategoryQuery::class);
        $beforeTree = $categories->publicDirectoryTree();
        $versions = app(CacheVersionRegistry::class);
        $collectionsVersion = $versions->version(CacheDomain::Collections);

        app(CatalogCollectionCategoryService::class)->confirmAssignments(
            $admin,
            [[
                'collectionPublicId' => $collection->public_id,
                'expectedContentVersion' => 1,
                'categoryPublicId' => $category->public_id,
            ]],
        );

        $directory = app(CatalogCollectionQuery::class)->publicDirectory(
            category: $root->slug,
            subcategory: $category->slug,
        );
        $afterTree = $categories->publicDirectoryTree();
        $afterCategory = $afterTree['tree']
            ->firstWhere('id', $root->id)
            ?->children
            ->firstWhere('id', $category->id);

        $this->assertSame(0, $beforeTree['uncategorized']);
        $this->assertSame(0, $afterTree['uncategorized']);
        $this->assertSame(1, $afterTree['total']);
        $this->assertSame(1, $afterCategory?->public_collections_count);
        $this->assertTrue($directory->contains('public_id', $collection->public_id));
        $this->assertGreaterThan(
            $collectionsVersion,
            $versions->version(CacheDomain::Collections),
        );
    }

    public function test_only_content_manager_receives_classification_queue_and_summary(): void
    {
        $this->collection('Очередь Netflix');
        $manager = $this->administrator();
        $moderator = $this->administrator(AdminRoleCode::Moderator);

        Livewire::actingAs($manager)
            ->test(CatalogCollectionCategoryManager::class)
            ->assertViewHas('classificationSummary', fn ($summary): bool => $summary->uncategorized === 1)
            ->assertViewHas('classificationPage', fn ($page): bool => $page->total() === 1);

        Livewire::actingAs($moderator)
            ->test(CatalogCollectionCategoryManager::class)
            ->assertViewHas('classificationSummary', null)
            ->assertViewHas('classificationPage', null);
    }

    public function test_classification_queue_defaults_to_public_approved_and_moderation_is_filterable(): void
    {
        $admin = $this->administrator();
        $approved = $this->collection('Одобренная очередь Netflix', [
            'description' => 'Оригинальные проекты Netflix',
        ]);
        $pending = $this->collection('Ожидает модерации', [
            'moderation_status' => CatalogCollectionModerationStatus::Pending,
        ]);
        $private = $this->collection('Личная очередь', [
            'visibility' => CatalogCollectionVisibility::Private,
        ]);

        $component = Livewire::actingAs($admin)
            ->test(CatalogCollectionCategoryManager::class)
            ->assertSet('classificationVisibility', CatalogCollectionVisibility::Public->value)
            ->assertSet('classificationModerationStatus', CatalogCollectionModerationStatus::Approved->value)
            ->assertViewHas('classificationPage', fn ($page): bool => $page->pluck('public_id')->all() === [$approved->public_id])
            ->assertSeeText($approved->name)
            ->assertDontSeeText($pending->name)
            ->assertDontSeeText($private->name)
            ->assertSeeHtml('id="classification-moderation"')
            ->call('selectHighConfidence')
            ->call('prepareClassificationPreview')
            ->assertSet('classificationPreviewOpen', true)
            ->set('classificationModerationStatus', '')
            ->assertSet('classificationPreviewOpen', false)
            ->assertViewHas('classificationPage', fn ($page): bool => $page->total() === 2);

        $component
            ->set('paginators.collectionCategoryClassificationPage', 2)
            ->set('classificationModerationStatus', CatalogCollectionModerationStatus::Approved->value)
            ->assertSet('paginators.collectionCategoryClassificationPage', 1)
            ->set('classificationModerationStatus', 'forged')
            ->assertSet('classificationModerationStatus', CatalogCollectionModerationStatus::Approved->value);
    }

    public function test_classification_filter_resets_its_named_page_and_clears_preview_state(): void
    {
        $admin = $this->administrator();
        $this->collection('Фильтр Netflix', ['description' => 'Оригинальные проекты Netflix']);

        Livewire::actingAs($admin)
            ->test(CatalogCollectionCategoryManager::class)
            ->call('selectHighConfidence')
            ->call('prepareClassificationPreview')
            ->assertSet('classificationPreviewOpen', true)
            ->set('paginators.collectionCategoryClassificationPage', 2)
            ->set('classificationSearch', '  Netflix  ')
            ->assertSet('classificationSearch', 'Netflix')
            ->assertSet('classificationPreviewOpen', false)
            ->assertSet('selectedClassificationPublicIds', [])
            ->assertSet('paginators.collectionCategoryClassificationPage', 1);
    }

    public function test_high_confidence_selection_and_preview_do_not_write_to_database(): void
    {
        $admin = $this->administrator();
        $collection = $this->collection('Лучшие сериалы Netflix', [
            'description' => 'Оригинальные проекты Netflix',
        ]);

        Livewire::actingAs($admin)
            ->test(CatalogCollectionCategoryManager::class)
            ->call('selectHighConfidence')
            ->assertSet('selectedClassificationPublicIds', [$collection->public_id])
            ->assertSet('classificationPreviewOpen', false)
            ->call('prepareClassificationPreview')
            ->assertSet('classificationPreviewOpen', true);

        $this->assertNull($collection->refresh()->catalog_collection_category_id);
        $this->assertSame(1, $collection->content_version);
    }

    public function test_page_selection_and_clear_are_bounded_to_the_current_queue_without_writes(): void
    {
        $admin = $this->administrator();
        $first = $this->collection('Первая строка страницы');
        $second = $this->collection('Вторая строка страницы');
        $excluded = $this->collection('Исключённая личная строка', [
            'visibility' => CatalogCollectionVisibility::Private,
        ]);
        $category = CatalogCollectionCategory::query()->where('slug', 'comedy')->firstOrFail();

        Livewire::actingAs($admin)
            ->test(CatalogCollectionCategoryManager::class)
            ->call('selectCurrentClassificationPage')
            ->assertSet('selectedClassificationPublicIds', [
                $second->public_id,
                $first->public_id,
            ])
            ->set('classificationCategoryByCollection.'.$first->public_id, $category->public_id)
            ->set('classificationBatchCategoryPublicId', $category->public_id)
            ->call('clearClassificationSelection')
            ->assertSet('selectedClassificationPublicIds', [])
            ->assertSet('classificationCategoryByCollection', [])
            ->assertSet('classificationBatchCategoryPublicId', '')
            ->assertSet('classificationPreviewOpen', false);

        $this->assertNull($first->refresh()->catalog_collection_category_id);
        $this->assertNull($second->refresh()->catalog_collection_category_id);
        $this->assertNull($excluded->refresh()->catalog_collection_category_id);
    }

    public function test_suggestion_and_manual_category_staging_are_current_page_only_and_write_nothing(): void
    {
        $admin = $this->administrator();
        $collection = $this->collection('Лучшие сериалы Netflix', [
            'description' => 'Оригинальные проекты Netflix',
        ]);
        $foreign = $this->collection('Личная строка вне очереди', [
            'visibility' => CatalogCollectionVisibility::Private,
        ]);
        $netflix = CatalogCollectionCategory::query()->where('slug', 'netflix')->firstOrFail();
        $comedy = CatalogCollectionCategory::query()->where('slug', 'comedy')->firstOrFail();

        $component = Livewire::actingAs($admin)
            ->test(CatalogCollectionCategoryManager::class)
            ->call('stageClassificationSuggestion', $collection->public_id)
            ->assertSet('selectedClassificationPublicIds', [$collection->public_id])
            ->assertSet(
                'classificationCategoryByCollection.'.$collection->public_id,
                $netflix->public_id,
            )
            ->call('stageClassificationSuggestion', $foreign->public_id)
            ->assertHasErrors('classificationSuggestion')
            ->assertSet('selectedClassificationPublicIds', [$collection->public_id]);

        $component
            ->set('classificationCategoryByCollection.'.$collection->public_id, $comedy->public_id)
            ->assertSet('selectedClassificationPublicIds', [$collection->public_id])
            ->set('classificationCategoryByCollection.'.$collection->public_id, '')
            ->assertSet('selectedClassificationPublicIds', []);

        $this->assertNull($collection->refresh()->catalog_collection_category_id);
        $this->assertNull($foreign->refresh()->catalog_collection_category_id);
    }

    public function test_batch_category_staging_accepts_only_selected_current_page_rows(): void
    {
        $admin = $this->administrator();
        $first = $this->collection('Первая пакетная строка');
        $second = $this->collection('Вторая пакетная строка');
        $foreign = $this->collection('Личная внедрённая строка', [
            'visibility' => CatalogCollectionVisibility::Private,
        ]);
        $category = CatalogCollectionCategory::query()->where('slug', 'comedy')->firstOrFail();

        $component = Livewire::actingAs($admin)
            ->test(CatalogCollectionCategoryManager::class)
            ->set('selectedClassificationPublicIds', [
                $first->public_id,
                $second->public_id,
                $foreign->public_id,
            ])
            ->set('classificationBatchCategoryPublicId', $category->public_id)
            ->call('applyClassificationBatchCategory')
            ->assertSet('selectedClassificationPublicIds', [
                $first->public_id,
                $second->public_id,
            ])
            ->assertSet('classificationCategoryByCollection', [
                $first->public_id => $category->public_id,
                $second->public_id => $category->public_id,
            ]);

        $component
            ->call('clearClassificationSelection')
            ->set('classificationBatchCategoryPublicId', $category->public_id)
            ->call('applyClassificationBatchCategory')
            ->assertHasErrors('selectedClassificationPublicIds')
            ->set('selectedClassificationPublicIds', [$first->public_id])
            ->set('classificationBatchCategoryPublicId', (string) Str::uuid())
            ->call('applyClassificationBatchCategory')
            ->assertHasErrors('classificationBatchCategoryPublicId');

        $this->assertNull($first->refresh()->catalog_collection_category_id);
        $this->assertNull($second->refresh()->catalog_collection_category_id);
        $this->assertNull($foreign->refresh()->catalog_collection_category_id);
    }

    public function test_request_scoped_classification_page_is_reused_between_action_and_render(): void
    {
        $admin = $this->administrator();
        $this->collection('Лучшие сериалы Netflix', [
            'description' => 'Оригинальные проекты Netflix',
        ]);
        $component = Livewire::actingAs($admin)
            ->test(CatalogCollectionCategoryManager::class);
        $pageQueries = [];

        DB::listen(static function (QueryExecuted $query) use (&$pageQueries): void {
            $sql = str($query->sql)
                ->replace(['`', '"'], '')
                ->lower()
                ->squish()
                ->toString();

            if (str_contains($sql, 'from catalog_collections')
                && str_contains($sql, 'catalog_collection_category_id is null')
                && str_contains($sql, 'order by updated_at desc, id desc')
                && str_contains($sql, ' limit ')) {
                $pageQueries[] = $sql;
            }
        });

        $component->call('selectHighConfidence');

        $this->assertCount(1, $pageQueries, implode("\n", $pageQueries));
    }

    public function test_confidence_order_is_limited_to_the_current_database_page(): void
    {
        $admin = $this->administrator();
        $high = $this->collection('Лучшие сериалы Netflix', [
            'description' => 'Оригинальные проекты Netflix',
        ]);
        $low = $this->collection('Фантастика');
        $none = $this->collection('Нейтральный редакционный список');
        $queryPage = app(CatalogCollectionClassificationQuery::class)
            ->paginateUncategorized(
                visibility: CatalogCollectionVisibility::Public->value,
                moderationStatus: CatalogCollectionModerationStatus::Approved->value,
            );

        $this->assertSame(
            [$none->public_id, $low->public_id, $high->public_id],
            $queryPage->pluck('public_id')->all(),
        );

        Livewire::actingAs($admin)
            ->test(CatalogCollectionCategoryManager::class)
            ->assertViewHas(
                'classificationPage',
                fn ($page): bool => $page->pluck('public_id')->all() === [
                    $high->public_id,
                    $low->public_id,
                    $none->public_id,
                ],
            )
            ->call('selectCurrentClassificationPage')
            ->assertSet('selectedClassificationPublicIds', [
                $high->public_id,
                $low->public_id,
                $none->public_id,
            ]);
    }

    public function test_final_confirmation_applies_reviewed_rows_and_skips_a_stale_row(): void
    {
        $admin = $this->administrator();
        $netflixCategory = CatalogCollectionCategory::query()->where('slug', 'netflix')->firstOrFail();
        $manualCategory = CatalogCollectionCategory::query()->where('slug', 'comedy')->firstOrFail();
        $suggested = $this->collection('Сериалы Netflix');
        $manual = $this->collection('Ручной выбор редактора');
        $component = Livewire::actingAs($admin)
            ->test(CatalogCollectionCategoryManager::class)
            ->set('selectedClassificationPublicIds', [
                $suggested->public_id,
                $manual->public_id,
            ])
            ->set('classificationCategoryByCollection', [
                $suggested->public_id => $netflixCategory->public_id,
                $manual->public_id => $manualCategory->public_id,
            ])
            ->call('prepareClassificationPreview')
            ->assertSet('classificationPreviewOpen', true);

        CatalogCollection::query()
            ->whereKey($manual->id)
            ->update(['content_version' => 2]);

        $component
            ->call('confirmClassificationAssignments')
            ->assertSet('classificationPreviewOpen', false)
            ->assertSet('selectedClassificationPublicIds', [])
            ->assertSeeText(__('collections.classification.confirmed', [
                'changed' => 1,
                'skipped' => 1,
            ]));

        $this->assertSame(
            $netflixCategory->id,
            $suggested->refresh()->catalog_collection_category_id,
        );
        $this->assertNull($manual->refresh()->catalog_collection_category_id);
    }

    public function test_manager_renders_responsive_text_only_classification_controls_and_preview(): void
    {
        $admin = $this->administrator();
        $collection = $this->collection('Интерфейс Netflix', [
            'description' => 'Оригинальные проекты Netflix',
        ]);

        $component = Livewire::actingAs($admin)
            ->test(CatalogCollectionCategoryManager::class)
            ->assertSeeHtml('data-collection-classification')
            ->assertSeeHtml('data-classification-summary')
            ->assertSeeHtml('data-classification-row')
            ->assertSeeHtml('id="classification-search"')
            ->assertSeeHtml('id="classification-visibility"')
            ->assertSeeHtml('id="classification-type"')
            ->assertSeeHtml('id="classification-per-page"')
            ->assertSeeHtml('id="classification-batch-category"')
            ->assertSeeText(__('collections.classification.select_current_page'))
            ->assertSeeText(__('collections.classification.accept_suggestion'))
            ->assertSeeText(__('collections.classification.apply_to_selected'))
            ->assertSeeHtml('min-h-11')
            ->assertSeeHtml('data-category-create-disclosure')
            ->assertSeeHtml('data-category-root-disclosure')
            ->assertSeeHtml('<summary')
            ->assertDontSeeHtml('x-data')
            ->assertDontSeeHtml('<script')
            ->assertDontSeeHtml('<style')
            ->assertDontSeeHtml('<img')
            ->assertDontSeeHtml('poster');
        $this->assertSame(
            5,
            substr_count($component->html(), 'data-category-root-disclosure'),
        );

        $template = file_get_contents(resource_path(
            'views/livewire/collections/catalog-collection-category-manager.blade.php',
        ));

        $this->assertIsString($template);
        $this->assertSame(
            1,
            substr_count(
                $template,
                "@island(name: 'collection-classification-pagination'",
            ),
        );
        $this->assertLessThan(
            strpos($template, 'data-collection-classification'),
            strpos(
                $template,
                "@island(name: 'collection-classification-pagination'",
            ),
        );
        $this->assertGreaterThan(
            strpos($template, 'data-category-root-disclosure'),
            strpos($template, '@endisland'),
        );

        $component
            ->call('selectHighConfidence')
            ->call('prepareClassificationPreview')
            ->assertSet('classificationPreviewOpen', true)
            ->assertSeeHtml('data-classification-preview')
            ->assertSeeHtml('data-classification-confirm')
            ->assertSeeText($collection->name)
            ->assertSeeText(__('collections.classification.cancel_preview'))
            ->assertSeeText(__('collections.classification.confirm_assignments'));

        Livewire::actingAs($this->administrator(AdminRoleCode::Moderator))
            ->test(CatalogCollectionCategoryManager::class)
            ->assertDontSeeHtml('data-collection-classification')
            ->assertDontSeeHtml('data-category-create-disclosure');
    }

    private function administrator(
        AdminRoleCode $roleCode = AdminRoleCode::ContentManager,
    ): User {
        $user = User::factory()->create();
        AdminUserRole::query()->create([
            'user_id' => $user->id,
            'admin_role_id' => AdminRole::query()
                ->where('code', $roleCode)
                ->valueOrFail('id'),
            'status' => AdminMembershipStatus::Active,
            'reason_code' => 'collection_classification_test',
            'assigned_at' => now(),
        ]);

        return $user;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function collection(string $name, array $attributes = []): CatalogCollection
    {
        return CatalogCollection::query()->create([
            'public_id' => (string) Str::uuid(),
            'name' => $name,
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(6)),
            'visibility' => CatalogCollectionVisibility::Public,
            'moderation_status' => CatalogCollectionModerationStatus::Approved,
            'content_version' => 1,
            'published_at' => now(),
            ...$attributes,
        ]);
    }
}
