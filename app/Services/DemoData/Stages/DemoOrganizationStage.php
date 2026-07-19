<?php

declare(strict_types=1);

namespace App\Services\DemoData\Stages;

use App\Contracts\DemoDataStage;
use App\DTOs\DemoData\DemoDataOptions;
use App\DTOs\DemoData\DemoStageReport;
use App\Enums\CatalogCollectionModerationStatus;
use App\Enums\CatalogCollectionSort;
use App\Enums\CatalogCollectionType;
use App\Enums\CatalogCollectionVisibility;
use App\Enums\TagModerationStatus;
use App\Enums\TagSource;
use App\Enums\TagType;
use App\Enums\TagVisibility;
use App\Models\CatalogCollection;
use App\Models\CatalogCollectionItem;
use App\Models\Tag;
use App\Models\TagTranslation;
use App\Models\User;
use App\Models\UserTag;
use App\Services\DemoData\DemoBulkWriter;
use App\Services\DemoData\DemoPersonaFactory;
use App\Services\DemoData\DemoRasterAsset;
use App\Services\DemoData\DemoRussianText;
use App\Services\DemoData\DemoStableValue;
use App\Services\DemoData\DemoTitleSelector;
use App\Services\Tags\TagNormalizationService;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Support\Collection;

final readonly class DemoOrganizationStage implements DemoDataStage
{
    public function __construct(
        private DemoStableValue $stable,
        private DemoPersonaFactory $personas,
        private DemoRussianText $text,
        private TagNormalizationService $normalizer,
    ) {}

    public function key(): string
    {
        return 'organization';
    }

    public function run(DemoDataOptions $options, ?Closure $progress = null): DemoStageReport
    {
        $options->assertEnvironment(app()->environment());

        return $this->repairKnownDemoUsers($options, $progress);
    }

    public function repairKnownDemoUsers(DemoDataOptions $options, ?Closure $progress = null): DemoStageReport
    {
        $startedAt = microtime(true);
        $selector = new DemoTitleSelector($options);
        $writer = new DemoBulkWriter($options);
        $assets = new DemoRasterAsset($options, $this->stable);
        $users = $this->users($options);
        $personalTagCount = 0;
        $personalAssignmentCount = 0;
        $collectionCount = 0;
        $collectionItemCount = 0;

        foreach ($users as $userIndex => $user) {
            $persona = $this->personas->make($userIndex);
            $createdAt = $this->createdAt($userIndex);
            $updatedAt = $createdAt->addDays(180);
            $tagRows = $this->personalTagRows($user, $userIndex, $options, $createdAt, $updatedAt);
            $writer->upsert(
                (new UserTag)->getTable(),
                $tagRows,
                ['user_id', 'normalized_name_hash'],
                $this->updates($tagRows, ['user_id', 'normalized_name_hash', 'created_at']),
            );
            $personalTags = UserTag::query()
                ->where('user_id', $user->id)
                ->whereIn('normalized_name_hash', array_column($tagRows, 'normalized_name_hash'))
                ->orderBy('id')
                ->get(['id', 'normalized_name_hash']);
            $collectionRows = $this->collectionRows(
                $user,
                $userIndex,
                $options,
                $assets,
                $createdAt,
                $updatedAt,
            );
            $writer->upsert(
                (new CatalogCollection)->getTable(),
                $collectionRows,
                ['public_id'],
                $this->updates($collectionRows, ['public_id', 'created_at']),
            );
            $collections = CatalogCollection::query()
                ->whereIn('public_id', array_column($collectionRows, 'public_id'))
                ->orderBy('id')
                ->get(['id', 'public_id']);

            $personalTagCount += count($tagRows);
            $collectionCount += count($collectionRows);

            foreach ($selector->selectedIds($userIndex)->chunk($options->chunkSize) as $titleIds) {
                $ids = $titleIds->values()->all();
                $personalRows = $this->personalAssignmentRows(
                    $userIndex,
                    $ids,
                    $personalTags,
                    $options,
                    $createdAt,
                    $updatedAt,
                );
                $collectionItemRows = $this->collectionItemRows(
                    $user,
                    $userIndex,
                    $ids,
                    $collections,
                    $options,
                    $createdAt,
                    $updatedAt,
                );
                $writer->upsert(
                    'catalog_title_user_tag',
                    $personalRows,
                    ['user_tag_id', 'catalog_title_id'],
                    ['position', 'updated_at'],
                );
                $writer->upsert(
                    (new CatalogCollectionItem)->getTable(),
                    $collectionItemRows,
                    ['catalog_collection_id', 'catalog_title_id'],
                    ['added_by_id', 'position', 'updated_at'],
                );
                $personalAssignmentCount += count($personalRows);
                $collectionItemCount += count($collectionItemRows);
            }

            $progress?->__invoke($this->key(), $userIndex, $options->userCount);
        }

        $publicTagIds = $this->publicTagIds($options, $writer);
        $publicAssignmentCount = $this->assignPublicTags($options, $selector, $writer, $publicTagIds);

        return new DemoStageReport($this->key(), [
            'personal_tags' => $personalTagCount,
            'personal_assignments' => $personalAssignmentCount,
            'collections' => $collectionCount,
            'collection_items' => $collectionItemCount,
            'public_tags' => count($publicTagIds),
            'public_assignments' => $publicAssignmentCount,
        ], microtime(true) - $startedAt);
    }

    /** @return Collection<int, User> */
    private function users(DemoDataOptions $options): Collection
    {
        $emails = collect(range(1, $options->userCount))
            ->mapWithKeys(fn (int $index): array => ["user{$index}@example.com" => $index]);
        $usersByEmail = User::query()
            ->whereIn('email', $emails->keys())
            ->get()
            ->keyBy('email');

        return $emails->mapWithKeys(function (int $index, string $email) use ($usersByEmail): array {
            /** @var User $user */
            $user = $usersByEmail->get($email) ?? throw new \LogicException("Demo user {$email} is missing.");

            return [$index => $user];
        });
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function personalTagRows(
        User $user,
        int $userIndex,
        DemoDataOptions $options,
        CarbonImmutable $createdAt,
        CarbonImmutable $updatedAt,
    ): array {
        $persona = $this->personas->make($userIndex);
        $count = $this->stable->integer(
            "organization:user:{$userIndex}:personal-tag-count",
            $options->personalTagMinimum,
            $options->personalTagMaximum,
        );
        $rows = [];

        for ($ordinal = 0; $ordinal < $count; $ordinal++) {
            $name = $this->normalizer->display($this->text->personalTag($persona, $ordinal));
            $normalized = $this->normalizer->comparison($name);
            $collectionText = $this->text->collection($persona, $ordinal % 20);
            $rows[] = [
                'public_id' => $this->stable->uuid("organization:user:{$userIndex}:personal-tag:{$ordinal}"),
                'user_id' => $user->id,
                'name' => $name,
                'normalized_name' => $normalized,
                'normalized_name_hash' => $this->normalizer->hash($name),
                'description' => 'Личная метка для удобной навигации. '.$collectionText['description'],
                'content_locale' => 'ru',
                'content_version' => 1,
                'deleted_at' => null,
                'created_at' => $createdAt->addMinutes($ordinal),
                'updated_at' => $updatedAt->addMinutes($ordinal),
            ];
        }

        return $rows;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function collectionRows(
        User $user,
        int $userIndex,
        DemoDataOptions $options,
        DemoRasterAsset $assets,
        CarbonImmutable $createdAt,
        CarbonImmutable $updatedAt,
    ): array {
        $persona = $this->personas->make($userIndex);
        $count = $this->stable->integer(
            "organization:user:{$userIndex}:collection-count",
            $options->collectionMinimum,
            $options->collectionMaximum,
        );
        $visibilityCases = CatalogCollectionVisibility::cases();
        $sortCases = CatalogCollectionSort::cases();
        $versionHash = substr(hash('sha256', $options->version), 0, 12);
        $rows = [];

        for ($ordinal = 0; $ordinal < $count; $ordinal++) {
            $copy = $this->text->collection($persona, $ordinal);
            $publicId = $this->stable->uuid("organization:user:{$userIndex}:collection:{$ordinal}");
            $cover = $assets->store(
                'collection-covers',
                $publicId,
                960,
                540,
                'catalog-collections/'.$publicId.'/demo',
                'webp',
            );
            $visibility = $visibilityCases[($userIndex + $ordinal - 1) % count($visibilityCases)];
            $rows[] = [
                'public_id' => $publicId,
                'owner_id' => $user->id,
                'name' => $copy['name'],
                'description' => $copy['description'],
                'slug' => "demo-{$versionHash}-{$userIndex}-".($ordinal + 1),
                'type' => CatalogCollectionType::User->value,
                'visibility' => $visibility->value,
                'moderation_status' => CatalogCollectionModerationStatus::Approved->value,
                'sort_mode' => $sortCases[($userIndex + $ordinal - 1) % count($sortCases)]->value,
                'content_locale' => 'ru',
                'is_featured' => false,
                'cover_disk' => $cover['disk'],
                'cover_path' => $cover['path'],
                'cover_mime_type' => $cover['mime_type'],
                'cover_size' => $cover['size'],
                'cover_version' => 1,
                'content_version' => 1,
                'published_at' => $visibility === CatalogCollectionVisibility::Private ? null : $createdAt,
                'deleted_at' => null,
                'created_at' => $createdAt->addHours($ordinal),
                'updated_at' => $updatedAt->addHours($ordinal),
            ];
        }

        return $rows;
    }

    /**
     * @param  list<int>  $titleIds
     * @param  Collection<int, UserTag>  $tags
     * @return list<array<string, mixed>>
     */
    private function personalAssignmentRows(
        int $userIndex,
        array $titleIds,
        Collection $tags,
        DemoDataOptions $options,
        CarbonImmutable $createdAt,
        CarbonImmutable $updatedAt,
    ): array {
        $rows = [];
        $tagIds = $tags->pluck('id')->map(static fn (mixed $id): int => (int) $id)->values()->all();

        foreach ($titleIds as $position => $titleId) {
            $count = min(count($tagIds), $this->stable->integer(
                "organization:user:{$userIndex}:title:{$titleId}:personal-tag-count",
                $options->personalTagsPerTitleMinimum,
                $options->personalTagsPerTitleMaximum,
            ));
            $offset = $this->stable->integer(
                "organization:user:{$userIndex}:title:{$titleId}:personal-tag-offset",
                0,
                count($tagIds) - 1,
            );

            for ($ordinal = 0; $ordinal < $count; $ordinal++) {
                $rows[] = [
                    'user_tag_id' => $tagIds[($offset + $ordinal) % count($tagIds)],
                    'catalog_title_id' => $titleId,
                    'position' => $position * 10 + $ordinal,
                    'created_at' => $createdAt,
                    'updated_at' => $updatedAt,
                ];
            }
        }

        return $rows;
    }

    /**
     * @param  list<int>  $titleIds
     * @param  Collection<int, CatalogCollection>  $collections
     * @return list<array<string, mixed>>
     */
    private function collectionItemRows(
        User $user,
        int $userIndex,
        array $titleIds,
        Collection $collections,
        DemoDataOptions $options,
        CarbonImmutable $createdAt,
        CarbonImmutable $updatedAt,
    ): array {
        $rows = [];
        $collectionIds = $collections->pluck('id')->map(static fn (mixed $id): int => (int) $id)->values()->all();

        foreach ($titleIds as $position => $titleId) {
            $count = min(count($collectionIds), $this->stable->integer(
                "organization:user:{$userIndex}:title:{$titleId}:collection-count",
                $options->collectionsPerTitleMinimum,
                $options->collectionsPerTitleMaximum,
            ));
            $offset = $this->stable->integer(
                "organization:user:{$userIndex}:title:{$titleId}:collection-offset",
                0,
                count($collectionIds) - 1,
            );

            for ($ordinal = 0; $ordinal < $count; $ordinal++) {
                $rows[] = [
                    'catalog_collection_id' => $collectionIds[($offset + $ordinal) % count($collectionIds)],
                    'catalog_title_id' => $titleId,
                    'added_by_id' => $user->id,
                    'position' => $position * 10 + $ordinal,
                    'created_at' => $createdAt,
                    'updated_at' => $updatedAt,
                ];
            }
        }

        return $rows;
    }

    /** @return list<int> */
    private function publicTagIds(DemoDataOptions $options, DemoBulkWriter $writer): array
    {
        $eligible = Tag::query()->publiclyEligible()->orderBy('id')->limit($options->publicTagTarget)->pluck('id');

        if ($eligible->count() >= $options->publicTagTarget) {
            return $eligible->map(static fn (mixed $id): int => (int) $id)->all();
        }

        $existingHashes = Tag::query()
            ->whereNotNull('normalized_name_hash')
            ->pluck('normalized_name_hash')
            ->filter(static fn (mixed $hash): bool => is_string($hash))
            ->flip();
        $missing = $options->publicTagTarget - $eligible->count();
        $rows = [];
        $ordinal = 0;
        $now = CarbonImmutable::parse('2025-12-31 12:00:00');
        $versionHash = substr(hash('sha256', $options->version), 0, 12);

        while (count($rows) < $missing) {
            $name = $this->normalizer->display($this->text->publicTag($ordinal));
            $normalized = $this->normalizer->comparison($name);
            $hash = $this->normalizer->hash($name);

            if (! $existingHashes->has($hash)) {
                $rows[] = [
                    'public_id' => $this->stable->uuid("organization:public-tag:{$ordinal}"),
                    'name' => $name,
                    'slug' => "demo-tag-{$versionHash}-".($ordinal + 1),
                    'source_url' => null,
                    'code' => "demo-tag-{$versionHash}-".($ordinal + 1),
                    'type' => TagType::System->value,
                    'visibility' => TagVisibility::Public->value,
                    'moderation_status' => TagModerationStatus::Approved->value,
                    'source' => TagSource::System->value,
                    'normalized_name' => $normalized,
                    'normalized_name_hash' => $hash,
                    'content_version' => 1,
                    'merged_into_id' => null,
                    'archived_at' => null,
                    'created_at' => $now->addMinutes($ordinal),
                    'updated_at' => $now->addMinutes($ordinal),
                ];
                $existingHashes->put($hash, true);
            }

            $ordinal++;
        }

        $writer->upsert(
            (new Tag)->getTable(),
            $rows,
            ['normalized_name_hash'],
            $this->updates($rows, ['normalized_name_hash', 'created_at']),
        );
        $generatedTags = Tag::query()
            ->whereIn('normalized_name_hash', array_column($rows, 'normalized_name_hash'))
            ->get(['id', 'name', 'normalized_name_hash']);
        $translationRows = $generatedTags->map(function (Tag $tag) use ($now): array {
            $name = $tag->canonicalName();

            return [
                'tag_id' => $tag->id,
                'locale' => 'ru',
                'label' => $name,
                'short_description' => "Сериалы с характеристикой «{$name}».",
                'description' => "Тематическая метка «{$name}» объединяет подходящие сериалы, передачи и документальные истории.",
                'seo_title' => null,
                'seo_description' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        })->all();
        $writer->upsert(
            (new TagTranslation)->getTable(),
            $translationRows,
            ['tag_id', 'locale'],
            $this->updates($translationRows, ['tag_id', 'locale', 'created_at']),
        );

        return Tag::query()
            ->publiclyEligible()
            ->orderBy('id')
            ->limit($options->publicTagTarget)
            ->pluck('id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();
    }

    /**
     * @param  list<int>  $tagIds
     */
    private function assignPublicTags(
        DemoDataOptions $options,
        DemoTitleSelector $selector,
        DemoBulkWriter $writer,
        array $tagIds,
    ): int {
        if ($tagIds === []) {
            return 0;
        }

        $total = 0;

        foreach ($selector->selectedIds(1)->chunk($options->chunkSize) as $titleIds) {
            $rows = [];

            foreach ($titleIds as $titleId) {
                $count = min(count($tagIds), $this->stable->integer(
                    "organization:title:{$titleId}:public-tag-count",
                    min(3, count($tagIds)),
                    min(12, count($tagIds)),
                ));
                $offset = $this->stable->integer(
                    "organization:title:{$titleId}:public-tag-offset",
                    0,
                    count($tagIds) - 1,
                );

                for ($ordinal = 0; $ordinal < $count; $ordinal++) {
                    $rows[] = [
                        'catalog_title_id' => $titleId,
                        'tag_id' => $tagIds[($offset + $ordinal) % count($tagIds)],
                    ];
                }
            }

            $writer->upsert('catalog_title_tag', $rows, ['catalog_title_id', 'tag_id'], []);
            $total += count($rows);
        }

        return $total;
    }

    private function createdAt(int $userIndex): CarbonImmutable
    {
        return $this->stable->date(
            "organization:user:{$userIndex}:created-at",
            CarbonImmutable::parse('2023-01-01 00:00:00'),
            CarbonImmutable::parse('2025-06-30 23:59:59'),
        );
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @param  list<string>  $excluded
     * @return list<string>
     */
    private function updates(array $rows, array $excluded): array
    {
        return $rows === [] ? [] : array_values(array_diff(array_keys($rows[0]), $excluded));
    }
}
