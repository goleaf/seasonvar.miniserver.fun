<?php

declare(strict_types=1);

namespace App\Livewire\Forms;

use App\Http\Requests\CatalogTitlesRequest;
use Livewire\Attributes\Url;
use Livewire\Form;

class CatalogSeriesFilters extends Form
{
    /** @var array<string, string> */
    public const TAXONOMY_PROPERTIES = [
        'genre' => 'genre',
        'country' => 'country',
        'actor' => 'actor',
        'director' => 'director',
        'age_rating' => 'age_rating',
        'translation' => 'translation',
        'status' => 'status',
        'network' => 'network',
        'studio' => 'studio',
        'tag' => 'tag',
    ];

    /** @var array<string, string> */
    public const ADVANCED_REQUEST_PROPERTIES = [
        'year_from' => 'yearFrom',
        'year_to' => 'yearTo',
        'seasons_min' => 'seasonsMin',
        'seasons_max' => 'seasonsMax',
        'episodes_min' => 'episodesMin',
        'episodes_max' => 'episodesMax',
        'rating_source' => 'ratingSource',
        'rating_min' => 'ratingMin',
        'votes_min' => 'votesMin',
        'video' => 'video',
        'updated' => 'updated',
    ];

    #[Url(as: 'q', history: true, except: '')]
    public string|int|null $search = '';

    /** @var list<int|string> */
    #[Url(as: 'year', history: true, except: [])]
    public array $years = [];

    /** @var list<string> */
    #[Url(history: true, except: [])]
    public array $genre = [];

    /** @var list<string> */
    #[Url(history: true, except: [])]
    public array $country = [];

    /** @var list<string> */
    #[Url(history: true, except: [])]
    public array $actor = [];

    /** @var list<string> */
    #[Url(history: true, except: [])]
    public array $director = [];

    /** @var list<string> */
    #[Url(as: 'age_rating', history: true, except: [])]
    public array $age_rating = [];

    /** @var list<string> */
    #[Url(history: true, except: [])]
    public array $translation = [];

    /** @var list<string> */
    #[Url(history: true, except: [])]
    public array $status = [];

    /** @var list<string> */
    #[Url(history: true, except: [])]
    public array $network = [];

    /** @var list<string> */
    #[Url(history: true, except: [])]
    public array $studio = [];

    /** @var list<string> */
    #[Url(history: true, except: [])]
    public array $tag = [];

    /** @var list<string> */
    #[Url(as: 'exclude_country', history: true, except: [])]
    public array $excludeCountry = [];

    /** @var list<string> */
    #[Url(as: 'exclude_genre', history: true, except: [])]
    public array $excludeGenre = [];

    /** @var list<string> */
    #[Url(as: 'quality', history: true, except: [])]
    public array $qualities = [];

    /** @var list<string> */
    #[Url(as: 'publication_type', history: true, except: [])]
    public array $publicationTypes = [];

    /** @var list<string> */
    #[Url(history: true, except: [])]
    public array $subtitles = [];

    #[Url(as: 'title', history: true, except: '')]
    public string|int|null $titleContext = '';

    #[Url(as: 'year_from', history: true, except: '')]
    public string|int|null $yearFrom = '';

    #[Url(as: 'year_to', history: true, except: '')]
    public string|int|null $yearTo = '';

    #[Url(as: 'seasons_min', history: true, except: '')]
    public string|int|null $seasonsMin = '';

    #[Url(as: 'seasons_max', history: true, except: '')]
    public string|int|null $seasonsMax = '';

    #[Url(as: 'episodes_min', history: true, except: '')]
    public string|int|null $episodesMin = '';

    #[Url(as: 'episodes_max', history: true, except: '')]
    public string|int|null $episodesMax = '';

    #[Url(as: 'rating_source', history: true, except: '')]
    public ?string $ratingSource = '';

    #[Url(as: 'rating_min', history: true, except: '')]
    public string|int|float|null $ratingMin = '';

    #[Url(as: 'votes_min', history: true, except: '')]
    public string|int|null $votesMin = '';

    #[Url(history: true, except: '')]
    public ?string $video = '';

    #[Url(history: true, except: '')]
    public ?string $updated = '';

    #[Url(history: true, except: '')]
    public ?string $letter = '';

    #[Url(history: true, except: 'updated')]
    public string $sort = 'updated';

    #[Url(as: 'per_page', history: true, except: 24)]
    public string|int $perPage = 24;

