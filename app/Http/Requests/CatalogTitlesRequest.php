<?php

namespace App\Http\Requests;

use App\Enums\CatalogFilterType;
use App\Enums\CatalogSort;
use App\Rules\CatalogFilterSlug;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;
use Illuminate\Validation\Validator;

class CatalogTitlesRequest extends FormRequest
{
    private const MAX_SELECTIONS = 20;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<string|ValidationRule|Enum>>
     */
    public function rules(): array
    {
        $rules = [
            'q' => ['nullable', 'string', 'min:2', 'max:80'],
            'year' => ['nullable', 'array', 'max:'.self::MAX_SELECTIONS],
            'year.*' => ['integer', 'distinct', 'between:1900,'.((int) now()->format('Y') + 1)],
            'title' => $this->slugRules(),
            'sort' => ['nullable', Rule::enum(CatalogSort::class)],
            'type' => ['nullable', Rule::enum(CatalogFilterType::class)],
            'taxonomy' => $this->slugRules(),
            'exclude_country' => $this->slugListRules(),
            'exclude_country.*' => $this->slugItemRules(),
            'exclude_genre' => $this->slugListRules(),
            'exclude_genre.*' => $this->slugItemRules(),
            'year_from' => ['nullable', 'integer', 'between:1900,'.((int) now()->format('Y') + 1)],
            'year_to' => ['nullable', 'integer', 'between:1900,'.((int) now()->format('Y') + 1)],
            'seasons_min' => ['nullable', 'integer', 'min:0', 'max:9999'],
            'seasons_max' => ['nullable', 'integer', 'min:0', 'max:9999'],
            'episodes_min' => ['nullable', 'integer', 'min:0', 'max:999999'],
            'episodes_max' => ['nullable', 'integer', 'min:0', 'max:999999'],
            'rating_source' => ['nullable', Rule::in(['imdb', 'kinopoisk'])],
            'rating_min' => ['nullable', 'numeric', 'between:0,10'],
            'votes_min' => ['nullable', 'integer', 'min:0'],
            'video' => ['nullable', Rule::in(['available', 'missing'])],
            'subtitles' => ['nullable', Rule::in(['available', 'missing'])],
            'quality' => ['nullable', 'array', 'max:'.self::MAX_SELECTIONS],
            'quality.*' => ['string', 'distinct', Rule::in(['2160p', '1440p', '1080p', '720p', '480p', '360p', '240p'])],
            'updated' => ['nullable', Rule::in(['day', 'week', 'month', 'year'])],
            'letter' => ['nullable', 'string', 'regex:/^(?:latin|[A-Za-zА-Яа-яЁё]|#)$/iu'],
            'view' => ['nullable', Rule::in(['grid', 'list'])],
            'per_page' => ['nullable', 'integer', Rule::in([24, 48, 96])],
        ];

        foreach (CatalogFilterType::values() as $filterType) {
            $rules[$filterType] = $this->slugListRules();
            $rules[$filterType.'.*'] = $this->slugItemRules();
        }

        return $rules;
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'q.string' => 'Поисковый запрос должен быть строкой.',
            'q.min' => 'Введите не менее 2 символов для поиска.',
            'q.max' => 'Поисковый запрос слишком длинный.',
            'year.array' => 'Годы должны быть переданы списком.',
            'year.max' => 'Выбрано слишком много значений фильтра.',
            'year.*.integer' => 'Год должен быть целым числом.',
            'year.*.between' => 'Выбран недопустимый год.',
            'sort.enum' => 'Выбрана неподдерживаемая сортировка.',
            'type.enum' => 'Выбран неподдерживаемый тип фильтра.',
            '*.array' => 'Значения фильтра должны быть переданы списком.',
            '*.max' => 'Выбрано слишком много значений фильтра.',
            'letter.regex' => 'Выбрана неподдерживаемая буква.',
            'quality.*.in' => 'Выбрано неподдерживаемое качество видео.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        $attributes = [
            'q' => 'поиск',
            'year' => 'год',
            'title' => 'страница сериала',
            'sort' => 'сортировка',
            'type' => 'тип фильтра',
            'taxonomy' => 'значение фильтра',
        ];

        foreach (CatalogFilterType::cases() as $filterType) {
            $attributes[$filterType->value] = $filterType->label();
        }

