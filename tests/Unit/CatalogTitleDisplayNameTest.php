<?php

namespace Tests\Unit;

use App\Support\CatalogTitleDisplayName;
use PHPUnit\Framework\TestCase;

class CatalogTitleDisplayNameTest extends TestCase
{
    public function test_it_separates_a_matching_original_title_suffix(): void
    {
        $name = CatalogTitleDisplayName::from(
            "Королевские гонки РуПола/RuPaul's Drag Race",
            "RuPaul's Drag Race",
        );

        $this->assertSame('Королевские гонки РуПола', $name->primary);
        $this->assertSame("RuPaul's Drag Race", $name->original);
    }

    public function test_it_preserves_unrelated_slashes_and_avoids_duplicate_original_lines(): void
    {
        $unrelated = CatalogTitleDisplayName::from('Мир/Дружба', 'World Friendship');
        $duplicate = CatalogTitleDisplayName::from('Friends', ' friends ');

        $this->assertSame('Мир/Дружба', $unrelated->primary);
        $this->assertSame('World Friendship', $unrelated->original);
        $this->assertSame('Friends', $duplicate->primary);
        $this->assertNull($duplicate->original);
    }

    public function test_it_compares_aliases_independently_of_case_spacing_and_apostrophe_style(): void
    {
        $name = CatalogTitleDisplayName::from(
            "Королевские гонки РуПола/RuPaul's Drag Race",
            "RuPaul's Drag Race",
        );

        $this->assertSame(
            CatalogTitleDisplayName::comparisonKey(' RuPaul’s   Drag Race '),
            CatalogTitleDisplayName::comparisonKey("rupaul's drag race"),
        );
        $this->assertSame(
            CatalogTitleDisplayName::nameHash(' RuPaul’s   Drag Race '),
            CatalogTitleDisplayName::nameHash("rupaul's drag race"),
        );
        $this->assertTrue($name->contains(' RuPaul’s   Drag Race '));
        $this->assertTrue($name->contains('Королевские гонки РуПола'));
        $this->assertFalse($name->contains('Drag Race Untucked'));
    }
}
