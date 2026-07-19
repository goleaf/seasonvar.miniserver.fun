<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\Administration\AdminTableState;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class AdminTableStateTest extends TestCase
{
    #[Test]
    public function it_allowlists_sort_filter_and_page_state_with_deterministic_fallbacks(): void
    {
        $state = AdminTableState::from(
            input: [
                'sort' => 'password',
                'direction' => 'sideways',
                'page' => -9,
                'per_page' => 5000,
                'search' => str_repeat('a', 120),
                'filters' => ['status' => 'active', 'private' => 'secret', 'role' => ['nested']],
            ],
            sortColumns: ['created' => 'users.created_at', 'name' => 'users.name'],
            defaultSort: 'created',
            filterCodes: ['status'],
        );

        self::assertSame('created', $state->sort);
        self::assertSame('desc', $state->direction);
        self::assertSame('users.created_at', $state->sortColumn());
        self::assertSame(1, $state->page);
        self::assertSame(25, $state->perPage);
        self::assertSame(80, mb_strlen($state->search));
        self::assertSame(['status' => 'active'], $state->filters);
    }

    #[Test]
    public function it_bounds_selection_to_unique_public_ids(): void
    {
        $identities = array_map(static fn (int $index): string => sprintf('00000000-0000-4000-8000-%012d', $index), range(1, 70));
        $state = AdminTableState::from(
            input: ['selected' => [$identities[0], 'not-a-public-id', ...$identities, $identities[0]]],
            sortColumns: ['created' => 'created_at'],
            defaultSort: 'created',
            filterCodes: [],
        );

        self::assertCount(50, $state->selected);
        self::assertSame($identities[0], $state->selected[0]);
    }
}
