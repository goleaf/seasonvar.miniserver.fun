<?php

namespace Tests\Unit;

use App\Support\CatalogAlphabet;
use Tests\TestCase;

class CatalogAlphabetTest extends TestCase
{
    public function test_it_builds_the_full_title_filter_groups(): void
    {
        $groups = CatalogAlphabet::titleGroups();

        $this->assertSame(['#'], $groups['symbols']);
        $this->assertSame(mb_str_split('АБВГДЕЖЗИЙКЛМНОПРСТУФХЦЧШЩЪЫЬЭЮЯ'), $groups['cyrillic']);
        $this->assertSame(range('A', 'Z'), $groups['latin']);
    }

    public function test_it_groups_only_available_letters_in_canonical_script_order(): void
    {
        $groups = CatalogAlphabet::availableGroups(['я', 'B', '#', 'Ё', 'A', 'б', 'A']);

        $this->assertSame(['#'], $groups['symbols']);
        $this->assertSame(['Б', 'Ё', 'Я'], $groups['cyrillic']);
        $this->assertSame(['A', 'B'], $groups['latin']);
    }
}
