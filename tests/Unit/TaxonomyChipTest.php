<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Enums\TagType;
use App\Models\Tag;
use Tests\TestCase;

final class TaxonomyChipTest extends TestCase
{
    public function test_tag_domain_type_does_not_replace_the_catalog_filter_type(): void
    {
        $tag = new Tag;
        $tag->forceFill([
            'name' => 'Импортный тег',
            'slug' => 'importnyi-teg',
            'type' => TagType::Imported,
        ]);

        $this->blade('<x-ui.taxonomy-chip :taxonomy="$tag" />', ['tag' => $tag])
            ->assertSeeText('Импортный тег')
            ->assertSee(route('titles.taxonomy', [
                'type' => 'tag',
                'taxonomy' => 'importnyi-teg',
            ]), false);
    }
}