    /** @return array<string, mixed> */
    public function toRequestInput(): array
    {
        $input = [
            'q' => $this->search,
            'year' => $this->years,
            'exclude_country' => $this->excludeCountry,
            'exclude_genre' => $this->excludeGenre,
            'quality' => $this->qualities,
            'publication_type' => $this->publicationTypes,
            'subtitles' => $this->subtitles,
            'title' => $this->titleContext,
            'year_from' => $this->yearFrom,
            'year_to' => $this->yearTo,
            'seasons_min' => $this->seasonsMin,
            'seasons_max' => $this->seasonsMax,
            'episodes_min' => $this->episodesMin,
            'episodes_max' => $this->episodesMax,
            'rating_source' => $this->ratingSource,
            'rating_min' => $this->ratingMin,
            'votes_min' => $this->votesMin,
            'video' => $this->video,
            'updated' => $this->updated,
            'letter' => $this->letter,
            'sort' => $this->sort,
            'per_page' => $this->perPage,
        ];

        foreach (self::TAXONOMY_PROPERTIES as $requestKey => $property) {
            $input[$requestKey] = $this->{$property};
        }

        return collect($input)
            ->reject(fn (mixed $value, string $key): bool => $this->isDefaultValue($key, $value))
            ->all();
    }

    public function fillFromRequest(CatalogTitlesRequest $request): void
    {
        $this->search = $request->normalizedSearch();
        $this->years = $request->years();
        $this->excludeCountry = $request->excludedFilterSlugs()['country'];
        $this->excludeGenre = $request->excludedFilterSlugs()['genre'];
        $this->qualities = $request->qualities();
        $this->publicationTypes = $request->publicationTypes();
        $this->subtitles = $request->subtitleAvailability();
        $this->titleContext = $request->titleContextSlug() ?? '';
        $this->yearFrom = $request->yearFrom() ?? '';
        $this->yearTo = $request->yearTo() ?? '';
        $this->seasonsMin = $request->seasonsMin() ?? '';
        $this->seasonsMax = $request->seasonsMax() ?? '';
        $this->episodesMin = $request->episodesMin() ?? '';
        $this->episodesMax = $request->episodesMax() ?? '';
        $this->ratingSource = $request->ratingSource() ?? '';
        $this->ratingMin = $request->ratingMin() ?? '';
        $this->votesMin = $request->votesMin() ?? '';
        $this->video = $request->videoAvailability() ?? '';
        $this->updated = $request->updatedPeriod() ?? '';
        $this->letter = $request->letter() ?? '';
        $this->sort = $request->sort()->value;
        $this->perPage = $request->perPage();

        foreach (self::TAXONOMY_PROPERTIES as $requestKey => $property) {
            $this->{$property} = $request->filterSlugs()[$requestKey];
        }
    }

    public function resetGroup(string $group): bool
    {
        $property = match ($group) {
            'year' => 'years',
            'exclude_country' => 'excludeCountry',
            'exclude_genre' => 'excludeGenre',
            'quality' => 'qualities',
            'publication_type' => 'publicationTypes',
            'subtitles' => 'subtitles',
            default => self::TAXONOMY_PROPERTIES[$group] ?? null,
        };

        if ($property === null) {
            return false;
        }

        $this->{$property} = [];

        return true;
    }

    public function resetAdvanced(string $key): bool
    {
        $property = self::ADVANCED_REQUEST_PROPERTIES[$key] ?? match ($key) {
            'letter' => 'letter',
            default => null,
        };

        if ($property === null) {
            return false;
        }

        $this->{$property} = '';

        return true;
    }

    public function resetAdvancedFilters(): void
    {
        foreach (self::ADVANCED_REQUEST_PROPERTIES as $property) {
            $this->{$property} = '';
        }

        $this->qualities = [];
    }

    public function removeTaxonomy(string $type, string $slug): bool
    {
        $property = self::TAXONOMY_PROPERTIES[$type] ?? null;

        if ($property === null) {
            return false;
        }

        $this->{$property} = array_values(array_diff($this->{$property}, [$slug]));

        return true;
    }

    public function removeExcluded(string $type, string $slug): bool
    {
        $property = match ($type) {
            'country' => 'excludeCountry',
            'genre' => 'excludeGenre',
            default => null,
        };

        if ($property === null) {
            return false;
        }

        $this->{$property} = array_values(array_diff($this->{$property}, [$slug]));

        return true;
    }

    public function removeChoice(string $group, string $value): bool
    {
        $property = match ($group) {
            'publication_type' => 'publicationTypes',
            'subtitles' => 'subtitles',
            'quality' => 'qualities',
            default => null,
        };

        if ($property === null) {
            return false;
        }

        $this->{$property} = array_values(array_diff($this->{$property}, [$value]));

        return true;
    }

    public function resetAllFilters(): void
    {
        $this->reset();
    }

    private function isDefaultValue(string $key, mixed $value): bool
    {
        return $value === null
            || $value === ''
            || $value === []
            || ($key === 'sort' && $value === 'updated')
            || ($key === 'per_page' && (int) $value === 24);
    }
}