        return $attributes;
    }

    protected function prepareForValidation(): void
    {
        $normalized = [];
        $repeatedKeys = array_merge(
            ['year', 'exclude_country', 'exclude_genre', 'quality'],
            CatalogFilterType::values(),
        );

        foreach ($repeatedKeys as $key) {
            if (! $this->query->has($key)) {
                continue;
            }

            $values = is_array($this->query($key)) ? $this->query($key) : [$this->query($key)];
            $normalized[$key] = $this->normalizeRepeatedValues($values);
        }

        foreach (['q', 'title', 'sort', 'type', 'taxonomy', 'year_from', 'year_to', 'seasons_min', 'seasons_max', 'episodes_min', 'episodes_max', 'rating_source', 'rating_min', 'votes_min', 'video', 'subtitles', 'updated', 'letter', 'view', 'per_page'] as $key) {
            if (! $this->query->has($key) || ! is_scalar($this->query($key))) {
                continue;
            }

            $value = trim((string) $this->query($key));
            $normalized[$key] = $key === 'q'
                ? (preg_replace('/\s+/u', ' ', $value) ?: '')
                : $value;
        }

        $this->merge($normalized);
    }

    public function normalizedSearch(): string
    {
        return preg_replace('/\s+/u', ' ', trim($this->stringQuery('q'))) ?: '';
    }

    public function requestedYear(): string
    {
        $years = $this->query('year', []);
        $first = is_array($years) ? reset($years) : $years;

        return is_scalar($first) ? trim((string) $first) : '';
    }

    public function year(): ?int
    {
        $years = $this->years();

        return count($years) === 1 ? $years[0] : null;
    }

    public function invalidYear(): bool
    {
        return $this->requestedYear() !== '' && $this->years() === [];
    }

    public function titleContextSlug(): ?string
    {
        $slug = $this->filterSlug($this->query('title', ''));

        return $slug === '' ? null : $slug;
    }

    public function sort(): CatalogSort
    {
        return CatalogSort::tryFrom($this->stringQuery('sort')) ?? CatalogSort::Updated;
    }

    /** @return list<int> */
    public function years(): array
    {
        return collect($this->arrayQuery('year'))
            ->filter(fn (mixed $year): bool => is_scalar($year) && preg_match('/^\d{4}$/', (string) $year) === 1)
            ->map(fn (mixed $year): int => (int) $year)
            ->filter(fn (int $year): bool => $year >= 1900 && $year <= ((int) now()->format('Y') + 1))
            ->unique()
            ->values()
            ->all();
    }

    /** @return array<string, list<string>> */
    public function filterSlugs(): array
    {
        return collect(CatalogFilterType::values())
            ->mapWithKeys(fn (string $type): array => [$type => $this->normalizedSlugList($type)])
            ->all();
    }

    /** @return array{country: list<string>, genre: list<string>} */
    public function excludedFilterSlugs(): array
    {
        return [
            'country' => $this->normalizedSlugList('exclude_country'),
            'genre' => $this->normalizedSlugList('exclude_genre'),
        ];
    }

    public function yearFrom(): ?int
    {
        return $this->nullableInt('year_from');
    }

    public function yearTo(): ?int
    {
        return $this->nullableInt('year_to');
    }

    public function seasonsMin(): ?int
    {
        return $this->nullableInt('seasons_min');
    }

    public function seasonsMax(): ?int
    {
        return $this->nullableInt('seasons_max');
    }

    public function episodesMin(): ?int
    {
        return $this->nullableInt('episodes_min');
    }

    public function episodesMax(): ?int
    {
        return $this->nullableInt('episodes_max');
    }

    public function ratingSource(): ?string
    {
        return $this->nullableString('rating_source');
    }

    public function ratingMin(): ?float
    {
        return $this->nullableFloat('rating_min');
    }

    public function votesMin(): ?int
    {
        return $this->nullableInt('votes_min');
    }

    public function videoAvailability(): ?string
    {
        return $this->nullableString('video');
    }

    public function subtitleAvailability(): ?string
    {
        return $this->nullableString('subtitles');
    }

    public function updatedPeriod(): ?string
    {
        return $this->nullableString('updated');
    }

    public function letter(): ?string
    {
        return $this->nullableString('letter');
    }

    /** @return list<string> */
    public function qualities(): array
    {
        return $this->scalarStringList('quality');
    }

    public function view(): string
    {
        return $this->nullableString('view') ?? 'grid';
    }

    public function perPage(): int
    {
        return $this->nullableInt('per_page') ?? 24;
    }

    /** @return array<string, mixed> */
    public function catalogQueryState(): array
    {
        $keys = array_merge(
            ['year', 'exclude_country', 'exclude_genre', 'quality'],
            CatalogFilterType::values(),
            ['year_from', 'year_to', 'seasons_min', 'seasons_max', 'episodes_min', 'episodes_max', 'rating_source', 'rating_min', 'votes_min', 'video', 'subtitles', 'updated', 'letter', 'view', 'per_page'],
        );

        return collect($keys)
            ->filter(fn (string $key): bool => $this->query->has($key))
            ->mapWithKeys(fn (string $key): array => [$key => $this->query($key)])
            ->all();
    }

    /** @return list<Closure(Validator): void> */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                foreach ([['year_from', 'year_to'], ['seasons_min', 'seasons_max'], ['episodes_min', 'episodes_max']] as [$from, $to]) {
                    $minimum = $this->nullableInt($from);
                    $maximum = $this->nullableInt($to);

                    if ($minimum !== null && $maximum !== null && $minimum > $maximum) {
                        $validator->errors()->add($from, 'Начало диапазона не может быть больше конца.');
                    }
                }

                foreach (['country', 'genre'] as $type) {
                    if (array_intersect($this->filterSlugs()[$type], $this->excludedFilterSlugs()[$type]) !== []) {
                        $validator->errors()->add($type, 'Одно значение нельзя одновременно включить и исключить.');
                    }
                }
            },
        ];
    }

    /**
     * @param  array<int, string>  $filterTypes
     */
    public function legacyType(array $filterTypes): string
    {
        $value = $this->stringQuery('type');

        return in_array($value, $filterTypes, true) ? $value : '';
    }

    public function legacyTaxonomy(): ?string
    {
        return $this->filterSlug($this->query('taxonomy', ''));
    }

    public function filterSlug(mixed $value): ?string
    {
        return CatalogFilterSlug::normalize($value);
    }

    /**
     * @return list<string|ValidationRule>
     */
    private function slugRules(): array
    {
        return ['nullable', 'string', 'max:'.CatalogFilterSlug::MAX_LENGTH, new CatalogFilterSlug];
    }

    /** @return list<string> */
    private function slugListRules(): array
    {
        return ['nullable', 'array', 'max:'.self::MAX_SELECTIONS];
    }

    /** @return list<string|ValidationRule> */
    private function slugItemRules(): array
    {
        return ['string', 'distinct', 'max:'.CatalogFilterSlug::MAX_LENGTH, new CatalogFilterSlug];
    }

    private function stringQuery(string $key): string
    {
        $value = $this->query($key, '');

        return is_scalar($value) ? trim((string) $value) : '';
    }

    /** @return list<mixed> */
    private function arrayQuery(string $key): array
    {
        $value = $this->query($key, []);

        return is_array($value) ? array_values($value) : [$value];
    }

    /** @param array<mixed> $values @return list<mixed> */
    private function normalizeRepeatedValues(array $values): array
    {
        $normalized = [];

        foreach ($values as $value) {
            if (! is_scalar($value)) {
                $normalized[] = $value;

                continue;
            }

            $value = trim((string) $value);

            if ($value !== '' && ! in_array($value, $normalized, true)) {
                $normalized[] = $value;
            }
        }

        return $normalized;
    }

    /** @return list<string> */
    private function normalizedSlugList(string $key): array
    {
        return collect($this->arrayQuery($key))
            ->map(fn (mixed $value): ?string => CatalogFilterSlug::normalize($value))
            ->filter(fn (?string $value): bool => $value !== null && $value !== '')
            ->unique()
            ->values()
            ->all();
    }

    /** @return list<string> */
    private function scalarStringList(string $key): array
    {
        return collect($this->arrayQuery($key))
            ->filter(fn (mixed $value): bool => is_scalar($value))
            ->map(fn (mixed $value): string => trim((string) $value))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    private function nullableString(string $key): ?string
    {
        $value = $this->stringQuery($key);

        return $value === '' ? null : $value;
    }

    private function nullableInt(string $key): ?int
    {
        $value = $this->nullableString($key);

        return $value !== null && preg_match('/^-?\d+$/', $value) === 1 ? (int) $value : null;
    }

    private function nullableFloat(string $key): ?float
    {
        $value = $this->nullableString($key);

        return $value !== null && is_numeric($value) ? (float) $value : null;
    }
}
