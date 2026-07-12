<?php

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

    #[Url(as: 'q', history: true, except: '')]
    public string|int $search = '';

    #[Url(as: 'year', history: true, except: [])]
    public array $years = [];

    #[Url(history: true, except: [])]
    public array $genre = [];

    #[Url(history: true, except: [])]
    public array $country = [];

    #[Url(history: true, except: [])]
    public array $actor = [];

    #[Url(history: true, except: [])]
    public array $director = [];

    #[Url(as: 'age_rating', history: true, except: [])]
    public array $age_rating = [];

    #[Url(history: true, except: [])]
    public array $translation = [];

    #[Url(history: true, except: [])]
    public array $status = [];

    #[Url(history: true, except: [])]
    public array $network = [];

    #[Url(history: true, except: [])]
    public array $studio = [];

    #[Url(history: true, except: [])]
    public array $tag = [];

    #[Url(as: 'exclude_country', history: true, except: [])]
    public array $excludeCountry = [];

    #[Url(as: 'exclude_genre', history: true, except: [])]
    public array $excludeGenre = [];

    #[Url(as: 'quality', history: true, except: [])]
    public array $qualities = [];

    #[Url(as: 'title', history: true, except: '')]
    public string|int $titleContext = '';

    #[Url(as: 'year_from', history: true, except: '')]
    public string|int $yearFrom = '';

    #[Url(as: 'year_to', history: true, except: '')]
    public string|int $yearTo = '';

    #[Url(as: 'seasons_min', history: true, except: '')]
    public string|int $seasonsMin = '';

    #[Url(as: 'seasons_max', history: true, except: '')]
    public string|int $seasonsMax = '';

    #[Url(as: 'episodes_min', history: true, except: '')]
    public string|int $episodesMin = '';

    #[Url(as: 'episodes_max', history: true, except: '')]
    public string|int $episodesMax = '';

    #[Url(as: 'rating_source', history: true, except: '')]
    public string $ratingSource = '';

    #[Url(as: 'rating_min', history: true, except: '')]
    public string|int|float $ratingMin = '';

    #[Url(as: 'votes_min', history: true, except: '')]
    public string|int $votesMin = '';

    #[Url(history: true, except: '')]
    public string $video = '';

    #[Url(history: true, except: '')]
    public string $subtitles = '';

    #[Url(history: true, except: '')]
    public string $updated = '';

    #[Url(history: true, except: '')]
    public string $letter = '';

    #[Url(history: true, except: 'updated')]
    public string $sort = 'updated';

    #[Url(history: true, except: 'grid')]
    public string $view = 'grid';

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
            'subtitles' => $this->subtitles,
            'updated' => $this->updated,
            'letter' => $this->letter,
            'sort' => $this->sort,
            'view' => $this->view,
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
        $this->subtitles = $request->subtitleAvailability() ?? '';
        $this->updated = $request->updatedPeriod() ?? '';
        $this->letter = $request->letter() ?? '';
        $this->sort = $request->sort()->value;
        $this->view = $request->view();
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
        $property = match ($key) {
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
            'subtitles' => 'subtitles',
            'updated' => 'updated',
            'letter' => 'letter',
            default => null,
        };

        if ($property === null) {
            return false;
        }

        $this->{$property} = '';

        return true;
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

    public function resetAllFilters(): void
    {
        $this->reset();
    }

    private function isDefaultValue(string $key, mixed $value): bool
    {
        return $value === ''
            || $value === []
            || ($key === 'sort' && $value === 'updated')
            || ($key === 'view' && $value === 'grid')
            || ($key === 'per_page' && (int) $value === 24);
    }
}
