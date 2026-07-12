<?php

namespace Tests\Unit;

use App\Services\Catalog\Search\CatalogSearchNormalizer;
use Tests\TestCase;

class CatalogSearchNormalizerTest extends TestCase
{
    public function test_it_normalizes_unicode_case_whitespace_and_punctuation(): void
    {
        $normalizer = new CatalogSearchNormalizer;

        $this->assertSame('OA', $normalizer->display("  ＯＡ\n"));
        $this->assertSame('федор лавров', $normalizer->key('ФЁДОР, ЛАВРОВ'));
        $this->assertSame(['11', '22', '63'], $normalizer->tokens('11.22.63'));
    }

    public function test_it_builds_user_facing_cyrillic_transliteration_and_legacy_variants(): void
    {
        $normalizer = new CatalogSearchNormalizer;

        $this->assertSame('znakhar', $normalizer->transliterate('Знахарь'));
        $this->assertContains('znaxar', $normalizer->legacyVariants('znakhar'));
        $this->assertContains('фёдор', $normalizer->legacyVariants('Федор'));
    }
}
