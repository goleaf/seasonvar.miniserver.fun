<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\CatalogCollectionModerationStatus;
use App\Enums\CatalogCollectionVisibility;
use App\Models\CatalogCollection;
use App\Models\CatalogCollectionCategory;
use App\Services\Collections\CatalogCollectionQuery;
use App\View\Components\Collections\CollectionCard;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use ReflectionMethod;
use Tests\TestCase;

final class CatalogCollectionTextPresentationTest extends TestCase
{
    use RefreshDatabase;

    public function test_shared_collection_card_is_text_only_and_exposes_category_context(): void
    {
        $category = CatalogCollectionCategory::query()
            ->where('slug', 'detective-and-crime')
            ->firstOrFail();
        $collection = CatalogCollection::query()->create([
            'public_id' => (string) Str::uuid(),
            'catalog_collection_category_id' => $category->id,
            'name' => 'Текстовая подборка',
            'description' => 'Описание без декоративной обложки.',
            'slug' => 'tekstovaya-podborka-'.Str::lower(Str::random(6)),
            'visibility' => CatalogCollectionVisibility::Public,
            'moderation_status' => CatalogCollectionModerationStatus::Approved,
            'published_at' => now(),
        ]);
        $summary = app(CatalogCollectionQuery::class)->summary($collection);

        $html = Blade::render(
            '<x-collections.collection-card :collection="$collection" />',
            ['collection' => $summary],
        );

        $this->assertStringNotContainsString('<img', $html);
        $this->assertStringNotContainsString('poster-frame', $html);
        $this->assertStringNotContainsString('border-l-4', $html);
        $this->assertStringNotContainsString('legacy-cover.webp', $html);
        $this->assertStringNotContainsString('/cover', $html);
        $this->assertStringContainsString('Темы и жанры', $html);
        $this->assertStringContainsString('Детективы и криминал', $html);
        $this->assertStringContainsString('Текстовая подборка', $html);
        $this->assertStringContainsString('Описание без декоративной обложки.', $html);
    }

    public function test_card_constructor_has_no_cover_service_dependency(): void
    {
        $parameters = collect((new ReflectionMethod(CollectionCard::class, '__construct'))->getParameters());

        $this->assertFalse($parameters->contains(
            fn (\ReflectionParameter $parameter): bool => $parameter->getType()?->__toString()
                === 'App\Services\Collections\CatalogCollectionCoverService',
        ));
    }

    public function test_collection_summaries_do_not_execute_fallback_poster_subqueries(): void
    {
        $collection = CatalogCollection::query()->create([
            'public_id' => (string) Str::uuid(),
            'name' => 'Без poster-subquery',
            'slug' => 'bez-poster-subquery-'.Str::lower(Str::random(6)),
            'visibility' => CatalogCollectionVisibility::Public,
            'moderation_status' => CatalogCollectionModerationStatus::Approved,
            'published_at' => now(),
        ]);
        $sql = [];
        DB::listen(static function (QueryExecuted $query) use (&$sql): void {
            $sql[] = strtolower($query->sql);
        });

        app(CatalogCollectionQuery::class)->summary($collection);

        $this->assertFalse(collect($sql)->contains(
            fn (string $query): bool => str_contains($query, 'fallback_item')
                || str_contains($query, 'poster_url'),
        ));
    }
}
