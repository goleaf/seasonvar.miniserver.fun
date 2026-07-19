<?php

declare(strict_types=1);

namespace App\Support\Administration;

use Illuminate\Support\Str;

final readonly class AdminTableState
{
    /**
     * @param  array<string, string>  $sortColumns
     * @param  array<string, string>  $filters
     * @param  list<string>  $selected
     */
    private function __construct(
        public string $sort,
        public string $direction,
        public int $page,
        public int $perPage,
        public string $search,
        public array $filters,
        public array $selected,
        private array $sortColumns,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     * @param  array<string, string>  $sortColumns
     * @param  list<string>  $filterCodes
     */
    public static function from(array $input, array $sortColumns, string $defaultSort, array $filterCodes): self
    {
        if (! isset($sortColumns[$defaultSort])) {
            throw new \InvalidArgumentException('The default administration sort must be allowlisted.');
        }

        $sort = is_string($input['sort'] ?? null) && isset($sortColumns[$input['sort']])
            ? $input['sort']
            : $defaultSort;
        $direction = in_array($input['direction'] ?? null, ['asc', 'desc'], true)
            ? $input['direction']
            : 'desc';
        $page = max(1, filter_var($input['page'] ?? 1, FILTER_VALIDATE_INT) ?: 1);
        $requestedPageSize = (int) ($input['per_page'] ?? 25);
        $perPage = in_array($requestedPageSize, [15, 25, 50], true)
            ? $requestedPageSize
            : 25;
        $search = Str::of((string) ($input['search'] ?? ''))->squish()->limit(80, '')->toString();
        $filters = self::normalizeFilters($input['filters'] ?? [], $filterCodes);
        $selected = self::normalizeSelection($input['selected'] ?? []);

        return new self($sort, $direction, $page, $perPage, $search, $filters, $selected, $sortColumns);
    }

    public function sortColumn(): string
    {
        return $this->sortColumns[$this->sort];
    }

    /** @param mixed $input @param list<string> $filterCodes @return array<string, string> */
    private static function normalizeFilters(mixed $input, array $filterCodes): array
    {
        if (! is_array($input)) {
            return [];
        }

        $filters = [];

        foreach ($filterCodes as $filterCode) {
            $value = $input[$filterCode] ?? null;

            if (! is_scalar($value)) {
                continue;
            }

            $normalized = Str::of((string) $value)->squish()->limit(100, '')->toString();

            if ($normalized !== '') {
                $filters[$filterCode] = $normalized;
            }
        }

        return $filters;
    }

    /** @param mixed $input @return list<string> */
    private static function normalizeSelection(mixed $input): array
    {
        if (! is_array($input)) {
            return [];
        }

        return collect($input)
            ->filter(fn (mixed $identity): bool => is_string($identity) && Str::isUuid($identity))
            ->unique()
            ->take(50)
            ->values()
            ->all();
    }
}
