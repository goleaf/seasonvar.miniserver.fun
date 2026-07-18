<?php

declare(strict_types=1);

namespace App\Livewire\Forms\Library;

use App\DTOs\UserLibraryFilters;
use App\Enums\CatalogPublicationType;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Url;
use Livewire\Form;

final class LibraryFilters extends Form
{
    #[Url(as: 'q', except: '')]
    public string $query = '';

    #[Url(except: '')]
    public string $type = '';

    #[Url(except: '')]
    public string|int $year = '';

    #[Url(as: 'tag', except: '')]
    public string $personalTag = '';

    #[Url(except: 'updated')]
    public string $sort = 'updated';

    #[Url(except: 'desc')]
    public string $direction = 'desc';

    public function normalize(): void
    {
        $this->query = str($this->query)->squish()->toString();
        $this->type = str($this->type)->trim()->lower()->toString();
        $this->personalTag = str($this->personalTag)->trim()->lower()->toString();
        $this->sort = str($this->sort)->trim()->lower()->toString();
        $this->direction = str($this->direction)->trim()->lower()->toString();

        if (is_string($this->year)) {
            $this->year = trim($this->year);
        }
    }

    public function validateFor(string $section): void
    {
        $sorts = $this->allowedSorts($section);

        $this->validate([
            'query' => ['nullable', 'string', 'max:160'],
            'type' => ['nullable', Rule::enum(CatalogPublicationType::class)],
            'year' => ['nullable', 'integer', 'min:1900', 'max:'.(now()->year + 1)],
            'personalTag' => ['nullable', 'uuid'],
            'sort' => ['required', Rule::in($sorts)],
            'direction' => ['required', Rule::in(['asc', 'desc'])],
        ], [
            'query.max' => __('library.validation.query_max'),
            'type.enum' => __('library.validation.type'),
            'year.integer' => __('library.validation.year_integer'),
            'year.min' => __('library.validation.year_min'),
            'year.max' => __('library.validation.year_max'),
            'personalTag.uuid' => __('tags.validation.personal_tag'),
            'sort.in' => __('library.validation.sort'),
            'direction.in' => __('library.validation.direction'),
        ]);
    }

    public function toDto(string $section, int $perPage = 12): UserLibraryFilters
    {
        $allowedSorts = $this->allowedSorts($section);
        $type = CatalogPublicationType::tryFrom($this->type);
        $year = filter_var($this->year, FILTER_VALIDATE_INT);

        return new UserLibraryFilters(
            query: str($this->query)->squish()->limit(160, '')->toString(),
            type: $type?->value,
            year: $year !== false && $year >= 1900 && $year <= now()->year + 1 ? $year : null,
            personalTagPublicId: preg_match('/^[a-f0-9-]{36}$/iD', $this->personalTag) === 1
                ? $this->personalTag
                : null,
            sort: in_array($this->sort, $allowedSorts, true) ? $this->sort : 'updated',
            direction: in_array($this->direction, ['asc', 'desc'], true) ? $this->direction : 'desc',
            perPage: max(1, min(48, $perPage)),
        );
    }

    /** @return list<string> */
    private function allowedSorts(string $section): array
    {
        $sorts = ['updated', 'title', 'year'];

        if ($section === 'ratings') {
            $sorts[] = 'rating';
        }

        if (in_array($section, [
            'planned',
            'watching',
            'paused',
            'completed',
            'dropped',
            'with-updates',
            'without-updates',
        ], true)) {
            $sorts[] = 'recently-watched';
            $sorts[] = 'progress';
            $sorts[] = 'status';
        }

        return $sorts;
    }
}
