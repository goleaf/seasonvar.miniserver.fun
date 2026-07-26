<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\DTOs\CatalogSmartCollectionRules;
use App\Enums\CatalogSmartCollectionCompletion;
use App\Enums\CatalogWatchStatus;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class CatalogSmartCollectionRulesTest extends TestCase
{
    public function test_rules_normalize_all_supported_values_and_preserve_zero_boundaries(): void
    {
        $rules = CatalogSmartCollectionRules::fromInput([
            'country_slug' => '  iuznaia-koreia ',
            'genre_slug' => 'trillery',
            'actor_slug' => '',
            'imdb_min' => '0,0',
            'year_from' => '2024',
            'year_to' => 2026,
            'completion' => CatalogSmartCollectionCompletion::Completed->value,
            'episodes_max' => '8',
            'max_episode_minutes' => 60,
            'in_library' => '1',
            'unwatched' => true,
            'has_subtitles' => 1,
            'has_new_episodes' => false,
            'watch_status' => CatalogWatchStatus::Dropped->value,
            'watch_status_older_days' => '90',
            'video_available' => true,
        ]);

        $this->assertSame([
            'country_slug' => 'iuznaia-koreia',
            'genre_slug' => 'trillery',
            'actor_slug' => null,
            'imdb_min' => 0.0,
            'year_from' => 2024,
            'year_to' => 2026,
            'completion' => 'completed',
            'episodes_max' => 8,
            'max_episode_minutes' => 60,
            'in_library' => true,
            'unwatched' => true,
            'has_subtitles' => true,
            'has_new_episodes' => false,
            'watch_status' => 'dropped',
            'watch_status_older_days' => 90,
            'video_available' => true,
        ], $rules->toArray());
        $this->assertTrue($rules->hasActiveRules());
    }

    #[DataProvider('invalidRules')]
    public function test_rules_reject_unknown_empty_or_impossible_input(array $input, string $field): void
    {
        try {
            CatalogSmartCollectionRules::fromInput($input);
            $this->fail('Ожидалась ошибка правил умной подборки.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey($field, $exception->errors());
        }
    }

    public function test_stored_unknown_version_or_key_fails_closed(): void
    {
        $this->assertNull(CatalogSmartCollectionRules::fromStored(['genre_slug' => 'trillery'], 2));
        $this->assertNull(CatalogSmartCollectionRules::fromStored([
            'genre_slug' => 'trillery',
            'unsafe_operator' => 'or 1=1',
        ], 1));
    }

    /** @return iterable<string, array{array<string, mixed>, string}> */
    public static function invalidRules(): iterable
    {
        yield 'empty' => [[], 'rules'];
        yield 'unknown key' => [['genre_slug' => 'trillery', 'unsafe' => true], 'rules'];
        yield 'bad slug' => [['country_slug' => '../secret'], 'country_slug'];
        yield 'rating above maximum' => [['imdb_min' => '10.1'], 'imdb_min'];
        yield 'year order' => [['year_from' => 2026, 'year_to' => 2025], 'year_to'];
        yield 'age without status' => [['watch_status_older_days' => 90], 'watch_status_older_days'];
        yield 'age with wrong status' => [[
            'watch_status' => CatalogWatchStatus::Watching->value,
            'watch_status_older_days' => 90,
        ], 'watch_status_older_days'];
    }
}
