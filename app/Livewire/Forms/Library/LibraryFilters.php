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

    #[Url(except: 'updated')]
    public string $sort = 'updated';

    #[Url(except: 'desc')]
    public string $direction = 'desc';

    public function normalize(): void
    {
        $this->query = str($this->query)->squish()->toString();
        $this->type = str($this->type)->trim()->lower()->toString();
        $this->sort = str($this->sort)->trim()->lower()->toString();
        $this->direction = str($this->direction)->trim()->lower()->toString();

        if (is_string($this->year)) {
            $this->year = trim($this->year);
        }
    }

    public function validateFor(string $section): void
    {
        $sorts = $section === 'ratings'
            ? ['updated', 'rating', 'title', 'year']
            : ['updated', 'title', 'year'];

        $this->validate([
            'query' => ['nullable', 'string', 'max:160'],
            'type' => ['nullable', Rule::enum(CatalogPublicationType::class)],
            'year' => ['nullable', 'integer', 'min:1900', 'max:'.(now()->year + 1)],
            'sort' => ['required', Rule::in($sorts)],
            'direction' => ['required', Rule::in(['asc', 'desc'])],
        ], [
            'query.max' => 'Поисковый запрос не должен быть длиннее 160 символов.',
            'type.enum' => 'Выбран неизвестный тип публикации.',
            'year.integer' => 'Год должен быть целым числом.',
            'year.min' => 'Год должен быть не меньше 1900.',
            'year.max' => 'Указан недопустимый год.',
            'sort.in' => 'Выбран недоступный способ сортировки.',
            'direction.in' => 'Выбрано недоступное направление сортировки.',
        ]);
    }

    public function toDto(string $section, int $perPage = 12): UserLibraryFilters
    {
        $allowedSorts = $section === 'ratings'
            ? ['updated', 'rating', 'title', 'year']
            : ['updated', 'title', 'year'];
        $type = CatalogPublicationType::tryFrom($this->type);
        $year = filter_var($this->year, FILTER_VALIDATE_INT);

        return new UserLibraryFilters(
            query: str($this->query)->squish()->limit(160, '')->toString(),
            type: $type?->value,
            year: $year !== false && $year >= 1900 && $year <= now()->year + 1 ? $year : null,
            sort: in_array($this->sort, $allowedSorts, true) ? $this->sort : 'updated',
            direction: in_array($this->direction, ['asc', 'desc'], true) ? $this->direction : 'desc',
            perPage: max(1, min(48, $perPage)),
        );
    }
}
